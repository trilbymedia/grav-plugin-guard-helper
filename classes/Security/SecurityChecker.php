<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Security;

use Grav\Common\Grav;
use Grav\Common\Yaml;
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
    private Grav $grav;
    private ?LookupCache $cache;

    public function __construct(Grav $grav, ?LookupCache $cache = null)
    {
        $this->grav = $grav;
        $this->cache = $cache;
    }

    /**
     * Run the check against the public feed at $feedUrl.
     *
     * `status` is "ok" when the feed was read (findings may still be empty:
     * the site is clean), or "unknown" when it could not be, in which case
     * nothing was checked and `findings` is empty for that reason alone.
     *
     * @param bool $refresh fetch the feed even when a fresh copy is stored
     * @return array{status:string, checked_at:string, package_count:int, advisory_count:?int, findings:array<int,array<string,mixed>>}
     */
    public function run(string $feedUrl, bool $refresh = false): array
    {
        $installed = $this->installedPackages();
        $feed = (new AdvisoryFeed($feedUrl, $this->cache ?? LookupCache::forGrav($this->grav)))->load($refresh);

        return [
            'status' => $feed['ok'] ? 'ok' : 'unknown',
            'checked_at' => gmdate('c', $feed['checked_at']),
            'package_count' => count($installed),
            'advisory_count' => $feed['ok'] ? count($feed['advisories']) : null,
            'findings' => $feed['ok'] ? self::match($installed, $feed['advisories']) : [],
        ];
    }
    /**
     * Match installed packages against advisories. Pure — the testable core.
     *
     * @param array<int,array{type:string, slug:string, version:string}> $installed
     * @param array<int,array<string,mixed>> $advisories
     * @return array<int,array<string,mixed>>
     */
    public static function match(array $installed, array $advisories): array
    {
        $versions = [];
        foreach ($installed as $pkg) {
            $versions[$pkg['type'] . ':' . $pkg['slug']] = (string)$pkg['version'];
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
            $findings[] = $advisory;
        }

        return $findings;
    }

    /**
     * Installed packages read from the filesystem (core + plugin/theme
     * blueprints), matching what the cloud corpus is keyed on.
     *
     * @return array<int,array{type:string, slug:string, version:string}>
     */
    public function installedPackages(): array
    {
        $out = [];
        if (defined('GRAV_VERSION') && GRAV_VERSION !== '') {
            $out[] = ['type' => 'core', 'slug' => 'core', 'version' => (string)GRAV_VERSION];
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
                $data = Yaml::parse((string)file_get_contents($bp));
                $version = is_array($data) ? ($data['version'] ?? null) : null;
                if (is_string($version) && $version !== '') {
                    $out[] = ['type' => $type, 'slug' => (string)$slug, 'version' => $version];
                }
            }
        }

        return $out;
    }
}
