<?php

declare(strict_types=1);

use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Support\NamespaceResolver;

it('maps a directory to the namespace composer autoloads it under', function (): void {
    $resolver = new NamespaceResolver([
        'App\\' => ['/srv/app/app'],
        'Modules\\' => ['/srv/app/Modules'],
        'App\\Docker\\' => ['/srv/app/app/Docker'],
    ]);

    expect($resolver->resolve('/srv/app/app/Docker/Meilisearch'))->toBe('App\\Docker\\Meilisearch')
        ->and($resolver->resolve('/srv/app/app/Docker'))->toBe('App\\Docker')
        ->and($resolver->resolve('/srv/app/Modules/Search/Docker'))->toBe('Modules\\Search\\Docker')
        ->and($resolver->resolve('/srv/app/app/Http/../Docker/Mail/'))->toBe('App\\Docker\\Mail')
        ->and(fn () => $resolver->resolve('/srv/app/database/docker'))->toThrow(ComposeException::class, 'No PSR-4 autoload mapping');
});

it('normalizes separators, dot segments, and drive letters', function (): void {
    expect(NamespaceResolver::normalize('C:\\Work\\app\\Docker\\'))->toBe('C:/Work/app/Docker')
        ->and(NamespaceResolver::normalize('/srv/./app/../app/Docker'))->toBe('/srv/app/Docker')
        ->and(NamespaceResolver::normalize('relative/path/'))->toBe('relative/path');
});

it('reads the real composer map and resolves this package', function (): void {
    expect(NamespaceResolver::fromComposer()->resolve(dirname(__DIR__, 2).'/src/Docker'))->toBe('Mozex\\Compose\\Docker')
        ->and(app(NamespaceResolver::class))->toBeInstanceOf(NamespaceResolver::class);
});
