<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Security;

/**
 * A small, dependency-free version-constraint matcher for the in-admin security
 * checker. The cloud authors GravSec advisories with simple Composer-style
 * constraints, and we deliberately do NOT pull composer/semver into the plugin —
 * this supports exactly the subset those advisories use:
 *
 *   comparators  <  <=  >  >=  =  !=   (no operator means exact match)
 *   AND          space- or comma-separated within a group  (">=2.0 <2.1.3")
 *   OR           "||" between groups                        ("<1.5 || >=2.0 <2.0.4")
 *
 * Anything it can't parse returns false (fail-closed): a constraint we can't
 * understand must never silently mark a site "vulnerable".
 */
final class Constraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);
        $constraint = trim($constraint);
        if ($version === '' || $constraint === '') {
            return false;
        }

        foreach (explode('||', $constraint) as $group) {
            if (self::satisfiesGroup($version, trim($group))) {
                return true; // any OR group passing is enough
            }
        }

        return false;
    }

    /** Every comparator in an AND group must hold. */
    private static function satisfiesGroup(string $version, string $group): bool
    {
        $parts = preg_split('/[\s,]+/', $group, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return false;
        }
        foreach ($parts as $part) {
            if (!self::satisfiesComparator($version, $part)) {
                return false;
            }
        }

        return true;
    }

    private static function satisfiesComparator(string $version, string $part): bool
    {
        if (!preg_match('/^(<=|>=|!=|<|>|=)?\s*(.+)$/', $part, $m)) {
            return false;
        }
        $op = $m[1] !== '' ? $m[1] : '=';
        $target = self::normalize($m[2]);
        if ($target === '') {
            return false;
        }

        return match ($op) {
            '<' => version_compare($version, $target, '<'),
            '<=' => version_compare($version, $target, '<='),
            '>' => version_compare($version, $target, '>'),
            '>=' => version_compare($version, $target, '>='),
            '!=' => version_compare($version, $target, '!='),
            default => version_compare($version, $target, '=='),
        };
    }

    /** Strip a leading v and surrounding whitespace; '' if nothing usable. */
    private static function normalize(string $version): string
    {
        $version = trim($version);
        if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
            $version = substr($version, 1);
        }

        return $version;
    }
}
