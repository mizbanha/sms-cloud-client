<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Tests\Variants;

use Mizbanha\SmsCloud\Tests\TestCase;

/** Publishing through a queue job instead of an inline scheduled command. */
abstract class QueueModeTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sms-cloud.publish.mode', 'queue');
    }
}
