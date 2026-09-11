<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Support\ComposeFile;

it('finds the compose file in the order compose itself uses', function (): void {
    $directory = temporaryDirectory();

    expect(ComposeFile::find($directory))->toBeNull();

    File::put($directory.'/docker-compose.yml', 'services: {}');
    expect(ComposeFile::find($directory))->toBe($directory.DIRECTORY_SEPARATOR.'docker-compose.yml');

    File::put($directory.'/compose.yaml', 'services: {}');
    expect(ComposeFile::find($directory))->toBe($directory.DIRECTORY_SEPARATOR.'compose.yaml');
});

it('refuses a missing file, invalid yaml, and yaml that is not a mapping', function (): void {
    $directory = temporaryDirectory();
    File::put($directory.'/scalar.yml', 'just a string');

    expect(fn () => ComposeFile::load($directory.'/docker-compose.yml'))
        ->toThrow(ComposeException::class, 'No compose file found')
        ->and(fn () => ComposeFile::load(fixturesPath('Broken/Invalid/docker-compose.yml')))
        ->toThrow(ComposeException::class, 'could not be read')
        ->and(fn () => ComposeFile::load($directory.'/scalar.yml'))
        ->toThrow(ComposeException::class, 'YAML mapping');
});

it('reads the project name only when the file declares one', function (): void {
    expect(ComposeFile::load(fixturesPath('Plain/Meilisearch/docker-compose.yml'))->name())->toBe('meilisearch')
        ->and(ComposeFile::load(fixturesPath('Plain/Mailpit/compose.yaml'))->name())->toBeNull();
});

it('collects the fixed container names sorted', function (): void {
    $compose = ComposeFile::load(fixturesPath('Modules/Gateway/Docker/docker-compose.yml'));

    expect($compose->containerNames())->toBe(['rdp-gateway-caddy', 'rdp-gateway-guacamole', 'rdp-gateway-guacd', 'rdp-gateway-init'])
        ->and(ComposeFile::load(fixturesPath('Plain/Mailpit/compose.yaml'))->containerNames())->toBe([]);
});

it('lists the variables the file consumes without a default', function (): void {
    expect(ComposeFile::load(fixturesPath('Modules/Gateway/Docker/docker-compose.yml'))->requiredVariables())
        ->toBe(['GATEWAY_DOMAIN', 'GATEWAY_SECRET'])
        ->and(ComposeFile::load(fixturesPath('Plain/Meilisearch/docker-compose.yml'))->requiredVariables())
        ->toBe(['MEILISEARCH_KEY'])
        ->and(ComposeFile::load(fixturesPath('Plain/Mailpit/compose.yaml'))->requiredVariables())
        ->toBe([]);
});

it('treats escaped dollars and error-style expansions correctly', function (): void {
    $directory = temporaryDirectory();
    File::put($directory.'/compose.yaml', implode("\n", [
        'services:',
        '    app:',
        '        image: alpine',
        '        command: echo $${NOT_A_VAR} ${MUST:?set me} ${ALSO?set me} ${OPTIONAL:+alt} ${DEFAULTED-x}',
    ]));

    expect(ComposeFile::load($directory.'/compose.yaml')->requiredVariables())->toBe(['ALSO', 'MUST']);
});

it('ignores commented-out references and looks inside nested fallbacks', function (): void {
    $directory = temporaryDirectory();
    File::put($directory.'/compose.yaml', implode("\n", [
        'services:',
        '    app:',
        '        image: alpine',
        '        # ports:',
        "        #     - '\${OLD_PORT}:80'",
        '        environment:',
        '            BIND: ${BIND:-${DEFAULT_BIND:-127.0.0.1}}',
        '            KEY: ${KEY:-${SHARED_KEY}}',
        '            PLAIN: $PLAIN_VAR',
    ]));

    expect(ComposeFile::load($directory.'/compose.yaml')->requiredVariables())->toBe(['PLAIN_VAR', 'SHARED_KEY']);
});

it('lists every reference in a string, outermost first', function (): void {
    expect(ComposeFile::variables('${A:-${B}} $C $$D ${E'))->toBe([
        ['name' => 'A', 'operator' => ':-', 'argument' => '${B}'],
        ['name' => 'C', 'operator' => '', 'argument' => ''],
    ]);
});

