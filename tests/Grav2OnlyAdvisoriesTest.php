<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\Security\SecurityChecker;
use PHPUnit\Framework\TestCase;

/**
 * Advisories fixed only in Grav 2, as Guard Cloud's matcher treats them: a
 * Grav 1.x site does not list them as findings (no update clears them) and
 * is told how many there are instead; a Grav 2 site lists them as ever; and
 * a feed from before the grav2_only fields behaves exactly as it did.
 */
final class Grav2OnlyAdvisoriesTest extends TestCase
{
    /** @return array<int,array{type:string, slug:string, version:string}> */
    private function site(string $core): array
    {
        return [
            ['type' => 'core', 'slug' => 'core', 'version' => $core],
            ['type' => 'plugin', 'slug' => 'form', 'version' => '7.0.0'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function feed(): array
    {
        return [
            // Fixed in a normal update on either line.
            ['advisory_id' => 'GHSA-aaaa-aaaa-aaaa', 'type' => 'plugin', 'slug' => 'form', 'affected' => '<7.1', 'fixed_in' => '7.1.0'],
            // Fixed only in Grav 2; the 1.x line is affected up to its end.
            ['advisory_id' => 'GHSA-bbbb-bbbb-bbbb', 'type' => 'core', 'slug' => 'core', 'affected' => '<=2.1.6', 'fixed_in' => '2.1.7',
                'grav2_only' => true, 'grav1_affected' => '<1.8.0-beta.1'],
        ];
    }

    public function testGrav1SkipsGrav2OnlyAdvisories(): void
    {
        $ids = array_column(SecurityChecker::match($this->site('1.7.51'), $this->feed()), 'advisory_id');

        $this->assertSame(['GHSA-aaaa-aaaa-aaaa'], $ids);
    }

    public function testGrav2DoesNotSkipGrav2OnlyAdvisories(): void
    {
        $ids = array_column(SecurityChecker::match($this->site('2.1.6'), $this->feed()), 'advisory_id');
        sort($ids);

        $this->assertSame(['GHSA-aaaa-aaaa-aaaa', 'GHSA-bbbb-bbbb-bbbb'], $ids);
        $this->assertSame([], SecurityChecker::grav2OnlyFor($this->site('2.1.6'), $this->feed()), 'nothing to count on Grav 2');
    }

    public function testFirstGrav2PreReleaseIsNotGrav1(): void
    {
        $this->assertTrue(SecurityChecker::isGrav1('1.7.51'));
        $this->assertTrue(SecurityChecker::isGrav1('1.8.0-alpha.3'));
        $this->assertFalse(SecurityChecker::isGrav1('1.8.0-beta.1'));
        $this->assertFalse(SecurityChecker::isGrav1('2.0.0'));
        $this->assertFalse(SecurityChecker::isGrav1(''));
        $this->assertCount(2, SecurityChecker::match($this->site('1.8.0-beta.1'), $this->feed()));
    }

    public function testCountsEachGhsaOnceAndOnlyInstalledPackages(): void
    {
        $advisories = [
            ['advisory_id' => 'GHSA-1111-1111-1111/1.7', 'type' => 'core', 'slug' => 'core', 'grav2_only' => true, 'grav1_affected' => '<1.8.0-beta.1'],
            // The same advisory again under another suffix, and with none.
            ['advisory_id' => 'GHSA-1111-1111-1111/admin', 'type' => 'core', 'slug' => 'core', 'grav2_only' => true, 'grav1_affected' => '<1.8.0-beta.1'],
            ['advisory_id' => 'GHSA-1111-1111-1111', 'type' => 'core', 'slug' => 'core', 'grav2_only' => true, 'grav1_affected' => '<1.8.0-beta.1'],
            // An installed plugin, inside its range.
            ['advisory_id' => 'GHSA-2222-2222-2222', 'type' => 'plugin', 'slug' => 'form', 'grav2_only' => true, 'grav1_affected' => '>=6.0 <7.1'],
            // An installed plugin, outside its range.
            ['advisory_id' => 'GHSA-3333-3333-3333', 'type' => 'plugin', 'slug' => 'form', 'grav2_only' => true, 'grav1_affected' => '<6.0'],
            // A plugin that is not installed.
            ['advisory_id' => 'GHSA-4444-4444-4444', 'type' => 'plugin', 'slug' => 'admin', 'grav2_only' => true, 'grav1_affected' => '<2'],
            // No Grav 1.x range, or not marked: not counted.
            ['advisory_id' => 'GHSA-5555-5555-5555', 'type' => 'core', 'slug' => 'core', 'grav2_only' => true, 'grav1_affected' => null],
            ['advisory_id' => 'GHSA-6666-6666-6666', 'type' => 'core', 'slug' => 'core', 'grav2_only' => false, 'grav1_affected' => '<1.8.0-beta.1'],
        ];

        $ids = SecurityChecker::grav2OnlyFor($this->site('1.7.51'), $advisories);
        sort($ids);

        $this->assertSame(['GHSA-1111-1111-1111', 'GHSA-2222-2222-2222'], $ids);
    }

    public function testOldFeedWithoutTheFieldsBehavesAsBefore(): void
    {
        $feed = [
            ['advisory_id' => 'A1', 'type' => 'core', 'slug' => 'core', 'affected' => '<1.7.48'],
            ['advisory_id' => 'A2', 'type' => 'plugin', 'slug' => 'form', 'affected' => '<7.1'],
        ];

        $this->assertSame(['A1', 'A2'], array_column(SecurityChecker::match($this->site('1.7.40'), $feed), 'advisory_id'));
        $this->assertSame([], SecurityChecker::grav2OnlyFor($this->site('1.7.40'), $feed));
    }
}
