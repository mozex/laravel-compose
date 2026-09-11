---
title: Compose Files
weight: 4
---

Any compose file works. These conventions come from running app-owned containers in production, and the scaffolded file follows them.

## Name the project and the containers

```yaml
name: meilisearch

services:
    meilisearch:
        image: getmeili/meilisearch:v1.16
        container_name: meilisearch
```

`name:` becomes the stack name. `container_name:` is what the redeploy sweeps with `docker rm -f` before `up`. Without a fixed name, Compose generates one per project, and a stale container from an older checkout of the same app can sit there under a different project name, holding the port. With a fixed name, the sweep removes it whatever project created it.

## Publish on loopback

```yaml
        ports:
            - '127.0.0.1:${MEILISEARCH_PORT:-7700}:7700'
```

A publish without an address (`7700:7700`) listens on every interface, and Docker writes its own iptables rules that bypass UFW and similar host firewalls. The port answers from the internet no matter what `ufw status` says. Binding the address is the boundary; the firewall isn't. The doctor warns about every publish that listens everywhere.

When an app on another machine needs the port, bind the private interface (a VPN or tailnet address), never `0.0.0.0`.

## Add a healthcheck

```yaml
        healthcheck:
            test: ["CMD", "wget", "--no-verbose", "--spider", "http://localhost:7700/health"]
            interval: 30s
            timeout: 5s
            retries: 3
            start_period: 10s
```

Three things read it: `wait()` on the stack (`up --wait` returns when every service is healthy), `compose:status`, and the laravel-health check. Use the probe the image ships; official images often carry busybox `wget` and no `curl`.

## Cap the logs

```yaml
        logging:
            driver: json-file
            options:
                max-size: 50m
                max-file: '3'
```

A chatty container fills a disk in weeks otherwise.

## Prefer named volumes

```yaml
        volumes:
            - 'meilisearch-data:/meili_data'

volumes:
    meilisearch-data:
        driver: local
```

Named volumes survive redeploys and don't care which release created them. A bind mount of a file in the stack directory (`./Caddyfile:/etc/caddy/Caddyfile:ro`) works on the same host, but it pins the running container to the release directory that started it, and it can't work at all on a [remote daemon](../deploying/remote-daemons). Copy such files into an image or keep them small and accept the coupling.

## Pinned or floating tags

Both are fine, and the redeploy handles both. A pinned tag (`:v1.16`) changes when you change it. A floating tag (`:latest`) is refreshed by the `pull` step on every redeploy, which `up` alone would never do. Float only when the image can migrate its own data in place and the data is rebuildable; pin everything else.

## Profiles for production-only services

```yaml
    caddy:
        image: caddy:2-alpine
        container_name: rdp-gateway-caddy
        profiles:
            - tls
```

A service behind a profile only starts when the stack's `profiles()` returns it. That's how one compose file serves a laptop, where the browser hits the container directly, and a server, where a TLS origin sits in front of it.

## One-shot init containers

```yaml
    init:
        image: alpine:3
        container_name: rdp-gateway-init
        restart: "no"
        command: sh -c "chown -R 1000:1000 /drive"
```

A service that runs once and exits with code 0 counts as finished, not dead, in `compose:status` and the health check.

## Resource limits the daemon won't give you

```yaml
        ulimits:
            nofile:
                soft: 65535
                hard: 65535
```

Containers inherit the daemon's file-descriptor limit, which is low for a search engine rebuilding an index. Whatever the image's documentation says about limits belongs in the compose file, where a redeploy applies it.
