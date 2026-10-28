<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\SchedulerWebhook;
use PHPUnit\Framework\TestCase;

/**
 * Switching Grav's scheduler webhook on for the agent: what is written, and
 * above all what is not. A token the site already has is never replaced,
 * settings other than the webhook's are kept, environment folders get our
 * values only where they would otherwise win, nothing is written when a file
 * cannot be, and the token never appears in what the admin pages are sent.
 *
 * Grav's YAML classes are not available here, so files are read and written
 * through a stand-in codec (var_export); SchedulerWebhook only ever touches
 * them through the callables it is given.
 */
final class SchedulerWebhookTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var int */
    private $writes = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/guard-webhook-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/user/config', 0777, true);
        $this->writes = 0;
    }

    protected function tearDown(): void
    {
        @chmod($this->root . '/user/config', 0777);
        $this->removeTree($this->root);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param string[] $overrides
     * @param array<string,mixed> $env
     */
    private function webhook(array $overrides = [], array $env = [], ?callable $read = null, ?callable $agentRead = null): SchedulerWebhook
    {
        // Stands in for the agent's own reader: the same file, read the same way.
        $agentRead = $agentRead ?? static function (string $file): array {
            $data = is_file($file) ? include $file : [];

            return is_array($data) ? $data : [];
        };

        return $this->build($overrides, $env, $read, $agentRead);
    }

    /**
     * @param string[] $overrides
     * @param array<string,mixed> $env
     */
    private function build(array $overrides, array $env, ?callable $read, ?callable $agentRead, int $lockWaitMs = 200): SchedulerWebhook
    {
        return new SchedulerWebhook(
            $this->root,
            $overrides,
            $env + ['managed' => true, 'core' => true, 'plugin_enabled' => false],
            $read ?? static function (string $file): array {
                $data = include $file;

                return is_array($data) ? $data : [];
            },
            function (string $file, array $data): void {
                $this->writes++;
                file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
            },
            $agentRead,
            $this->root . '/cache/guard-helper-scheduler.lock',
            $lockWaitMs
        );
    }

    private function base(): string
    {
        return $this->root . '/user/config/scheduler.yaml';
    }

    /** @param array<string,mixed> $data */
    private function put(string $file, array $data): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
    }

    /** @return array<string,mixed> */
    private function get(string $file): array
    {
        return include $file;
    }

    private function envFile(): string
    {
        return $this->root . '/user/env/example.com/config/scheduler.yaml';
    }

    private function strongToken(): string
    {
        return str_repeat('ab12', 16);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = "$dir/$item";
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function testSwitchesItOnWithAFreshTokenOnASiteThatHasNone(): void
    {
        $this->assertSame('off', $this->webhook()->status()['state']);

        $res = $this->webhook()->ensure();

        $this->assertSame('ready', $res['state']);
        $this->assertTrue($res['changed']);
        $this->assertSame('guard-helper', $res['served_by'], 'without the scheduler-webhook plugin, Guard Helper answers');

        $webhook = $this->get($this->base())['modern']['webhook'];
        $this->assertTrue($webhook['enabled']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $webhook['token'], '256 bits from random_bytes, as hex');
        $this->assertArrayNotHasKey('path', $webhook, 'the default path is left to Grav and the agent');

        // The admin pages never get the token.
        $this->assertStringNotContainsString($webhook['token'], (string) json_encode($res));
        $this->assertStringNotContainsString($webhook['token'], (string) json_encode($this->webhook()->status()));
    }

    public function testEverySiteGetsItsOwnToken(): void
    {
        $this->webhook()->ensure();
        $first = $this->get($this->base())['modern']['webhook']['token'];
        unlink($this->base());
        $this->webhook()->ensure();

        $this->assertNotSame($first, $this->get($this->base())['modern']['webhook']['token']);
    }

    public function testKeepsEveryOtherSchedulerSetting(): void
    {
        $before = [
            'status' => ['cache-purge' => 'enabled'],
            'custom_jobs' => ['nightly' => ['command' => 'bin/plugin x', 'at' => '0 2 * * *', 'args' => ['a', 'b']]],
            'modern' => [
                'workers' => 2,
                'history' => ['retention_days' => 7],
                'webhook' => ['path' => '/scheduler/webhook'],
            ],
        ];
        $this->put($this->base(), $before);

        $this->webhook()->ensure();
        $after = $this->get($this->base());

        $this->assertSame($before['status'], $after['status']);
        $this->assertSame($before['custom_jobs'], $after['custom_jobs']);
        $this->assertSame(2, $after['modern']['workers']);
        $this->assertSame(['retention_days' => 7], $after['modern']['history']);
        $this->assertSame('/scheduler/webhook', $after['modern']['webhook']['path']);
        $this->assertTrue($after['modern']['webhook']['enabled']);
    }

    public function testNeverReplacesATokenTheSiteAlreadyHas(): void
    {
        $token = $this->strongToken();
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => false, 'token' => $token]]]);

        $res = $this->webhook()->ensure();

        $this->assertSame('ready', $res['state']);
        $this->assertSame($token, $this->get($this->base())['modern']['webhook']['token']);
        $this->assertTrue($this->get($this->base())['modern']['webhook']['enabled']);
    }

    public function testAWebhookConfiguredByHandIsLeftAsItIs(): void
    {
        $token = $this->strongToken();
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $token, 'path' => '/scheduler/webhook']]]);
        $mtime = filemtime($this->base());

        $res = $this->webhook([], ['plugin_enabled' => true, 'plugin_version' => '1.1.5'])->ensure();

        $this->assertSame('ready', $res['state']);
        $this->assertSame('scheduler-webhook', $res['served_by']);
        $this->assertFalse($res['changed']);
        $this->assertSame(0, $this->writes, 'a site that is already right is not written to');
        $this->assertSame($mtime, filemtime($this->base()));
    }

    public function testRunningItTwiceWritesOnce(): void
    {
        $this->webhook()->ensure();
        $second = $this->webhook()->ensure();

        $this->assertFalse($second['changed']);
        $this->assertSame(1, $this->writes);
    }

    public function testWillNotSwitchOnBehindAShortToken(): void
    {
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => false, 'token' => 'secret']]]);

        $res = $this->webhook()->ensure();

        $this->assertSame('weak_token', $res['state']);
        $this->assertSame(0, $this->writes);
        $this->assertSame(['enabled' => false, 'token' => 'secret'], $this->get($this->base())['modern']['webhook']);
    }

    public function testAShortTokenSwitchedOnByHandIsNotAnsweredByGuardHelper(): void
    {
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => 'secret']]]);

        $res = $this->webhook()->ensure();

        $this->assertSame('no_endpoint', $res['state'], 'Guard Helper will not stand behind a short token');
        $this->assertSame(0, $this->writes);

        // The scheduler-webhook plugin answers it regardless; say so, and warn.
        $withPlugin = $this->webhook([], ['plugin_enabled' => true, 'plugin_version' => '1.1.5'])->status();
        $this->assertSame('ready', $withPlugin['state']);
        $this->assertTrue($withPlugin['weak_token']);
    }

    public function testAnEnvironmentFileThatWouldWinGetsTheSameValues(): void
    {
        // What Grav's classic admin leaves behind when the scheduler settings
        // are saved on a site with an environment folder: the whole block.
        $this->put($this->envFile(), [
            'modern' => ['workers' => 1, 'webhook' => ['enabled' => false, 'token' => null, 'path' => '/scheduler/webhook']],
            'custom_jobs' => ['x' => ['at' => '* * * * *']],
        ]);

        $before = $this->webhook([$this->envFile()])->status();
        $this->assertSame('off', $before['state']);

        $res = $this->webhook([$this->envFile()])->ensure();
        $this->assertSame('ready', $res['state']);

        $base = $this->get($this->base())['modern']['webhook'];
        $env = $this->get($this->envFile());
        $this->assertTrue($env['modern']['webhook']['enabled']);
        $this->assertSame($base['token'], $env['modern']['webhook']['token'], 'Grav reads it from here, the agent from user/config');
        $this->assertSame(1, $env['modern']['workers']);
        $this->assertSame(['x' => ['at' => '* * * * *']], $env['custom_jobs']);
    }

    public function testAnEnvironmentFileIsOnlyGivenTheKeysItAlreadySets(): void
    {
        $this->put($this->envFile(), ['modern' => ['webhook' => ['enabled' => false]]]);

        $this->webhook([$this->envFile()])->ensure();

        $this->assertSame(['enabled' => true], $this->get($this->envFile())['modern']['webhook']);
    }

    public function testNoEnvironmentFileIsEverCreated(): void
    {
        $this->webhook()->ensure();

        $this->assertFileDoesNotExist($this->envFile());
        $this->assertDirectoryDoesNotExist($this->root . '/user/env');
    }

    public function testAnEnvironmentTokenIsCopiedToTheFileTheAgentReads(): void
    {
        $token = $this->strongToken();
        $this->put($this->envFile(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $token]]]);

        $this->assertSame('mismatch', $this->webhook([$this->envFile()])->status()['state'], 'the agent would send no token');

        $res = $this->webhook([$this->envFile()])->ensure();

        $this->assertSame('ready', $res['state']);
        $this->assertSame($token, $this->get($this->base())['modern']['webhook']['token']);
        $this->assertSame($token, $this->get($this->envFile())['modern']['webhook']['token']);
    }

    public function testTwoDifferentTokensAreLeftForTheOwner(): void
    {
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $this->strongToken()]]]);
        $this->put($this->envFile(), ['modern' => ['webhook' => ['token' => str_repeat('cd34', 16)]]]);

        $res = $this->webhook([$this->envFile()])->ensure();

        $this->assertSame('conflict', $res['state']);
        $this->assertSame(['user/config/scheduler.yaml', 'user/env/example.com/config/scheduler.yaml'], $res['files']);
        $this->assertSame(0, $this->writes);
    }

    public function testNothingIsWrittenUnlessEveryFileCanBe(): void
    {
        $this->put($this->envFile(), ['modern' => ['webhook' => ['enabled' => false]]]);
        chmod($this->root . '/user/config', 0555);
        if (is_writable($this->root . '/user/config')) {
            $this->markTestSkipped('running as a user that ignores permissions');
        }

        $res = $this->webhook([$this->envFile()])->ensure();

        $this->assertSame('not_writable', $res['state']);
        $this->assertSame('user/config/scheduler.yaml', $res['file']);
        $this->assertSame(0, $this->writes, 'the environment file is not half-updated either');
        $this->assertSame(['enabled' => false], $this->get($this->envFile())['modern']['webhook']);
    }

    public function testAFileGravCannotReadIsNeverRewritten(): void
    {
        file_put_contents($this->base(), "modern: [broken\n");
        $read = static function (string $file): array {
            throw new \RuntimeException('Unable to parse');
        };

        $res = $this->webhook([], [], $read)->ensure();

        $this->assertSame('unreadable', $res['state']);
        $this->assertSame('user/config/scheduler.yaml', $res['file']);
        $this->assertSame(0, $this->writes);
        $this->assertSame("modern: [broken\n", file_get_contents($this->base()));
    }

    public function testAWebhookBlockThatIsNotAMapIsLeftAlone(): void
    {
        $this->put($this->base(), ['modern' => ['webhook' => 'on']]);

        $this->assertSame('unreadable', $this->webhook()->ensure()['state']);
        $this->assertSame(0, $this->writes);
    }

    public function testAPathTheWebhookPluginDoesNotAnswerIsCorrected(): void
    {
        // scheduler-webhook answers /scheduler/webhook only; the setting is
        // read by nothing but the agent, which would post somewhere else.
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $this->strongToken(), 'path' => '/cron/run']]]);

        $this->assertSame('mismatch', $this->webhook()->status()['state']);
        $res = $this->webhook()->ensure();

        $this->assertSame('ready', $res['state']);
        $this->assertSame('/scheduler/webhook', $this->get($this->base())['modern']['webhook']['path']);
    }

    public function testATrailingSlashCountsAsAnotherPath(): void
    {
        // The agent posts it as written; Grav answers with a redirect the
        // agent does not follow.
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $this->strongToken(), 'path' => '/scheduler/webhook/']]]);

        $this->assertSame('mismatch', $this->webhook()->status()['state']);
        $this->webhook()->ensure();
        $this->assertSame('/scheduler/webhook', $this->get($this->base())['modern']['webhook']['path']);
    }

    public function testWhatTheAgentReadsDecides(): void
    {
        $token = $this->strongToken();
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $token]]]);

        // The agent's own reader sees something else (a hand-edited value it
        // parses differently, say): that is a mismatch, not ready.
        $agentSees = ['modern' => ['webhook' => ['enabled' => true, 'token' => substr($token, 0, 20)]]];
        $webhook = $this->webhook([], [], null, static function () use (&$agentSees): array {
            return $agentSees;
        });
        $this->assertSame('mismatch', $webhook->status()['state']);

        $agentSees = ['modern' => ['webhook' => ['enabled' => true, 'token' => $token]]];
        $this->assertSame('ready', $webhook->status()['state']);
    }

    public function testTurnedOffInGuardHelperItChangesNothing(): void
    {
        $webhook = $this->webhook([], ['managed' => false]);

        $res = $webhook->ensure();

        $this->assertSame('off', $res['state']);
        $this->assertFalse($res['managed']);
        $this->assertFalse($res['changed']);
        $this->assertFileDoesNotExist($this->base());
        $this->assertStringContainsString('set not to change it', $res['message']);
    }

    public function testTurnedOffInGuardHelperGuardHelperDoesNotAnswer(): void
    {
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $this->strongToken()]]]);

        $this->assertSame('no_endpoint', $this->webhook([], ['managed' => false])->status()['state']);
        $this->assertSame('ready', $this->webhook([], ['managed' => false, 'plugin_enabled' => true, 'plugin_version' => '1.1.5'])->status()['state']);
    }

    public function testAnOldSchedulerWebhookPluginIsNamed(): void
    {
        // Before 1.1.1 it also wanted scheduler.modern.enabled, a switch
        // Grav 2 no longer has, and answered 404 without it.
        $this->webhook()->ensure();

        $res = $this->webhook([], ['plugin_enabled' => true, 'plugin_version' => '1.1.0'])->status();

        $this->assertSame('plugin_outdated', $res['state']);
        $this->assertStringContainsString('1.1.1', $res['message']);
    }

    public function testAGravWithoutWebhookSupportIsLeftAlone(): void
    {
        $res = $this->webhook([], ['core' => false])->ensure();

        $this->assertSame('unsupported', $res['state']);
        $this->assertSame(0, $this->writes);
    }

    public function testASiteWithNoSchedulerJobsStillGetsIt(): void
    {
        // Nothing for Guard Cloud to run yet, but it is ready for when there
        // is; the cloud does not drive a site until it has jobs.
        $this->put($this->base(), ['custom_jobs' => []]);

        $this->assertSame('ready', $this->webhook()->ensure()['state']);
        $this->assertSame([], $this->get($this->base())['custom_jobs']);
    }

    public function testWithoutTheAgentsReaderItIsNeverReady(): void
    {
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $this->strongToken()]]]);

        // No agent installed, or its autoloader could not be loaded.
        $none = $this->build([], [], null, null)->status();
        $this->assertSame('unverified', $none['state']);
        $this->assertFalse($none['ready']);
        $this->assertSame('guard-helper', $none['served_by']);

        $nullReader = $this->build([], [], null, static function (string $file): ?array {
            return null;
        })->status();
        $this->assertSame('unverified', $nullReader['state']);
        $this->assertStringContainsString("couldn't confirm", $nullReader['message']);

        // Real problems are still reported as themselves.
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $this->strongToken(), 'path' => '/cron']]]);
        $this->assertSame('mismatch', $this->build([], [], null, null)->status()['state']);
    }

    public function testSetupWithoutTheAgentsReaderStillWritesAndReportsUnverified(): void
    {
        $res = $this->build([], [], null, null)->ensure();

        $this->assertSame('unverified', $res['state']);
        $this->assertTrue($res['changed']);
        $webhook = $this->get($this->base())['modern']['webhook'];
        $this->assertTrue($webhook['enabled']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $webhook['token']);

        // A second run changes nothing.
        $this->assertFalse($this->build([], [], null, null)->ensure()['changed']);
        $this->assertSame(1, $this->writes);
    }

    public function testTheWrittenSettingsAreBlockStyleForTheAgentsParser(): void
    {
        // The agent's YAML reader skips flow maps ("webhook: {enabled: true}").
        // ensure() hands the writer plain nested arrays, which Grav's dumper
        // writes as block mappings at this depth (its inline limit is 5).
        $this->webhook()->ensure();

        $data = $this->get($this->base());
        $this->assertSame(['enabled', 'token'], array_keys($data['modern']['webhook']));
        $this->assertIsArray($data['modern']);
        $this->assertSame(['webhook'], array_keys($data['modern']));
        $this->assertLessThanOrEqual(5, $this->depth($data));
    }

    /** @param mixed $value */
    private function depth($value): int
    {
        return is_array($value) && $value !== [] ? 1 + max(array_map([$this, 'depth'], $value)) : 0;
    }

    public function testABadBasePathIsRepairedEvenWhenAnEnvironmentFileHasItRight(): void
    {
        $token = $this->strongToken();
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $token, 'path' => '/cron/run']]]);
        $this->put($this->envFile(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $token, 'path' => '/scheduler/webhook']]]);

        // Grav's merged view says /scheduler/webhook; the agent reads /cron/run.
        $this->assertSame('mismatch', $this->webhook([$this->envFile()])->status()['state']);

        $res = $this->webhook([$this->envFile()])->ensure();

        $this->assertSame('ready', $res['state']);
        $this->assertSame('/scheduler/webhook', $this->get($this->base())['modern']['webhook']['path']);
        $this->assertSame('/scheduler/webhook', $this->get($this->envFile())['modern']['webhook']['path']);
    }

    public function testABadEnvironmentPathIsRepairedEvenWhenTheBaseHasItRight(): void
    {
        $token = $this->strongToken();
        $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $token, 'path' => '/scheduler/webhook']]]);
        $this->put($this->envFile(), ['modern' => ['webhook' => ['path' => '/cron/run']]]);

        $this->assertSame('ready', $this->webhook([$this->envFile()])->ensure()['state']);
        $this->assertSame('/scheduler/webhook', $this->get($this->envFile())['modern']['webhook']['path']);
    }

    public function testAnotherRequestHoldingTheLockMeansNothingIsChanged(): void
    {
        mkdir($this->root . '/cache', 0777, true);
        $held = fopen($this->root . '/cache/guard-helper-scheduler.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX));

        $res = $this->webhook()->ensure();

        flock($held, LOCK_UN);
        fclose($held);
        $this->assertSame('busy', $res['state']);
        $this->assertFalse($res['changed']);
        $this->assertSame(0, $this->writes);
        $this->assertFileDoesNotExist($this->base());

        // Once the other request is done, it goes through.
        $this->assertSame('ready', $this->webhook()->ensure()['state']);
    }

    public function testATokenAnotherRequestWroteBeforeTheLockWasOursIsKept(): void
    {
        $theirs = $this->strongToken();
        $calls = 0;
        // Request B saves its token right after request A's first look at the
        // file (A's status() before it takes the lock).
        $read = function (string $file) use (&$calls, $theirs): array {
            $data = is_file($file) ? include $file : [];
            if (++$calls === 1) {
                $this->put($this->base(), ['modern' => ['webhook' => ['enabled' => true, 'token' => $theirs]], 'custom_jobs' => ['b' => ['at' => '* * * * *']]]);
            }

            return is_array($data) ? $data : [];
        };
        $this->put($this->base(), ['status' => ['x' => 'y']]);

        $res = $this->webhook([], [], $read)->ensure();

        $saved = $this->get($this->base());
        $this->assertSame($theirs, $saved['modern']['webhook']['token']);
        $this->assertSame(['b' => ['at' => '* * * * *']], $saved['custom_jobs'], "B's scheduler edit survives");
        $this->assertSame('ready', $res['state']);
        $this->assertSame(0, $this->writes, 'nothing was left to change');
    }

    public function testConcurrentSetupsAgreeOnOneToken(): void
    {
        $worker = __DIR__ . '/fixtures/ensure-worker.php';
        $lock = $this->root . '/cache/guard-helper-scheduler.lock';
        $procs = [];
        for ($i = 0; $i < 4; $i++) {
            $proc = proc_open(
                [PHP_BINARY, $worker, $this->root, $lock],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }

        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, "worker output: {$out} {$err}");
            $results[] = $decoded;
        }

        $tokens = array_unique(array_column($results, 'token'));
        $this->assertCount(1, $tokens, 'every request ends with the same token');
        $this->assertSame($tokens[0], $this->get($this->base())['modern']['webhook']['token']);
        $this->assertSame(1, array_sum(array_column($results, 'writes')), 'only one request wrote');
        foreach ($results as $r) {
            $this->assertSame('ready', $r['state']);
        }
    }

    // ── the agent's autoloader ───────────────────────────────────────────────

    private function agentRelease(string $dir): string
    {
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/src/autoload.php', '<?php');

        return realpath($dir . '/src/autoload.php');
    }

    public function testAFlatAgentInstallIsFoundInGuardSrc(): void
    {
        $this->assertNull(SchedulerWebhook::resolveAgentAutoload($this->root));

        $flat = $this->agentRelease($this->root . '/_guard');

        $this->assertSame($flat, SchedulerWebhook::resolveAgentAutoload($this->root));
    }

    public function testASelfUpdatedAgentIsResolvedThroughItsPointerFile(): void
    {
        $this->agentRelease($this->root . '/_guard');
        $live = $this->agentRelease($this->root . '/_guard/releases/v1.2.0');
        $this->agentRelease($this->root . '/_guard/releases/v1.1.0');
        file_put_contents($this->root . '/_guard/current.pointer', "releases/v1.2.0\n");

        $this->assertSame($live, SchedulerWebhook::resolveAgentAutoload($this->root));
    }

    public function testASelfUpdatedAgentIsResolvedThroughItsCurrentSymlink(): void
    {
        $this->agentRelease($this->root . '/_guard');
        $live = $this->agentRelease($this->root . '/_guard/releases/v1.2.0');
        symlink('releases/v1.2.0', $this->root . '/_guard/current');
        // The symlink wins over a stale pointer file, as in the agent's boot.php.
        file_put_contents($this->root . '/_guard/current.pointer', 'releases/v0.0.1');

        $this->assertSame($live, SchedulerWebhook::resolveAgentAutoload($this->root));
    }

    public function testAPointerThatEscapesTheAgentFolderIsRefused(): void
    {
        $outside = $this->agentRelease($this->root . '/elsewhere');
        mkdir($this->root . '/_guard/releases', 0777, true);

        foreach (['releases/../../elsewhere', '/' . dirname($outside, 2), 'releases/..', "releases/v1\0", 'other/v1'] as $pointer) {
            file_put_contents($this->root . '/_guard/current.pointer', $pointer);
            $this->assertNull(SchedulerWebhook::resolveAgentAutoload($this->root), $pointer);
        }

        // A release folder that is itself a link out of _guard/ is refused too,
        // by the real path of what would be loaded.
        symlink(dirname($outside, 2) . '/elsewhere', $this->root . '/_guard/releases/v9');
        file_put_contents($this->root . '/_guard/current.pointer', 'releases/v9');
        $this->assertNull(SchedulerWebhook::resolveAgentAutoload($this->root));
    }

    public function testAPointerThatDoesNotResolveFallsBackToTheFlatTreeLikeTheAgent(): void
    {
        $flat = $this->agentRelease($this->root . '/_guard');
        file_put_contents($this->root . '/_guard/current.pointer', 'releases/v9.9.9');

        $this->assertSame($flat, SchedulerWebhook::resolveAgentAutoload($this->root));
    }

    // ── who answers the route ────────────────────────────────────────────────

    private function fakeGrav(bool $enabled, array $loaded): \ArrayAccess
    {
        $config = new class($enabled) {
            private $enabled;

            public function __construct(bool $enabled)
            {
                $this->enabled = $enabled;
            }

            public function get(string $key, $default = null)
            {
                return $key === 'plugins.scheduler-webhook.enabled' ? $this->enabled : $default;
            }
        };
        $plugins = array_map(static function (string $name): object {
            $o = new \stdClass();
            $o->name = $name;

            return $o;
        }, $loaded);

        return new \ArrayObject(['config' => $config, 'plugins' => $plugins]);
    }

    public function testTheOfficialPluginCountsOnlyWhenInstalledEnabledAndLoaded(): void
    {
        if (!defined('GRAV_ROOT')) {
            define('GRAV_ROOT', sys_get_temp_dir() . '/guard-helper-grav-root');
        }
        $dir = rtrim(GRAV_ROOT, '/') . '/user/plugins/scheduler-webhook';
        $this->removeTree($dir);

        // A stale `enabled: true` for a plugin that is not there.
        $this->assertFalse(SchedulerWebhook::officialPluginActive($this->fakeGrav(true, ['scheduler-webhook'])));

        mkdir($dir, 0777, true);
        try {
            $this->assertTrue(SchedulerWebhook::officialPluginActive($this->fakeGrav(true, ['email', 'scheduler-webhook'])));
            $this->assertFalse(SchedulerWebhook::officialPluginActive($this->fakeGrav(false, ['scheduler-webhook'])), 'disabled');
            $this->assertFalse(SchedulerWebhook::officialPluginActive($this->fakeGrav(true, ['email'])), 'not loaded by Grav');
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testEveryStateHasAMessageWithoutJargon(): void
    {
        foreach (['ready', 'unverified', 'busy', 'off', 'mismatch', 'no_endpoint', 'plugin_outdated', 'conflict', 'weak_token', 'not_writable', 'unreadable', 'unsupported'] as $state) {
            $msg = SchedulerWebhook::message($state, ['files' => ['a.yaml']]);
            $this->assertNotSame('', $msg, $state);
            $this->assertDoesNotMatchRegularExpression('/\b(surface|shape)/i', $msg, $state);
        }
    }
}
