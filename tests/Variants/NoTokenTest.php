<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Mizbanha\Sms\Events\AttemptRecorded;
use Mizbanha\SmsCloud\Tests\Variants\NoTokenTestCase;

uses(NoTokenTestCase::class);

it('stays inert without a token even when enabled', function () {
    expect(Event::hasListeners(AttemptRecorded::class))->toBeFalse();

    $this->artisan('sms-cloud:run')->expectsOutputToContain('not enabled')->assertSuccessful();
});
