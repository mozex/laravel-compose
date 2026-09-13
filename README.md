# Laravel Compose

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-compose.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-compose)
[![GitHub Checks Workflow Status](https://img.shields.io/github/actions/workflow/status/mozex/laravel-compose/checks.yml?branch=main&label=checks&style=flat-square)](https://github.com/mozex/laravel-compose/actions/workflows/checks.yml)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-compose/v1)
[![License](https://img.shields.io/github/license/mozex/laravel-compose.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-compose)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-compose.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-compose)

Some Laravel apps need a container or two next to them: a Meilisearch index, a Telegram Bot API server, a headless browser for page scans, an image toolchain. This package keeps each of those as a Docker Compose stack inside the app. The env file is written from your own config, and one Artisan command recreates the containers on every deploy. Status, logs, teardown, and a preflight doctor are included. You don't need a hosting panel to run the stacks, and the panel keeps working if you have one.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/laravel-compose/v1)**: searchable docs, version requirements, detailed changelog, and more.

## Table of Contents

- [Introduction](https://mozex.dev/docs/laravel-compose/v1)
- [Installation](https://mozex.dev/docs/laravel-compose/v1/installation)
- Stacks
  - [Defining a Stack](https://mozex.dev/docs/laravel-compose/v1/stacks/defining-a-stack)
  - [Discovery](https://mozex.dev/docs/laravel-compose/v1/stacks/discovery)
  - [Environment Files](https://mozex.dev/docs/laravel-compose/v1/stacks/environment-files)
  - [Compose Files](https://mozex.dev/docs/laravel-compose/v1/stacks/compose-files)
- Deploying
  - [Redeploy](https://mozex.dev/docs/laravel-compose/v1/deploying/redeploy)
  - [Hosting Platforms](https://mozex.dev/docs/laravel-compose/v1/deploying/hosting-platforms)
  - [Remote Daemons](https://mozex.dev/docs/laravel-compose/v1/deploying/remote-daemons)
  - [Doctor](https://mozex.dev/docs/laravel-compose/v1/deploying/doctor)
- Operating
  - [Status, Logs, and Down](https://mozex.dev/docs/laravel-compose/v1/operating/status-logs-down)
  - [Health Check](https://mozex.dev/docs/laravel-compose/v1/operating/health-check)
  - [Events](https://mozex.dev/docs/laravel-compose/v1/operating/events)
  - [Testing](https://mozex.dev/docs/laravel-compose/v1/operating/testing)
- Recipes
  - [Meilisearch](https://mozex.dev/docs/laravel-compose/v1/recipes/meilisearch)
  - [Telegram Bot API](https://mozex.dev/docs/laravel-compose/v1/recipes/telegram-bot-api)
- [Configuration](https://mozex.dev/docs/laravel-compose/v1/configuration)
- [AI Integration](https://mozex.dev/docs/laravel-compose/v1/ai-integration)

## Support This Project

I maintain this package along with [several other open-source PHP packages](https://mozex.dev/docs) used by thousands of developers every day.

If my packages save you time or help your business, consider [**sponsoring my work on GitHub Sponsors**](https://github.com/sponsors/mozex). Your support lets me keep these packages updated, respond to issues quickly, and ship new features.

Business sponsors get logo placement in package READMEs. [**See sponsorship tiers →**](https://github.com/sponsors/mozex)

## What You Get

**Stacks live with the code.** A stack is a directory holding a `docker-compose.yml` and, usually, a small `Stack` class beside it. Put it in `app/Docker/Meilisearch/` or `Modules/Search/Docker/`; both layouts are found without configuration. The class file marks the location, so there's no path string to drift.

**One command per deploy.** `php artisan compose:redeploy` writes each stack's env file from your config, refreshes the operator link, pulls, force-removes the old containers, and runs `compose up`. The order comes from production incidents, not taste. It's idempotent, so it's safe to run any time.

**Env files you never hand-edit.** Values come from `environment()` on the stack class, which reads your app's config. Quoting follows Compose's rules, the file lands with `0600` permissions, the app's own `.env` can't shadow it, and the doctor tells you when the compose file consumes a variable nobody writes.

**Works without a panel.** `compose:status`, `compose:logs`, `compose:down`, and a `StacksCheck` for spatie/laravel-health cover the day-two work. Stack objects expose `status()`, `logs()`, `exec()`, and `down()` for your own commands.

**Panels keep working.** Set `COMPOSE_LINK_DIRECTORY=/home/ploi/containers` (or whatever user your host runs as) and every stack gets a stable link there under the name Ploi's panel gives it, so the container screen shows the logs of what the deploy just created.

**Same server or another one.** The default is the daemon on the machine that runs the deploy. Point `COMPOSE_DOCKER_HOST` at `ssh://deploy@docker-box`, or name a docker context, and the same commands run against that machine.

**A doctor for the things that go wrong.** `compose:doctor` checks the binary, the compose plugin, the daemon and the docker group, every compose file with the environment the stack would write, publishes on `0.0.0.0` (Docker bypasses UFW), a link directory the panel created as root, bind mounts that can't exist on a remote daemon, and env files git wouldn't ignore.

**Test doubles.** `Compose::fake()` records redeploys instead of running them and gives you `assertRedeployed()` and friends. Everything docker-related goes through Laravel's `Process` facade, so `Process::fake()` sees all of it.

## Installation

> **Requires [PHP 8.2+](https://php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/laravel-compose/v1/requirements)

```bash
composer require mozex/laravel-compose
```

Publish the config file if you want to change the defaults:

```bash
php artisan vendor:publish --tag=compose-config
```

The server needs Docker with the Compose plugin, and the user that runs your deploy has to be in the `docker` group. `compose:doctor` tells you when either is missing.

## Quick Start

Scaffold a stack:

```bash
php artisan compose:make meilisearch
```

That creates `app/Docker/Meilisearch/` with a `docker-compose.yml`, a `MeilisearchStack.php`, and a `.gitignore` for the generated env file. The compose file names the project and container after your app (`shop-meilisearch` for an app called Shop), and that's the name `compose:logs` and the other commands take. Edit the compose file for the image you want, then fill in the stack class:

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
}
```

The compose file reads those values as `${MEILISEARCH_KEY}` and `${MEILISEARCH_PORT:-7700}`. Wire the switch to an env variable in `config/services.php`:

```php
'meilisearch' => [
    'manage_container' => (bool) env('MANAGE_MEILISEARCH_CONTAINER', false),
],
```

Check the result, then bring it up:

```bash
php artisan compose:doctor
php artisan compose:redeploy
```

Add `php artisan compose:redeploy` to your deploy script, before any step that talks to the container, and set `MANAGE_MEILISEARCH_CONTAINER=true` on the hosts that run Docker. Every other host skips the stack.

The [documentation site](https://mozex.dev/docs/laravel-compose/v1) covers stack discovery, the redeploy recipe step by step, hosting platforms, remote daemons, and testing. It also has copy-and-paste recipes for [Meilisearch](https://mozex.dev/docs/laravel-compose/v1/recipes/meilisearch) and a [Telegram Bot API server](https://mozex.dev/docs/laravel-compose/v1/recipes/telegram-bot-api), each verified against a real daemon.

## Resources

Visit the [documentation site](https://mozex.dev/docs/laravel-compose/v1) for searchable docs auto-updated from this repository.

- **[AI Integration](https://mozex.dev/docs/laravel-compose/v1/ai-integration)**: Use this package with AI coding assistants via Context7 and Laravel Boost
- **[Requirements](https://mozex.dev/docs/laravel-compose/v1/requirements)**: PHP, Laravel, and dependency versions
- **[Changelog](https://mozex.dev/docs/laravel-compose/v1/changelog)**: Release history with linked pull requests and diffs
- **[Contributing](https://mozex.dev/docs/laravel-compose/v1/contributing)**: Development setup, code quality, and PR guidelines
- **[Questions & Issues](https://mozex.dev/docs/laravel-compose/v1/questions-and-issues)**: Bug reports, feature requests, and help
- **[Security](mailto:hello@mozex.dev)**: Report vulnerabilities directly via email

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
