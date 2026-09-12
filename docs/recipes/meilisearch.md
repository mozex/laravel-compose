---
title: Meilisearch
weight: 1
---

A Meilisearch container for Laravel Scout, bound to loopback, master-keyed, with the index settings synced after every deploy. Copy the files below into the app and you're done. Every compose file on this page was brought up against a real daemon before it was published, healthcheck included.

## The compose file

Save it as `app/Docker/Meilisearch/docker-compose.yml`:

```yaml
name: meilisearch

services:
    meilisearch:
        image: getmeili/meilisearch:v1.53
        container_name: ${COMPOSE_PROJECT_NAME}
        restart: unless-stopped
        ports:
            - '127.0.0.1:${MEILISEARCH_PORT:-7700}:7700'
        environment:
            MEILI_MASTER_KEY: ${MEILISEARCH_KEY}
            MEILI_ENV: production
            MEILI_NO_ANALYTICS: 'true'
            MEILI_UPGRADE_DB: 'true'
        healthcheck:
            test: ["CMD", "wget", "--no-verbose", "--spider", "http://127.0.0.1:7700/health"]
            interval: 30s
            timeout: 5s
            retries: 3
            start_period: 10s
        ulimits:
            nofile:
                soft: 65535
                hard: 65535
        volumes:
            - 'meilisearch-data:/meili_data'
        logging:
            driver: json-file
            options:
                max-size: 50m
                max-file: '3'

volumes:
    meilisearch-data:
        driver: local
```

What the non-obvious lines do:

- `MEILI_ENV: production` makes the master key mandatory and switches off the preview dashboard. The app is the only client, on every host.
- `MEILI_UPGRADE_DB` migrates the database in place when you bump the image tag. Without it, a newer binary refuses to open an older volume and the container crash-loops through a rollout.
- The `nofile` limit exists because a settings change rebuilds every index structure at once. The daemon's default limit killed a full-index task on a 10 million document index; the failure was asynchronous, minutes after the deploy exited 0.
- The healthcheck probes `127.0.0.1`, not `localhost`. Inside a container `localhost` can resolve to `::1` first, and Meilisearch listens on IPv4 only.

If the server runs more than one app, change `name:` to `yourapp-meilisearch`. The container name follows it.

The tag is pinned so an upgrade is something you do on purpose. Riding `getmeili/meilisearch:latest` works too: the redeploy's pull step refreshes it on every deploy and `MEILI_UPGRADE_DB` migrates the data, and the worst case of a failed upgrade is a `scout:import`, since the index is rebuilt from your models. Pick that when you'd rather never think about the tag.

## The stack class

Save it as `app/Docker/Meilisearch/MeilisearchStack.php`:

```php
<?php

declare(strict_types=1);

namespace App\Docker\Meilisearch;

use Illuminate\Support\Facades\Config;
use Mozex\Compose\Stack;

class MeilisearchStack extends Stack
{
    /**
     * @return array<string, string|int>
     */
    public function environment(): array
    {
        return [
            'MEILISEARCH_KEY' => (string) Config::get('scout.meilisearch.key'),
            'MEILISEARCH_PORT' => (int) (parse_url((string) Config::get('scout.meilisearch.host'), PHP_URL_PORT) ?: 7700),
        ];
    }

    public function enabled(): bool
    {
        return (bool) Config::get('services.meilisearch.manage_container', false);
    }

    public function wait(): ?int
    {
        return 60;
    }
}
```

The port comes from the one URL the app already dials, so the app and the container can't disagree on it. `wait()` makes the redeploy block until the healthcheck passes, which is what lets the settings sync below run right after.

## Config and env

Install Scout with the Meilisearch driver if you haven't:

```bash
composer require laravel/scout meilisearch/meilisearch-php http-interop/http-factory-guzzle
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
```

Add the per-host switch to `config/services.php`:

```php
'meilisearch' => [
    'manage_container' => (bool) env('MANAGE_MEILISEARCH_CONTAINER', false),
],
```

Then in the production `.env`:

```
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=
MANAGE_MEILISEARCH_CONTAINER=true
```

Production mode wants a master key of at least 16 bytes. Generate one:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Laravel's root `.gitignore` already ignores `.env` at every depth, so the generated env file in the stack directory never gets committed. `compose:doctor` checks that too.

## Deploy

Two lines in the deploy script, in this order:

```bash
php artisan compose:redeploy
php artisan scout:sync-index-settings
```

Run `php artisan compose:doctor` once on the server first. It confirms the docker group membership, checks the compose file with the values the stack will write, and flags anything that listens on every interface.

## On a laptop

Leave `MANAGE_MEILISEARCH_CONTAINER` unset and the stack is skipped, so the deploy script stays identical everywhere. Herd bundles its own Meilisearch on port 7700; if you'd rather run this container locally beside it, set `MEILISEARCH_HOST=http://127.0.0.1:7701` and the stack publishes on 7701 by itself.
