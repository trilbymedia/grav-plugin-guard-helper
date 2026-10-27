<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use FilesystemIterator;
use Grav\Plugin\GuardHelper\AgentBootstrap;
use Grav\Plugin\GuardHelper\BootstrapException;
use Grav\Plugin\GuardHelper\ReleaseVerifier;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * The browser-only agent bootstrap: it must REFUSE anything the release key
 * did not sign — a forged manifest, a zip that does not match it, a zip from
 * another host, a tree with a file the manifest does not list — and on a
 * genuine release unpack the agent into _guard/ and pair it in-process,
 * producing a code with no shell access (review 2026-10-27, P0-3).
 *
 * The tests sign with their own key, handed to the constructor; production
 * always verifies against ReleaseVerifier's pinned release key.
 */
final class AgentBootstrapTest extends TestCase
{
    /** @var string */
    private $gravRoot;

    /** @var string raw Ed25519 secret key the test releases are signed with */
    private $signingSecret;

    /** @var string raw public half */
    private $signingPublic;

    protected function setUp(): void
    {
        $this->gravRoot = sys_get_temp_dir() . '/guard-helper-' . bin2hex(random_bytes(4));
        mkdir($this->gravRoot, 0777, true);
        $pair = sodium_crypto_sign_keypair();
        $this->signingSecret = sodium_crypto_sign_secretkey($pair);
        $this->signingPublic = sodium_crypto_sign_publickey($pair);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->gravRoot);
    }

    /**
     * A signed release as the cloud serves it: [descriptor json, zip bytes].
     *
     * @param array<string,string> $files path => contents, under one top dir
     * @param callable|null $alter fn(array $manifest): array, applied before signing
     * @return array{0:string,1:string}
     */
    private function release(array $files, ?callable $alter = null, string $zipUrl = 'https://cloud.test/agent/release/zip?v=9.9.9'): array
    {
        $tree = sys_get_temp_dir() . '/guard-rel-' . bin2hex(random_bytes(4));
        $zipPath = $tree . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname("$tree/$path"))) {
                mkdir(dirname("$tree/$path"), 0777, true);
            }
            file_put_contents("$tree/$path", $contents);
            $zip->addFromString("guard-agent-9.9.9/$path", $contents);
        }
        $zip->close();
        $bytes = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        $manifest = [
            'format' => 1,
            'version' => '9.9.9',
            'channel' => 'stable',
            'created' => '2026-10-27T00:00:00Z',
            'zip_sha256' => hash('sha256', $bytes),
            'files' => ReleaseVerifier::treeHashes($tree),
            'min_replaces' => '1.0.0',
            'rotate_keys' => null,
        ];
        $this->removeTree($tree);
        if ($alter !== null) {
            $manifest = $alter($manifest);
        }
        $signature = sodium_bin2hex(sodium_crypto_sign_detached(ReleaseVerifier::signingString($manifest), $this->signingSecret));

        return [(string) json_encode(['manifest' => $manifest, 'signature' => $signature, 'zip_url' => $zipUrl]), $bytes];
    }

    /** A fetcher serving one descriptor and one zip. */
    private function serving(string $descriptor, string $zip): callable
    {
        return static function (string $url) use ($descriptor, $zip): ?string {
            if (str_ends_with($url, '/agent/release/manifest')) {
                return $descriptor;
            }

            return str_starts_with($url, 'https://cloud.test/agent/release/zip') ? $zip : null;
        };
    }

    private function boot(callable $fetcher, ?string $key = null): AgentBootstrap
    {
        return new AgentBootstrap($this->gravRoot, $fetcher, $key ?? $this->signingPublic);
    }

    private function assertRefused(AgentBootstrap $boot, string $pattern): void
    {
        try {
            $boot->install('https://cloud.test');
            $this->fail('expected the install to be refused');
        } catch (BootstrapException $e) {
            $this->assertMatchesRegularExpression($pattern, $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->gravRoot . '/_guard', 'nothing may be unpacked into place');
        $this->assertSame([], glob($this->gravRoot . '/.guard-install-*') ?: [], 'staging is cleaned up');
    }

    public function testRefusesAReleaseTheReleaseKeyDidNotSign(): void
    {
        [$descriptor, $zip] = $this->release(['agent.php' => '<?php // evil']);

        // Production's pinned key, not the test key that signed it.
        $this->assertRefused(new AgentBootstrap($this->gravRoot, $this->serving($descriptor, $zip)), '/not signed by the Guard release key/');
    }

    public function testRefusesAZipThatDoesNotMatchTheSignedHash(): void
    {
        [$descriptor] = $this->release(['agent.php' => '<?php // real']);

        $this->assertRefused($this->boot($this->serving($descriptor, 'this is not the real agent zip')), '/sha256 mismatch/i');
    }

    public function testRefusesAZipServedFromAnotherHost(): void
    {
        [$descriptor, $zip] = $this->release(['agent.php' => '<?php'], null, 'https://evil.test/agent.zip');

        $this->assertRefused($this->boot(static function (string $url) use ($descriptor, $zip): ?string {
            return str_ends_with($url, '/agent/release/manifest') ? $descriptor : $zip;
        }), '/not served from/');
    }

    public function testRefusesATreeThatDoesNotMatchTheSignedManifest(): void
    {
        // Signed, hash-correct zip — but it carries a file the manifest omits.
        [$descriptor, $zip] = $this->release(
            ['agent.php' => '<?php', 'src/autoload.php' => '<?php', 'extra.php' => '<?php // smuggled'],
            static function (array $m): array {
                unset($m['files']['extra.php']);

                return $m;
            }
        );

        $this->assertRefused($this->boot($this->serving($descriptor, $zip)), '/does not match its signed manifest/');
    }

    /**
     * A genuinely signed release below the security floor is refused: the
     * cloud could otherwise install 1.0.3 and use its holes once paired.
     */
    public function testRefusesASignedReleaseBelowTheSecurityFloor(): void
    {
        [$descriptor, $zip] = $this->release(['agent.php' => '<?php'], static function (array $m): array {
            $m['version'] = '1.0.3';

            return $m;
        });

        $this->assertRefused($this->boot($this->serving($descriptor, $zip)), '/older than the minimum secure version 1\.0\.4/');
    }

    /** A redirect from the cloud stops the install, and says what happened. */
    public function testARedirectFromTheCloudStopsTheInstall(): void
    {
        $redirecting = static function (string $url): ?string {
            throw new BootstrapException("Guard Cloud answered $url with a redirect (HTTP 302).");
        };

        $this->assertRefused($this->boot($redirecting), '/with a redirect \(HTTP 302\)/');
        // ...while the version badge just reports "unknown", as for any failure
        $this->assertNull($this->boot($redirecting)->latestAgentVersion('https://cloud.test'));
    }

    /**
     * The default fetcher never follows a redirect, on curl or the stream
     * wrapper: a 3xx is a failure with its own message. It also only speaks
     * https, so an http URL is not even requested.
     */
    public function testTheDefaultFetcherRefusesRedirectsAndPlainHttp(): void
    {
        $refuse = new \ReflectionMethod(AgentBootstrap::class, 'refuseRedirect');
        $refuse->setAccessible(true);
        foreach ([301, 302, 307, 308] as $status) {
            try {
                $refuse->invoke(null, 'https://cloud.test/agent/release/manifest', $status);
                $this->fail("HTTP $status must be refused");
            } catch (BootstrapException $e) {
                $this->assertStringContainsString("redirect (HTTP $status)", $e->getMessage());
            }
        }
        $refuse->invoke(null, 'https://cloud.test/x', 200);

        $get = new \ReflectionMethod(AgentBootstrap::class, 'httpGet');
        $get->setAccessible(true);
        $this->assertNull($get->invoke(null, 'http://127.0.0.1:1/agent/release/manifest'));
        $this->assertNull($get->invoke(null, 'file:///etc/passwd'));

        $src = (string) file_get_contents(__DIR__ . '/../classes/AgentBootstrap.php');
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $src);
        $this->assertStringContainsString("'follow_location' => 0", $src);
        $this->assertStringContainsString('CURLOPT_PROTOCOLS => CURLPROTO_HTTPS', $src);
    }

    public function testMalformedManifestIsRejected(): void
    {
        $fetcher = static fn(string $url): ?string => 'not json';
        $this->expectException(BootstrapException::class);
        $this->boot($fetcher)->install('https://cloud.test');
    }

    public function testUnreachableCloudIsRejected(): void
    {
        $fetcher = static fn(string $url): ?string => null;
        $this->expectException(BootstrapException::class);
        $this->boot($fetcher)->install('https://cloud.test');
    }

    public function testFullInstallAndPair(): void
    {
        $agentSrc = realpath(__DIR__ . '/../../agent');
        if ($agentSrc === false || !is_file($agentSrc . '/agent.php')) {
            $this->markTestSkipped('agent source not available beside the helper repo');
        }
        if (!extension_loaded('sodium') || !extension_loaded('zip')) {
            $this->markTestSkipped('ext-sodium/ext-zip required');
        }

        [$descriptor, $zip] = $this->release($this->agentFiles($agentSrc));
        $boot = $this->boot($this->serving($descriptor, $zip));
        $this->assertFalse($boot->isInstalled());

        $res = $boot->install('https://cloud.test', 'https://example.com/blog/');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}$/', $res['code']);
        $this->assertSame('/_guard/agent.php', $res['endpoint_path']);
        $this->assertSame(900, $res['ttl']);
        $this->assertNotEmpty($res['key_id']);
        $this->assertTrue($boot->isInstalled());

        // key is PHP-guarded (a direct request would execute → emit nothing)
        $this->assertStringStartsWith('<?php', (string) file_get_contents($this->gravRoot . '/_guard/keys/agent.key.php'));

        // config pins the right grav_root + cloud_url + site_url + a randomized db name
        $cfg = include $this->gravRoot . '/_guard/data/config.php';
        $this->assertSame('https://cloud.test', $cfg['cloud_url']);
        $this->assertSame($this->gravRoot, $cfg['grav_root']);
        $this->assertSame('https://example.com/blog', $cfg['site_url']);
        $this->assertNotEmpty($cfg['db']);
        $this->assertFileExists($this->gravRoot . '/_guard/data/' . $cfg['db']);

        // a second install is refused (don't clobber a paired agent)
        $this->expectException(BootstrapException::class);
        $boot->install('https://cloud.test');
    }

    /**
     * Agents installed before 1.0.4 have no site_url, and their bin/pair
     * ignores --site-url. "Show pairing code" (repair) is how they get one:
     * it writes the URL into the existing config and leaves everything else,
     * including the key, alone. A URL already there is not replaced.
     */
    public function testRepairWritesTheSiteUrlIntoAnExistingAgent(): void
    {
        $agentSrc = realpath(__DIR__ . '/../../agent');
        if ($agentSrc === false || !is_file($agentSrc . '/agent.php')) {
            $this->markTestSkipped('agent source not available beside the helper repo');
        }

        [$descriptor, $zip] = $this->release($this->agentFiles($agentSrc));
        $boot = $this->boot($this->serving($descriptor, $zip));
        $boot->install('https://cloud.test');
        $before = include $this->gravRoot . '/_guard/data/config.php';
        $this->assertArrayNotHasKey('site_url', $before, 'an install without a URL, like a pre-1.0.4 agent');
        $key = (string) file_get_contents($this->gravRoot . '/_guard/keys/agent.key.php');

        $res = $boot->repair('https://example.com/');

        $after = include $this->gravRoot . '/_guard/data/config.php';
        $this->assertSame('https://example.com', $after['site_url']);
        $this->assertSame($before, array_diff_key($after, ['site_url' => true]), 'nothing else in the config changes');
        $this->assertSame($key, (string) file_get_contents($this->gravRoot . '/_guard/keys/agent.key.php'));
        $this->assertNotEmpty($res['code']);

        $boot->repair('https://other.example');
        $this->assertSame('https://example.com', (include $this->gravRoot . '/_guard/data/config.php')['site_url']);
    }

    /** @return array<string,string> the files an install needs, relative to the agent root */
    private function agentFiles(string $agentSrc): array
    {
        $files = [
            'agent.php' => (string) file_get_contents($agentSrc . '/agent.php'),
            'tick.php' => (string) file_get_contents($agentSrc . '/tick.php'),
        ];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($agentSrc . '/src', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($agentSrc) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /** The version people need when something misbehaves, read from the agent itself. */
    public function testReportsTheInstalledAgentVersion(): void
    {
        $boot = new AgentBootstrap($this->gravRoot);
        $this->assertNull($boot->agentVersion(), 'nothing installed yet');

        mkdir($this->gravRoot . '/_guard/src', 0777, true);
        file_put_contents(
            $this->gravRoot . '/_guard/src/Version.php',
            "<?php\nfinal class Version { public const VERSION = '0.1.34-dev'; }\n"
        );

        $this->assertSame('0.1.34-dev', $boot->agentVersion());
    }

    /** Write a release tree under _guard/releases and point `current` at it. */
    private function stageRelease(string $version, string $pointer): void
    {
        $dir = $this->gravRoot . '/_guard/releases/v' . $version;
        mkdir($dir . '/src', 0777, true);
        file_put_contents(
            $dir . '/src/Version.php',
            "<?php\nfinal class Version { public const VERSION = '$version'; }\n"
        );
        file_put_contents($this->gravRoot . '/_guard/current.pointer', $pointer);
    }

    /** The flat tree, as a freshly installed agent leaves it. */
    private function stageBootstrap(string $version = '0.1.34-dev'): void
    {
        mkdir($this->gravRoot . '/_guard/src', 0777, true);
        file_put_contents(
            $this->gravRoot . '/_guard/src/Version.php',
            "<?php\nfinal class Version { public const VERSION = '$version'; }\n"
        );
    }

    /**
     * After a self-update the flat src/ is only the bootstrap — the live agent
     * lives in releases/vX.Y.Z. Reading the flat tree reports the version the
     * site was INSTALLED with forever after, which is the one number this
     * screen exists to answer.
     */
    public function testReportsTheRunningReleaseNotTheBootstrap(): void
    {
        $this->stageBootstrap();
        $this->stageRelease('1.0.0', 'releases/v1.0.0');

        $this->assertSame('1.0.0', (new AgentBootstrap($this->gravRoot))->agentVersion());
    }

    /**
     * The pointer is a writable file that decides which code runs, so it gets
     * the same containment boot.php applies. One that escapes, or names a
     * directory that is not there, falls back to the bootstrap rather than
     * reading whatever it aimed at.
     */
    public function testARogueOrDanglingPointerFallsBackToTheFlatTree(): void
    {
        $this->stageBootstrap();
        // Somewhere an attacker would like it to look, staged so that the only
        // thing stopping the read is the containment check itself.
        mkdir($this->gravRoot . '/evil/src', 0777, true);
        file_put_contents(
            $this->gravRoot . '/evil/src/Version.php',
            "<?php\nfinal class Version { public const VERSION = '9.9.9'; }\n"
        );

        $boot = new AgentBootstrap($this->gravRoot);
        foreach (['releases/../../evil', '/etc', '../evil', 'releases/v2.0.0', ''] as $pointer) {
            file_put_contents($this->gravRoot . '/_guard/current.pointer', $pointer);
            $this->assertSame('0.1.34-dev', $boot->agentVersion(), "pointer: $pointer");
        }
    }

    /**
     * A cloud we cannot reach must report "unknown", never "current" — the
     * whole point of the badge is to be trusted when it says nothing is wrong.
     */
    public function testUnknownLatestIsNotTreatedAsUpToDate(): void
    {
        $this->assertNull($this->boot(static fn(string $u): ?string => null)->latestAgentVersion('https://cloud.test'));
        $this->assertNull($this->boot(static fn(string $u): ?string => 'not json')->latestAgentVersion('https://cloud.test'));
        $this->assertNull($this->boot(static fn(string $u): ?string => '{"version":"9.9.9"}')->latestAgentVersion('https://cloud.test'));

        // a version nobody signed is not "latest" either
        [$descriptor] = $this->release(['agent.php' => '<?php']);
        $unsigned = new AgentBootstrap($this->gravRoot, static fn(string $u): ?string => $descriptor);
        $this->assertNull($unsigned->latestAgentVersion('https://cloud.test'));
    }

    public function testReadsTheLatestVersionFromTheSignedDescriptor(): void
    {
        [$descriptor] = $this->release(['agent.php' => '<?php']);
        $seen = null;
        $boot = $this->boot(static function (string $url) use (&$seen, $descriptor): ?string {
            $seen = $url;

            return $descriptor;
        });

        $this->assertSame('9.9.9', $boot->latestAgentVersion('https://cloud.test/'));
        $this->assertSame(
            'https://cloud.test/agent/release/manifest',
            $seen,
            'the same descriptor a fresh install verifies, so "latest" means what a new install gets'
        );
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
