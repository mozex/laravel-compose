---
title: Introduction
weight: 0
---

Laravel Compose lets a Laravel app own the Docker Compose stacks it depends on. A stack is a directory inside the app with a `docker-compose.yml` and, usually, a small `Stack` class beside it. The package regenerates each stack's env file from your config and recreates its containers on every deploy, with one Artisan command that's safe to run any time.

It grew out of production apps that run a search engine, a Telegram Bot API server, and a remote-desktop gateway as containers on the same host as the app. Each of those started as a hand-written Artisan command. The commands converged on the same recipe through the same incidents, and that recipe is what ships here.

## What a stack is

```
app/Docker/Meilisearch/
├── docker-compose.yml     the services, images, ports, volumes
├── MeilisearchStack.php   environment() and per-host knobs
└── .env                   generated on every redeploy, gitignored
```

The class file marks the location. `name()` defaults to the compose file's `name:`, `containerNames()` to every `container_name:` in it, and `directory()` to where the class lives. You override what you need: `environment()` for the values the compose file consumes, `enabled()` so machines without Docker skip the stack, `profiles()`, `wait()`, `host()` for a remote daemon.

A directory with a compose file and no class works too. It's registered with an empty environment, and the compose file's own `${VAR:-default}` values carry the knobs.

## What a redeploy does

`php artisan compose:redeploy` runs these steps for every enabled stack:

1. Write the env file. Compose reads it at `up` time, so it has to exist first.
2. Refresh the operator link, if a link directory is configured. A failure here is reported and printed, never fatal.
3. Build, when the stack says so.
4. `docker compose pull`, result ignored. `up` never refreshes a tag it already holds, so a floating tag would freeze at the first deploy without this. It runs before the sweep so the old container keeps serving during the download.
5. `docker rm -f` on the fixed container names, result ignored. A container created under another project context (a renamed directory, an older layout) is invisible to this project's `up` and wedges it with a name conflict.
6. `docker compose up --detach --remove-orphans`, with `--wait` when the stack asks for it.

The [Redeploy](deploying/redeploy) page walks through each step and what happens when one fails.

## What else is in the box

- `compose:status`, `compose:logs`, and `compose:down` for hosts without a panel.
- `compose:doctor`, a preflight that checks the daemon, every compose file, and the host setup.
- `compose:make` to scaffold a stack.
- An optional link into a hosting panel's container directory, so Ploi and similar panels show the stack's logs.
- Remote daemons through `DOCKER_HOST` or a docker context.
- Lifecycle events, a spatie/laravel-health check, and `Compose::fake()` for tests.

## Where to start

[Installation](installation) takes a minute. [Defining a Stack](stacks/defining-a-stack) covers the class, and [Compose Files](stacks/compose-files) covers what the compose file should look like for a container an app depends on. When it's time to ship, read [Hosting Platforms](deploying/hosting-platforms) for the line that goes in your deploy script.
