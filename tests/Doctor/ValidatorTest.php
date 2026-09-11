<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Doctor\Validator;
use Mozex\Compose\Facades\Compose;
use Mozex\Compose\StackRegistry;

enum FixtureBind: string
{
    case Loopback = '127.0.0.1';
}

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

function healthyDaemon(): void
{
    Process::fake([
        '*--version*' => Process::result('Docker version 29.0.1, build a7dcaa6'),
        '*compose*version*' => Process::result('2.40.0'),
        '*info*--format*' => Process::result('29.0.1'),
        '*' => Process::result(''),
    ]);
}

it('describes the client, the compose plugin, and the daemon when they answer', function (): void {
    healthyDaemon();

    $report = app(Validator::class)->run();

    expect($report->isClean())->toBeTrue()
        ->and(array_map(fn ($problem) => $problem->message, $report->notes()))->toBe([
            'Docker client 29.0.1.',
            'Docker Compose 2.40.0.',
            'Docker daemon 29.0.1.',
            'No stacks are registered. Run `compose:make {name}` to scaffold one.',
        ]);
});

it('stops at a docker binary that cannot run', function (): void {
    Process::fake(['*' => Process::result('', 'not found', 127)]);
    config()->set('compose.docker.binary', '/opt/nope/docker');

    $report = app(Validator::class)->run();

    expect($report->errors())->toHaveCount(1)
        ->and($report->errors()[0]->message)->toContain('[/opt/nope/docker] could not run: not found');

    Process::assertRanTimes(fn (PendingProcess $process): bool => str_contains(implode(' ', $process->command), 'version'), 1);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['/opt/nope/docker', '--version']);
});

it('flags a missing compose plugin and an unreachable daemon, with the docker-group hint', function (): void {
    Process::fake([
        '*--version*' => Process::result('Docker version 29.0.1, build a7dcaa6'),
        '*compose*version*' => Process::result('', 'unknown command', 1),
        '*info*--format*' => Process::result('', 'permission denied while trying to connect to the Docker daemon socket', 1),
    ]);

    $errors = array_map(fn ($problem) => $problem->message, app(Validator::class)->run()->errors());

    expect($errors)->toHaveCount(2)
        ->and($errors[0])->toContain('compose plugin is not installed')
        ->and($errors[1])->toContain('refused the connection')
        ->and($errors[1])->toContain('sudo usermod -aG docker');

    Process::fake([
        '*--version*' => Process::result('Docker version 29.0.1, build a7dcaa6'),
        '*compose*version*' => Process::result('2.40.0'),
        '*info*--format*' => Process::result('', 'Cannot connect to the Docker daemon', 1),
    ]);

    expect(app(Validator::class)->run()->errors()[0]->message)->toBe('The Docker daemon is not reachable: Cannot connect to the Docker daemon');
});

it('refuses a compose plugin older than the redeploy needs', function (): void {
    Process::fake([
        '*--version*' => Process::result('Docker version 29.0.1, build a7dcaa6'),
        '*compose*version*' => Process::result('v2.17.3'),
        '*info*--format*' => Process::result('29.0.1'),
    ]);

    $report = app(Validator::class)->run();

    expect($report->notes()[1]->message)->toBe('Docker Compose 2.17.3.')
        ->and($report->errors())->toHaveCount(1)
        ->and($report->errors()[0]->message)->toContain('Docker Compose 2.17.3 is older than 2.19.0');
});

it('keeps a bare version string when the client prints one', function (): void {
    Process::fake(['*' => Process::result('29.0.1')]);

    expect(app(Validator::class)->run()->notes()[0]->message)->toBe('Docker client 29.0.1.');
});

it('mentions the remote target when the daemon lives elsewhere', function (): void {
    healthyDaemon();
    config()->set('compose.docker.host', 'ssh://deploy@box');

    expect(app(Validator::class)->run()->notes()[2]->message)->toBe('Docker daemon 29.0.1 via ssh://deploy@box.');
});

