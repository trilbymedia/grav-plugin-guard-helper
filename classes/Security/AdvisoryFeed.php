<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Security;

use Grav\Plugin\GuardHelper\LookupCache;

/**
 * The public GravSec advisory feed, fetched from Guard Cloud and kept.
 *
 * A feed that could not be fetched is "unknown", never "no advisories": the
 * checker must not tell someone their site is clean because Guard Cloud was
 * down. So a failure is kept as a failure, and only for FAILURE_TTL, while a
 * real answer (even an empty one) is kept for TTL. The cache key carries the
 * feed URL, so pointing the plugin at another cloud never reuses the old one.
 */
final class AdvisoryFeed
{
    /** The public feed changes slowly. */
    public const TTL = 3600;
    /** A failed fetch is asked again after this long. */
    public const FAILURE_TTL = 120;

    /**
     * This runs on the Admin Next dashboard's notifications call, so a cloud
     * that does not answer may cost it a couple of seconds, not eight.
     */
    private const CONNECT_TIMEOUT = 2;
    private const TIMEOUT = 4;

    private string $url;
    private LookupCache $cache;
    /** @var callable(string, int, int):?string */
    private $fetcher;

    /**
     * @param callable(string, int, int):?string|null $fetcher GET a URL with
     *   a total and a connect timeout → body, or null; tests pass one
     */
    public function __construct(string $url, LookupCache $cache, ?callable $fetcher = null)
    {
        $this->url = $url;
        $this->cache = $cache;
        $this->fetcher = $fetcher ?? [self::class, 'httpGet'];
    }

    /**
     * The advisories, from the cache when fresh, otherwise fetched (bounded)
     * and stored.
     *
     * @param bool $refresh fetch even when a fresh answer is stored
     * @return array{ok:bool, advisories:array<int,array<string,mixed>>, checked_at:int}
     *   ok false: the feed could not be fetched or read, and `advisories` is
     *   empty only because nothing is known
     */
    public function load(bool $refresh = false): array
    {
        $entry = $refresh ? null : $this->cache->get($this->key());
        if ($entry === null) {
            $advisories = $this->fetch();
            $this->cache->put($this->key(), $advisories !== null, $advisories, $advisories !== null ? self::TTL : self::FAILURE_TTL);
            $entry = $this->cache->get($this->key()) ?? ['ok' => $advisories !== null, 'value' => $advisories, 'at' => time()];
        }

        return self::result($entry);
    }

    /**
     * What is stored, without fetching: for a screen that draws first and
     * asks afterwards.
     *
     * @return array{ok:bool, advisories:array<int,array<string,mixed>>, checked_at:int}|null
     *   null when nothing fresh is stored
     */
    public function stored(): ?array
    {
        $entry = $this->cache->get($this->key());

        return $entry === null ? null : self::result($entry);
    }

    private function key(): string
    {
        return 'advisory-feed|' . $this->url;
    }

    /**
     * @param array{ok:bool, value:mixed, at:int} $entry
     * @return array{ok:bool, advisories:array<int,array<string,mixed>>, checked_at:int}
     */
    private static function result(array $entry): array
    {
        $ok = $entry['ok'] && is_array($entry['value']);

        return [
            'ok' => $ok,
            'advisories' => $ok ? array_values(array_filter($entry['value'], 'is_array')) : [],
            'checked_at' => (int) $entry['at'],
        ];
    }

    /** @return array<int,mixed>|null null when the feed could not be fetched or read */
    private function fetch(): ?array
    {
        try {
            $raw = ($this->fetcher)($this->url, self::TIMEOUT, self::CONNECT_TIMEOUT);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['advisories'] ?? null)) {
            return null;
        }

        return $decoded['advisories'];
    }

    private static function httpGet(string $url, int $timeout, int $connectTimeout): ?string
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'GuardHelper',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return ($body !== false && $status >= 200 && $status < 300) ? (string) $body : null;
        }

        $context = stream_context_create([
            'http' => ['timeout' => $timeout, 'header' => "User-Agent: GuardHelper\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
