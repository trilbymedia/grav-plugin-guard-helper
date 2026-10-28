<?php

// One "request" for SchedulerWebhookTest::testConcurrentSetupsAgreeOnOneToken:
// runs SchedulerWebhook::ensure() against the Grav root in $argv[1], with a
// slow write so unlocked requests would overlap, and prints what it saw.

declare(strict_types=1);

use Grav\Plugin\GuardHelper\SchedulerWebhook;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $root, $lockFile] = $argv;
$writes = 0;

$webhook = new SchedulerWebhook(
    $root,
    [],
    ['managed' => true, 'core' => true, 'plugin_enabled' => false],
    static function (string $file): array {
        $data = include $file;

        return is_array($data) ? $data : [];
    },
    static function (string $file, array $data) use (&$writes): void {
        $writes++;
        usleep(200000);
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
    },
    static function (string $file): array {
        $data = include $file;

        return is_array($data) ? $data : [];
    },
    $lockFile,
    20000
);

$res = $webhook->ensure();
$file = $root . '/user/config/scheduler.yaml';
$saved = is_file($file) ? include $file : [];

echo json_encode([
    'state' => $res['state'],
    'writes' => $writes,
    'token' => $saved['modern']['webhook']['token'] ?? null,
]);
