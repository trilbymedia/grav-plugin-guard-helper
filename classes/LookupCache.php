<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

/**
 * A small file cache for the answers Guard Helper asks Guard Cloud for: the
 * newest agent version and the public advisory feed.
 *
 * An admin screen draws from what is already known and never waits on a
 * network call, so these answers are kept between requests. A failed lookup
 * is kept too, as a failure and only briefly: long enough that a cloud that
 * is down does not cost every page load a timeout, short enough that the
 * answer comes back soon after the cloud does. A failure is never stored as
 * an empty answer, since "no advisories" and "could not ask" mean different
 * things to the person reading the screen.
 *
 * Files rather than Grav's cache, so the answers survive a site with Grav's
 * cache switched off (where every dashboard load would otherwise ask again)
 * and live beside the Helper's other state in user/data/guard-helper/.
 */
final class LookupCache
{
    private string $dir;
    /** @var callable():int */
    private $clock;

    /**
     * @param callable():int|null $clock the current Unix time; tests pass one
     */
    public function __construct(string $dir, ?callable $clock = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    /** The Helper's cache folder on this site: user/data/guard-helper/cache. */
    public static function forGrav($grav): self
    {
        $dir = null;
        try {
            $dir = $grav['locator']->findResource('user-data://guard-helper', true, true);
        } catch (\Throwable $e) {
            // no user-data stream on this Grav: use the folder it stands for
        }
        if (!is_string($dir) || $dir === '') {
            $dir = rtrim(GRAV_ROOT, '/') . '/user/data/guard-helper';
        }

        return new self(rtrim($dir, '/') . '/cache');
    }

    /**
     * The stored answer for $key, while it is still fresh.
     *
     * @return array{ok:bool, value:mixed, at:int}|null null when nothing
     *   fresh is stored (never asked, expired, or unreadable)
     */
    public function get(string $key): ?array
    {
        $raw = @file_get_contents($this->file($key));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || ($entry['key'] ?? null) !== $key
            || !isset($entry['expires'], $entry['at']) || !is_int($entry['expires']) || !is_int($entry['at'])) {
            return null;
        }
        if ($entry['expires'] <= ($this->clock)()) {
            return null;
        }

        return ['ok' => (bool) ($entry['ok'] ?? false), 'value' => $entry['value'] ?? null, 'at' => $entry['at']];
    }

    /**
     * Keep an answer for $ttl seconds. $ok false records a failed lookup
     * (with no value), so the caller can say "unknown" rather than "none".
     * Never throws: a cache that cannot be written just means asking again.
     *
     * @param mixed $value anything json_encode() can carry
     */
    public function put(string $key, bool $ok, $value, int $ttl): void
    {
        $now = ($this->clock)();
        $json = json_encode([
            'key' => $key,
            'ok' => $ok,
            'value' => $ok ? $value : null,
            'at' => $now,
            'expires' => $now + max(1, $ttl),
        ]);
        if ($json === false) {
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return;
        }

        // Written beside the final name and renamed, so a reader never sees
        // half a file.
        $file = $this->file($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.json';
    }
}
