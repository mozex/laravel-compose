---
title: Defining a Stack
weight: 1
---

A stack class extends `Mozex\Compose\Stack` and lives in the same directory as its compose file. The quickest way to get one is the scaffolder:

```bash
php artisan compose:make meilisearch
```

It writes `app/Docker/Meilisearch/MeilisearchStack.php`, a starter `docker-compose.yml`, and a `.gitignore` containing `.env`. The compose file's `name:` and `container_name:` carry your app's name (`shop-meilisearch` for an app called Shop), so two apps on one server never sweep each other's containers; that prefixed name is what `compose:logs` and the other commands take. Pass `--path=` to put it somewhere else; the namespace is derived from your `composer.json` autoload map, so `Modules/Search/Docker` works as well as `app/Docker`.

## A real example

This stack runs Meilisearch for a Laravel Scout app. The port comes from the one URL the app already dials, so the app and the container can never disagree on it:

```php
namespace App\Docker\Meilisearch;

use Illuminate\Support\Facades\Config;
use Mozex\Compose\Stack;

class MeilisearchStack extends Stack
{
    public function environment(): array
    {
        return [
            'MEILISEARCH_KEY' => Config::get('scout.meilisearch.key'),
            'MEILISEARCH_PORT' => parse_url(Config::get('scout.meilisearch.host'), PHP_URL_PORT) ?: 7700,
        ];
    }

    public function enabled(): bool
    {
        return (bool) Config::get('services.meilisearch.manage_container', false);
    }

    public function wait(): ?int
    {
        return 60;
    }
}
```

`manage_container` is an ordinary config key, so give it an env variable in `config/services.php`:

```php
'meilisearch' => [
    'manage_container' => (bool) env('MANAGE_MEILISEARCH_CONTAINER', false),
],
```

With it false on a laptop that runs Meilisearch natively, the deploy pipeline stays the same and the stack is skipped.

## Every method and its default

You only override what differs from the default.

| Method | Default | What it controls |
|---|---|---|
| `environment()` | `[]` | Values written to the env file before every redeploy. Scalars, null, backed enums, and `Stringable` objects. |
| `name()` | compose `name:`, else the directory name | The Compose project name. Lowercase letters, digits, `-` and `_`, starting with a letter or digit. |
| `directory()` | where the class file lives | The stack directory. |
| `composePath()` | first of `compose.yaml`, `compose.yml`, `docker-compose.yaml`, `docker-compose.yml` in the directory | The compose file. |
| `containerNames()` | every `container_name:` in the compose file | Names force-removed before `up`. |
| `enabled()` | `true` | Whether this host manages the stack. Read a config value here. |
| `profiles()` | `[]` | Compose profiles to activate, such as a production-only TLS sidecar. |
| `build()` | `false` | Run `compose build --pull` first and pass `--build` to `up`. |
| `pull()` | `true` | Run `compose pull` before the sweep. |
| `wait()` | `null` | Seconds for `up --wait --wait-timeout`. Null returns as soon as the containers are created. |
| `timeout()` | config `timeouts.up` (120) | Seconds allowed for `up`. Raised automatically to `wait() + 30` when waiting. |
| `pullTimeout()` | config `timeouts.pull` (300) | Seconds allowed for `pull`. |
| `buildTimeout()` | config `timeouts.build` (600) | Seconds allowed for `build`. |
| `linkPath()` | `{link_directory}/{name}` | Where the operator link points. An empty string disables the link for this stack. |
| `host()` | config `docker.host` | `DOCKER_HOST` for this stack. |
| `context()` | config `docker.context` | Docker context for this stack. |

## Runtime helpers

A stack object also talks to the running containers, which is what your own commands and health checks use:

```php
use Mozex\Compose\Facades\Compose;

$stack = Compose::stack('meilisearch');

$stack->isRunning();                       // every container running, or finished as a one-shot
$stack->isHealthy();                       // running, and every healthcheck reports healthy
$stack->status()->containers;              // ContainerStatus objects: name, service, state, health, ports
$stack->logs(service: 'meilisearch', tail: 50);
$stack->exec('meilisearch', ['ls', '/meili_data']);
$stack->down(volumes: false);
```

`exec()` is the hook for maintenance that has to run inside a container. A gateway that stores per-session upload folders in a named volume, for example, prunes them from a scheduled command with `$stack->exec('guacd', ['find', '/drive', '-type', 'f', '-mmin', '+1440', '-delete'])`.

## A stack with a production-only sidecar

Profiles let one compose file serve local development and production. This gateway runs a Caddy TLS origin only where `tls_enabled` is on:

```php
class GatewayStack extends Stack
{
    public function environment(): array
    {
        return [
            'GATEWAY_PORT' => Config::get('gateway.port'),
            'GATEWAY_DOMAIN' => Config::get('gateway.domain'),
            'JSON_SECRET_KEY' => Config::get('gateway.secret'),
        ];
    }

    public function profiles(): array
    {
        return Config::get('gateway.tls_enabled') ? ['tls'] : [];
    }
}
```

The `caddy` service in the compose file declares `profiles: [tls]`, so a local `compose:redeploy` never starts it.
