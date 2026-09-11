<?php

declare(strict_types=1);

return [
    /*
     * Master switch. When false, every stack is skipped on redeploy, which is
     * what CI and hosts without Docker want. Per-stack enabled() checks still
     * apply when this is true.
     */
    'enabled' => (bool) env('COMPOSE_ENABLED', true),

    /*
     * Stack classes to register explicitly. Each one extends
     * Mozex\Compose\Stack and lives beside its compose file.
     */
    'stacks' => [
        // App\Docker\Meilisearch\MeilisearchStack::class,
    ],

    /*
     * Directories to scan for stacks. Globs are allowed. A directory that
     * holds a compose file is a stack; otherwise each of its child directories
     * is checked. A Stack subclass beside the compose file defines the stack.
     * Without one, the directory still counts as a stack with an empty
     * environment, so compose-side ${VAR:-default} values carry every knob.
     */
    'discover' => [
        app_path('Docker'),
        base_path('Modules/*/Docker'),
    ],

    /*
     * Optional directory each stack gets linked into as
     * {link_directory}/{stack name}, so a hosting panel or a person on the
     * server finds the stack outside the release tree. Ploi keeps its
     * containers in /home/{user}/containers, where {user} is the system user
     * the site runs as. Leave empty to skip linking. A stack can override the
     * full path through linkPath(). Linking is skipped for remote daemons.
     */
    'link_directory' => env('COMPOSE_LINK_DIRECTORY'),

    'docker' => [
        /*
         * The docker executable. A full path works when it's not on PATH.
         */
        'binary' => env('COMPOSE_DOCKER_BINARY', 'docker'),

        /*
         * Talk to a daemon on another machine. Accepts what DOCKER_HOST
         * accepts: ssh://user@host, tcp://host:2376, unix:///path. Empty means
         * the local daemon. A stack can override this through host().
         */
        'host' => env('COMPOSE_DOCKER_HOST'),

        /*
         * A named docker context, as an alternative to a host. Empty means the
         * current context. A stack can override this through context().
         */
        'context' => env('COMPOSE_DOCKER_CONTEXT'),
    ],

    /*
     * Name of the environment file written into each stack directory before
     * every redeploy. Compose reads .env by default; change this only if your
     * compose files point at a different file.
     */
    'env_file' => '.env',

    /*
     * Default timeouts in seconds for each step of a redeploy. A stack can
     * override the pull, build, and up values through its own methods.
     */
    'timeouts' => [
        'pull' => 300,
        'build' => 600,
        'up' => 120,
        'down' => 60,
        'remove' => 30,
    ],
];
