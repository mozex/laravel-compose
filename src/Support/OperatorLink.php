<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;

/**
 * Keeps a stable path on the host pointing at the stack directory inside the
 * current release, so a hosting panel or an operator finds the stack without
 * knowing which release is live. The link is convenience: a failure here is
 * reported, never allowed to fail a rollout.
 */
class OperatorLink
{
    public function __construct(
        protected Filesystem $files,
        protected Repository $config,
    ) {}

    /**
     * Where the stack should be linked, or null when no link is wanted.
     * Trailing separators are stripped: with one, every filesystem call
     * resolves through the link instead of at it.
     */
    public function pathFor(Stack $stack): ?string
    {
        $path = $stack->linkPath();

        if ($path === null) {
            $directory = $this->config->get('compose.link_directory');

            if (! is_string($directory) || trim($directory) === '') {
                return null;
            }

            $path = rtrim(trim($directory), '/\\').'/'.$stack->name();
        }

        $path = rtrim(trim($path), '/\\');

        return $path === '' ? null : $path;
    }

    /**
     * Point the link at the stack's directory, replacing whatever sits at the
     * path. Returns the link path, or null when the stack has none.
     */
    public function refresh(Stack $stack): ?string
    {
        $linkPath = $this->pathFor($stack);

        if ($linkPath === null) {
            return null;
        }

        $directory = $stack->directory();

        // readlink resolves symlinks and Windows junctions alike; is_link does
        // not recognise a junction, so an already-correct link is detected here.
        $existingTarget = @readlink($linkPath);

        if ($existingTarget !== false && $this->comparable($existingTarget) === $this->comparable($directory)) {
            return $linkPath;
        }

        $this->files->ensureDirectoryExists(dirname($linkPath));

        // Remove what holds the path without ever following it: rmdir and
        // unlink take a link, a junction, or an empty directory on both
        // platforms. Only a non-empty real directory needs the recursive delete.
        if (file_exists($linkPath) || is_link($linkPath)) {
            if (! @rmdir($linkPath) && ! @unlink($linkPath) && $this->files->exists($linkPath)) {
                $this->files->deleteDirectory($linkPath);
            }
        }

        clearstatcache(true, $linkPath);

        if (file_exists($linkPath) || is_link($linkPath)) {
            throw ComposeException::linkPathResisted($stack->name(), $linkPath);
        }

        $this->files->link($directory, $linkPath);

        return $linkPath;
    }

    protected function comparable(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
