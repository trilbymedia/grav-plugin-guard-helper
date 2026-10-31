<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\Security\PhpSupport;
use PHPUnit\Framework\TestCase;

/**
 * Whether a site's PHP still gets security fixes, and what to move to:
 * PHP 8.3 on Grav 1.7, 8.3 or newer on Grav 2.
 */
final class PhpSupportTest extends TestCase
{
    private static function day(string $date): int
    {
        return (int) strtotime($date . ' 12:00:00 UTC');
    }

    public function testABranchPastItsEndGetsNoFixes(): void
    {
        $php = PhpSupport::check('7.4.33', '1.7.53', self::day('2026-10-31'));

        $this->assertSame('unsupported', $php['status']);
        $this->assertSame('7.4', $php['branch']);
        $this->assertSame('PHP 7.4.33 gets no security fixes', $php['title']);
        $this->assertStringContainsString('Security support for PHP 7.4 ended on 28 Nov 2022, so', $php['detail']);
        $this->assertStringContainsString('Upgrade to PHP 8.3, the newest PHP Grav 1.7 runs on.', $php['detail']);
    }

    public function testEightOneEndedAtTheEndOf2025(): void
    {
        $this->assertSame('ending', PhpSupport::check('8.1.31', '1.7.53', self::day('2025-12-30'))['status']);
        $this->assertSame('unsupported', PhpSupport::check('8.1.31', '1.7.53', self::day('2026-01-01'))['status']);
    }

    public function testAnOlderBranchStillSupportedIsAWarning(): void
    {
        // 8.2 has fixes until the end of 2026, but it is older than 8.3.
        $php = PhpSupport::check('8.2.35', '1.7.53', self::day('2026-01-15'));

        $this->assertSame('ending', $php['status']);
        $this->assertSame('PHP 8.2.35 gets security fixes until 31 Dec 2026', $php['title']);
        $this->assertStringContainsString('Upgrade to PHP 8.3', $php['detail']);
    }

    public function testTheRecommendedBranchIsFine(): void
    {
        $php = PhpSupport::check('8.3.36', '1.7.53', self::day('2026-10-31'));

        $this->assertSame('ok', $php['status']);
        $this->assertSame('PHP 8.3.36 gets security fixes until 31 Dec 2027', $php['title']);
        $this->assertSame('', $php['detail']);
        $this->assertSame('PHP 8.3', $php['recommended']);
    }

    public function testGravTwoIsToldEightThreeOrNewer(): void
    {
        $php = PhpSupport::check('8.1.2', '2.0.4', self::day('2026-10-31'));

        $this->assertSame('PHP 8.3 or newer', $php['recommended']);
        $this->assertStringEndsWith('Upgrade to PHP 8.3 or newer.', $php['detail']);
        $this->assertSame('PHP 8.3 or newer', PhpSupport::check('8.4.1', '2.0.0-beta.3')['recommended']);
    }

    public function testEightThreeNearItsEndOnGravOneSevenPointsAtGravTwo(): void
    {
        // Grav 1.7 can't run a newer PHP, so the advice is a newer Grav.
        $php = PhpSupport::check('8.3.40', '1.7.53', self::day('2027-10-01'));

        $this->assertSame('ending', $php['status']);
        $this->assertStringContainsString('moving the site to Grav 2', $php['detail']);

        $this->assertStringEndsWith('Upgrade to PHP 8.3 or newer.', PhpSupport::check('8.3.40', '2.1.0', self::day('2027-10-01'))['detail']);
    }

    public function testABranchNewerThanTheTableIsFine(): void
    {
        $php = PhpSupport::check('8.6.0', '2.0.4', self::day('2026-10-31'));

        $this->assertSame('ok', $php['status']);
        $this->assertNull($php['ends']);
        $this->assertSame('PHP 8.6.0 gets security fixes', $php['title']);
    }

    public function testABranchOlderThanTheTableGetsNoFixes(): void
    {
        $php = PhpSupport::check('5.6.40', '1.7.53', self::day('2026-10-31'));

        $this->assertSame('unsupported', $php['status']);
        $this->assertStringContainsString('ended years ago', $php['detail']);
    }
}