it('reports a variable the compose file needs and the stack does not write', function (): void {
    $stack = fakeStack([
        'name' => 'needy',
        'compose' => "name: needy\nservices:\n    app:\n        image: alpine\n        environment:\n            SECRET: \${SECRET}\n            OTHER: \${OTHER}\n            FINE: \${FINE:-x}\n",
        'environment' => ['OTHER' => 'set'],
    ]);
    Compose::register($stack);

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->errors())->toHaveCount(1)
        ->and($report->errors()[0]->stack)->toBe('needy')
        ->and($report->errors()[0]->message)->toBe('The compose file consumes SECRET without a default, but environment() does not provide it.');
});

it('warns instead of failing for a variable the shell provides', function (): void {
    Compose::register(fakeStack([
        'name' => 'shelly',
        'compose' => "name: shelly\nservices:\n    app:\n        image: alpine\n        environment:\n            SEARCH_PATH: \${PATH}\n            SECRET: \${SECRET}\n",
        'environment' => [],
    ]));

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->errors())->toHaveCount(1)
        ->and($report->errors()[0]->message)->toBe('The compose file consumes SECRET without a default, but environment() does not provide it.')
        ->and($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0]->message)->toContain('The compose file consumes PATH without a default, and environment() does not provide it. It is set in this shell');
});

it('reports a broken compose file per stack and still checks the others', function (): void {
    $parent = temporaryDirectory();
    File::ensureDirectoryExists($parent.'/Garbled');
    File::put($parent.'/Garbled/docker-compose.yml', "services:\n    app: [\n");
    File::ensureDirectoryExists($parent.'/Needy');
    File::put($parent.'/Needy/docker-compose.yml', "name: needy\nservices:\n    app:\n        image: alpine\n        environment:\n            SECRET: \${SECRET}\n");
    config()->set('compose.discover', [$parent]);

    $report = app(Validator::class)->run(withDaemon: false);

    expect(array_map(fn ($problem) => $problem->stack, $report->errors()))->toBe(['garbled', 'needy'])
        ->and($report->errors()[0]->message)->toContain('could not be read');
});

it('reads a hand-written env file for a stack with nothing to write', function (): void {
    Process::fake(['*' => Process::result('ok')]);
    $compose = "name: quiet\nservices:\n    app:\n        image: alpine\n        environment:\n            SECRET: \${SECRET}\n";
    $stack = fakeStack(['name' => 'quiet', 'compose' => $compose, 'environment' => []]);
    Compose::register($stack);

    $report = app(Validator::class)->run();

    expect($report->errors())->toHaveCount(1)
        ->and($report->errors()[0]->message)->toBe('The compose file consumes SECRET without a default, but environment() does not provide it.');

    File::put($stack->directory().'/.env', "# written by hand\nSECRET=shh\n");

    $report = app(Validator::class)->run();

    expect($report->errors())->toBe([]);

    // Compose reads the file in the directory: no temporary env file is passed.
    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -2) === ['config', '--quiet']
        && ! in_array('--env-file', $process->command, true));

    File::put($stack->directory().'/.env', "OTHER=x\n");

    expect(app(Validator::class)->run(withDaemon: false)->errors()[0]->message)
        ->toBe('The compose file consumes SECRET without a default, but neither environment() nor ['.$stack->directory().DIRECTORY_SEPARATOR.'.env] provides it.');
});

it('warns about publishes on every interface, resolving the address through the environment', function (): void {
    Compose::register(fakeStack([
        'name' => 'open',
        'compose' => "name: open\nservices:\n    app:\n        image: alpine\n        ports:\n            - '\${BIND:-0.0.0.0}:80:80'\n",
        'environment' => ['BIND' => '0.0.0.0'],
    ]));
    Compose::register(fakeStack([
        'name' => 'closed',
        'compose' => "name: closed\nservices:\n    app:\n        image: alpine\n        ports:\n            - '\${BIND:-0.0.0.0}:80:80'\n",
        'environment' => ['BIND' => '127.0.0.1'],
    ]));

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0]->stack)->toBe('open')
        ->and($report->warnings()[0]->message)->toContain('Publishes on every interface: app: ${BIND:-0.0.0.0}:80:80')
        ->and($report->isClean())->toBeTrue();
});

