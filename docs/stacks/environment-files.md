---
title: Environment Files
weight: 3
---

Before every redeploy, the package writes `environment()` into the stack directory as `.env`. Compose reads that file when it interpolates `${VAR}` in the compose file, and only then. Nothing else in the app reads it. The name is configurable through `env_file`; a name other than `.env` is handed to compose as `--env-file` on every call.

## Why the file is generated

Your app already has one source of truth for its settings, and secrets shared between the app and a container (a Meilisearch master key, a gateway's signing secret) must never drift between the two. So the stack class reads config, the config reads the app's own `.env`, and the container env file is an output. A value only has to change in one place, and the next deploy applies it.

The other side of that coin: never edit the generated file by hand on the server. The next redeploy overwrites it, silently. The one exception is a stack whose `environment()` is empty (a class-less one, for instance): with nothing to write, an existing file is left as it is.

## The shell doesn't get a say

Compose reads the shell before the env file: a variable set in the environment of the `docker compose` process beats the same variable in the file. Laravel exports the app's own `.env` into the environment of every process it starts, so an app key that shares a name with a stack key (`MEILISEARCH_PORT` in the app's `.env` and in `environment()`, say) would win over the file the redeploy just wrote, and the container would come up with the app's value. Silently.

So every compose command runs with the keys the stack writes unset, for that process only. The file is the value compose sees, and `environment()` is the one place a container's setting comes from. A variable the stack doesn't write still comes from the shell, which is what the `${VAR:-default}` knobs and `compose:doctor`'s "set in this shell" warning are about.

## What can go in it

Keys must match `[A-Za-z_][A-Za-z0-9_]*`. Values can be strings, integers, floats, booleans (`true`/`false`), null (written as empty), backed enums (their value), and `Stringable` objects. Anything else, an array for instance, throws a `ComposeException` naming the key and the stack.

Values are quoted with Compose's own rules, because a raw `KEY=value` line breaks in ways that are hard to spot:

| Value | Written as | Why |
|---|---|---|
| `abc-123` | `abc-123` | Nothing to protect. |
| `hello world # tag` | `'hello world # tag'` | An unquoted ` #` starts a comment; the value would be truncated. |
| `say "hi" $HOME` | `'say "hi" $HOME'` | Single quotes keep everything literal, including the dollar. |
| `it's` | `"it's"` | Single quotes can't hold a single quote, so double quotes with `\`, `"`, and `$` escaped. |
| `ends with \` | `"ends with \\"` | Compose reads a backslash before a closing quote as an escaped quote, single quotes included, and never finds the end of the value. |
| a value with a line break | throws | Env files are line-oriented; there is no way to write it. |

The test suite round-trips every one of those through `docker compose config`, so what you put in `environment()` is what the container sees.

## Secrets

The file is written with `0600` permissions and sits inside the working tree. `compose:make` adds a `.gitignore` with `.env` to the stack directory, and Laravel's own root `.gitignore` already contains `.env`, which git applies at every depth. `compose:doctor` asks git whether the file would be ignored and warns when it wouldn't.

## The contract with the compose file

Every variable the compose file consumes without a default must be a key of `environment()`. `${MEILI_KEY}` and `${MEILI_KEY:?message}` count; `${PORT:-7700}` and `${PORT-7700}` don't, they carry their own fallback. The doctor checks this before anything touches Docker, so a renamed key fails the preflight instead of the rollout. For a stack with an empty `environment()`, the keys of a hand-written env file in its directory count instead.

Knobs the stack doesn't own belong in the compose file as defaults. Bind addresses, timeouts, log levels: give them `${VAR:-value}` there and only write the ones the app decides.
