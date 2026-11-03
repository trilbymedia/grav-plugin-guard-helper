<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

/**
 * Every job Grav's scheduler knows, with its schedule, kept in
 * user/data/guard-helper/scheduler-jobs.json for the Guard Agent.
 *
 * Grav core (cache-clear, cache-purge, backups) and plugins register their
 * jobs in PHP when the scheduler starts, so the agent, which reads the site
 * as files, only ever saw the custom jobs in user/config/scheduler.yaml.
 * The Helper runs inside Grav: once the scheduler has its jobs, it writes
 * their ids and schedules here, and the agent reports them to Guard Cloud
 * alongside the custom ones. The file is rewritten only when a job, its
 * schedule or its on/off state changes, not on every run.
 */
final class JobList
{
    public const FILE = 'scheduler-jobs.json';

    /**
     * 2: every job is listed, those without a schedule as "* * * * *". The
     * agent relies on the list being complete only from this format on.
     */
    public const FORMAT = 2;

    /**
     * @param object $scheduler Grav's Scheduler, as onSchedulerInitialized hands it over
     * @return list<array{id:string, at:string, enabled:bool}>
     */
    public static function fromScheduler(object $scheduler): array
    {
        if (!method_exists($scheduler, 'getQueuedJobs')) {
            return [];
        }
        // getQueuedJobs(true), not getAllJobs(): on Grav 1.7 getAllJobs()
        // loads the custom jobs again and queues each one twice. The agent
        // reads custom jobs from scheduler.yaml itself.
        [$background, $foreground] = $scheduler->getQueuedJobs(true);
        $jobs = [];
        foreach (array_merge((array) $background, (array) $foreground) as $job) {
            if (!is_object($job) || !method_exists($job, 'getId') || !method_exists($job, 'getAt')) {
                continue;
            }
            $id = trim((string) $job->getId());
            // A job registered without a schedule runs every minute (Grav's
            // Job::isDue() falls back to that), so it is listed that way: the
            // agent skips starting Grav only when no listed job is due.
            $at = trim((string) $job->getAt()) ?: '* * * * *';
            if ($id === '') {
                continue;
            }
            $jobs[$id] = [
                'id' => $id,
                'at' => $at,
                'enabled' => method_exists($job, 'getEnabled') ? (bool) $job->getEnabled() : true,
            ];
        }
        ksort($jobs);

        return array_values($jobs);
    }

    /**
     * Write the list when it differs from what the file holds.
     *
     * @param list<array{id:string, at:string, enabled:bool}> $jobs
     * @return bool true when the file was written
     */
    public static function write(string $dir, array $jobs): bool
    {
        $path = rtrim($dir, '/') . '/' . self::FILE;
        $current = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (is_array($current) && ($current['format'] ?? null) === self::FORMAT && ($current['jobs'] ?? null) === $jobs) {
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $body = json_encode(['format' => self::FORMAT, 'written_at' => time(), 'jobs' => $jobs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($body === false || @file_put_contents($tmp, $body . "\n") === false) {
            return false;
        }

        return @rename($tmp, $path) || (@unlink($tmp) && false);
    }
}
