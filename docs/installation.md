---
title: Installation
weight: 1
---

Install the package with Composer:

```bash
composer require mozex/laravel-compose
```

The service provider registers itself through package discovery. Publish the config file when you want to change a default:

```bash
php artisan vendor:publish --tag=compose-config
```

That writes `config/compose.php`. The [Configuration](./configuration.md) page explains every key.

## What the server needs

- Docker Engine with the Compose plugin, version 2.17 or newer, so `docker compose version` works. Ploi's Docker server type and Forge's Docker install both provide it; on a plain Ubuntu box, follow Docker's own install guide and add `docker-compose-plugin`.
- The user that runs your deploy in the `docker` group. Without it, every command fails with `permission denied` on the daemon socket.

Run the doctor after installing to confirm both:

```bash
php artisan compose:doctor
```

## Hosts without Docker

Nothing here requires Docker to be present. A stack whose `enabled()` returns false is skipped, and `COMPOSE_ENABLED=false` skips every stack on a host. CI, local machines that use a natively installed service instead, and staging boxes without Docker all run the same deploy script unchanged.

## Next step

Scaffold your first stack with `php artisan compose:make {name}` and read [Defining a Stack](./stacks/defining-a-stack.md).
