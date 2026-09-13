---
name: laravel-compose
description: Define, redeploy, inspect, and test the Docker Compose stacks a Laravel app owns with mozex/laravel-compose (compose:make, compose:redeploy, compose:status, compose:logs, compose:down, compose:doctor). Use when the user wants the app to run a container next to itself (a search engine, a bot API server, a headless browser, an image toolchain), wire container recreation into a deploy script, write or change a Stack class or its compose file, debug why a stack didn't deploy, or test code that redeploys stacks.
---

# Laravel Compose

This project has `mozex/laravel-compose` installed. A stack is a directory with a compose file and, usually, a `Stack` class beside it. The package writes the stack's env file from config and recreates its containers on every deploy through `php artisan compose:redeploy`. Prefer the package's commands and the `Stack` API over hand-written `docker` calls.

## When to use this skill

- The user wants a service to run in a container that the app owns and redeploys.
- A deploy script needs the container recreated, or a container did not come back after a deploy.
- A `Stack` class or compose file needs to be created or changed.
- Code that redeploys, inspects, or tears down stacks needs tests.

## Creating a stack

```bash
php artisan compose:make meilisearch
```

Writes `app/Docker/Meilisearch/` (or `--path=Modules/Search/Docker`) with `MeilisearchStack.php`, `docker-compose.yml`, and a `.gitignore` for the generated env file. The namespace comes from the project's PSR-4 map, so any autoloaded directory works.

Discovery is on by default for `app/Docker/*` and `Modules/*/Docker`. A stack elsewhere goes in `config/compose.php` under `stacks`, or through `Compose::register(MyStack::class)`. A compose directory with no class is still a stack (empty environment).

For Meilisearch or a Telegram Bot API server, use the package's recipes (docs `recipes/meilisearch` and `recipes/telegram-bot-api`) rather than writing a compose file from scratch: they carry the image tag, healthcheck, upgrade and file-descriptor settings, the stack class, the `config/services.php` block, and the `.env` keys, all verified against a real daemon.

## The Stack class

Override only what differs from the defaults. `name()`, `directory()`, and `containerNames()` are read from the compose file and the class location.

```php
namespace App\Docker\Meilisearch;

use Illuminate\Support\Facades\Config;
use Mozex\Compose\Stack;

class MeilisearchStack extends Stack
{
    // Written to the stack's .env before every redeploy. Read config, never env() directly.
    public function environment(): array
    {
        return [
            'MEILISEARCH_KEY' => Config::get('scout.meilisearch.key'),
            'MEILISEARCH_PORT' => parse_url(Config::get('scout.meilisearch.host'), PHP_URL_PORT) ?: 7700,
        ];
    }

    // False on hosts without Docker, CI, and machines using a native service. Redeploy skips the stack.
    public function enabled(): bool
    {
        return (bool) Config::get('services.meilisearch.manage_container', false);
    }

    public function profiles(): array { return []; }   // compose profiles to activate, e.g. ['tls']
    public function wait(): ?int { return 60; }        // up --wait --wait-timeout 60; null = don't wait
    public function build(): bool { return false; }    // compose build --pull first, for build: sections
    public function pull(): bool { return true; }      // compose pull before the sweep (keeps floating tags fresh)
    public function host(): ?string { return null; }   // DOCKER_HOST override for a remote daemon
    public function linkPath(): ?string { return null; } // null = {link_directory}/{name, dashes as underscores}; '' = no link
}
```

Rules that matter:

- Every `${VAR}` the compose file consumes without a default must be a key of `environment()`. `${VAR:-x}` is fine without one, and so is `${COMPOSE_PROJECT_NAME}`, which compose fills from the project name. `compose:doctor` enforces it; for a class-less stack it reads the hand-written env file in the directory instead.
- The env file always wins over the shell: every compose command runs with the keys the stack writes unset, so the app's own `.env` (which Laravel exports to child processes) can't shadow a stack value that shares its name.
- Derive a port from the URL the app already dials rather than adding a second config key; the two can then never disagree.
- Never edit the generated `.env` on a server. The next redeploy overwrites it.
- Values may be scalars, null, backed enums, or `Stringable`; no arrays, no line breaks. Quoting is handled.

## The compose file

```yaml
name: meilisearch

services:
    meilisearch:
        image: getmeili/meilisearch:v1.16
        container_name: meilisearch                  # required for the stale-container sweep
        restart: unless-stopped
        ports:
            - '127.0.0.1:${MEILISEARCH_PORT:-7700}:7700'   # loopback: Docker bypasses UFW
        environment:
            MEILI_MASTER_KEY: ${MEILISEARCH_KEY}
        healthcheck:
            test: ["CMD", "wget", "--no-verbose", "--spider", "http://127.0.0.1:7700/health"]
            interval: 30s
        volumes:
            - 'meilisearch-data:/meili_data'           # named volume, not a bind mount
        logging:
            driver: json-file
            options: { max-size: 50m, max-file: '3' }

volumes:
    meilisearch-data:
```

