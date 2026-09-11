# laravel-compose

Laravel package that lets an app own Docker Compose stacks (a compose file plus an optional `Stack` class beside it) and recreate them on every deploy with one command, `compose:redeploy`. Extracted from production Laravel apps that run Meilisearch, a Telegram Bot API server, and a Guacamole gateway as sidecar containers next to the app on the same host.

## Architecture

```
src/
  Stack.php                 Abstract base: environment(), enabled(), profiles(), build(), pull(), wait(), timeouts,
                            linkPath(), host(), context(), plus runtime helpers status()/logs()/exec()/down().
                            name(), directory(), containerNames() default from the compose file and class location.
  DiscoveredStack.php       A compose directory with no Stack class beside it (empty environment).
  StackRegistry.php         config('compose.stacks') + runtime register() + directory discovery (globs, class-map scan).
  Docker.php                The only place the docker binary runs. Process facade, explicit --project-name/--project-directory/--file,
                            DOCKER_HOST / --context support, timeouts from config.
  Actions/RedeployStack.php The recipe (env write, link, build?, pull, rm -f sweep, up). Order is load-bearing, see the docblock.
  Compose.php               Manager behind the facade: stacks(), stack(), register(), redeploy(), validate(), docker().
  Doctor/                   Validator + Report/Problem/Severity behind compose:doctor and Compose::validate().
  Support/ComposeFile.php   Symfony YAML parse: name, container names, required ${VAR}s, profiles, bind mounts, public publishes, interpolation.
  Support/EnvFile.php       Compose-compatible quoting, 0600 perms, refuses newlines.
  Support/OperatorLink.php  Symlink/junction refresh at {link_directory}/{name}; never fatal to a rollout.
  Support/StackStatus.php   Parses `compose ps --format json` (NDJSON and array forms).
  Support/NamespaceResolver Directory -> namespace via Composer's PSR-4 map (used by compose:make).
  Commands/                 compose:redeploy, status, logs, down, doctor, make.
  Health/StacksCheck.php    spatie/laravel-health check (optional dependency, only loaded when used).
  Testing/ComposeFake.php   Compose::fake() with assertRedeployed/assertSkipped/assertNotRedeployed/assertNothingRedeployed.
  Events/                   StackRedeployingEvent, StackSkippedEvent, StackRedeployedEvent, StackRedeployFailedEvent.
resources/stubs/            Files written by compose:make.
```

Dependency flow: Commands -> Compose/Docker/Validator -> Stack/Registry -> Support. Nothing in `src/` uses foundation helpers (`config()`, `base_path()`); the package depends on split `illuminate/*` components only.

## Key design decisions

- **The redeploy order is not negotiable.** Env before `up` (compose reads it then), `pull` before the `rm -f` sweep (old container serves during the download, registry outage still redeploys), sweep before `up` (a fixed `container_name` created under another project context wedges `up` with a name conflict). Pull and rm results are ignored on purpose.
- **Everything runs through the `Process` facade** so `Process::fake()` and `Compose::fake()` see all of it. Never reach for Symfony Process directly.
- **Compose project name and directory are always explicit** (`--project-name`, `--project-directory`, `--file`) so two stacks in directories both called `Docker` cannot collide.
- **Defaults come from the compose file**, not from duplicated PHP: `name:` and `container_name:` are parsed, and the doctor checks every non-defaulted `${VAR}` against `environment()`. That replaces a hand-written parity test.
- **Class-less stacks are valid.** A directory with a compose file and no class is a `DiscoveredStack`; compose-side `${VAR:-default}` carries the knobs.
- **The operator link is convenience.** Its failure is reported and printed but never fails the deploy. It is skipped for remote daemons. The link directory is a full path; the package never assumes a username. An old link or an empty directory at the path is replaced; a directory with content is refused, never deleted (the doctor warns about it).
- **Remote daemons** are `DOCKER_HOST` (env) or `--context` (flag), per stack or global. Bind mounts of stack-local files do not exist there; the doctor warns.
- **PHP 8.2 floor.** No typed class constants, no `new X()->method()` without parentheses. PHPStan `type_coverage.constant` is 0 for that reason.
- **Fake patterns in tests use `*` between tokens** (`'*info*--format*'`). Symfony quotes every argument on Linux, so `'*info --format*'` matches on Windows only.

## Extension points

- Subclass `Stack` and override any method; register through config `stacks`, `Compose::register()`, or discovery.
- Listen to the four events for deploy notifications.
- Replace timeouts, binary, host, context, env file name, link directory in `config/compose.php`.
- `Compose::validate()` returns the doctor report for use in an app's own test suite.

## Testing

```bash
composer test            # lint + phpstan + type coverage + pest
composer test:unit       # pest only
```

- Tests live beside what they cover (`tests/Support`, `tests/Commands`, `tests/Doctor`, ...). `tests/Pest.php` provides `fakeStack()` (anonymous Stack in a temp dir with overrides), `temporaryDirectory()`, `fixturesPath()`, and `dockerComposeAvailable()`.
- `tests/Fixtures/` holds stack layouts: `Plain/` (parent directory with a classed and a class-less stack), `Modules/*/Docker` (module style), `Broken/` (two classes, invalid YAML).
- `tests/Integration/RealDockerTest.php` runs the real recipe against a real daemon with a throwaway alpine container, `--wait` included. It skips when docker compose or the daemon is missing. `tests/Support/EnvFileTest.php` also round-trips quoting through `docker compose config`.
- `expectsOutputToContain` matches substrings against single write calls: keep every expected substring unique to one output line.
- `Process::assertRanInOrder` does not exist on Laravel 11.0; capture the sequence with a closure fake instead.

## Development notes

- CI: `.github/workflows/checks.yml` (lint, types, type coverage, PHP 8.2-8.5 x Laravel 11-13 x lowest/stable). GitHub runners ship docker compose, so the integration test runs there too.
- `CHANGELOG.md` is generated from GitHub releases by the changelog workflow. Never edit it by hand.
- Docs live in `docs/` (multi-page, rendered on mozex.dev); the README is the GitHub gateway. Boost skill: `resources/boost/skills/laravel-compose/SKILL.md`.
- Commit messages: short, one line, no attribution lines.
