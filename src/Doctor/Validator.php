<?php

declare(strict_types=1);

namespace Mozex\Compose\Doctor;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Docker;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Mozex\Compose\StackRegistry;
use Mozex\Compose\Support\EnvFile;
use Mozex\Compose\Support\OperatorLink;
use Throwable;

/**
 * The preflight behind `compose:doctor`. Every check here is a rollout that
 * went wrong once: a variable the compose file wants and nobody writes, a
 * publish on every interface that Docker's own firewall rules expose, a link
 * directory the panel created as root, a daemon the deploy user cannot reach.
 */
class Validator
{
    public function __construct(
        protected Docker $docker,
        protected StackRegistry $registry,
        protected OperatorLink $link,
        protected Repository $config,
    ) {}

    /**
     * @param  bool  $withDaemon  Also run the checks that talk to the docker binary and daemon
     */
    public function run(bool $withDaemon = true): Report
    {
        $problems = [];

        if ($withDaemon) {
            $this->checkDaemon($problems);
        }

        if (! $this->config->get('compose.enabled', true)) {
            $problems[] = Problem::info('Compose is disabled on this host (compose.enabled is false); redeploy skips every stack.');
        }

        try {
            $stacks = $this->registry->all();
        } catch (ComposeException $exception) {
            $problems[] = Problem::error($exception->getMessage());

            return new Report($problems);
        }

        if ($stacks === []) {
            $problems[] = Problem::info('No stacks are registered. Run `compose:make {name}` to scaffold one.');
        }

        foreach ($stacks as $stack) {
            $this->checkStack($stack, $problems, $withDaemon);
        }

        return new Report($problems);
    }

    /**
     * @param  list<Problem>  $problems
     */
    protected function checkDaemon(array &$problems): void
    {
        // `docker --version` never talks to the daemon, so its exit code says
        // whether the binary runs; `docker version` fails whenever the daemon
        // is unreachable, which would hide the real reason below.
        $client = $this->docker->run(['--version'], null, null, 15);

        if ($client->failed()) {
            $problems[] = Problem::error("The docker binary [{$this->docker->binary()}] could not run: ".$this->trim($client->errorOutput().$client->output()));

            return;
        }

        $problems[] = Problem::info('Docker client '.$this->clientVersion($client->output()).'.');

        $compose = $this->docker->run(['compose', 'version', '--short'], null, null, 15);

        if ($compose->failed()) {
            $problems[] = Problem::error('The docker compose plugin is not installed. Install docker-compose-plugin so `docker compose` works.');
        }

        if ($compose->successful()) {
            $problems[] = Problem::info('Docker Compose '.$this->trim($compose->output()).'.');
        }

        $daemon = $this->docker->run(['info', '--format', '{{.ServerVersion}}'], null, null, 20);

        if ($daemon->successful()) {
            $target = $this->docker->context() ?? $this->docker->host();
            $problems[] = Problem::info('Docker daemon '.$this->trim($daemon->output()).($target === null ? '' : " via {$target}").'.');

            return;
        }

        $reason = $this->trim($daemon->errorOutput().$daemon->output());

        if (stripos($reason, 'permission denied') !== false) {
            $problems[] = Problem::error(
                "The Docker daemon refused the connection: {$reason}. Add this user to the docker group "
                ."(sudo usermod -aG docker {$this->user()}) and open a new session.",
            );

            return;
        }

        $problems[] = Problem::error("The Docker daemon is not reachable: {$reason}");
    }

