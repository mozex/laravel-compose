---
title: Hosting Platforms
weight: 2
---

The default setup is the simplest one: the containers run on the server that runs the app, the deploy user talks to the local daemon, and `compose:redeploy` is one line in the deploy script. Everything below is about where that line goes and what each platform adds.

## Ploi

Ploi installs Docker with one click on an existing server or as a "Docker server" type, and adds the `ploi` user (or the custom system user you gave the site) to the docker group.

Put the redeploy in your deploy script, after the new release is ready:

```bash
php artisan compose:redeploy
```

If you drive deploys through Composer scripts, it's the first entry of `deploy:after`.

Ploi's panel keeps its containers in `/home/{user}/containers/{name}`, where `{user}` is the system user the site runs as. Point the link directory there and every stack gets a link named after itself, so the panel's container screen shows the logs of what the deploy just created:

```
COMPOSE_LINK_DIRECTORY=/home/ploi/containers
```

Two things to know about that directory:

- The panel creates it as root the first time you open the containers screen. The deploy user then can't write the link. Run `sudo chown -R ploi:ploi /home/ploi/containers` once (with your user in place of `ploi`); `compose:doctor` prints that exact command when it applies. Until it's done, the link is skipped with a line in the deploy log and the container still comes up.
- Ploi stores its own copy of the compose YAML in its database and would write it into the linked directory, which is your release tree, if you deployed the container from the panel. Use the panel for logs and status. Deploy from the app.

## Laravel Forge

Install Docker on the server with Docker's own install script and add the `forge` user to the docker group. Then add the line to the site's deploy script, after `php artisan migrate --force`:

```bash
php artisan compose:redeploy
```

Forge has no container screen, so `compose:status` and `compose:logs` are how you look at the stacks. The link directory can stay unset.

## Envoyer

Add a "Run script" hook on the "Activate New Release" action:

```bash
cd {{ release }} && php artisan compose:redeploy
```

The env file is written into the release that runs the command, which is the one about to go live.

## Plain SSH, Deployer, CI runners

Any post-deploy step that runs Artisan in the new release directory works. The command is safe to rerun, so a retry after a flaky network is fine.

## Per-host switches

The same deploy script runs on production, staging, and a laptop, and only some of those run Docker. Two switches handle it:

- `enabled()` on the stack, reading a config value such as `MANAGE_MEILISEARCH_CONTAINER=true`. The stack is skipped elsewhere.
- `COMPOSE_ENABLED=false`, which skips every stack on that host.

A skipped stack prints one line and exits 0, so CI never fails for lack of a daemon.

## The release directory

Containers are created from the release directory that ran the redeploy. Named volumes don't care. A bind mount of a file inside the stack directory does: it keeps pointing at that release. If your deploy prunes old releases, keep enough of them that the running container's release survives, or avoid bind mounts in stacks that live long. The next redeploy moves everything to the new release anyway.
