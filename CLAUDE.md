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
  Docker.php                The only place the docker binary runs. Process facade, explicit --project-name/--project-directory/--file
                            (+ --env-file when env_file is not .env), DOCKER_HOST / --context resolution, timeouts from config.
  Actions/RedeployStack.php The recipe (env write, link, build?, pull, rm -f sweep, up). Order is load-bearing, see the docblock.
  Compose.php               Manager behind the facade: stacks(), stack(), register(), redeploy(), validate(), docker().
  Doctor/                   Validator + Report/Problem/Severity behind compose:doctor and Compose::validate().
  Support/ComposeFile.php   Symfony YAML parse: name, container names, required ${VAR}s, profiles, bind mounts, public publishes, interpolation.
  Support/EnvFile.php       Env path from config, hand-written file detection, parse() with the quoting rules quote() writes,
                            0600 perms, refuses newlines.
  Support/OperatorLink.php  Symlink/junction refresh at {link_directory}/{name with _ for -}; never fatal to a rollout.
  Support/StackStatus.php   Parses `compose ps --format json` (NDJSON and array forms) into ContainerStatus rows.
  Support/ContainerStatus.php One `ps` row: name, service, state, health, exit code, published ports; isRunning()/isFinished()/isHealthy().
  Support/NamespaceResolver Directory -> namespace via Composer's PSR-4 map (used by compose:make).
  Enums/RedeployResult.php  Redeployed | Skipped | Failed, returned per stack by Compose::redeploy().
  Exceptions/ComposeException.php  The one exception class, named static factories per failure.
  Commands/                 compose:redeploy, status, logs, down, doctor, make.
  Health/StacksCheck.php    spatie/laravel-health check (optional dependency, only loaded when used).
  Testing/ComposeFake.php   Compose::fake() with assertRedeployed/assertSkipped/assertNotRedeployed/assertNothingRedeployed.
  Events/                   StackRedeployingEvent, StackSkippedEvent, StackRedeployedEvent, StackRedeployFailedEvent.
