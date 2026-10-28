<?php

// One "request" for SchedulerWebhookTest / AgentBootstrapTest: optionally
// registers an agent autoloader first (as another part of the plugin would
// have), then asks SchedulerWebhook::agentReader() or AgentBootstrap for the
// agent's code, and prints which copy of the agent answered.
//
//   php agent-reader-worker.php reader <autoload> <file> [<preloaded autoload>]
//   php agent-reader-worker.php cron   <grav root>

declare(strict_types=1);

use Grav\Plugin\GuardHelper\AgentBootstrap;
use Grav\Plugin\GuardHelper\SchedulerWebhook;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';

if ($mode === 'reader') {
    [, , $autoload, $file] = $argv;
    if (isset($argv[4])) {
        require_once $argv[4];
        class_exists(\GravGuard\Agent\Support\Yaml::class);
    }
    echo json_encode(['read' => (SchedulerWebhook::agentReader($autoload))($file)]);
} elseif ($mode === 'cron') {
    $status = (new AgentBootstrap($argv[2]))->cronStatus();
    echo json_encode([
        'status' => $status,
        'autoloaders' => array_map(static function ($loader) {
            return is_array($loader) ? get_class($loader[0]) : 'closure';
        }, spl_autoload_functions()),
        'included' => array_values(array_filter(get_included_files(), static function (string $f): bool {
            return substr($f, -strlen('/src/autoload.php')) === '/src/autoload.php';
        })),
    ]);
}
