<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\Security\Constraint;
use Grav\Plugin\GuardHelper\Security\SecurityChecker;
use PHPUnit\Framework\TestCase;

/**
 * The free in-admin checker's two pure pieces: the dependency-free constraint
 * matcher (we deliberately don't ship composer/semver in the plugin) and the
 * installed-vs-advisory match. Both must fail closed — a constraint we can't
 * parse must never flag a healthy site.
 */
final class SecurityCheckerTest extends TestCase
{
    public function testConstraintComparators(): void
    {
        $this->assertTrue(Constraint::satisfies('1.10.43', '<1.10.44'));
        $this->assertFalse(Constraint::satisfies('1.10.44', '<1.10.44'));
        $this->assertTrue(Constraint::satisfies('2.0.0', '>=2.0'));
        $this->assertTrue(Constraint::satisfies('1.5.0', '<=1.5.0'));
        $this->assertFalse(Constraint::satisfies('1.5.1', '<=1.5.0'));
    }

    public function testConstraintAndRange(): void
    {
        $this->assertTrue(Constraint::satisfies('1.2.0', '>=1.0 <1.5'));
        $this->assertFalse(Constraint::satisfies('0.9.0', '>=1.0 <1.5'));
        $this->assertFalse(Constraint::satisfies('1.5.0', '>=1.0 <1.5'));
        // comma works as AND too
        $this->assertTrue(Constraint::satisfies('1.2.0', '>=1.0,<1.5'));
    }

    public function testConstraintOr(): void
    {
        $this->assertTrue(Constraint::satisfies('0.4.0', '<0.5 || >=2.0 <2.0.4'));
        $this->assertTrue(Constraint::satisfies('2.0.3', '<0.5 || >=2.0 <2.0.4'));
        $this->assertFalse(Constraint::satisfies('1.0.0', '<0.5 || >=2.0 <2.0.4'));
    }

    public function testConstraintStripsLeadingV(): void
    {
        $this->assertTrue(Constraint::satisfies('v1.0.0', '<2.0'));
    }

    public function testConstraintFailsClosedOnGarbage(): void
    {
        $this->assertFalse(Constraint::satisfies('1.0.0', 'not a constraint'));
        $this->assertFalse(Constraint::satisfies('', '<1.0'));
        $this->assertFalse(Constraint::satisfies('1.0.0', ''));
    }

    public function testMatchFindsVulnerableInstalledPackages(): void
    {
        $installed = [
            ['type' => 'core', 'slug' => 'core', 'version' => '1.7.40'],
            ['type' => 'plugin', 'slug' => 'admin', 'version' => '1.10.44'],
            ['type' => 'plugin', 'slug' => 'form', 'version' => '5.1.0'],
        ];
        $advisories = [
            ['advisory_id' => 'A1', 'type' => 'core', 'slug' => 'core', 'affected' => '<1.7.48'],
            ['advisory_id' => 'A2', 'type' => 'plugin', 'slug' => 'admin', 'affected' => '<1.10.44'], // patched
            ['advisory_id' => 'A3', 'type' => 'plugin', 'slug' => 'form', 'affected' => '<5.2'],
            ['advisory_id' => 'A4', 'type' => 'plugin', 'slug' => 'notinstalled', 'affected' => '<9'],
        ];

        $findings = SecurityChecker::match($installed, $advisories);
        $ids = array_column($findings, 'advisory_id');

        sort($ids);
        $this->assertSame(['A1', 'A3'], $ids);
        $this->assertSame('1.7.40', $findings[0]['installed_version']);
    }

    public function testFindingsAreNamedAndWorstFirst(): void
    {
        $installed = [
            ['type' => 'plugin', 'slug' => 'form', 'version' => '9.0.3', 'name' => 'Form'],
            ['type' => 'plugin', 'slug' => 'admin', 'version' => '1.10.40', 'name' => 'Admin Panel'],
            ['type' => 'theme', 'slug' => 'quark', 'version' => '2.0.0'],
        ];
        $advisories = [
            ['advisory_id' => 'LOW', 'type' => 'plugin', 'slug' => 'admin', 'affected' => '<2', 'severity' => 'low'],
            ['advisory_id' => 'UNRATED', 'type' => 'theme', 'slug' => 'quark', 'affected' => '<3'],
            ['advisory_id' => 'CRIT', 'type' => 'plugin', 'slug' => 'form', 'affected' => '<10', 'severity' => 'Critical'],
            ['advisory_id' => 'HIGH', 'type' => 'plugin', 'slug' => 'admin', 'affected' => '<2', 'severity' => 'high'],
        ];

        $findings = SecurityChecker::match($installed, $advisories);

        $this->assertSame(['CRIT', 'HIGH', 'LOW', 'UNRATED'], array_column($findings, 'advisory_id'));
        $this->assertSame('Form', $findings[0]['package_name']);
        $this->assertSame('9.0.3', $findings[0]['installed_version']);
        $this->assertSame('quark', $findings[3]['package_name'], 'a package with no name shows its slug');
    }
}
