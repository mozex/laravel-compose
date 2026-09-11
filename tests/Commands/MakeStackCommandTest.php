<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mozex\Compose\Support\ComposeFile;
use Mozex\Compose\Support\NamespaceResolver;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    $this->root = temporaryDirectory();
    app()->instance(NamespaceResolver::class, new NamespaceResolver(['App\\Docker\\' => [$this->root]]));
    config()->set('compose.discover', [$this->root, fixturesPath('Modules/*/Docker')]);
    config()->set('app.name', 'Shop Admin');
});

it('scaffolds a stack class, a compose file, and a gitignore under the first discovery directory', function (): void {
    artisan('compose:make', ['name' => 'Image Tools'])
        ->expectsOutputToContain('Stack [shop-admin-image-tools] scaffolded in')
        ->assertSuccessful();

    $directory = $this->root.'/ImageTools';
    $class = File::get($directory.'/ImageToolsStack.php');
    $compose = ComposeFile::load($directory.'/docker-compose.yml');

    expect($class)->toContain('namespace App\\Docker\\ImageTools;')
        ->and($class)->toContain('class ImageToolsStack extends Stack')
        ->and($class)->toContain("'IMAGE_TOOLS_PORT' => (int) Config::get('services.image-tools.port', 8080)")
        ->and($compose->name())->toBe('shop-admin-image-tools')
        ->and($compose->containerNames())->toBe(['shop-admin-image-tools'])
        ->and(array_keys($compose->services()))->toBe(['image-tools'])
        ->and($compose->requiredVariables())->toBe([])
        ->and($compose->publicPublishes())->toBe([])
        ->and(File::get($directory.'/.gitignore'))->toBe(".env\n");
});

it('puts the configured env file name in the gitignore', function (): void {
    config()->set('compose.env_file', '.env.stack');

    artisan('compose:make', ['name' => 'meilisearch'])->assertSuccessful();

    expect(File::get($this->root.'/Meilisearch/.gitignore'))->toBe(".env.stack\n");
});

it('falls back to a plain app prefix when the app has no usable name', function (): void {
    config()->set('app.name', '');

    artisan('compose:make', ['name' => 'meilisearch'])->assertSuccessful();

    expect(ComposeFile::load($this->root.'/Meilisearch/docker-compose.yml')->name())->toBe('app-meilisearch');
});

it('honours an absolute path and refuses to overwrite', function (): void {
    $custom = $this->root.'/custom';

    artisan('compose:make', ['name' => 'meilisearch', '--path' => $custom])->assertSuccessful();

    expect(File::exists($custom.'/Meilisearch/MeilisearchStack.php'))->toBeTrue()
        ->and(File::get($custom.'/Meilisearch/MeilisearchStack.php'))->toContain('namespace App\\Docker\\custom\\Meilisearch;');

    artisan('compose:make', ['name' => 'meilisearch', '--path' => $custom])
        ->expectsOutputToContain('already exists')
        ->assertFailed();
});

it('resolves a relative path against the app root', function (): void {
    $root = app()->basePath('Modules'.DIRECTORY_SEPARATOR.'Search');
    app()->instance(NamespaceResolver::class, new NamespaceResolver(['Modules\\Search\\' => [$root]]));

    artisan('compose:make', ['name' => 'meilisearch', '--path' => 'Modules/Search/Docker'])->assertSuccessful();

    expect(File::get($root.'/Docker/Meilisearch/MeilisearchStack.php'))->toContain('namespace Modules\\Search\\Docker\\Meilisearch;');

    File::deleteDirectory($root);
});

it('refuses names that cannot become a class, and a directory outside every autoloaded namespace', function (): void {
    artisan('compose:make', ['name' => '!!!'])->expectsOutputToContain('Use a name that starts with a letter')->assertFailed();
    artisan('compose:make', ['name' => '2fa'])->expectsOutputToContain('Use a name that starts with a letter')->assertFailed();

    expect(File::exists($this->root.'/2fa'))->toBeFalse();

    artisan('compose:make', ['name' => 'lost', '--path' => temporaryDirectory()])
        ->expectsOutputToContain('No PSR-4 autoload mapping')
        ->assertFailed();
});

it('falls back to app/Docker when discovery has only globs', function (): void {
    config()->set('compose.discover', [fixturesPath('Modules/*/Docker')]);
    $expected = app()->basePath('app'.DIRECTORY_SEPARATOR.'Docker');
    app()->instance(NamespaceResolver::class, new NamespaceResolver(['App\\Docker\\' => [$expected]]));

    artisan('compose:make', ['name' => 'fallback'])->assertSuccessful();

    expect(File::exists($expected.'/Fallback/FallbackStack.php'))->toBeTrue();

    File::deleteDirectory($expected.'/Fallback');
});
