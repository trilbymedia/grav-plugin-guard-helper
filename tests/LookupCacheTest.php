<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\LookupCache;
use PHPUnit\Framework\TestCase;

/**
 * The cache behind every answer Guard Helper asks Guard Cloud for. What
 * matters: an answer lasts exactly as long as it was stored for, a failure is
 * stored as a failure (never as an empty answer), and keys never collide.
 */
final class LookupCacheTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var int */
    private $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guard-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function cache(): LookupCache
    {
        return new LookupCache($this->dir, function (): int {
            return $this->now;
        });
    }

    public function testNothingStoredIsNull(): void
    {
        $this->assertNull($this->cache()->get('anything'));
    }

    public function testAnAnswerLastsForItsTtlAndNoLonger(): void
    {
        $cache = $this->cache();
        $cache->put('k', true, ['a' => 1], 60);

        $this->now += 59;
        $this->assertSame(['ok' => true, 'value' => ['a' => 1], 'at' => 1_800_000_000], $cache->get('k'));

        $this->now += 1;
        $this->assertNull($cache->get('k'), 'expired at exactly its TTL');
    }

    public function testAFailureIsStoredAsAFailureWithNoValue(): void
    {
        $cache = $this->cache();
        $cache->put('k', false, ['ignored'], 120);

        $entry = $cache->get('k');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['ok']);
        $this->assertNull($entry['value']);
    }

    public function testAnEmptyAnswerIsStillAnAnswer(): void
    {
        $cache = $this->cache();
        $cache->put('k', true, [], 60);

        $this->assertSame(['ok' => true, 'value' => [], 'at' => $this->now], $cache->get('k'));
    }

    public function testKeysAreSeparate(): void
    {
        $cache = $this->cache();
        $cache->put('feed|https://a.test/feed', true, ['a'], 60);
        $cache->put('feed|https://b.test/feed', false, null, 60);

        $this->assertTrue($cache->get('feed|https://a.test/feed')['ok']);
        $this->assertFalse($cache->get('feed|https://b.test/feed')['ok']);
        $this->assertNull($cache->get('feed|https://c.test/feed'));
    }

    public function testAnUnreadableFileIsTreatedAsNothingStored(): void
    {
        $cache = $this->cache();
        $cache->put('k', true, 'v', 60);
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            file_put_contents($file, '{not json');
        }

        $this->assertNull($cache->get('k'));
    }

    public function testAFolderThatCannotBeCreatedIsNotAnError(): void
    {
        $blocker = $this->dir . '-file';
        file_put_contents($blocker, 'x');
        try {
            $cache = new LookupCache($blocker . '/cache');
            $cache->put('k', true, 'v', 60);
            $this->assertNull($cache->get('k'));
        } finally {
            @unlink($blocker);
        }
    }
}
