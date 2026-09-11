<?php

declare(strict_types=1);

namespace Mozex\Compose;

use Mozex\Compose\Commands\DoctorCommand;
use Mozex\Compose\Commands\DownCommand;
use Mozex\Compose\Commands\LogsCommand;
use Mozex\Compose\Commands\MakeStackCommand;
use Mozex\Compose\Commands\RedeployCommand;
use Mozex\Compose\Commands\StatusCommand;
use Mozex\Compose\Support\NamespaceResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ComposeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-compose')
            ->hasConfigFile()
            ->hasCommands([
                RedeployCommand::class,
                StatusCommand::class,
                LogsCommand::class,
                DownCommand::class,
                DoctorCommand::class,
                MakeStackCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(StackRegistry::class);
        $this->app->singleton(Docker::class);
        $this->app->singleton(Compose::class);
        $this->app->bind(NamespaceResolver::class, fn (): NamespaceResolver => NamespaceResolver::fromComposer());
    }
}
