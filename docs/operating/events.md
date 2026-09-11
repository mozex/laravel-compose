---
title: Events
weight: 3
---

Each stack fires events as a redeploy goes through it. They carry the `Stack` object, and the failure event carries the step and the `ProcessResult`.

| Event | When |
|---|---|
| `Mozex\Compose\Events\StackRedeployingEvent` | Before anything runs, including for stacks that turn out to be disabled. |
| `Mozex\Compose\Events\StackSkippedEvent` | The stack or the master switch is disabled. |
| `Mozex\Compose\Events\StackRedeployedEvent` | `up` succeeded. |
| `Mozex\Compose\Events\StackRedeployFailedEvent` | `build` or `up` failed. `$event->step` is `build` or `up`; `$event->result->errorOutput()` has Compose's reason. |

A listener that tells the team when a container didn't come back:

```php
use Mozex\Compose\Events\StackRedeployFailedEvent;

class NotifyOnFailedStack
{
    public function handle(StackRedeployFailedEvent $event): void
    {
        Notification::route('slack', config('services.slack.ops'))->notify(
            new StackFailedNotification($event->stack->name(), $event->step, $event->result->errorOutput()),
        );
    }
}
```

The events are plain classes. Register listeners in a service provider or through Laravel's event discovery, whichever the app uses.
