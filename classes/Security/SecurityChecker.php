<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Security;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\GuardHelper\BlueprintHeader;
use Grav\Plugin\GuardHelper\LookupCache;

/**
 * The free in-admin security checker (plan §9.1 exposure ladder). Reads the
 * site's own installed package versions, fetches the public GravSec advisory
 * feed, and reports which installed packages have known vulnerabilities — no
 * account, no agent required. This is the funnel: the value is visible before
 * anyone pairs a site.
 *
 * The feed is cached (see AdvisoryFeed); matching is local, so a site's package
 * list never leaves the box for the free check.
 */
final class SecurityChecker
{
    /** @var Grav */
    private $grav;
    /** @var LookupCache|null */
    private $cache;

    public function __construct(Grav $grav, ?LookupCache $cache = null)
    {
        $this->grav = $grav;
        $this->cache = $cache;
    }

    /**
     * Run the check against the public feed at $feedUrl.
     *
     * `status` is "ok" when the feed was read (findings may still be empty:
     * the site is clean), "unknown" when it could not be, in which case
     * nothing was checked and `findings` is empty for that reason alone, or
     * "pending" when $askCloud is false and no copy of the feed is stored.
     *
     * @param bool $refresh fetch the feed even when a fresh copy is stored
     * @param bool $askCloud false to use only a stored copy (a screen drawing)
     * `php` is whether this site's PHP still gets security fixes
     * (PhpSupport::check), worked out on the site, so it is there whatever
     * the feed did.
     *
     * @return array{status:string, checked_at:?string, package_count:int, advisory_count:?int, findings:array<int,array<string,mixed>>, php:array<string,mixed>}
     */
    public function run(string $feedUrl, bool $refresh = false, bool $askCloud = true): array
    {
        $installed = $this->installedPackages();
        $php = self::php();
        $source = new AdvisoryFeed($feedUrl, $this->cache ?? LookupCache::forGrav($this->grav));
        $feed = $askCloud ? $source->load($refresh) : $source->forDisplay();
        if ($feed === null) {
            return ['status' => 'pending', 'checked_at' => null, 'package_count' => count($installed), 'advisory_count' => null, 'findings' => [], 'php' => $php];
        }

        return [
            'status' => $feed['ok'] ? 'ok' : 'unknown',
            'checked_at' => gmdate('c', $feed['checked_at']),
            'package_count' => count($installed),
            'advisory_count' => $feed['ok'] ? count($feed['advisories']) : null,
            'findings' => $feed['ok'] ? self::match($installed, $feed['advisories']) : [],
            'php' => $php,
        ];
    }

    /** @return array<string,mixed> PhpSupport::check() for the PHP running this request */
    public static function php(): array
    {
        return PhpSupport::check(PHP_VERSION, defined('GRAV_VERSION') ? (string) GRAV_VERSION : '');
    }

    /**
     * Match installed packages against advisories. Pure — the testable core.
     *
     * @param array<int,array{type:string, slug:string, version:string, name?:string}> $installed
     * @param array<int,array<string,mixed>> $advisories
     * @return array<int,array<string,mixed>>
     */
    public static function match(array $installed, array $advisories): array
    {
        $versions = [];
        $names = [];
        foreach ($installed as $pkg) {
            $versions[$pkg['type'] . ':' . $pkg['slug']] = (string)$pkg['version'];
            $names[$pkg['type'] . ':' . $pkg['slug']] = (string)($pkg['name'] ?? '');
        }

        $findings = [];
        foreach ($advisories as $advisory) {
            $key = ($advisory['type'] ?? '') . ':' . ($advisory['slug'] ?? '');
            if (!isset($versions[$key])) {
                continue;
            }
            if (!Constraint::satisfies($versions[$key], (string)($advisory['affected'] ?? ''))) {
                continue;
            }
            $advisory['installed_version'] = $versions[$key];
            // What the package calls itself ("Form", "Grav"), for display.
            $advisory['package_name'] = $names[$key] !== '' ? $names[$key] : (string)($advisory['slug'] ?? '');
            $findings[] = $advisory;
        }

        // Worst first, then by package, so the list reads in the order to act.
        usort($findings, static function (array $a, array $b): int {
            return [self::severityRank($a['severity'] ?? null), strtolower($a['package_name'])]
                <=> [self::severityRank($b['severity'] ?? null), strtolower($b['package_name'])];
        });

        return $findings;
    }

    /** critical 0 … low 3, anything unnamed last. */
    private static function severityRank($severity): int
    {
        $ranks = ['critical' => 0, 'high' => 1, 'medium' => 2, 'moderate' => 2, 'low' => 3];

        return $ranks[strtolower((string)$severity)] ?? 4;
    }

    /**
     * Installed packages read from the filesystem (core + plugin/theme
     * blueprints), matching what the cloud corpus is keyed on.
     *
     * @return array<int,array{type:string, slug:string, version:string, name:string}>
     */
    public function installedPackages(): array
    {
        $out = [];
        if (defined('GRAV_VERSION') && GRAV_VERSION !== '') {
            $out[] = ['type' => 'core', 'slug' => 'core', 'version' => (string)GRAV_VERSION, 'name' => 'Grav'];
        }

        $locator = $this->grav['locator'];
        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $dir => $type) {
            $base = $locator->findResource('user://' . $dir, true);
            if (!is_string($base) || !is_dir($base)) {
                continue;
            }
            foreach (scandir($base) ?: [] as $slug) {
                if ($slug[0] === '.' || !is_dir("$base/$slug")) {
                    continue;
                }
                $bp = "$base/$slug/blueprints.yaml";
                if (!is_file($bp)) {
                    continue;
                }
                $src = (string)file_get_contents($bp);
                // Only name and version are wanted, and reading them line by
                // line costs a fraction of parsing every blueprint as YAML.
                // A file the line reader cannot be sure of is parsed instead.
                $header = BlueprintHeader::read($src);
                if ($header === null) {
                    $data = Yaml::parse($src);
                    $header = [
                        'name' => is_array($data) && is_string($data['name'] ?? null) ? $data['name'] : '',
                        'version' => is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '',
                    ];
                }
                if ($header['version'] !== '') {
                    $name = $header['name'] !== '' ? $header['name'] : (string)$slug;
                    $out[] = ['type' => $type, 'slug' => (string)$slug, 'version' => $header['version'], 'name' => $name];
                }
            }
        }

        return $out;
    }
}
