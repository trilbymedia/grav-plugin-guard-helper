<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Security;

/**
 * Is the PHP this site runs on still getting security fixes?
 *
 * A PHP branch past its end of life gets no fixes at all, however serious:
 * the critical fixes PHP shipped in 2026 went to 8.2 and up only. The plugin
 * installs on PHP 7.3 and up (Grav 1.7's own floor) so that those sites can still be watched, and
 * this check tells them plainly what that PHP leaves open and what to move to:
 * PHP 8.3 on Grav 1.7 (the newest it runs on), 8.3 or newer on Grav 2.
 *
 * The end dates are PHP's published end of security support for each branch,
 * so the status moves on by itself as the dates pass. Both admins show the
 * title and detail as written here, so they say the same thing.
 */
final class PhpSupport
{
    /** End of security support per branch (php.net/supported-versions). */
    public const ENDS = [
        '7.0' => '2019-01-10',
        '7.1' => '2019-12-01',
        '7.2' => '2020-11-30',
        '7.3' => '2021-12-06',
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /** The branch recommended to every site. */
    public const RECOMMENDED = '8.3';

    /** Warn this long before a branch's security support ends. */
    private const WARN_DAYS = 183;

    /**
     * `status` is "unsupported" (no security fixes any more), "ending" (fixes
     * stop soon, or the branch is older than recommended) or "ok".
     *
     * @return array{status:string, version:string, branch:string, ends:?string, recommended:string, title:string, detail:string}
     */
    public static function check(string $phpVersion, string $gravVersion, ?int $now = null): array
    {
        $now = $now ?? time();
        $parts = explode('.', $phpVersion);
        $branch = (int) ($parts[0] ?? 0) . '.' . (int) ($parts[1] ?? 0);
        $grav1 = $gravVersion !== '' && version_compare($gravVersion, '2.0.0-dev', '<');
        $recommended = $grav1 ? 'PHP ' . self::RECOMMENDED : 'PHP ' . self::RECOMMENDED . ' or newer';
        $advice = $grav1
            ? 'Upgrade to PHP ' . self::RECOMMENDED . ', the newest PHP Grav 1.7 runs on.'
            : 'Upgrade to PHP ' . self::RECOMMENDED . ' or newer.';

        $ends = self::ENDS[$branch] ?? null;
        $endsAt = $ends !== null ? (int) strtotime($ends . ' 23:59:59 UTC') : null;
        $oldest = (string) array_key_first(self::ENDS);
        $tooOld = $ends === null && version_compare($branch, $oldest, '<');

        $out = [
            'status' => 'ok',
            'version' => $phpVersion,
            'branch' => $branch,
            'ends' => $ends,
            'recommended' => $recommended,
            'title' => '',
            'detail' => '',
        ];

        if ($tooOld || ($endsAt !== null && $endsAt < $now)) {
            $ended = $ends !== null ? 'ended on ' . self::date($ends) : 'ended years ago';
            $out['status'] = 'unsupported';
            $out['title'] = 'PHP ' . $phpVersion . ' gets no security fixes';
            $out['detail'] = 'Security support for PHP ' . $branch . ' ' . $ended
                . ', so flaws found since then stay open on this site, including the critical ones PHP fixed in 2026 only for 8.2 and up. '
                . $advice;

            return $out;
        }

        $soon = $endsAt !== null && $endsAt - $now < self::WARN_DAYS * 86400;
        $older = version_compare($branch, self::RECOMMENDED, '<');
        if ($soon || $older) {
            $out['status'] = 'ending';
            $out['title'] = 'PHP ' . $phpVersion . ' gets security fixes until ' . self::date((string) $ends);
            if ($grav1 && !$older) {
                // Grav 1.7 can't go past 8.3, so the way on is Grav 2.
                $out['detail'] = 'After that, flaws found in PHP ' . $branch . ' stay open on this site. Grav 1.7 runs on PHP up to '
                    . self::RECOMMENDED . ', so moving the site to Grav 2 is the way to a newer PHP.';
            } else {
                $out['detail'] = 'After that, flaws found in PHP ' . $branch . ' stay open on this site. ' . $advice;
            }

            return $out;
        }

        $out['title'] = 'PHP ' . $phpVersion . ($ends !== null
            ? ' gets security fixes until ' . self::date($ends)
            : ' gets security fixes');

        return $out;
    }

    /** "31 Dec 2026", the date format the Guard page uses. */
    private static function date(string $ymd): string
    {
        return gmdate('j M Y', (int) strtotime($ymd . ' 12:00:00 UTC'));
    }
}
