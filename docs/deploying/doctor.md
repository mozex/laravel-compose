---
title: Doctor
weight: 4
---

```bash
php artisan compose:doctor
php artisan compose:doctor --no-daemon   # skip everything that needs docker
```

The doctor is a preflight. Every check is something that broke a real deploy once. It exits 1 on any error and 0 otherwise; warnings are printed but don't fail it.

## Host checks

- The docker binary runs (the configured `docker.binary`, `docker` by default).
- The Compose plugin is installed.
- The daemon answers. A `permission denied` becomes a hint naming the user and the `usermod -aG docker` command; anything else is printed as Docker reported it. With a remote host or context, the address reached is printed.

## Per-stack checks

- The compose file parses.
- A `Stack` class found in the directory can be autoloaded. When it can't, the stack silently runs class-less, and the warning names the class.
- `environment()` renders: valid keys, no line breaks, supported value types.
- Every `${VAR}` the compose file consumes without a default is a key of `environment()`. A stack with nothing to write (a class-less one) is checked against the env file already in its directory instead, the one a redeploy leaves alone.
- `docker compose config` accepts the file with the environment the stack would write. The env is passed through a temporary file, so the stack directory isn't touched. A stack with nothing to write is checked with its own env file.
- No publish listens on every interface. Addresses given as `${BIND:-...}` are resolved through the stack's environment first, so a variable that resolves to `127.0.0.1` passes.
- For remote daemons, no bind mounts.
- For local daemons with a link directory, the link can be created or refreshed by this user. When it can't, the warning names the directory that needs `chown` and the user to give it to. A directory with content sitting at the link path gets its own warning, since the redeploy leaves it alone.
- The env file would be ignored by git, when the stack lives in a repository.
- Disabled stacks and a disabled master switch are noted, so "why didn't it deploy" has an answer.

## In your test suite

The same checks are available as a report, which makes a good architecture test: it catches a compose file that consumes a renamed variable before the branch merges.

```php
use Mozex\Compose\Facades\Compose;

it('keeps every stack consistent with its compose file', function (): void {
    $report = Compose::validate(withDaemon: false);

    expect($report->isClean())->toBeTrue(implode("\n", $report->lines()));
});
```

`Report` exposes `errors()`, `warnings()`, `notes()`, `forStack($name)`, `isClean()`, and `hasWarnings()`. Each `Problem` has a `severity`, a `message`, and the `stack` it belongs to, or null for host-level findings.
