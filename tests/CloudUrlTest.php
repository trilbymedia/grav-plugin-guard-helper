<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\CloudUrl;
use PHPUnit\Framework\TestCase;

/**
 * Guard Cloud moved to cloud.gravguard.com. A saved config holding the old
 * default must reach the new host; anything else is used as written.
 */
final class CloudUrlTest extends TestCase
{
    public function testEmptyGivesTheDefault(): void
    {
        self::assertSame('https://cloud.gravguard.com', CloudUrl::from(null));
        self::assertSame('https://cloud.gravguard.com', CloudUrl::from('  '));
    }

    public function testTheOldDefaultMovesToTheNewHost(): void
    {
        self::assertSame('https://cloud.gravguard.com', CloudUrl::from('https://gravguard.com'));
        self::assertSame('https://cloud.gravguard.com', CloudUrl::from('https://gravguard.com/'));
        self::assertSame('https://cloud.gravguard.com', CloudUrl::from('https://www.gravguard.com'));
    }

    public function testOtherCloudsAreKept(): void
    {
        self::assertSame('https://cloud-staging.gravguard.com', CloudUrl::from('https://cloud-staging.gravguard.com/'));
        self::assertSame('https://gg-cloud.test', CloudUrl::from('https://gg-cloud.test'));
    }
}
