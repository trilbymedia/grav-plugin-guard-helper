<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

/**
 * Grav's scheduler webhook, set up so the Guard Agent can run the site's
 * scheduler on hosts where it cannot start a process.
 *
 * Guard Cloud runs a site's Grav scheduler by sending the agent a signed
 * `scheduler.trigger`. Those arrive as a web request into _guard/agent.php,
 * and under PHP-FPM proc_open/exec are usually disabled, so the agent cannot
 * run `bin/grav scheduler`. Its only fallback is Grav's own scheduler webhook:
 * it reads `modern.webhook.{enabled,token,path}` from
 * user/config/scheduler.yaml (that file only, with its own small YAML reader)
 * and POSTs to `<site_url><path>` with the token in `X-Webhook-Token`.
 *
 * What Grav itself provides (checked against the 1.7 and 2.x source):
 *   - Core holds the settings and Scheduler::processWebhookTrigger() (1.7.49
 *     and newer), but no route. The route comes from the scheduler-webhook
 *     plugin, which answers `/scheduler/webhook` only, whatever
 *     `modern.webhook.path` says; that setting is read by nothing in Grav.
 *   - scheduler-webhook before 1.1.1 also needed `scheduler.modern.enabled`,
 *     a switch Grav 2 dropped; 1.1.1 and newer do not look at it.
 *   - A webhook call runs the jobs that are due: on 1.7 and 2.0 only those due
 *     this minute, on 2.1 and newer also slots missed since the last run.
 *
 * So the job here is to make the three readers agree: Grav's merged config
 * (system ← user/config ← the environment folder, if one exists for this
 * host), the agent's view of user/config/scheduler.yaml, and the route
 * actually being answered. When the scheduler-webhook plugin is not enabled,
 * Guard Helper answers that one route itself (see GuardHelperPlugin), so a
 * site needs no other plugin.
 *
 * Writing rules:
 *   - a token the site already has is never replaced; one is generated only
 *     when there is none, from random_bytes (256 bits, hex, as Grav's own
 *     admin generates them)
 *   - a token shorter than MIN_TOKEN_LENGTH is not switched on: it is the
 *     owner's, and Guard will not open an endpoint behind it
 *   - only the webhook keys change; every other scheduler setting is kept
 *   - environment config files are updated only where they already set one
 *     of these keys (Grav would read our value from there, not user/config);
 *     no environment file is ever created
 *   - nothing is written unless every file that has to change is writable,
 *     and a file Grav cannot parse is never rewritten
 *   - the whole read-decide-write runs under one exclusive lock, with the
 *     files read again inside it, so two requests cannot each generate a
 *     token and have the second save discard the first's token or any
 *     scheduler edit made in between. The lock file lives in user/data, which
 *     clearing Grav's cache never touches, and is never deleted
 *   - Grav's own config saves do not take that lock, so each file is also
 *     fingerprinted when it is read and compared again just before it is
 *     written; a file that changed in between starts the whole decision over
 *     (WRITE_ATTEMPTS times), and nothing is written over a change Guard
 *     has not seen
 *   - the base file's path is checked and repaired on its own: the agent
 *     reads only user/config/scheduler.yaml, so a correct path in an
 *     environment file does not make a wrong one there harmless
 *
 * The token never leaves the server. Guard Cloud does not see it: the agent
 * reads it from the file on the site and sends it to the site.
 */
final class SchedulerWebhook
{
    /** The one route the scheduler-webhook plugin (and Guard Helper) answers. */
    public const ROUTE = '/scheduler/webhook';

    /** 128 bits as hex. Grav's admin generates 64 characters; so does this. */
    public const MIN_TOKEN_LENGTH = 32;

    public const OFFICIAL_PLUGIN = 'scheduler-webhook';

    /** The first scheduler-webhook release that works without `scheduler.modern.enabled`. */
    public const OFFICIAL_PLUGIN_MIN = '1.1.1';

    /** Where the agent reads the webhook settings, relative to the Grav root. */
    public const BASE_FILE = 'user/config/scheduler.yaml';

    /** How long ensure() waits for another request's lock before giving up. */
    public const LOCK_WAIT_MS = 5000;