    /**
     * @param  list<Problem>  $problems
     */
    protected function checkStack(Stack $stack, array &$problems, bool $withDaemon): void
    {
        $name = $stack->name();

        try {
            $compose = $stack->compose();
            $environment = $stack->environment();
            $rendered = EnvFile::render($environment, $name);
        } catch (Throwable $exception) {
            $problems[] = Problem::error($exception->getMessage(), $name);

            return;
        }

        $missing = array_values(array_diff($compose->requiredVariables(), array_map('strval', array_keys($environment))));

        if ($missing !== []) {
            $problems[] = Problem::error(
                'The compose file consumes '.implode(', ', $missing).' without a default, but environment() does not provide '
                .(count($missing) === 1 ? 'it' : 'them').'.',
                $name,
            );
        }

        $strings = [];

        foreach ($environment as $key => $value) {
            $strings[(string) $key] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        foreach ($compose->publicPublishes($strings) as $publish) {
            $problems[] = Problem::warning(
                "Publishes on every interface: {$publish}. Docker bypasses UFW and similar host firewalls, so bind "
                .'to 127.0.0.1 or a private address unless the port must be public.',
                $name,
            );
        }

        if (! $stack->enabled()) {
            $problems[] = Problem::info('Disabled on this host; redeploy skips it.', $name);
        }

        foreach ($this->registry->unloadableClasses()[rtrim(str_replace('\\', '/', $stack->directory()), '/')] ?? [] as $class) {
            $problems[] = Problem::warning(
                "The class [{$class}] in the stack directory could not be autoloaded, so the stack runs class-less with an empty environment. "
                .'Check the namespace against composer.json, and run `composer dump-autoload`.',
                $name,
            );
        }

        if ($this->docker->isRemote($stack)) {
            foreach ($compose->bindMounts() as $mount) {
                $problems[] = Problem::warning(
                    "The bind mount [{$mount}] points at a path on this machine, but the stack runs on a remote daemon where it does not exist. Use a named volume or copy the file into the image.",
                    $name,
                );
            }
        }

        if (! $this->docker->isRemote($stack)) {
            $this->checkLink($stack, $problems);
        }

        $this->checkEnvFileIgnored($stack, $problems);

        if ($withDaemon && $missing === []) {
            $this->checkComposeConfig($stack, $rendered, $problems);
        }
    }

    /**
     * The env file carries secrets and sits inside the working tree. When the
     * stack lives in a git repository, git itself is asked whether the file
     * would be ignored; a `.env` line in the app's root .gitignore already
     * covers every directory, which is why most apps pass without doing a thing.
     *
     * @param  list<Problem>  $problems
     */
    protected function checkEnvFileIgnored(Stack $stack, array &$problems): void
    {
        $directory = $stack->directory();

        if (! $this->insideGitRepository($directory)) {
            return;
        }

        $file = $this->config->get('compose.env_file', '.env');
        $envPath = $directory.DIRECTORY_SEPARATOR.(is_string($file) && $file !== '' ? $file : '.env');
        $result = Process::path($directory)->timeout(10)->run(['git', 'check-ignore', '--quiet', $envPath]);

        // Exit 1 means "not ignored"; anything else (git missing, not a repo)
        // is not this check's concern.
        if ($result->exitCode() !== 1) {
            return;
        }

        $problems[] = Problem::warning(
            "The env file [{$envPath}] is not ignored by git, so a redeploy would leave secrets in the working tree ready to be committed. "
            .'Add its name to a .gitignore.',
            $stack->name(),
        );
    }

    protected function insideGitRepository(string $directory): bool
    {
        $current = $directory;

        while (true) {
            if (file_exists($current.DIRECTORY_SEPARATOR.'.git')) {
                return true;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return false;
            }

            $current = $parent;
        }
    }

    /**
     * @param  list<Problem>  $problems
     */
    protected function checkLink(Stack $stack, array &$problems): void
    {
        $path = $this->link->pathFor($stack);

        if ($path === null) {
            return;
        }

        $parent = dirname($path);

        if (file_exists($path) || is_link($path)) {
            if ($this->isRealDirectory($path) && count((array) scandir($path)) > 2) {
                $problems[] = Problem::warning(
                    "The operator link path [{$path}] is a directory with content, not a link. The redeploy leaves it alone and "
                    .'skips the link; move the content away so the link can take its place.',
                    $stack->name(),
                );

                return;
            }

            if (! is_writable($parent)) {
                $user = $this->user();
                $problems[] = Problem::warning(
                    "The operator link [{$path}] exists but [{$parent}] is not writable by this user, so it cannot be refreshed. "
                    ."Run: sudo chown {$user}:{$user} {$parent}",
                    $stack->name(),
                );
            }

            return;
        }

        $ancestor = $parent;

        while (! file_exists($ancestor)) {
            $next = dirname($ancestor);

            if ($next === $ancestor) {
                break;
            }

            $ancestor = $next;
        }

        if (! is_writable($ancestor)) {
            $user = $this->user();
            $problems[] = Problem::warning(
                "The operator link [{$path}] cannot be created: [{$ancestor}] is not writable by this user. "
                ."Hosting panels often create the containers directory as root. Run: sudo mkdir -p {$parent} && sudo chown {$user}:{$user} {$parent}",
                $stack->name(),
            );
        }
    }

    /**
     * Let compose itself validate the file with the environment the stack
     * would write, without touching the stack directory.
     *
     * @param  list<Problem>  $problems
     */
    protected function checkComposeConfig(Stack $stack, string $rendered, array &$problems): void
    {
        $envFile = tempnam(sys_get_temp_dir(), 'laravel-compose-');

        if ($envFile === false) {
            return;
        }

        file_put_contents($envFile, $rendered);

        try {
            $result = $this->docker->compose($stack, ['--env-file', $envFile, 'config', '--quiet'], 30);

            if ($result->failed()) {
                $problems[] = Problem::error('`docker compose config` rejected the stack: '.$this->trim($result->errorOutput().$result->output()), $stack->name());
            }
        } finally {
            @unlink($envFile);
        }
    }

    /**
     * A directory that is neither a symlink nor a junction. readlink returns
     * false for a plain directory on Linux and the directory's own path on
     * Windows, and a junction's target on both.
     */
    protected function isRealDirectory(string $path): bool
    {
        if (! is_dir($path) || is_link($path)) {
            return false;
        }

        $target = @readlink($path);

        return $target === false || rtrim(str_replace('\\', '/', $target), '/') === rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * `Docker version 29.0.1, build a7dcaa6` reduced to the version.
     */
    protected function clientVersion(string $output): string
    {
        if (preg_match('/^Docker version ([^,\s]+)/i', trim($output), $match) === 1) {
            return $match[1];
        }

        return $this->trim($output);
    }

    protected function user(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if ($user !== false) {
                return $user['name'];
            }
        }

        $user = $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? null;

        return is_string($user) && $user !== '' ? $user : get_current_user();
    }

    protected function trim(string $output): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $output));
    }
}
