<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Tests\Variants;

use Mizbanha\SmsCloud\Tests\TestCase;

/** Enabled, but nobody pasted the token in. */
abstract class NoTokenTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sms-cloud.token', '');
    }
}
