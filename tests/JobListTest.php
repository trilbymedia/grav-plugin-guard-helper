<?php

declare(strict_types=1);

use Grav\Plugin\GuardHelper\JobList;
use PHPUnit\Framework\TestCase;

/** The list of every scheduler job the Helper keeps for the Guard Agent. */
final class JobListTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guard-joblist-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    private static function job(string $id, string $at, bool $enabled = true): object
    {
        return new class ($id, $at, $enabled) {
            private $id; private $at; private $enabled;
            public function __construct(string $id, string $at, bool $enabled) { $this->id = $id; $this->at = $at; $this->enabled = $enabled; }
            public function getId() { return $this->id; }
            public function getAt() { return $this->at; }
            public function getEnabled() { return $this->enabled; }
        };
    }

    private static function scheduler(array $background, array $foreground): object
    {
        return new class ($background, $foreground) {
            private $b; private $f;
            public function __construct(array $b, array $f) { $this->b = $b; $this->f = $f; }
            public function getQueuedJobs($all = false) { return [$this->b, $this->f]; }
        };
    }

    public function testAJobItsWhenConditionKeepsOffIsListedAsOff(): void
    {
        // Grav 2.2.25+: the job says so itself.
        $asked = new class {
            public function getId() { return 'yetisearch-pro-embed'; }
            public function getAt() { return '*/15 * * * *'; }
            public function getEnabled() { return true; }
            public function passesWhen() { return false; }
        };
        // Older Grav: when()'s result sits in a private property.
        $older = new class {
            private $truthTest = false;
            public function getId() { return 'yetisearch-pro-maintenance'; }
            public function getAt() { return '30 9 * * *'; }
            public function getEnabled() { return true; }
        };

        $jobs = JobList::fromScheduler(self::scheduler([], [$asked, $older, self::job('cache-purge', '0 4 * * *')]));

        $this->assertSame([
            ['id' => 'cache-purge', 'at' => '0 4 * * *', 'enabled' => true],
            ['id' => 'yetisearch-pro-embed', 'at' => '*/15 * * * *', 'enabled' => false],
            ['id' => 'yetisearch-pro-maintenance', 'at' => '30 9 * * *', 'enabled' => false],
        ], $jobs);
    }

    public function testListsEveryRegisteredJobWithItsScheduleSortedById(): void
    {
        $jobs = JobList::fromScheduler(self::scheduler(
            [self::job('kahunacart-jobs', '* * * * *')],
            [self::job('cache-purge', '0 4 * * *'), self::job('default-site-backup', '0 3 * * *', false), self::job('', '* * * * *')]
        ));

        $this->assertSame([
            ['id' => 'cache-purge', 'at' => '0 4 * * *', 'enabled' => true],
            ['id' => 'default-site-backup', 'at' => '0 3 * * *', 'enabled' => false],
            ['id' => 'kahunacart-jobs', 'at' => '* * * * *', 'enabled' => true],
        ], $jobs);
    }

    public function testWritesOnlyWhenTheListChanges(): void
    {
        $jobs = [['id' => 'cache-purge', 'at' => '0 4 * * *', 'enabled' => true]];
        $this->assertTrue(JobList::write($this->dir, $jobs));
        $this->assertFalse(JobList::write($this->dir, $jobs), 'the same list again is not rewritten');

        $jobs[0]['enabled'] = false;
        $this->assertTrue(JobList::write($this->dir, $jobs));
        $saved = json_decode((string) file_get_contents($this->dir . '/' . JobList::FILE), true);
        $this->assertSame($jobs, $saved['jobs']);
        $this->assertSame([JobList::FILE], array_map('basename', glob($this->dir . '/*') ?: []), 'no temp file left behind');
    }

    public function testAJobWithoutAScheduleIsListedAsEveryMinute(): void
    {
        $jobs = JobList::fromScheduler(self::scheduler([self::job('queue-runner', '')], []));

        $this->assertSame([['id' => 'queue-runner', 'at' => '* * * * *', 'enabled' => true]], $jobs);
    }

    public function testAnOlderListIsRewrittenInTheCurrentFormat(): void
    {
        $jobs = [['id' => 'cache-purge', 'at' => '0 4 * * *', 'enabled' => true]];
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/' . JobList::FILE, json_encode(['written_at' => 1, 'jobs' => $jobs]));

        $this->assertTrue(JobList::write($this->dir, $jobs), 'same jobs, but no format: rewritten');
        $saved = json_decode((string) file_get_contents($this->dir . '/' . JobList::FILE), true);
        $this->assertSame(JobList::FORMAT, $saved['format']);
        $this->assertFalse(JobList::write($this->dir, $jobs));
    }

    public function testASchedulerWithoutTheMethodGivesNothing(): void
    {
        $this->assertSame([], JobList::fromScheduler(new stdClass()));
    }
}
