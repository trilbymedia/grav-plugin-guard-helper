<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Two rules about guard-helper.php that cost a page load when broken and that
 * nothing else here would notice, since the file needs Grav to load. Checked
 * on the source, as ImportsTest does.
 */
final class PluginWiringTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/../guard-helper.php');
    }

    /** Grav calls autoload() itself on loading a plugin, so an event for it registers the loader a second time. */
    public function testTheAutoloaderIsNotAlsoRegisteredThroughAnEvent(): void
    {
        $this->assertStringContainsString('public function autoload(): void', $this->source());
        $this->assertDoesNotMatchRegularExpression("/=>\\s*\\[+\\s*'autoload'/", $this->source());
        $this->assertSame(1, preg_match_all('/spl_autoload_register\\(/', $this->source()));
    }

    /** The dashboard notification runs on every load, so it reads the stored feed only. */
    public function testTheDashboardBannerNeverFetchesTheFeed(): void
    {
        $src = $this->source();
        $start = strpos($src, 'function onApiDashboardNotifications');
        $end = strpos($src, '// ── Scheduler webhook', (int) $start);
        $this->assertNotFalse($start);
        $body = substr($src, (int) $start, (int) $end - (int) $start);

        $this->assertSame(1, preg_match_all('/->run\\(/', $body));
        $this->assertMatchesRegularExpression('/->run\\(\\$feedUrl, false, false\\)/', $body);
    }
}
