---
title: Configuration
weight: 5
---

Publish the file with `php artisan vendor:publish --tag=laravel-compose-config`. Every key has a default that works for a single server running the app and its containers.

```php
return [
    'enabled' => (bool) env('COMPOSE_ENABLED', true),

    'stacks' => [
        // App\Docker\Meilisearch\MeilisearchStack::class,
    ],

    'discover' => [
        app_path('Docker'),
        base_path('Modules/*/Docker'),
    ],

    'link_directory' => env('COMPOSE_LINK_DIRECTORY'),

    'docker' => [
        'binary' => env('COMPOSE_DOCKER_BINARY', 'docker'),
        'host' => env('COMPOSE_DOCKER_HOST'),
        'context' => env('COMPOSE_DOCKER_CONTEXT'),
    ],

    'env_file' => '.env',

    'timeouts' => [
        'pull' => 300,
        'build' => 600,
        'up' => 120,
        'down' => 60,
        'remove' => 30,
    ],
];
```

## `enabled`

The master switch. `COMPOSE_ENABLED=false` makes every redeploy skip every stack, which is what CI and hosts without Docker want. Per-stack `enabled()` still applies when it's true.

## `stacks`

Stack classes registered explicitly. See [Discovery](stacks/discovery).

## `discover`

Directories or globs to scan. A match holding a compose file is a stack; otherwise its children are checked. The defaults cover `app/Docker/*` and `Modules/*/Docker`.

## `link_directory`

When set, each stack is linked at `{link_directory}/{name}` so a hosting panel or a person on the server finds it outside the release tree. Ploi keeps containers in `/home/{user}/containers`. Empty means no link. A stack overrides the full path through `linkPath()`, and returns an empty string to opt out. Links are skipped for remote daemons.

An old link or an empty directory at that path is replaced. A directory with content is never deleted: the link is skipped, the reason is printed in the deploy output, and `compose:doctor` warns about it until the content is moved away.

## `docker.binary`

The docker executable. A full path works when it isn't on the deploy user's `PATH`.

## `docker.host` and `docker.context`

A `DOCKER_HOST` value or a docker context name for a [remote daemon](deploying/remote-daemons). Empty means the local daemon and the current context. Stacks override both.

## `env_file`

The name of the generated env file inside each stack directory. Compose reads `.env` on its own; any other name is passed to every compose call as `--env-file` once the file exists, and `compose:make` puts it in the stack's `.gitignore`.

## `timeouts`

Seconds allowed for each step. `up` is raised automatically to `wait() + 30` on stacks that wait. `pull`, `build`, and `up` can be overridden per stack through `pullTimeout()`, `buildTimeout()`, and `timeout()`.