- Give every service a `container_name`. Publish on `127.0.0.1` (or a private address), never `0.0.0.0` or a bare `7700:7700`.
- Project and container names are global on the host. When several apps share a server, prefix both with the app (`shop-meilisearch`); `compose:make` writes `{app}-{stack}` names by default. `container_name: ${COMPOSE_PROJECT_NAME}-meilisearch` works too; the sweep resolves `${VAR}` in names before `docker rm -f`.
- Add a healthcheck; `wait()`, `compose:status`, and the health check read it. Probe `127.0.0.1`, never `localhost` (it can resolve to `::1` inside the container while the service listens on IPv4).
- Prefer named volumes. A bind mount of a stack-local file pins the container to the release directory and cannot work on a remote daemon.
- Production-only sidecars go behind `profiles: [tls]` and `profiles()` on the class.

## Deploying

`php artisan compose:redeploy` writes the env file (a stack with an empty `environment()` leaves an existing file alone), refreshes the operator link, builds if asked, pulls (result ignored), force-removes the fixed container names (result ignored), then runs `compose up --detach --remove-orphans` (`--wait` when the stack waits). A step past its timeout counts as failed, nothing worse. Exit 1 if any stack failed. The order is deliberate; do not reorder or "simplify" it.

Put it in the deploy script after the release is built and before anything that talks to a container: first entry of a Composer `deploy:after` script, a Forge deploy-script line after `migrate --force`, an Envoyer "Activate New Release" hook. `--dry-run` prints the plan without running anything.

Panels: set `COMPOSE_LINK_DIRECTORY=/home/{user}/containers` (Ploi's container directory) so the panel shows the stack's logs. The link is named after the stack with dashes as underscores (`acme-search` -> `acme_search`), matching the directory Ploi creates. Ploi setup, once per stack: create a container in the panel with the stack's name, then `sudo rm -rf /home/{user}/containers/acme_search` (the panel creates it as root with its own `docker-compose.yml`, which the link can never replace), then deploy. The panel may also have created the containers directory itself as root; the doctor prints the `chown` to run. A panel container with another name needs `linkPath()` returning its full path. Never deploy the container from the panel; it would overwrite the compose file in the release tree.

Remote daemon: `COMPOSE_DOCKER_HOST=ssh://user@host` or `COMPOSE_DOCKER_CONTEXT=name`, or `host()`/`context()` on one stack (a stack value replaces both globals; a context beside a host wins). The link is skipped and bind mounts do not exist there.

## Inspecting and stopping

```bash
php artisan compose:doctor              # preflight: binary, plugin, daemon, docker group, every compose file, ${VAR} coverage, public publishes, link directory, gitignore
php artisan compose:status              # table per container; exit 1 when an enabled stack is not running
php artisan compose:logs meilisearch --tail=200 --follow   # --tail=all for everything
php artisan compose:down meilisearch --volumes   # asks; --force for scripts
```

From code:

```php
$stack = Compose::stack('meilisearch');
$stack->isRunning(); $stack->isHealthy(); $stack->status()->containers;
$stack->logs(tail: 50);
$stack->exec('meilisearch', ['ls', '/meili_data']);   // maintenance inside a container
$stack->down();
```

Health: `Health::checks([StacksCheck::new()])` with spatie/laravel-health. Events: `StackRedeployingEvent`, `StackSkippedEvent`, `StackRedeployedEvent`, `StackRedeployFailedEvent` (`step` is `compose`, `env`, `build`, or `up`; `reason()` has the message).

## Testing

```php
$fake = Compose::fake();          // records redeploys, also fakes Process
Compose::redeploy();
$fake->assertRedeployed('meilisearch')->assertSkipped('mailpit');
$fake->shouldFail('meilisearch');  // next redeploy reports Failed
```

`Process::fake()` alone runs the real recipe against faked commands; assert on `$process->command` arrays. Fake patterns need `*` between tokens (`'*ps*--format*'`), because Symfony quotes each argument on Linux.

Architecture test for every project with stacks:

```php
expect(Compose::validate(withDaemon: false)->isClean())->toBeTrue();
```

## Debugging a stack that did not deploy

1. `php artisan compose:redeploy --dry-run`: is the stack listed, and is it skipped? Skipped means `enabled()` or `COMPOSE_ENABLED` is off on this host.
2. `php artisan compose:doctor`: missing variables, a rejected compose file, a daemon the user cannot reach, a root-owned link directory.
3. `php artisan compose:logs {stack}`: the container's own reason.
4. A name conflict on `up` means a container with that `container_name` exists under another project; the sweep handles it on the next redeploy, or `docker rm -f {name}` by hand.
