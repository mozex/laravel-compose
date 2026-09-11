<?php

declare(strict_types=1);

namespace Mozex\Compose;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ComposeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-compose')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(StackRegistry::class);
        $this->app->singleton(Docker::class);
        $this->app->singleton(Compose::class);
    }
}
