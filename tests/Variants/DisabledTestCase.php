<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Tests\Variants;

use Mizbanha\SmsCloud\Tests\TestCase;

/** The client installed but switched off. */
abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sms-cloud.enabled', false);
    }
}