it('resolves a publish address through a hand-written env file', function (): void {
    $compose = "name: bound\nservices:\n    app:\n        image: alpine\n        ports:\n            - '\${BIND}:80:80'\n";
    $stack = fakeStack(['name' => 'bound', 'compose' => $compose, 'environment' => []]);
    File::put($stack->directory().'/.env', "BIND='127.0.0.1'\n");
    Compose::register($stack);

    expect(app(Validator::class)->run(withDaemon: false)->warnings())->toBe([]);

    File::put($stack->directory().'/.env', "BIND=0.0.0.0\n");

    expect(app(Validator::class)->run(withDaemon: false)->warnings()[0]->message)->toContain('Publishes on every interface: app: ${BIND}:80:80');
});

it('renders booleans and enums the way the env file will before interpolating', function (): void {
    $compose = "name: typed\nservices:\n    app:\n        image: alpine\n        ports:\n            - '\${BIND:-0.0.0.0}:80:80'\n";
    Compose::register(fakeStack(['name' => 'typed', 'compose' => $compose, 'environment' => ['BIND' => FixtureBind::Loopback, 'DEBUG' => false]]));

    expect(app(Validator::class)->run(withDaemon: false)->warnings())->toBe([]);
});

it('notes disabled stacks and the master switch', function (): void {
    Compose::register(fakeStack(['name' => 'off', 'enabled' => false]));
    config()->set('compose.enabled', false);

    $notes = array_map(fn ($problem) => $problem->describe(), app(Validator::class)->run(withDaemon: false)->notes());

    expect($notes)->toBe([
        'Compose is disabled on this host (compose.enabled is false); redeploy skips every stack.',
        '[off] Disabled on this host; redeploy skips it.',
    ]);
});

it('warns about bind mounts on a remote daemon and skips the link check there', function (): void {
    config()->set('compose.link_directory', '/root/containers');
    Compose::register(fakeStack([
        'name' => 'remote',
        'host' => 'ssh://deploy@box',
        'compose' => "name: remote\nservices:\n    app:\n        image: alpine\n        volumes:\n            - './Caddyfile:/etc/caddy/Caddyfile:ro'\n            - 'data:/data'\nvolumes:\n    data:\n",
    ]));

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0]->message)->toContain('bind mount [./Caddyfile] points at a path on this machine');
});

it('surfaces an unreadable compose file and an unwritable env value as errors', function (): void {
    Compose::register(fakeStack(['name' => 'bad-env', 'environment' => ['BAD' => "a\nb"]]));
    $broken = fakeStack(['name' => 'broken', 'compose' => "services:\n    app: [\n"]);
    Compose::register($broken);

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->errors())->toHaveCount(2)
        ->and($report->errors()[0]->message)->toContain('contains a line break')
        ->and($report->errors()[1]->message)->toContain('could not be read');
});

it('reports a registry failure instead of throwing', function (): void {
    config()->set('compose.stacks', ['App\\Nope']);

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->errors())->toHaveCount(1)
        ->and($report->errors()[0]->message)->toContain('[App\\Nope] must be a class that extends');
});

it('lets compose validate the file with the environment the stack would write', function (): void {
    Process::fake([
        '*config*--quiet*' => Process::result('', 'service "app" has neither an image nor a build context', 1),
        '*' => Process::result('ok'),
    ]);
    $stack = fakeStack(['name' => 'checked', 'environment' => ['KEY' => 'value']]);
    Compose::register($stack);

    $report = app(Validator::class)->run();

    expect($report->errors())->toHaveCount(1)
        ->and($report->errors()[0]->message)->toContain('`docker compose config` rejected the stack: service "app" has neither');

    Process::assertRan(function (PendingProcess $process) use ($stack): bool {
        $command = $process->command;
        $index = array_search('--env-file', $command, true);

        return $index !== false
            && $process->path === $stack->directory()
            && array_slice($command, $index + 2) === ['config', '--quiet']
            && ! File::exists($command[$index + 1]);
    });
});

