<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use FilesystemIterator;
use Grav\Plugin\GuardHelper\AgentBootstrap;
use Grav\Plugin\GuardHelper\BootstrapException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * The browser-only agent bootstrap: it must REFUSE a tampered release (sha256
 * is the integrity boundary), and on a good release unpack the agent into
 * _guard/ and pair it in-process — producing a code with no shell access.
 */
final class AgentBootstrapTest extends TestCase
{
    /** @var string */
    private $gravRoot;

    protected function setUp(): void
    {
        $this->gravRoot = sys_get_temp_dir() . '/guard-helper-' . bin2hex(random_bytes(4));
        mkdir($this->gravRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->gravRoot);
    }

    public function testRefusesTamperedRelease(): void
    {
        $fetcher = static function (string $url): ?string {
            if (str_ends_with($url, 'release.json')) {
                return json_encode(['zip_url' => 'https://cloud.test/install/agent.zip', 'zip_sha256' => str_repeat('0', 64)]);
            }
            return 'this is not the real agent zip';
        };

        $boot = new AgentBootstrap($this->gravRoot, $fetcher);
        $this->expectException(BootstrapException::class);
        $this->expectExceptionMessageMatches('/sha256 mismatch/i');
        $boot->install('https://cloud.test');
    }

    public function testMalformedManifestIsRejected(): void
    {
        $fetcher = static fn(string $url): ?string => 'not json';
        $this->expectException(BootstrapException::class);
        (new AgentBootstrap($this->gravRoot, $fetcher))->install('https://cloud.test');
    }

    public function testUnreachableCloudIsRejected(): void
    {
        $fetcher = static fn(string $url): ?string => null;
        $this->expectException(BootstrapException::class);
        (new AgentBootstrap($this->gravRoot, $fetcher))->install('https://cloud.test');
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

        $zipBytes = $this->buildAgentZip($agentSrc);
        $sha = hash('sha256', $zipBytes);
        $fetcher = static function (string $url) use ($zipBytes, $sha): ?string {
            if (str_ends_with($url, 'release.json')) {
                return json_encode(['zip_url' => 'https://cloud.test/install/agent.zip', 'zip_sha256' => $sha]);
            }
            if (str_ends_with($url, 'agent.zip')) {
                return $zipBytes;
            }
            return null;
        };

        $boot = new AgentBootstrap($this->gravRoot, $fetcher);
        $this->assertFalse($boot->isInstalled());

        $res = $boot->install('https://cloud.test');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}$/', $res['code']);
        $this->assertSame('/_guard/agent.php', $res['endpoint_path']);
        $this->assertSame(900, $res['ttl']);
        $this->assertNotEmpty($res['key_id']);
        $this->assertTrue($boot->isInstalled());

        // key is PHP-guarded (a direct request would execute → emit nothing)
        $this->assertStringStartsWith('<?php', (string) file_get_contents($this->gravRoot . '/_guard/keys/agent.key.php'));

        // config pins the right grav_root + cloud_url + a randomized db name
        $cfg = include $this->gravRoot . '/_guard/data/config.php';
        $this->assertSame('https://cloud.test', $cfg['cloud_url']);
        $this->assertSame($this->gravRoot, $cfg['grav_root']);
        $this->assertNotEmpty($cfg['db']);
        $this->assertFileExists($this->gravRoot . '/_guard/data/' . $cfg['db']);

        // a second install is refused (don't clobber a paired agent)
        $this->expectException(BootstrapException::class);
        $boot->install('https://cloud.test');
    }

    private function buildAgentZip(string $agentSrc): string
    {
        $tmp = sys_get_temp_dir() . '/agentzip-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $prefix = 'guard-agent/'; // single top-level dir to exercise flattening
        $zip->addFile($agentSrc . '/agent.php', $prefix . 'agent.php');
        $zip->addFile($agentSrc . '/tick.php', $prefix . 'tick.php');
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($agentSrc . '/src', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), strlen($agentSrc) + 1);
                $zip->addFile($file->getPathname(), $prefix . $rel);
            }
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
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