resources/stubs/            Files written by compose:make.
```

Dependency flow: Commands -> Compose/Docker/Validator -> Stack/Registry -> Support. Nothing in `src/` uses foundation helpers (`config()`, `base_path()`); the package depends on split `illuminate/*` components only.

## Key design decisions

- **The redeploy order is not negotiable.** Env before `up` (compose reads it then), `pull` before the `rm -f` sweep (old container serves during the download, registry outage still redeploys), sweep before `up` (a fixed `container_name` created under another project context wedges `up` with a name conflict). Pull and rm results are ignored on purpose.
- **Everything runs through the `Process` facade** so `Process::fake()` and `Compose::fake()` see all of it. Never reach for Symfony Process directly. `Docker::run()` turns a `ProcessTimedOutException` into a failed result, so a stalled step never escapes as an exception.
- **The env file beats the shell.** Compose reads the process environment before the env file, and Laravel putenv()s the app's own `.env` into every child process, so an app key sharing a name with a stack key would silently win at `up`. `Docker::compose()` passes every key of `EnvFile::valuesFor($stack)` as `false` (Symfony unsets it) for compose processes only (`Docker::shadowedKeys()`); a stack whose `environment()` throws gets no unsets instead of an exception out of `status`/`logs`/`down`/the health check. `RealDockerTest` proves the unset against a real daemon by shadowing `GREETING` through `putenv` plus `$_SERVER` and `$_ENV`: Symfony only forwards `getenv()` keys that `$_SERVER` also holds, so a bare `putenv()` never reaches the child and would prove nothing.
- **Container names are interpolated before the sweep.** `ComposeFile::containerNames($environment)` resolves `${VAR}` the way compose does and drops anything Docker would refuse, so `container_name: ${COMPOSE_PROJECT_NAME}-app` is swept correctly. `Stack::interpolationValues()` is the source: shell, then env file values (`EnvFile::valuesFor()`, hand-written or rendered), then `COMPOSE_PROJECT_NAME` = `name()`. The doctor never lists `COMPOSE_PROJECT_NAME` as missing.
- **`EnvFile::quote()` double-quotes a value ending in a backslash.** Compose's dotenv treats `\'` as an escaped quote even inside single quotes and fails the whole file with "unterminated quoted value"; the docker round-trip test in `EnvFileTest` covers it.
- **Names are global on a host.** The scaffolder prefixes the project and container names with the app slug (`{app}-{stack}`) so two apps on one server can't sweep each other's containers. Hand-written compose files are the user's call; the docs explain the trade-off.
- **An empty `environment()` never overwrites an existing env file.** Class-less stacks are fed by hand-written files, so `RedeployStack::writeEnvironment()` leaves a non-empty file alone when there is nothing to write (`EnvFile::isHandWritten()`). The doctor mirrors that: it parses the file for the variable and publish checks and lets `compose config` read the file itself instead of a rendered temp file.
- **A bad compose file or env value fails one stack, not the run.** `Stack::name()` falls back to the directory name when the compose file can't be parsed, so the registry still holds the stack; `RedeployStack::execute()` reads the compose file and writes the env file inside try/catch, fires `StackRedeployFailedEvent` with step `compose` or `env` and the exception, and returns `Failed`, so the loop in `Compose::redeploy()` reaches every stack.
- **Docker target precedence lives in `Docker::target()`.** A stack's `host()`/`context()` replaces both global values; a context beside a host drops the host (Docker ignores `DOCKER_HOST` under `--context`); `default` means no context.
- **Unloadable Stack classes are tracked, not swallowed.** `StackRegistry::unloadableClasses()` records classes the class-map scan found but PHP couldn't autoload; the doctor warns per stack.
- **Compose project name and directory are always explicit** (`--project-name`, `--project-directory`, `--file`) so two stacks in directories both called `Docker` cannot collide.
- **Defaults come from the compose file**, not from duplicated PHP: `name:` and `container_name:` are parsed, and the doctor checks every non-defaulted `${VAR}` against `environment()`. That replaces a hand-written parity test.
- **Class-less stacks are valid.** A directory with a compose file and no class is a `DiscoveredStack`; compose-side `${VAR:-default}` carries the knobs.
- **The operator link is convenience.** Its default name is the stack name with dashes as underscores (`OperatorLink::nameFor()`), because Ploi's panel creates `acme_search` for a container named `acme-search`; seen on a real server, although Ploi's API docs show dashes kept. The panel's directory is root-owned with its own `docker-compose.yml`, so the doctor and the resisted-link message print the one-time `sudo rm -rf`. Its failure is reported and printed but never fails the deploy. It is skipped for remote daemons. The link directory is a full path; the package never assumes a username. An old link or an empty directory at the path is replaced; a directory with content is refused, never deleted (the doctor warns about it).
- **Remote daemons** are `DOCKER_HOST` (env) or `--context` (flag), per stack or global. Bind mounts of stack-local files do not exist there; the doctor warns.
- **The doctor requires Compose 2.17** (`Validator::MINIMUM_COMPOSE_VERSION`): `pull --ignore-buildable` arrived in 2.15 and `up --wait-timeout` in 2.17. It warns rather than errors for a `${VAR}` that only the shell provides.
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

- Tests live beside what they cover (`tests/Support`, `tests/Commands`, `tests/Doctor`, ...). `tests/Pest.php` provides `fakeStack()` (anonymous Stack in a temp dir with overrides), `temporaryDirectory()` (removed after each test by a global `afterEach` on the `uses()` chain, links unlinked rather than followed), `fixturesPath()`, and `dockerComposeAvailable()`.
- `Process::fake()` string patterns match the whole command line, temp paths included: match on `$process->command` in a closure instead of `'*ps*'`.
- `tests/Fixtures/` holds stack layouts: `Plain/` (parent directory with a classed and a class-less stack), `Modules/*/Docker` (module style), `Broken/` (two classes, invalid YAML).
- `tests/Integration/RealDockerTest.php` runs the real recipe against a real daemon with a throwaway alpine container, `--wait` included. It skips when docker compose or the daemon is missing. `tests/Support/EnvFileTest.php` also round-trips quoting through `docker compose config`.
- `expectsOutputToContain` matches substrings against single write calls: keep every expected substring unique to one output line.
- `Process::assertRanInOrder` does not exist on Laravel 11.0; capture the sequence with a closure fake instead.

## Development notes

- CI: `.github/workflows/checks.yml` (lint, types, type coverage, PHP 8.2-8.5 x Laravel 11-13 x lowest/stable). GitHub runners ship docker compose, so the integration test runs there too.
- `CHANGELOG.md` is generated from GitHub releases by the changelog workflow. Never edit it by hand.
- Docs live in `docs/` (multi-page, rendered on mozex.dev); the README is the GitHub gateway. Boost skill: `resources/boost/skills/laravel-compose/SKILL.md`. `docs/recipes/` holds copy-and-paste stacks (Meilisearch, Telegram Bot API); every compose file there was brought up with `docker compose up --wait` before publishing, and must be again when its image tag changes.
- Healthchecks probe `127.0.0.1`, never `localhost`: on Docker Desktop (and any daemon with IPv6 on the bridge) `localhost` resolves to `::1` first inside the container, the service listens on IPv4, and `wget --spider` fails with "connection refused" while the service is fine. The stub, the docs, the skill, and the recipes all follow this.
- Commit messages: short, one line, no attribution lines.
