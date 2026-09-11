---
title: Remote Daemons
weight: 3
---

Some setups keep containers on a separate machine: a Docker host next to the web servers, or one daemon shared by several apps. The same commands run there. Docker's CLI already knows how to talk to a remote daemon, and the package passes the address through.

## Pointing at another machine

Globally, in `.env`:

```
COMPOSE_DOCKER_HOST=ssh://deploy@docker-box
```

That value is what `DOCKER_HOST` accepts: `ssh://user@host`, `tcp://host:2376`, or `unix:///path/to/socket`. For SSH, the deploy user needs a key that the remote user accepts, and the remote user has to be in that machine's docker group.

Or name a docker context you created with `docker context create`:

```
COMPOSE_DOCKER_CONTEXT=docker-box
```

A single stack can override either through `host()` or `context()`, so one app can run a search index locally and a browser farm elsewhere. A `unix://` or `npipe://` host and the `default` context still count as the local machine.

## What changes

- **The operator link is skipped.** A link on the web server would point at a directory the remote daemon can't see.
- **Bind mounts don't exist there.** `./Caddyfile:/etc/caddy/Caddyfile` refers to a path on the machine running the command, and the remote daemon has no such file. The doctor warns about every bind mount in a stack with a remote daemon. Use named volumes, or bake the file into an image.
- **The app reaches the container over the network.** Publish the port on the private interface of the Docker host, never on `0.0.0.0`. Docker bypasses host firewalls, so a public publish is a public port.
- **The compose file and env file stay local.** Compose reads both on the machine running the command and sends the resolved configuration to the daemon. Secrets travel over the SSH or TLS connection you configured, not through a file on the remote host.

## Checking the connection

```bash
php artisan compose:doctor
```

The daemon check runs against the remote and prints its version and the address it reached. A wrong key or a missing docker group membership shows up here, with the reason Docker gave.
