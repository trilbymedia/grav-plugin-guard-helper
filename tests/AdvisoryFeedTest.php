<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\LookupCache;
use Grav\Plugin\GuardHelper\Security\AdvisoryFeed;
use PHPUnit\Framework\TestCase;

/**
 * The advisory feed behind the free checker. A feed that could not be
 * fetched used to be stored as "no advisories" for an hour, so a site was
 * reported clean because Guard Cloud was down. It must now say "unknown",
 * keep that only briefly, and never mix up two clouds' feeds.
 */
final class AdvisoryFeedTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var int */
    private $now = 1_800_000_000;

    /** @var int */
    private $calls = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guard-feed-' . bin2hex(random_bytes(4));
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

    /** @param string|null|callable $body */
    private function feed(string $url, $body): AdvisoryFeed
    {
        return new AdvisoryFeed($url, $this->cache(), function (string $u, int $timeout, int $connect) use ($body): ?string {
            $this->calls++;
            $this->assertLessThanOrEqual(4, $timeout, 'bounded: this runs on the dashboard');
            $this->assertLessThanOrEqual(2, $connect);

            return is_callable($body) ? $body($u) : $body;
        });
    }

    public function testAFeedIsReadAndKeptForAnHour(): void
    {
        $body = '{"advisories":[{"advisory_id":"A1","type":"plugin","slug":"form","affected":"<9.1.33"}]}';
        $first = $this->feed('https://cloud.test/feed/advisories.json', $body)->load();

        $this->assertTrue($first['ok']);
        $this->assertSame('A1', $first['advisories'][0]['advisory_id']);
        $this->assertSame($this->now, $first['checked_at']);

        $this->now += AdvisoryFeed::TTL - 1;
        $this->assertTrue($this->feed('https://cloud.test/feed/advisories.json', null)->load()['ok']);
        $this->assertSame(1, $this->calls);
    }

    public function testAFeedWithNoAdvisoriesIsAKnownCleanAnswer(): void
    {
        $result = $this->feed('https://cloud.test/feed/advisories.json', '{"advisories":[]}')->load();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['advisories']);
    }

    public function testAFailedFetchIsUnknownAndKeptOnlyBriefly(): void
    {
        foreach ([null, '', 'not json', '{"generated_at":"x"}', '{"advisories":"nope"}'] as $body) {
            $this->tearDown();
            $result = $this->feed('https://cloud.test/feed/advisories.json', $body)->load();
            $this->assertFalse($result['ok'], 'body: ' . var_export($body, true));
            $this->assertSame([], $result['advisories']);
        }

        // ...and a cloud that comes back is asked again within a couple of minutes
        $this->tearDown();
        $this->calls = 0;
        $this->feed('https://cloud.test/feed/advisories.json', null)->load();
        $this->now += AdvisoryFeed::FAILURE_TTL - 1;
        $this->assertFalse($this->feed('https://cloud.test/feed/advisories.json', '{"advisories":[]}')->load()['ok']);
        $this->assertSame(1, $this->calls, 'a dead cloud is not asked on every dashboard load');
        $this->now += 1;
        $this->assertTrue($this->feed('https://cloud.test/feed/advisories.json', '{"advisories":[]}')->load()['ok']);
        $this->assertLessThanOrEqual(120, AdvisoryFeed::FAILURE_TTL);
    }

    public function testAThrowingFetcherIsAFailureNotAnError(): void
    {
        $result = $this->feed('https://cloud.test/feed/advisories.json', static function (string $u): ?string {
            throw new \RuntimeException('boom');
        })->load();

        $this->assertFalse($result['ok']);
    }

    public function testEachCloudHasItsOwnFeed(): void
    {
        $this->feed('https://a.test/feed/advisories.json', '{"advisories":[{"advisory_id":"A"}]}')->load();
        $other = $this->feed('https://b.test/feed/advisories.json', null)->load();

        $this->assertFalse($other['ok'], 'switching cloud never reuses the old cloud\'s feed');
        $this->assertSame(2, $this->calls);
    }

    public function testRefreshAsksEvenWhenAnAnswerIsStored(): void
    {
        $this->feed('https://cloud.test/feed/advisories.json', null)->load();
        $result = $this->feed('https://cloud.test/feed/advisories.json', '{"advisories":[]}')->load(true);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $this->calls);
    }
}
