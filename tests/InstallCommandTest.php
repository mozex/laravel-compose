<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

const STAR_QUESTION = 'Would you like to show some love by starring laravel-compose on GitHub?';

beforeEach(function (): void {
    Process::fake();

    @unlink($this->app->configPath('compose.php'));
});

afterEach(function (): void {
    @unlink($this->app->configPath('compose.php'));
});

function assertOpenedRepository(): void
{
    Process::assertRan(
        fn (PendingProcess $process): bool => in_array('https://github.com/mozex/laravel-compose', (array) $process->command, true)
    );
}

it('publishes the config file', function (): void {
    $this->artisan('compose:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('compose.php')))
        ->toBe(file_get_contents(__DIR__.'/../config/compose.php'));
});

it('leaves an existing config file alone', function (): void {
    file_put_contents($this->app->configPath('compose.php'), '<?php return [];');

    $this->artisan('compose:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('compose.php')))->toBe('<?php return [];');
});

it('opens the repository when the user agrees to star it', function (): void {
    $this->artisan('compose:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->doesntExpectOutputToContain('please consider starring')
        ->doesntExpectOutputToContain("You'll find")
        ->assertSuccessful();

    assertOpenedRepository();
});

it('opens nothing when the user declines', function (): void {
    $this->artisan('compose:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('prints the repository link when the browser cannot be opened', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('compose:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find laravel-compose at https://github.com/mozex/laravel-compose")
        ->assertSuccessful();
});

it('prints the repository link when opening the browser throws', function (): void {
    Process::fake(fn () => throw new RuntimeException('No opener.'));

    $this->artisan('compose:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find laravel-compose at https://github.com/mozex/laravel-compose")
        ->assertSuccessful();
});

it('opens the repository with a note instead of asking when nobody can answer', function (): void {
    $this->artisan('compose:install', ['--no-interaction' => true])
        ->expectsOutputToContain('If laravel-compose saves you time, please consider starring it on GitHub: https://github.com/mozex/laravel-compose')
        ->assertSuccessful();

    assertOpenedRepository();

    expect($this->app->configPath('compose.php'))->toBeFile();
});
