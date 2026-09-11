---
title: Discovery
weight: 2
---

The package finds stacks three ways, and they combine.

## Directory discovery

`config/compose.php` ships with two patterns:

```php
'discover' => [
    app_path('Docker'),
    base_path('Modules/*/Docker'),
],
```

Each pattern is a path or a glob. A matched directory that holds a compose file is a stack. One that doesn't is treated as a parent, and each of its child directories with a compose file is a stack. So both of these layouts work with no configuration:

```
app/Docker/Meilisearch/docker-compose.yml     plain app: app/Docker is the parent
Modules/Search/Docker/docker-compose.yml      modular app: the Docker directory is the stack
```

A module with several stacks can add `base_path('Modules/*/Docker/*')` to the list.

When a `Stack` subclass is declared by a PHP file directly in the stack directory, that class defines the stack. The lookup reads those files to find the class name (subdirectories are left alone, so a bind-mounted PHP tree is never scanned), then loads it through Composer, so the namespace has to match a PSR-4 mapping in `composer.json`. A class that can't be loaded (a namespace typo, a stale authoritative classmap) makes the directory count as class-less; `compose:doctor` warns about it by name. Two `Stack` classes in one directory is an error.

## Class-less stacks

A directory with a compose file and no class becomes a `DiscoveredStack`: named after the compose file's `name:` or the directory, enabled, with an empty environment. That's enough for a service whose every knob has a `${VAR:-default}` in the compose file, such as a Mailpit for local development.

A class-less stack has nothing to write, so a redeploy leaves an existing env file in that directory alone. Writing one by hand is the way to give such a stack a value, and it survives deploys as long as the file is in the release (or linked into it). The doctor reads that file too, so a `${VAR}` it defines counts as provided.

## Explicit registration

List classes in config when you'd rather not scan, or when a stack lives outside the discovery paths:

```php
'stacks' => [
    App\Docker\Meilisearch\MeilisearchStack::class,
],
```

Or register at runtime from a service provider:

```php
use Mozex\Compose\Facades\Compose;

Compose::register(MeilisearchStack::class);
Compose::register(new GatewayStack);
```

Configured and registered stacks come first. A discovered stack whose directory is already registered is skipped, so listing a class that discovery would also find doesn't duplicate it.

## Names

Every stack needs a unique name, and it has to be a valid Compose project name: lowercase letters, digits, dashes, and underscores, starting with a letter or digit. Directory names are normalized (`Meilisearch` becomes `meilisearch`, `Image Tools` becomes `image-tools`); a `name()` you override is validated as-is. Two stacks with the same name throw, naming both classes and directories.

The name is passed to every compose call as `--project-name`, along with `--project-directory` and `--file`, so two stacks that both live in a directory called `Docker` never collide.

## Reading the registry

```php
Compose::stacks();          // array<string, Stack>, keyed by name
Compose::stack('meili');    // throws ComposeException for an unknown name
Compose::has('meili');
```
