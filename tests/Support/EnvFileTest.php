<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Support\EnvFile;

enum FixtureLevel: string
{
    case Error = 'error';
}

it('renders plain values raw and one trailing newline', function (): void {
    expect(EnvFile::render([
        'PORT' => 7700,
        'KEY' => 'abc-DEF_123.:/@+=,~%',
        'EMPTY' => '',
        'NOTHING' => null,
        'ON' => true,
        'OFF' => false,
        'RATIO' => 1.5,
        'LEVEL' => FixtureLevel::Error,
        'STRINGABLE' => str('hello'),
    ]))->toBe("PORT=7700\nKEY=abc-DEF_123.:/@+=,~%\nEMPTY=\nNOTHING=\nON=true\nOFF=false\nRATIO=1.5\nLEVEL=error\nSTRINGABLE=hello\n")
        ->and(EnvFile::render([]))->toBe('');
});

it('quotes values compose would otherwise truncate or misread', function (): void {
    expect(EnvFile::quote('hello world'))->toBe("'hello world'")
        ->and(EnvFile::quote('secret #not-a-comment'))->toBe("'secret #not-a-comment'")
        ->and(EnvFile::quote('say "hi"'))->toBe("'say \"hi\"'")
        ->and(EnvFile::quote('$literal'))->toBe("'\$literal'")
        ->and(EnvFile::quote("it's"))->toBe('"it\'s"')
        ->and(EnvFile::quote("it's \"quoted\" \\ \$5"))->toBe('"it\'s \\"quoted\\" \\\\ $$5"')
        ->and(EnvFile::quote('a\\b'))->toBe("'a\\b'")
        ->and(EnvFile::quote('ends with \\'))->toBe('"ends with \\\\"');
});

it('refuses keys, line breaks, and values it cannot represent', function (): void {
    expect(fn () => EnvFile::render(['not valid' => 'x'], 'meili'))
        ->toThrow(ComposeException::class, '[not valid] on stack [meili]')
        ->and(fn () => EnvFile::render(['1STARTS_WITH_DIGIT' => 'x']))
        ->toThrow(ComposeException::class, 'not a valid variable name')
        ->and(fn () => EnvFile::render(['BAD' => "a\nb"], 'meili'))
        ->toThrow(ComposeException::class, '[BAD] on stack [meili] contains a line break')
        ->and(fn () => EnvFile::render(['BAD' => "a\rb"]))
        ->toThrow(ComposeException::class, 'line break')
        ->and(fn () => EnvFile::render(['BAD' => ['nested']]))
        ->toThrow(ComposeException::class, 'is a array');
});

it('reads a hand-written file with the quoting rules it writes', function (): void {
    $contents = implode("\n", [
        '# comment',
        'SECRET=abc',
        '',
        'export PORT = 7700',
        "SPACED='hello world # tag' # note",
        'DOUBLE="it\'s \\"quoted\\" \\\\ $$5" # note',
        'TRAILING=value # a comment',
        'HASHED=value#kept',
        'EMPTY=',
        'SECRET=again',
        'not a key',
        '1BAD=x',
        '',
    ]);

    expect(EnvFile::parse($contents))->toBe([
        'SECRET' => 'again',
        'PORT' => '7700',
        'SPACED' => 'hello world # tag',
        'DOUBLE' => 'it\'s "quoted" \\ $5',
        'TRAILING' => 'value',
        'HASHED' => 'value#kept',
        'EMPTY' => '',
    ])
        ->and(EnvFile::keys($contents))->toBe(['SECRET', 'PORT', 'SPACED', 'DOUBLE', 'TRAILING', 'HASHED', 'EMPTY'])
        ->and(EnvFile::parse(''))->toBe([]);

    $values = ['A' => 'plain', 'B' => 'hello world', 'C' => "it's \"quoted\" \\ \$5", 'D' => ''];

    expect(EnvFile::parse(EnvFile::render($values)))->toBe($values);
});

it('resolves the env path from config and knows a hand-written file', function (): void {
    $envFile = app(EnvFile::class);
    $stack = fakeStack(['environment' => []]);

    expect($envFile->pathFor($stack))->toBe($stack->directory().DIRECTORY_SEPARATOR.'.env')
        ->and($envFile->isHandWritten($stack))->toBeFalse();

    File::put($stack->directory().'/.env', "HAND=written\n");

    expect($envFile->isHandWritten($stack))->toBeTrue()
        ->and($envFile->isHandWritten(fakeStack(['environment' => ['KEY' => 'v'], 'directory' => $stack->directory()])))->toBeFalse();

    config()->set('compose.env_file', '.env.stack');

    expect($envFile->pathFor($stack))->toBe($stack->directory().DIRECTORY_SEPARATOR.'.env.stack')
        ->and($envFile->isHandWritten($stack))->toBeFalse();
});

it('writes the file with owner-only permissions', function (): void {
    $directory = temporaryDirectory();
    $path = $directory.'/nested/.env';

    app(EnvFile::class)->write($path, ['TOKEN' => 'top secret'], 'fake');

    expect(File::get($path))->toBe("TOKEN='top secret'\n");

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($path) & 0777)->toBe(0600);
    }
});

it('round-trips every quoting shape through docker compose itself', function (): void {
    if (! dockerComposeAvailable()) {
        $this->markTestSkipped('docker compose is not available on this machine.');
    }

    $directory = temporaryDirectory();
    $values = [
        'PLAIN' => 'abc-123',
        'SPACED' => 'hello world # not a comment',
        'HASHED' => 'value#with#hashes',
        'DOUBLE' => 'say "hi" $HOME',
        'MIXED' => "it's \"quoted\" \\ back \$5 and \${SPACED}",
        'TRAILING' => 'ends with \\',
        'BLANK' => '',
    ];

    File::put($directory.'/compose.yaml', implode("\n", [
        'services:',
        '    probe:',
        '        image: alpine:3',
        '        environment:',
        ...array_map(fn (string $key): string => "            {$key}: \${{$key}}", array_keys($values)),
        '',
    ]));

    app(EnvFile::class)->write($directory.'/.env', $values, 'probe');

    $result = Process::path($directory)->timeout(60)->run(['docker', 'compose', '--project-name', 'laravel-compose-probe', 'config', '--format', 'json']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $config = json_decode($result->output(), true);

    // `compose config` escapes every dollar as `$$` in its rendered output so
    // the result can be fed back to compose; undo that to compare raw values.
    $rendered = array_map(fn (string $value): string => str_replace('$$', '$', $value), $config['services']['probe']['environment']);
    ksort($rendered);
    ksort($values);

    expect($rendered)->toBe($values);
});
