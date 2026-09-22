<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * What this package ships.
 *
 * ⚠️ It is installed inside other people's applications, so what it declares and
 * what it contains matter as much as what it does.
 */
function manifest(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);
}

it('declares no development repository override in what it publishes', function () {
    /*
     * `scripts/use-local-core.sh` adds a path repository so the suite can run
     * against an unreleased Core, so the WORKING manifest may legitimately have
     * one while tests run. What must never carry it is the COMMITTED manifest: a
     * consumer installing this package would resolve `mizbanha/laravel-sms` from
     * a folder that does not exist on their machine.
     */
    $committed = @shell_exec('git -C '.escapeshellarg(dirname(__DIR__, 2)).' show HEAD:composer.json 2>&1');

    if (! is_string($committed) || ! str_starts_with(trim($committed), '{')) {
        test()->markTestSkipped('not a git checkout');
    }

    expect(json_decode($committed, true))->not->toHaveKey('repositories');
});

it('depends on nothing beyond the framework pieces it uses and Core', function () {
    expect(array_keys(manifest()['require']))->toEqualCanonicalizing([
        'php',
        'illuminate/console',
        'illuminate/contracts',
        'illuminate/database',
        'illuminate/http',
        'illuminate/queue',
        'illuminate/support',
        'mizbanha/laravel-sms',
    ]);
});

it('keeps the lock file out of the repository', function () {
    expect(str_contains((string) file_get_contents(__DIR__.'/../../.gitignore'), 'composer.lock'))->toBeTrue();
});

it('ships no environment file, credential or developer path', function () {
    $files = collect(File::allFiles(__DIR__.'/../../src'))
        ->merge(File::allFiles(__DIR__.'/../../config'))
        ->merge(File::allFiles(__DIR__.'/../../database'));

    foreach ($files as $file) {
        $contents = (string) $file->getContents();

        expect($contents)->not->toContain('smsc_01')          // a real-looking token
            ->and($contents)->not->toContain('D:\\amidesfahani')
            ->and($contents)->not->toMatch('/\/(Users|home)\/[a-z]+\//i');
    }

    expect(File::exists(__DIR__.'/../../.env'))->toBeFalse()
        ->and(File::exists(__DIR__.'/../../auth.json'))->toBeFalse();
});

it('publishes exactly the two tags it documents', function () {
    $provider = (string) file_get_contents(__DIR__.'/../../src/SmsCloudServiceProvider.php');

    preg_match_all("/], '([a-z0-9-]+)'\);/", $provider, $matches);

    expect($matches[1])->toEqualCanonicalizing(['sms-cloud-config', 'sms-cloud-migrations']);
});

it('keeps the histogram boundaries in step with the server protocol', function () {
    // ⚠️ If these ever disagree, every percentile in SMS Cloud silently becomes
    // wrong for this client's data. They are part of protocol v1 on both sides.
    expect(Mizbanha\SmsCloud\Buffer\Histogram::BOUNDS)
        ->toBe([50, 100, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 10000, 20000])
        ->and(Mizbanha\SmsCloud\Buffer\Histogram::SIZE)->toBe(14);
});
