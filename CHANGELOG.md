# Changelog

All notable changes to `laravel-compose` will be documented in this file.

## 1.0.1 - 2026-09-12

### What's Changed

* Scaffolded healthchecks probe `127.0.0.1` instead of `localhost`. Inside a container `localhost` can resolve to `::1` first while the service listens on IPv4, and the check then fails with "connection refused" against a service that is fine. The compose file `compose:make` writes, the docs, and the Boost skill all follow suit.
* Two copy-and-paste recipes in the docs: Meilisearch for Laravel Scout, and a local Telegram Bot API server. Each has the compose file, the stack class, the `config/services.php` block, the `.env` keys, and the deploy lines, and both were brought up against a real daemon before publishing.

**Full Changelog**: https://github.com/mozex/laravel-compose/compare/1.0.0...1.0.1

## 1.0.0 - 2026-09-12

### What's Changed

The first release. A Laravel app can now own the Docker Compose stacks it depends on and recreate them on every deploy with one command.

* A stack is a directory with a `docker-compose.yml` and, usually, a small `Stack` class beside it. `app/Docker/*` and `Modules/*/Docker` are found without configuration, and a directory with no class still counts.
* `php artisan compose:redeploy` writes the stack's env file from your config, refreshes the operator link, builds when asked, pulls, sweeps stale containers by name, and runs `compose up`. The order comes from production incidents, and the command is safe to run again.
* Env files are generated, quoted the way Compose reads them, written with `0600` permissions, and never shadowed by the app's own `.env`.
* `compose:doctor` checks the docker binary, the Compose plugin version, the daemon, every compose file, `${VAR}` coverage, publishes on every interface, the link directory, and whether git ignores the env file.
* `compose:status`, `compose:logs`, and `compose:down` cover the day-to-day, and a `StacksCheck` for spatie/laravel-health watches the containers between deploys.
* Remote daemons through `DOCKER_HOST` or a docker context, four lifecycle events, and `Compose::fake()` with `assertRedeployed()` and friends for tests.
* Supports PHP 8.2 to 8.5 and Laravel 11 to 13.

**Full Changelog**: https://github.com/mozex/laravel-compose/commits/1.0.0