    /** How many times ensure() starts over when a file changes under it before it writes. */
    public const WRITE_ATTEMPTS = 3;

    /** @var string */
    private $gravRoot;

    /** @var string[] absolute paths of environment scheduler.yaml files, highest priority first */
    private $overrides;

    /** @var array<string,mixed> */
    private $env;

    /** @var callable(string):array parse a YAML file; throws when it cannot */
    private $read;

    /** @var callable(string,array):void write a YAML file; throws on failure */
    private $write;

    /** @var callable(string):?array|null the agent's own reading of a YAML file; returns null when the agent's reader cannot be loaded */
    private $agentRead;

    /** @var string|null file flock()ed around ensure()'s read-modify-write */
    private $lockFile;

    /** @var int */
    private $lockWaitMs;

    /**
     * @param string[] $overrides environment scheduler.yaml files that exist, highest priority first
     * @param array<string,mixed> $env
     *   managed: bool          Guard Helper's `scheduler_webhook` setting
     *   core: bool             core has Scheduler::processWebhookTrigger()
     *   plugin_enabled: bool   scheduler-webhook installed and enabled
     *   plugin_version: ?string
     *   unsupported: ?string   a reason this site cannot use it at all
     */
    public function __construct(
        string $gravRoot,
        array $overrides,
        array $env,
        callable $read,
        callable $write,
        ?callable $agentRead = null,
        ?string $lockFile = null,
        int $lockWaitMs = self::LOCK_WAIT_MS
    ) {
        $this->gravRoot = rtrim($gravRoot, '/');
        $this->overrides = array_values($overrides);
        $this->env = $env + [
            'managed' => true,
            'core' => true,
            'plugin_enabled' => false,
            'plugin_version' => null,
            'unsupported' => null,
        ];
        $this->read = $read;
        $this->write = $write;
        $this->agentRead = $agentRead;
        $this->lockFile = $lockFile ?? sys_get_temp_dir() . '/guard-helper-scheduler-' . md5($this->gravRoot) . '.lock';
        $this->lockWaitMs = max(0, $lockWaitMs);
    }

