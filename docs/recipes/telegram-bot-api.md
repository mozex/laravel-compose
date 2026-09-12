---
title: Telegram Bot API
weight: 2
---

A local Telegram Bot API server next to the app. The cloud API caps uploads at 50 MB and downloads at 20 MB; a local server raises uploads to 2000 MB, removes the download limit, and accepts webhooks on any local address and port. Copy the files below, add the two credentials from Telegram, and you're done. The compose file was brought up against a real daemon before it was published.

You need an `api_id` and `api_hash` from [my.telegram.org](https://my.telegram.org). They identify the application, not the bot, so one pair serves every bot the server runs.

## The compose file

Save it as `app/Docker/TelegramBotApi/docker-compose.yml`:

```yaml
name: telegram-bot-api

services:
    telegram-bot-api:
        image: aiogram/telegram-bot-api:10.3
        container_name: ${COMPOSE_PROJECT_NAME}
        restart: unless-stopped
        ports:
            - '127.0.0.1:${TELEGRAM_API_PORT:-8081}:8081'
        environment:
            TELEGRAM_API_ID: ${TELEGRAM_API_ID}
            TELEGRAM_API_HASH: ${TELEGRAM_API_HASH}
            TELEGRAM_STAT: 1
        healthcheck:
            test: ["CMD", "wget", "--no-verbose", "--spider", "http://127.0.0.1:8082/"]
            interval: 30s
            timeout: 5s
            retries: 3
            start_period: 10s
        volumes:
            - 'telegram-bot-api-data:/var/lib/telegram-bot-api'
        logging:
            driver: json-file
            options:
                max-size: 50m
                max-file: '3'

volumes:
    telegram-bot-api-data:
        driver: local
```

The image tag follows the Bot API version, so `10.3` is Bot API 10.3. `TELEGRAM_STAT` turns on a statistics page on port 8082 inside the container; it's what the healthcheck probes, and it stays unpublished. Only the API port reaches the host, on loopback. The probe uses `127.0.0.1` rather than `localhost` because `localhost` can resolve to `::1` first inside a container and the server listens on IPv4.

If the server runs more than one app, change `name:` to `yourapp-telegram-bot-api`. The container name follows it.

## The stack class

Save it as `app/Docker/TelegramBotApi/TelegramBotApiStack.php`:

```php
<?php

declare(strict_types=1);

namespace App\Docker\TelegramBotApi;

use Illuminate\Support\Facades\Config;
use Mozex\Compose\Stack;

class TelegramBotApiStack extends Stack
{
    /**
     * @return array<string, string|int>
     */
    public function environment(): array
    {
        return [
            'TELEGRAM_API_ID' => (string) Config::get('services.telegram.api_id'),
            'TELEGRAM_API_HASH' => (string) Config::get('services.telegram.api_hash'),
            'TELEGRAM_API_PORT' => (int) (parse_url((string) Config::get('services.telegram.api_url'), PHP_URL_PORT) ?: 8081),
        ];
    }

    public function enabled(): bool
    {
        return (bool) Config::get('services.telegram.manage_container', false);
    }

    public function wait(): ?int
    {
        return 60;
    }
}
```

## Config and env

In `config/services.php`:

```php
'telegram' => [
    'api_id' => env('TELEGRAM_API_ID'),
    'api_hash' => env('TELEGRAM_API_HASH'),
    'api_url' => env('TELEGRAM_BOT_API_URL', 'http://127.0.0.1:8081'),
    'manage_container' => (bool) env('MANAGE_TELEGRAM_CONTAINER', false),
],
```

In the production `.env`:

```
TELEGRAM_API_ID=
TELEGRAM_API_HASH=
TELEGRAM_BOT_API_URL=http://127.0.0.1:8081
MANAGE_TELEGRAM_CONTAINER=true
```

Point your bot library at that URL instead of `https://api.telegram.org`. With [Nutgram](https://nutgram.dev) that's one key in `config/nutgram.php`:

```php
'config' => [
    'api_url' => env('TELEGRAM_BOT_API_URL', 'http://127.0.0.1:8081'),
],
```

Other libraries call it the base URL; the value is the same.

## Moving a bot to the local server

A bot is logged in on one server at a time. Before the first request against the local one, log the bot out of the cloud API:

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/logOut"
```

The bot can log in to the local server right away. Going back to the cloud API is blocked for ten minutes after a logout, so don't flip a production bot back and forth to test.

## Deploy

```bash
php artisan compose:redeploy
```

Then register the webhook the way your library does, against the local server. Run `php artisan compose:doctor` once on the server first.

## Local mode

The server also has a local mode, where `getFile` returns an absolute path on the server's disk instead of a path to download over HTTP. It saves a copy of every file the bot receives, at the cost of the app needing to read the container's directory. Two changes turn it on:

```yaml
        environment:
            TELEGRAM_LOCAL: 1
        volumes:
            - '/srv/telegram-bot-api:/var/lib/telegram-bot-api'
```

The path the API returns starts with `/var/lib/telegram-bot-api`, so the app maps that prefix to the bind-mounted directory when it opens a file. Nutgram has `is_local` and a `local_path_transformer` for exactly this. The container writes as its own user (UID 101), so either make the directory readable to the app user or run the container with the app user's ID. Skip local mode unless you move files large enough for the extra copy to matter.
