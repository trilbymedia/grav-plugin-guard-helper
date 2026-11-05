<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Common\Grav;
use Grav\Plugin\GuardHelper\LookupCache;
use Grav\Plugin\GuardHelper\Security\AdvisoryFeed;
use Grav\Plugin\GuardHelper\Security\SecurityChecker;
use PHPUnit\Framework\TestCase;

/**
 * How the checker learns the installed packages and where it gets the feed:
 * names and versions come from a line read of each blueprint, with a YAML
 * parse only for a file that read cannot be sure of, and the stored-only run
 * the dashboard banner uses never goes to Guard Cloud.
 */
final class SecurityCheckerPackagesTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        if (!class_exists(Grav::class, false)) {
            eval('namespace Grav\Common; class Grav implements \ArrayAccess {
                public $items = [];
                public function offsetExists($k): bool { return isset($this->items[$k]); }
                public function offsetGet($k) { return $this->items[$k]; }
                public function offsetSet($k, $v): void { $this->items[$k] = $v; }
                public function offsetUnset($k): void { unset($this->items[$k]); }
            }');
        }
        if (!class_exists(\Grav\Common\Yaml::class, false)) {
            // Stands in for Grav's parser: records the call and answers from a table the tests fill.
            eval('namespace Grav\Common; class Yaml {
                public static $parsed = [];
                public static $answers = [];
                public static function parse($src) { self::$parsed[] = $src; return self::$answers[$src] ?? null; }
            }');
        }
        \Grav\Common\Yaml::$parsed = [];
        \Grav\Common\Yaml::$answers = [];
        $this->dir = sys_get_temp_dir() . '/guard-checker-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/plugins', 0777, true);
        mkdir($this->dir . '/themes', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/*/*/*') ?: [], glob($this->dir . '/*/*') ?: [], glob($this->dir . '/*') ?: []) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function checker(?LookupCache $cache = null): SecurityChecker
    {
        $dir = $this->dir;
        $grav = new Grav();
        $grav['locator'] = new class($dir) {
            /** @var string */
            private $dir;

            public function __construct(string $dir)
            {
                $this->dir = $dir;
            }

            public function findResource(string $uri)
            {
                return $this->dir . '/' . substr($uri, strlen('user://'));
            }
        };

        return new SecurityChecker($grav, $cache);
    }

    private function blueprint(string $type, string $slug, string $src): void
    {
        mkdir($this->dir . "/$type/$slug", 0777, true);
        file_put_contents($this->dir . "/$type/$slug/blueprints.yaml", $src);
    }

    /** @return array<string,array<string,string>> installed packages by "type:slug" */
    private function packages(SecurityChecker $checker): array
    {
        $by = [];
        foreach ($checker->installedPackages() as $pkg) {
            if ($pkg['type'] !== 'core') {
                $by[$pkg['type'] . ':' . $pkg['slug']] = $pkg;
            }
        }
        ksort($by);

        return $by;
    }

    public function testNamesAndVersionsAreReadWithoutParsingYaml(): void
    {
        $this->blueprint('plugins', 'form', "name: Form\nslug: form\nversion: 7.1.0\nform:\n  fields:\n    a:\n      name: nope\n      version: 0.0.1\n");
        $this->blueprint('themes', 'quark', "name: 'Quark'\nversion: \"2.0.0\"\n");

        $packages = $this->packages($this->checker());
        $this->assertSame('7.1.0', $packages['plugin:form']['version']);
        $this->assertSame('Form', $packages['plugin:form']['name']);
        $this->assertSame(['type' => 'theme', 'slug' => 'quark', 'version' => '2.0.0', 'name' => 'Quark'], $packages['theme:quark']);
        $this->assertSame([], \Grav\Common\Yaml::$parsed, 'nothing needed the YAML parser');
    }

    public function testAFileTheLineReaderCannotBeSureOfIsParsedAsYaml(): void
    {
        // A version YAML reads as a number is not a string, so the package is left out, as before.
        $this->blueprint('plugins', 'numeric', "name: Numeric\nversion: 1.0\n");
        // No name at all: the slug stands in for it.
        $this->blueprint('plugins', 'nameless', "version: 3.2.1\n");
        $this->blueprint('plugins', 'plain', "name: Plain\nversion: 1.2.3\n");
        \Grav\Common\Yaml::$answers["name: Numeric\nversion: 1.0\n"] = ['name' => 'Numeric', 'version' => 1.0];
        \Grav\Common\Yaml::$answers["version: 3.2.1\n"] = ['version' => '3.2.1'];

        $packages = $this->packages($this->checker());
        $this->assertSame(['plugin:nameless', 'plugin:plain'], array_keys($packages));
        $this->assertSame('nameless', $packages['plugin:nameless']['name']);
        $this->assertSame('3.2.1', $packages['plugin:nameless']['version']);
        $this->assertCount(2, \Grav\Common\Yaml::$parsed, 'only the two files that needed it were parsed');
    }

    public function testAPackageWithoutAVersionIsLeftOut(): void
    {
        $this->blueprint('plugins', 'noversion', "name: No Version\n");
        $this->assertSame([], $this->packages($this->checker()));
    }

    public function testTheStoredOnlyRunNeverAsksGuardCloud(): void
    {
        $url = 'http://127.0.0.1:9/feed/advisories.json';
        $cache = new LookupCache($this->dir . '/cache');
        $checker = $this->checker($cache);

        $started = microtime(true);
        $result = $checker->run($url, false, false);
        $this->assertLessThan(1.0, microtime(true) - $started, 'no fetch was tried');
        $this->assertSame('pending', $result['status']);
        $this->assertSame([], $result['findings']);
        $this->assertSame([], glob($this->dir . '/cache/*') ?: [], 'nothing was stored by looking');

        // With a copy stored, it is used.
        $cache->put('advisory-feed|' . $url, true, [
            ['type' => 'plugin', 'slug' => 'form', 'affected' => '<8.0', 'severity' => 'high', 'title' => 'x'],
        ], AdvisoryFeed::TTL);
        $this->blueprint('plugins', 'form', "name: Form\nversion: 7.1.0\n");
        $result = $checker->run($url, false, false);
        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['findings']);
    }

    public function testAStoredFailureRaisesNoFinding(): void
    {
        $url = 'http://127.0.0.1:9/feed/advisories.json';
        $cache = new LookupCache($this->dir . '/cache');
        $cache->put('advisory-feed|' . $url, false, null, AdvisoryFeed::FAILURE_TTL);

        $result = $this->checker($cache)->run($url, false, false);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame([], $result['findings']);
    }
}