    /**
     * Whether the official scheduler-webhook plugin is there to answer the
     * route: installed AND enabled AND actually loaded by Grav. The one check
     * both Guard Helper's route handler and status() use, so they cannot
     * disagree about who the endpoint is (a stale `enabled: true` left behind
     * after the plugin was deleted must not silence Guard Helper).
     *
     * @param \Grav\Common\Grav $grav
     */
    public static function officialPluginActive($grav): bool
    {
        $name = self::OFFICIAL_PLUGIN;
        if (!is_dir(rtrim(GRAV_ROOT, '/') . '/user/plugins/' . $name)) {
            return false;
        }
        if (!(bool) $grav['config']->get('plugins.' . $name . '.enabled', false)) {
            return false;
        }

        // Grav 1.7 and 2.x both keep the loaded plugin objects in
        // $grav['plugins'], each with its folder name in ->name.
        try {
            foreach ($grav['plugins'] as $plugin) {
                if (is_object($plugin) && ($plugin->name ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // fall through to the class check
        }

        return class_exists('Grav\\Plugin\\SchedulerWebhookPlugin', false);
    }

    /**
     * The agent's autoloader for the release it is running, resolved as the
     * agent's own boot.php does: a `current` symlink, else a `current.pointer`
     * file, naming a home-relative `releases/...` directory; no usable pointer
     * means a flat install, whose code is `_guard/src`. A path that does not
     * stay inside `_guard/` once links are resolved is refused.
     */
    public static function resolveAgentAutoload(string $gravRoot): ?string
    {
        $home = rtrim($gravRoot, '/') . '/_guard';
        $homeReal = realpath($home);
        if ($homeReal === false) {
            return null;
        }

        $pointer = null;
        $link = $home . '/current';
        if (is_link($link)) {
            $target = @readlink($link);
            if (is_string($target) && $target !== '') {
                $pointer = $target;
            }
        }
        if ($pointer === null && is_file($home . '/current.pointer')) {
            $pointer = trim((string) @file_get_contents($home . '/current.pointer'));
        }

        $candidates = [];
        if ($pointer !== null && $pointer !== ''
            && strpos($pointer, "\0") === false
            && $pointer[0] !== '/'
            && strpos($pointer, '\\') === false
            && !in_array('..', explode('/', $pointer), true)
            && strpos($pointer, 'releases/') === 0) {
            $candidates[] = $home . '/' . $pointer . '/src/autoload.php';
        }
        // No pointer, or it does not resolve: the flat tree is the live
        // release, which is also what the agent falls back to.
        $candidates[] = $home . '/src/autoload.php';

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real !== false && strpos($real, $homeReal . '/') === 0) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Wired to the running Grav: its environment folders, the
     * scheduler-webhook plugin, Grav's YAML classes, and the agent's own YAML
     * reader when the agent is installed.
     *
     * @param \Grav\Common\Grav $grav
     */
    public static function forGrav($grav, bool $managed): self
    {
        $root = rtrim(GRAV_ROOT, '/');
        $base = $root . '/' . self::BASE_FILE;
        $locator = $grav['locator'];

        // Every scheduler.yaml Grav merges, highest priority first. Anything
        // that is neither system/ nor user/config/ is an environment folder.
        $overrides = [];
        $baseReal = realpath(dirname($base));
        foreach ((array) $locator->findResources('config://scheduler.yaml', true) as $file) {
            $file = (string) $file;
            $dirReal = realpath(dirname($file));
            if ($dirReal === false || $dirReal === $baseReal
                || $dirReal === realpath($root . '/system/config')) {
                continue;
            }
            $overrides[] = $file;
        }

        // The agent reads <root>/user/config, so a site whose user folder
        // lives elsewhere is out of its reach.
        $unsupported = null;
        $userConfig = $locator->findResource('user://config', true);
        if (is_string($userConfig) && realpath($userConfig) !== $baseReal) {
            $unsupported = 'custom_user_folder';
        }

        $pluginDir = $root . '/user/plugins/' . self::OFFICIAL_PLUGIN;
        $pluginVersion = null;
        if (is_file($pluginDir . '/blueprints.yaml')
            && preg_match('/^version:\s*[\'"]?([^\s\'"]+)/m', (string) @file_get_contents($pluginDir . '/blueprints.yaml'), $m)) {
            $pluginVersion = $m[1];
        }

        // The release the agent actually runs, not just a flat install.
        $agentAutoload = self::resolveAgentAutoload($root);

        // Not in cache://: clearing Grav's cache deletes the files there, and a
        // lock file deleted while one request holds it lets a second request
        // lock a new one. user/data is site data that is never cleared.
        $lockDir = null;
        try {
            $lockDir = $locator->findResource('user-data://guard-helper', true, true);
        } catch (\Throwable $e) {
            // no user-data stream on this Grav: use the folder it stands for
        }
        if (!is_string($lockDir) || $lockDir === '') {
            $lockDir = $root . '/user/data/guard-helper';
        }
        $lockFile = rtrim($lockDir, '/') . '/scheduler.lock';

        return new self(
            $root,
            $overrides,
            [
                'managed' => $managed,
                'core' => method_exists(\Grav\Common\Scheduler\Scheduler::class, 'processWebhookTrigger'),
                'plugin_enabled' => self::officialPluginActive($grav),
                'plugin_version' => $pluginVersion,
                'unsupported' => $unsupported,
            ],
            static function (string $file): array {
                $raw = @file_get_contents($file);
                if ($raw === false) {
                    throw new \RuntimeException('unreadable');
                }
                $data = trim($raw) === '' ? [] : \Grav\Common\Yaml::parse($raw);

                return is_array($data) ? $data : [];
            },
            static function (string $file, array $data): void {
                // The same class Grav's admin saves config with: locked,
                // written to a temporary file and renamed into place.
                $yaml = \Grav\Common\File\CompiledYamlFile::instance($file);
                $yaml->save($data);
                $yaml->free();
            },
            self::agentReader($agentAutoload),
            $lockFile
        );
    }

    /**
     * The agent's own YAML reader, from the autoloader of the release the agent
     * runs. Null (so the webhook is only ever `unverified`) when there is no
     * such autoloader, when it does not provide the reader, or when the reader
     * that is loaded came from somewhere else: a class loaded earlier in the
     * request from another copy of the agent would answer for a release the
     * agent is not running.
     *
     * @return callable(string):?array
     */
    public static function agentReader(?string $agentAutoload): callable
    {
        return static function (string $file) use ($agentAutoload): ?array {
            if ($agentAutoload === null) {
                return null;
            }
            require_once $agentAutoload;
            if (!class_exists(\GravGuard\Agent\Support\Yaml::class)) {
                return null;
            }

            // <release>/src/autoload.php: the reader has to live in that src/.
            $src = realpath(dirname($agentAutoload));
            $loaded = (new \ReflectionClass(\GravGuard\Agent\Support\Yaml::class))->getFileName();
            $loaded = is_string($loaded) ? realpath($loaded) : false;
            if ($src === false || $loaded === false || strpos($loaded, $src . DIRECTORY_SEPARATOR) !== 0) {
                return null;
            }

            return \GravGuard\Agent\Support\Yaml::load($file);
        };
    }

    /**
     * Where things stand. Never includes the token.
     *
     * state is one of:
     *   ready        on, with a token, and the agent and Grav agree
     *   unverified   set up and Grav reads it, but the agent's own reader could
     *                not be loaded to confirm the agent does
     *   off          not on, or no token
     *   mismatch     Grav and the agent would read different settings
     *   no_endpoint  on, but nothing on this site answers the route
     *   plugin_outdated  scheduler-webhook is older than 1.1.1
     *   conflict     user/config and an environment folder hold different tokens
     *   weak_token   the existing token is too short for Guard to switch it on
     *   unreadable   a scheduler.yaml Grav cannot parse
     *   unsupported  this Grav cannot run its scheduler from a webhook
     *   busy         (ensure() only) another request is changing the files
     *   lock_error   (ensure() only) Guard cannot create or take its lock file
     *   write_conflict  (ensure() only) a scheduler file changed while Guard was
     *                updating it, WRITE_ATTEMPTS times in a row; nothing was written
     *   not_writable (ensure() only) a file that has to change cannot be written
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        if (!$this->env['core']) {
            return $this->result('unsupported', ['reason' => 'grav_too_old']);
        }
        if ($this->env['unsupported'] !== null) {
            return $this->result('unsupported', ['reason' => (string) $this->env['unsupported']]);
        }

        try {
            $layers = $this->layers();
        } catch (\RuntimeException $e) {
            return $this->result('unreadable', ['file' => $e->getMessage()]);
        }

        $tokens = $this->tokens($layers);
        if ($tokens === null) {
            return $this->result('conflict', ['files' => $this->definingFiles($layers, 'token')]);
        }

        return $this->evaluate($layers);
    }

    /**
     * Switch the webhook on so the agent can use it, changing as little as
     * possible. Safe to call repeatedly: a site that is already right is not
     * written to.
     *
     * @return array<string,mixed> status(), plus `changed`
     */
    public function ensure(): array
    {
        $status = $this->status();
        if (!$this->env['managed'] || in_array($status['state'], ['ready', 'unsupported', 'unreadable', 'conflict'], true)) {
            return $status + ['changed' => false];
        }

        // Two requests (a double click, the plugin's setup page and the API)
        // must not each decide there is no token and each write one. So the
        // decision is made again from the files as they are once the lock is
        // ours, and a token found then is kept.
        $lock = $this->lock();
        if (!is_resource($lock)) {
            return ($lock === 'busy'
                ? $this->result('busy')
                : $this->result('lock_error', ['folder' => $this->relative(dirname($this->lockFile))])) + ['changed' => false];
        }

        try {
            // Grav's own config saves do not take our lock. If a file changes
            // between the read and the write, decide again from what is there.
            for ($attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++) {
                $res = $this->ensureLocked();
                if ($res !== null) {
                    return $res;
                }
            }

            return $this->result('write_conflict') + ['changed' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Take the exclusive lock, waiting up to the configured time. The lock
     * file is only ever opened, never deleted.
     *
     * Contention and a lock that cannot work are told apart, because only
     * the first is cured by trying again:
     *
     * @return resource|string the held lock; 'busy' when another request kept
     *   it for the whole wait; 'lock_error' when the lock file cannot be
     *   created or opened, or flock() fails for a reason other than the lock
     *   being held (a file system without lock support). Nothing is ever
     *   changed without the lock.
     */
    private function lock()
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            return 'lock_error';
        }
        $fh = @fopen($this->lockFile, 'c');
        if ($fh === false) {
            return 'lock_error';
        }
        $deadline = microtime(true) + $this->lockWaitMs / 1000;
        do {
            $wouldBlock = 0;
            if (@flock($fh, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return $fh;
            }
            if (!$wouldBlock) {
                // Failed, but not because someone else holds it.
                fclose($fh);

                return 'lock_error';
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        fclose($fh);

        return 'busy';
    }

    /**
     * One read-decide-write pass, inside the lock.
     *
     * @return array<string,mixed>|null null when a file changed after it was
     *   read and before it could be written, so nothing (more) was written
     *   and the caller should decide again
     */
    private function ensureLocked(): ?array
    {
        // Everything below reads the files afresh, inside the lock.
        $status = $this->status();
        if (in_array($status['state'], ['ready', 'unsupported', 'unreadable', 'conflict'], true)) {
            return $status + ['changed' => false];
        }

        try {
            $layers = $this->layers();
        } catch (\RuntimeException $e) {
            return $this->result('unreadable', ['file' => $e->getMessage()]) + ['changed' => false];
        }
        $token = $this->tokens($layers);
        if ($token === null) {
            return $this->result('conflict', ['files' => $this->definingFiles($layers, 'token')]) + ['changed' => false];
        }

        if ($token !== '' && strlen($token) < self::MIN_TOKEN_LENGTH) {
            // The owner's token, so it is not replaced; and Guard will not
            // open an endpoint behind a short one. If they switched it on
            // themselves, that stays their call.
            return ($status['state'] === 'off' ? $this->result('weak_token') : $status) + ['changed' => false];
        }
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
        }

        $changes = [];
        foreach ($layers as $i => $layer) {
            $data = $layer['data'];
            $webhook = isset($data['modern']['webhook']) && is_array($data['modern']['webhook'])
                ? $data['modern']['webhook'] : [];

            if ($layer['base']) {
                // The file the agent reads always carries all of it.
                $webhook['enabled'] = true;
                $webhook['token'] = $token;
                // Checked on its own, whatever an environment file says: the
                // agent reads this file and nothing else.
                if (array_key_exists('path', $webhook) && !self::isRoute($webhook['path'])) {
                    $webhook['path'] = self::ROUTE;
                }
            } else {
                // An environment file wins over user/config for any key it
                // sets, so it gets our values for exactly those keys.
                foreach (['enabled' => true, 'token' => $token] as $key => $value) {
                    if (array_key_exists($key, $webhook)) {
                        $webhook[$key] = $value;
                    }
                }
                if (array_key_exists('path', $webhook) && !self::isRoute($webhook['path'])) {
                    $webhook['path'] = self::ROUTE;
                }
            }

            if (!isset($data['modern']) || !is_array($data['modern'])) {
                $data['modern'] = [];
            }
            $data['modern']['webhook'] = $webhook;

            if ($data !== $layer['data']) {
                $changes[$i] = $data;
            }
        }

        if ($changes === []) {
            return $status + ['changed' => false];
        }

        foreach (array_keys($changes) as $i) {
            if (!self::writable($layers[$i]['file'])) {
                return $this->result('not_writable', ['file' => $layers[$i]['rel']]) + ['changed' => false];
            }
        }

        // Everything the decision rested on (every layer, not just the files
        // being written) must still be what was read.
        if (!$this->unchanged($layers)) {
            return null;
        }

        // Environment files first: if user/config then fails, the agent
        // still reads the old settings and simply does not try the webhook.
        // Each file is compared once more right before its own write; a
        // change found part way leaves the files already written in a state
        // the next pass reads (it keeps the token they carry) and finishes.
        krsort($changes);
        foreach ($changes as $i => $data) {
            if (!$this->unchanged([$layers[$i]])) {
                return null;
            }
            try {
                ($this->write)($layers[$i]['file'], $data);
            } catch (\Throwable $e) {
                return $this->result('not_writable', ['file' => $layers[$i]['rel']]) + ['changed' => false];
            }
        }

        return $this->status() + ['changed' => true];
    }

    /** sha256 of a file's bytes; null when there is no such file. */
    private static function fingerprint(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $hash = @hash_file('sha256', $file);

        return $hash === false ? 'unreadable' : $hash;
    }

    /**
     * Whether every given layer's file is still exactly what was read.
     *
     * @param array<int,array<string,mixed>> $layers
     */
    private function unchanged(array $layers): bool
    {
        foreach ($layers as $layer) {
            $now = self::fingerprint($layer['file']);
            if ($now === null || $layer['hash'] === null) {
                if ($now !== $layer['hash']) {
                    return false;
                }
                continue;
            }
            if ($now === 'unreadable' || !hash_equals($layer['hash'], $now)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The config files Grav merges, lowest priority first: user/config, then
     * the environment files. Throws with the relative path of a file that
     * cannot be parsed, or whose `modern`/`webhook` is not a map.
     *
     * Each layer carries `hash`, the fingerprint of the file's bytes taken
     * BEFORE it is parsed: a change landing between the two makes the parse
     * newer than the fingerprint, which then no longer matches.
     *
     * @return array<int,array{file:string,rel:string,base:bool,data:array,hash:?string}>
     */
    private function layers(): array
    {
        $files = array_merge([$this->gravRoot . '/' . self::BASE_FILE], array_reverse($this->overrides));
        $layers = [];
        foreach ($files as $i => $file) {
            $rel = $this->relative($file);
            $data = [];
            $hash = self::fingerprint($file);
            if (is_file($file)) {
                try {
                    $data = ($this->read)($file);
                } catch (\Throwable $e) {
                    throw new \RuntimeException($rel);
                }
            }
            if ((array_key_exists('modern', $data) && !is_array($data['modern']))
                || (isset($data['modern']) && array_key_exists('webhook', $data['modern']) && !is_array($data['modern']['webhook']))) {
                throw new \RuntimeException($rel);
            }
            $layers[] = ['file' => $file, 'rel' => $rel, 'base' => $i === 0, 'data' => $data, 'hash' => $hash];
        }

        return $layers;
    }

    /**
     * The one token the files agree on: '' when none has one, null when two
     * files hold different tokens (or one is not text).
     *
     * @param array<int,array<string,mixed>> $layers
     */
    private function tokens(array $layers): ?string
    {
        $found = [];
        foreach ($layers as $layer) {
            $webhook = $layer['data']['modern']['webhook'] ?? [];
            if (!array_key_exists('token', $webhook) || $webhook['token'] === null || $webhook['token'] === '') {
                continue;
            }
            if (!is_string($webhook['token'])) {
                return null;
            }
            $found[$webhook['token']] = true;
        }
        if (count($found) > 1) {
            return null;
        }

        return $found === [] ? '' : (string) array_key_first($found);
    }

    /**
     * What Grav reads: each key from the highest file that sets it, a null
     * included, as array_replace_recursive merges them.
     *
     * @param array<int,array<string,mixed>> $layers
     * @return array{enabled:bool, token:mixed, path:string}
     */
    private function effective(array $layers): array
    {
        $out = ['enabled' => false, 'token' => null, 'path' => self::ROUTE];
        foreach ($layers as $layer) {
            $webhook = $layer['data']['modern']['webhook'] ?? [];
            foreach (array_keys($out) as $key) {
                if (array_key_exists($key, $webhook)) {
                    $out[$key] = $webhook[$key];
                }
            }
        }
        $out['enabled'] = (bool) $out['enabled'];
        $out['path'] = is_string($out['path']) ? $out['path'] : self::ROUTE;

        return $out;
    }

    /**
     * Whether Grav and the agent agree, and who answers the route.
     *
     * @param array<int,array<string,mixed>> $layers
     * @return array<string,mixed>
     */
    private function evaluate(array $layers): array
    {
        $grav = $this->effective($layers);
        $token = is_string($grav['token']) ? $grav['token'] : '';

        if (!$grav['enabled'] || $token === '') {
            return $this->result('off');
        }

        // The agent's own reader, so this checks what the agent will actually
        // see. When it cannot be loaded, Grav's reading of the file stands in
        // for the other checks, but the result is never `ready`: Grav's parser
        // is not the agent's, and "ready" must mean the agent was checked.
        $agent = null;
        if ($this->agentRead !== null) {
            $agent = ($this->agentRead)($layers[0]['file']);
        }
        $verified = $agent !== null;
        if (!$verified) {
            $agent = $layers[0]['data'];
        }
        $seen = isset($agent['modern']['webhook']) && is_array($agent['modern']['webhook']) ? $agent['modern']['webhook'] : [];
        $agentToken = isset($seen['token']) && is_string($seen['token']) ? $seen['token'] : '';
        $agentPath = self::normalizePath($seen['path'] ?? null);

        if (empty($seen['enabled']) || $agentToken === '' || !hash_equals($token, $agentToken)
            || $agentPath !== self::ROUTE || self::normalizePath($grav['path']) !== self::ROUTE) {
            return $this->result('mismatch', ['files' => $this->definingFiles($layers, null)]);
        }

        $weak = strlen($token) < self::MIN_TOKEN_LENGTH;
        if ($this->env['plugin_enabled']) {
            $version = $this->env['plugin_version'];
            if (is_string($version) && version_compare($version, self::OFFICIAL_PLUGIN_MIN, '<')) {
                return $this->result('plugin_outdated', ['plugin_version' => $version]);
            }

            return $this->result($verified ? 'ready' : 'unverified', ['served_by' => self::OFFICIAL_PLUGIN, 'weak_token' => $weak]);
        }

        // Guard Helper answers the route itself, but only for a token long
        // enough to stand behind, and only while it is allowed to manage it.
        if ($this->env['managed'] && !$weak) {
            return $this->result($verified ? 'ready' : 'unverified', ['served_by' => 'guard-helper', 'weak_token' => false]);
        }

        return $this->result('no_endpoint', ['weak_token' => $weak]);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function result(string $state, array $extra = []): array
    {
        $out = array_merge([
            'state' => $state,
            'ready' => $state === 'ready',
            'managed' => (bool) $this->env['managed'],
            'served_by' => null,
            'weak_token' => false,
            'file' => self::BASE_FILE,
        ], $extra);
        $out['message'] = self::message($state, $out);

        return $out;
    }

    /**
     * One plain sentence or two for the admin pages.
     *
     * @param array<string,mixed> $ctx
     */
    public static function message(string $state, array $ctx = []): string
    {
        $managed = (bool) ($ctx['managed'] ?? true);
        $files = isset($ctx['files']) && is_array($ctx['files']) ? implode(', ', $ctx['files']) : '';
        $file = isset($ctx['file']) && is_string($ctx['file']) ? $ctx['file'] : self::BASE_FILE;

        switch ($state) {
            case 'ready':
                $by = ($ctx['served_by'] ?? '') === self::OFFICIAL_PLUGIN
                    ? 'the Scheduler Webhook plugin answers it'
                    : 'Guard Helper answers it';
                $msg = "Guard Cloud can run this site's scheduled jobs through Grav's scheduler webhook; {$by}. The token stays on this server.";
                if (!empty($ctx['weak_token'])) {
                    $msg .= ' The webhook token is shorter than ' . self::MIN_TOKEN_LENGTH
                        . ' characters; consider a longer one in Configuration → Scheduler.';
                }

                return $msg;
            case 'unverified':
                return "The scheduler webhook is set up, but Guard couldn't confirm the agent reads it: the agent's own settings reader couldn't be loaded from _guard/. Reinstall or update the agent, then check again.";
            case 'busy':
                return 'Another request is changing the scheduler settings right now. Try again in a moment.';
            case 'lock_error':
                $folder = isset($ctx['folder']) && is_string($ctx['folder']) && $ctx['folder'] !== '' ? $ctx['folder'] : 'user/data/guard-helper';

                return "Guard couldn't create or lock its lock file, so it changed nothing. Make {$folder} writable by the web server (and on a file system that supports file locks), then try again.";
            case 'write_conflict':
                return 'The scheduler settings changed while Guard was updating them, so it wrote nothing. Try again.';
            case 'off':
                return $managed
                    ? "Grav's scheduler webhook is off. Guard Cloud can only run this site's scheduled jobs if the server lets the agent start Grav itself, which most hosts don't. Turning it on adds a token to {$file}."
                    : "Grav's scheduler webhook is off, and Guard Helper is set not to change it.";
            case 'mismatch':
                return 'The agent and Grav read different webhook settings' . ($files !== '' ? " ({$files})" : '')
                    . ', so the agent\'s calls would be refused.'
                    . ($managed ? ' Turning it on again lines them up.' : '');
            case 'no_endpoint':
                return $managed
                    ? "The webhook is on, but its token is shorter than " . self::MIN_TOKEN_LENGTH
                        . " characters, so Guard Helper won't answer it. Set a longer token in Configuration → Scheduler, or install the Scheduler Webhook plugin."
                    : 'The webhook is on, but nothing on this site answers it. Install the Scheduler Webhook plugin, or allow Guard Helper to manage the webhook.';
            case 'plugin_outdated':
                return 'The Scheduler Webhook plugin (' . (string) ($ctx['plugin_version'] ?? '?')
                    . ') is too old to answer the webhook. Update it to ' . self::OFFICIAL_PLUGIN_MIN . ' or newer.';
            case 'conflict':
                return 'Different webhook tokens are set in ' . ($files !== '' ? $files : 'more than one config file')
                    . '. Guard will not pick one; make them the same, or remove one, then try again.';
            case 'weak_token':
                return "This site already has a webhook token, but it's shorter than " . self::MIN_TOKEN_LENGTH
                    . ' characters. Guard keeps your token and will not switch the webhook on behind it. Set a longer one in Configuration → Scheduler, then try again.';
            case 'not_writable':
                return "Couldn't write {$file}. Make it writable by the web server, then try again.";
            case 'unreadable':
                return "Grav can't read {$file}, so Guard left it alone. Fix the file, then try again.";
            case 'unsupported':
                return ($ctx['reason'] ?? '') === 'custom_user_folder'
                    ? 'This site keeps its user folder outside the Grav root, where the agent cannot read the webhook settings.'
                    : 'This version of Grav cannot run its scheduler from a webhook. Grav 1.7.49 or newer can.';
        }

        return '';
    }

    /**
     * Relative paths of the files that set a webhook key (or `$key` only).
     *
     * @param array<int,array<string,mixed>> $layers
     * @return string[]
     */
    private function definingFiles(array $layers, ?string $key): array
    {
        $out = [];
        foreach ($layers as $layer) {
            $webhook = $layer['data']['modern']['webhook'] ?? null;
            if (!is_array($webhook)) {
                continue;
            }
            if ($key === null ? $webhook !== [] : array_key_exists($key, $webhook)) {
                $out[] = $layer['rel'];
            }
        }

        return $out;
    }

    private function relative(string $file): string
    {
        $prefix = $this->gravRoot . '/';

        return strncmp($file, $prefix, strlen($prefix)) === 0 ? substr($file, strlen($prefix)) : $file;
    }

    /**
     * Whether the agent would call the route: after exactly the agent's own
     * normalization (SchedulerRunner::webhookConfig()), the path is
     * `/scheduler/webhook`. Case, a trailing slash and whitespace all count:
     * the agent keeps them, and Grav answers a trailing slash with a redirect
     * the agent will not follow.
     *
     * @param mixed $path
     */
    private static function isRoute($path): bool
    {
        return self::normalizePath($path) === self::ROUTE;
    }

    /**
     * The path as the agent builds it, character for character:
     * `'/' . ltrim((string)($webhook['path'] ?? ROUTE), '/')`, then the query
     * string or fragment cut off. No trimming of whitespace.
     *
     * @param mixed $path
     */
    private static function normalizePath($path): string
    {
        if ($path === null) {
            $path = self::ROUTE;
        }
        if (is_array($path) || is_object($path)) {
            return '/Array'; // the agent's (string) cast; never the route
        }
        $path = '/' . ltrim((string) $path, '/');

        return (string) preg_replace('/[?#].*$/s', '', $path);
    }

    private static function writable(string $file): bool
    {
        if (is_file($file)) {
            return is_writable($file);
        }
        $dir = dirname($file);

        return is_dir($dir) && is_writable($dir);
    }
}
