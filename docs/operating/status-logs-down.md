---
title: Status, Logs, and Down
weight: 1
---

Three commands cover what a hosting panel would otherwise show you.

## Status

```bash
php artisan compose:status
php artisan compose:status meilisearch
```

```
+-------------+-------------+-------------+---------+---------+---------------------------+
| Stack       | Service     | Container   | State   | Health  | Ports                     |
+-------------+-------------+-------------+---------+---------+---------------------------+
| meilisearch | meilisearch | meilisearch | running | healthy | 127.0.0.1:7700->7700/tcp  |
| rdp-gateway | init        | ...-init    | exited  | -       | -                         |
| rdp-gateway | guacd       | ...-guacd   | running | -       | -                         |
| mailpit     | -           | -           | disabled| -       | -                         |
+-------------+-------------+-------------+---------+---------+---------------------------+
```

The exit code is meant for monitoring: 0 when every enabled stack is running, 1 when one has a stopped container or was never created. A container that exited with code 0 counts as finished, which is what a one-shot init service does. Disabled stacks are listed without asking Docker, so the command works on hosts without a daemon.

## Logs

```bash
php artisan compose:logs meilisearch
php artisan compose:logs meilisearch --service=meilisearch --tail=500
php artisan compose:logs rdp-gateway --follow
```

`--tail` defaults to 100 lines per container and accepts `all`. `--follow` streams until you stop it.

## Down

```bash
php artisan compose:down                     # stop and remove every enabled stack's containers
php artisan compose:down meilisearch
php artisan compose:down meilisearch --volumes
```

`--volumes` also removes the named volumes, which deletes their data, so it asks first. `--force` skips the question for scripts. Without a stack name, disabled stacks are skipped; naming one runs it regardless.

The next `compose:redeploy` brings everything back.

## From your own code

The commands are thin. Everything they do is available on the stack object, which is how you build a scheduled maintenance task or a custom dashboard:

```php
$stack = Compose::stack('rdp-gateway');

$status = $stack->status();
$status->isRunning();
$status->isHealthy();

foreach ($status->containers as $container) {
    $container->name;      // rdp-gateway-guacd
    $container->service;   // guacd
    $container->state;     // running, exited, ...
    $container->health;    // healthy, unhealthy, starting, or null without a healthcheck
    $container->ports;     // ['127.0.0.1:8989->8080/tcp']
}

$stack->logs(tail: 20);
$stack->exec('guacd', ['find', '/drive', '-type', 'f', '-mmin', '+1440', '-delete']);
$stack->down();
```

`Compose::docker()` returns the runner behind all of that when you need to run a docker command the helpers don't cover. It honours the configured binary, host, and context.
