---
title: Health Check
weight: 2
---

If the app uses [spatie/laravel-health](https://github.com/spatie/laravel-health), one check covers every stack:

```php
use Mozex\Compose\Health\StacksCheck;
use Spatie\Health\Facades\Health;

Health::checks([
    StacksCheck::new(),
]);
```

The check asks each enabled stack for its status and reports:

| Result | When |
|---|---|
| ok | Every enabled stack is running. Also when no stack is registered, or Compose is disabled on the host. |
| warning | Every stack is running, but one has a healthcheck that isn't green (`starting` or `unhealthy`). |
| failed | An enabled stack has a stopped container, or was never created. |

The meta carries one entry per stack (`healthy`, `unhealthy`, `down`, `not created`, or `disabled`), so a dashboard or an Oh Dear health page shows which one. Disabled stacks are never asked, so the check passes on hosts without Docker.

Pair it with `wait()` on the stack: `--wait` catches a container that never comes up during the deploy, and the health check catches one that dies later.
