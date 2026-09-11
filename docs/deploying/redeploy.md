---
title: Redeploy
weight: 1
---

```bash
php artisan compose:redeploy            # every stack
php artisan compose:redeploy meilisearch
php artisan compose:redeploy --dry-run  # print the plan, touch nothing
```

The command exits 0 when every stack was redeployed or skipped and 1 when any stack failed, so a deploy script stops there. Compose output streams through, and each stack ends with one line: redeployed, skipped, or failed.

## The steps, in order

The order isn't cosmetic. Each step is placed where it is because of a rollout that went wrong the other way.

**1. Write the env file.** Compose reads it at `up` time. Writing it first is what makes a changed value apply. A compose file that doesn't parse, or a value that can't be written (a line break, an array), fails this stack with the reason in the output, and the next stack still gets its turn.

**2. Refresh the operator link.** Only when a [link directory](hosting-platforms) is configured and the daemon is local. If the link can't be refreshed, the exception is reported through the app's exception handler and a line is printed in the deploy output, and the redeploy carries on. The link is convenience; the container is not.

**3. Build.** Only for stacks whose `build()` returns true: `compose build --pull`. A failed build fails the stack, since there's nothing to start.

**4. Pull, result ignored.** `compose pull --ignore-buildable --quiet`. `up` never refreshes a tag it already has locally, so without this a floating tag would freeze at whatever the first deploy pulled. Ignoring the result means a registry outage redeploys the local image instead of failing the rollout. Pulling before the sweep means the old container keeps serving through the download.

**5. Sweep, result ignored.** `docker rm -f` on every `container_name` in the compose file. A fixed-name container created under a different project context (the directory was renamed, the stack moved, an older layout ran) is invisible to this project's `up`, which then dies with a name conflict. Removing by name clears it whatever created it. A missing container is the normal case, hence the ignored result.

Container names are global on a Docker host. Two apps on one server that both name a container `meilisearch` would sweep each other's, so prefix names with the app (`shop-meilisearch`); the scaffolder does that for you. [Compose Files](../stacks/compose-files) has the details.

**6. Up.** `compose up --detach --remove-orphans`, plus `--build` for building stacks and `--wait --wait-timeout N` when `wait()` returns a number. `--remove-orphans` clears services removed from the compose file since the last rollout. A failed `up` fails the stack.

Because the sweep runs every time, `up` always creates fresh containers from the env file just written. A value can't be left un-applied on a container that was already running.

A step that runs past its timeout counts as a failed step, nothing more: a stalled pull is ignored like a failed one, a hung `up` fails the stack and fires the failure event, and the next stack still gets its turn. Timeouts live in [configuration](../configuration) and on the stack.

## Waiting for health

`wait()` makes `up` block until every service is running, or healthy where a healthcheck exists, and fail after the given seconds. Turn it on for stacks that later deploy steps talk to: a search index that gets its settings synced right after, for example. A dead just-recreated container is deploy breakage, and this is where it surfaces.

Leave it off for stacks that take minutes to become healthy and nothing in the deploy needs immediately.

## Where it goes in a deploy

Run it after the new release is built and its config is in place, and before anything that talks to a container. With Composer-script style deploys:

```json
"deploy:after": [
    "php artisan compose:redeploy",
    "php artisan search:sync-settings",
    "php artisan horizon:terminate"
]
```

[Hosting Platforms](hosting-platforms) has the equivalent for Ploi, Forge, Envoyer, and plain SSH.

## Dry run

```
meili ............................ /srv/app/current/app/Docker/Meilisearch
  env file: /srv/app/current/app/Docker/Meilisearch/.env with MEILISEARCH_KEY, MEILISEARCH_PORT
  $ docker compose --project-name meili --project-directory ... pull --ignore-buildable --quiet
  $ docker rm -f meilisearch
  $ docker compose --project-name meili --project-directory ... up --detach --remove-orphans --wait --wait-timeout 60
```

Env keys are printed, values never are.

## From code

```php
use Mozex\Compose\Facades\Compose;
use Mozex\Compose\Enums\RedeployResult;

$results = Compose::redeploy();            // ['meili' => RedeployResult::Redeployed, ...]
$results = Compose::redeploy('meili', fn (string $type, string $buffer) => print $buffer);
```

Each stack also fires [events](../operating/events) as it goes.