it('warns when the link directory cannot be written by this user', function (): void {
    if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
        $this->markTestSkipped('Needs a filesystem that enforces directory permissions for a non-root user.');
    }

    $locked = temporaryDirectory();
    chmod($locked, 0500);
    config()->set('compose.link_directory', $locked.'/containers');
    Compose::register(fakeStack(['name' => 'linked']));

    try {
        $report = app(Validator::class)->run(withDaemon: false);

        expect($report->warnings())->toHaveCount(1)
            ->and($report->warnings()[0]->message)->toContain("[{$locked}] is not writable by this user")
            ->and($report->warnings()[0]->message)->toContain("sudo mkdir -p {$locked}/containers && sudo chown");
    } finally {
        chmod($locked, 0700);
    }
});

it('warns about a stack class that cannot be autoloaded', function (): void {
    $directory = temporaryDirectory();
    File::put($directory.'/docker-compose.yml', "name: orphan\nservices:\n    app:\n        image: alpine\n");
    File::put($directory.'/OrphanStack.php', "<?php\n\nnamespace Nowhere\\Mapped;\n\nclass OrphanStack extends \\Mozex\\Compose\\Stack {}\n");
    config()->set('compose.discover', [$directory]);

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0]->stack)->toBe('orphan')
        ->and($report->warnings()[0]->message)->toContain('[Nowhere\\Mapped\\OrphanStack] in the stack directory could not be autoloaded');
});

it('warns when a directory with content sits where the operator link should go', function (): void {
    $directory = temporaryDirectory();
    File::ensureDirectoryExists($directory.'/occupied');
    File::put($directory.'/occupied/keep.txt', 'mine');
    config()->set('compose.link_directory', $directory);
    Compose::register(fakeStack(['name' => 'occupied']))->register(fakeStack(['name' => 'free']));

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0]->stack)->toBe('occupied')
        ->and($report->warnings()[0]->message)->toContain('is a directory with content, not a link');
});

it('warns when git would not ignore the env file, and stays quiet outside a repository', function (): void {
    Process::fake(['*git*check-ignore*' => Process::result(exitCode: 1)]);
    $tracked = fakeStack(['name' => 'tracked']);
    File::ensureDirectoryExists($tracked->directory().'/.git');
    $loose = fakeStack(['name' => 'loose']);
    Compose::register($tracked)->register($loose);

    $report = app(Validator::class)->run(withDaemon: false);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0]->stack)->toBe('tracked')
        ->and($report->warnings()[0]->message)->toContain('is not ignored by git');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', 'check-ignore', '--quiet', $tracked->directory().DIRECTORY_SEPARATOR.'.env']
        && $process->path === $tracked->directory());
    Process::assertNotRan(fn (PendingProcess $process): bool => $process->command[0] === 'git' && $process->path === $loose->directory());
});

it('accepts an env file git already ignores', function (): void {
    Process::fake(['*git*check-ignore*' => Process::result(exitCode: 0)]);
    $stack = fakeStack(['name' => 'ignored']);
    File::ensureDirectoryExists($stack->directory().'/.git');
    Compose::register($stack);

    expect(app(Validator::class)->run(withDaemon: false)->hasWarnings())->toBeFalse();
});

it('is reachable through the facade', function (): void {
    Compose::register(fakeStack(['name' => 'fine']));

    expect(Compose::validate(withDaemon: false)->isClean())->toBeTrue()
        ->and(app(StackRegistry::class)->names())->toBe(['fine']);
});