it('reports profiles, build steps, and bind mounts', function (): void {
    $gateway = ComposeFile::load(fixturesPath('Modules/Gateway/Docker/docker-compose.yml'));
    $directory = temporaryDirectory();
    File::put($directory.'/compose.yaml', implode("\n", [
        'services:',
        '    app:',
        '        build: .',
        '        volumes:',
        "            - 'C:\\data:/data'",
        "            - 'named:/named'",
        "            - '~/home:/home'",
    ]));
    $windows = ComposeFile::load($directory.'/compose.yaml');

    expect($gateway->profiles())->toBe(['tls'])
        ->and($gateway->hasBuildSteps())->toBeFalse()
        ->and($gateway->bindMounts())->toBe(['./Caddyfile', '/var/log/gateway'])
        ->and($windows->hasBuildSteps())->toBeTrue()
        ->and($windows->profiles())->toBe([])
        ->and($windows->bindMounts())->toBe(['C:\\data', '~/home']);
});

it('flags publishes that listen on every interface', function (): void {
    expect(ComposeFile::load(fixturesPath('Plain/Meilisearch/docker-compose.yml'))->publicPublishes())->toBe([])
        ->and(ComposeFile::load(fixturesPath('Plain/Mailpit/compose.yaml'))->publicPublishes())
        ->toBe(['mailpit: ${MAILPIT_PORT:-8025}:8025'])
        ->and(ComposeFile::load(fixturesPath('Modules/Gateway/Docker/docker-compose.yml'))->publicPublishes())
        ->toBe(['caddy: ${GATEWAY_TLS_PORT:-8443}:443']);
});

it('resolves the host address through the stack environment before judging a publish', function (): void {
    $directory = temporaryDirectory();
    File::put($directory.'/compose.yaml', implode("\n", [
        'services:',
        '    app:',
        '        image: alpine',
        '        ports:',
        "            - '\${BIND:-0.0.0.0}:80:80'",
        "            - '[::]:81:81'",
        '            - 3000',
        '            - 8000:8000',
        '            - target: 90',
        '              published: 9090',
        "              host_ip: '127.0.0.1'",
    ]));

    $compose = ComposeFile::load($directory.'/compose.yaml');

    expect($compose->publicPublishes())->toBe(['app: ${BIND:-0.0.0.0}:80:80', 'app: [::]:81:81', 'app: 3000', 'app: 8000:8000'])
        ->and($compose->publicPublishes(['BIND' => '127.0.0.1']))->toBe(['app: [::]:81:81', 'app: 3000', 'app: 8000:8000']);
});

it('interpolates variables the way compose does', function (): void {
    $environment = ['SET' => 'value', 'EMPTY' => ''];

    expect(ComposeFile::interpolate('${SET}', $environment))->toBe('value')
        ->and(ComposeFile::interpolate('$SET', $environment))->toBe('value')
        ->and(ComposeFile::interpolate('${MISSING}', $environment))->toBe('')
        ->and(ComposeFile::interpolate('${MISSING:-fallback}', $environment))->toBe('fallback')
        ->and(ComposeFile::interpolate('${EMPTY:-fallback}', $environment))->toBe('fallback')
        ->and(ComposeFile::interpolate('${EMPTY-fallback}', $environment))->toBe('')
        ->and(ComposeFile::interpolate('${SET:+alt}', $environment))->toBe('alt')
        ->and(ComposeFile::interpolate('${EMPTY:+alt}', $environment))->toBe('')
        ->and(ComposeFile::interpolate('${EMPTY+alt}', $environment))->toBe('alt')
        ->and(ComposeFile::interpolate('$$SET', $environment))->toBe('$SET')
        ->and(ComposeFile::interpolate('a-${SET}-b', $environment))->toBe('a-value-b')
        ->and(ComposeFile::interpolate('${MISSING:-${SET}}', $environment))->toBe('value')
        ->and(ComposeFile::interpolate('${MISSING:-${ALSO_MISSING:-deep}}', $environment))->toBe('deep')
        ->and(ComposeFile::interpolate('${DOLLARS}', ['DOLLARS' => 'a$$b']))->toBe('a$$b')
        ->and(ComposeFile::interpolate('${UNCLOSED', $environment))->toBe('${UNCLOSED');
});
