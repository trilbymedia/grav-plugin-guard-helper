<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

use GravGuard\Agent\Support\CronProbe;
use ZipArchive;

/**
 * Browser-only Guard Agent installer — the primary on-ramp for users with no
 * shell (the CLI installer/gi.php is the secondary, advanced path). Mirrors
 * what gi.php does, but runs inside Grav and pairs IN-PROCESS so it needs no
 * proc_open / crontab:
 *
 *   1. fetch the signed release descriptor (<cloud>/agent/release/manifest —
 *      the one the agent's self-update reads) and verify its Ed25519
 *      signature against the release key pinned in ReleaseVerifier
 *   2. download the zip from the same cloud and check it against the signed
 *      manifest's sha256 — refuse on any mismatch
 *   3. unpack into a staging dir, verify every file against the signed
 *      manifest, and only then move it into <grav-root>/_guard/ (the only
 *      reliably-writable spot)
 *   4. generate the keypair + pin grav_root/cloud_url/site_url + start a
 *      pairing window, driving the agent's own KeyStorage/PairingManager
 *      classes directly
 *
 * The secrets land PHP-guarded (KeyStorage / DirGuard handle that). Returns the
 * single-use pairing code for the user to enter in Guard Cloud → Add Site.
 *
 * Needing no crontab to INSTALL is not the same as needing none to run: the
 * agent's per-minute tick is the only thing that collects queued work, so a
 * site installed this way takes no scheduled backups until someone schedules
 * one. cronStatus() reports that, and the setup screen shows it.
 *
 * Zero runtime composer deps: the agent's classes come from the unpacked
 * release's own autoloader; this class is require()d by the plugin directly.
 */
final class AgentBootstrap
{
    private string $gravRoot;
    private string $guardDir;
    /** @var callable(string):?string GET a URL → body, or null on failure */
    private $fetcher;
    /** Raw Ed25519 key releases must be signed with. */
    private string $releaseKey;

    /**
     * @param string|null $releasePublicKey raw key; only tests pass one (they
     *   cannot sign with the real release key). Production always verifies
     *   against ReleaseVerifier's pinned key.
     */
    public function __construct(string $gravRoot, ?callable $fetcher = null, ?string $releasePublicKey = null)
    {
        $this->gravRoot = rtrim($gravRoot, '/');
        $this->guardDir = $this->gravRoot . '/_guard';
        $this->fetcher = $fetcher ?? [self::class, 'httpGet'];
        $this->releaseKey = $releasePublicKey ?? ReleaseVerifier::pinnedKey();
    }

    /** The agent is installed when its entry point + (guarded) key exist. */
    public function isInstalled(): bool
    {
        return is_file($this->guardDir . '/agent.php')
            && is_file($this->guardDir . '/keys/agent.key.php');
    }

    /** The agent's public endpoint path, relative to the site root. */
    public function endpointPath(): string
    {
        return '/_guard/agent.php';
    }

    /**
     * Whether this site can run the agent's per-minute tick, and whether it is.
     *
     * Installing in the browser needs no crontab, which is the whole point of
     * this path — but it also means nothing sets one up, and until now nothing
     * mentioned that. The tick is the only thing that collects queued work, so
     * a site without one takes no scheduled backups and applies no scheduled
     * updates while looking perfectly healthy. The setup screen has to say so,
     * and offer the line for anyone who does have a cron manager.
     *
     * @return array{can_spawn:bool, crontab:bool, installed:bool, line:string, reason:string}|null
     *   null before the agent is unpacked, when there is nothing to ask
     */
    public function cronStatus(): ?array
    {
        $autoload = $this->guardDir . '/src/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        require_once $autoload;

        if (!class_exists(CronProbe::class)) {
            // An agent released before the probe existed. Not knowing is
            // different from knowing there is no cron, so say nothing.
            return null;
        }

        return CronProbe::inspect($this->guardDir);
    }

    /**
     * Which agent is RUNNING here, read straight from its own source.
     *
     * Once a self-update has happened the flat tree is no longer the live
     * agent: the update stages a verified release into releases/vX.Y.Z/ and
     * points `current` at it, leaving the original src/ in place as the
     * bootstrap. Reading src/Version.php alone therefore reports the version
     * the site was INSTALLED with forever after — the one number this screen
     * exists to answer, answered wrong. Resolve the pointer the same way
     * boot.php does, and only fall back to the flat tree when there is none.
     *
     * Deliberately a file read rather than an autoload + class constant: this
     * runs on every page render, and an agent too old or too broken to
     * autoload should still report its version rather than take the screen
     * down with it.
     */
    public function agentVersion(): ?string
    {
        foreach ([$this->liveReleaseDir(), $this->guardDir] as $dir) {
            if ($dir === null) {
                continue;
            }
            $file = $dir . '/src/Version.php';
            if (!is_file($file)) {
                continue;
            }
            $src = (string) @file_get_contents($file);
            if (preg_match("/VERSION\\s*=\\s*'([^']+)'/", $src, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * The release directory `current` points at, or null when the agent has
     * never self-updated (the flat tree is then the live release).
     *
     * The pointer is attacker-relevant — it is a writable file that decides
     * which code the agent runs — so it gets the same containment boot.php
     * applies: a home-relative path under releases/ and nothing else.
     */
    private function liveReleaseDir(): ?string
    {
        $link = $this->guardDir . '/current';
        $pointer = null;
        if (is_link($link)) {
            $pointer = (string) @readlink($link);
        } elseif (is_file($this->guardDir . '/current.pointer')) {
            $pointer = trim((string) @file_get_contents($this->guardDir . '/current.pointer'));
        }
        if ($pointer === null || $pointer === '' || strpos($pointer, 'releases/') !== 0) {
            return null;
        }
        if (strpos($pointer, "\0") !== false || strpos($pointer, '\\') !== false
            || in_array('..', explode('/', $pointer), true)) {
            return null;
        }

        $dir = $this->guardDir . '/' . $pointer;

        return is_dir($dir) ? $dir : null;
    }

    /**
     * The newest agent the cloud is offering, from the same signed descriptor
     * install() verifies — so "latest" here means exactly what a fresh install
     * gets, and only a version the release key actually signed.
     *
     * Null on any failure. An unreachable cloud must not turn into a claim
     * that the agent is current, nor into an error on a page whose job is to
     * report health.
     */
    public function latestAgentVersion(string $cloudUrl): ?string
    {
        $descriptor = $this->descriptor(rtrim($cloudUrl, '/'));
        if ($descriptor === null
            || ReleaseVerifier::problem($descriptor['manifest'], $descriptor['signature'], $this->releaseKey) !== null) {
            return null;
        }

        return (string) $descriptor['manifest']['version'];
    }

    /**
     * The signed release descriptor: {manifest, signature, zip_url}.
     *
     * @return array{manifest:mixed, signature:mixed, zip_url:mixed}|null
     */
    private function descriptor(string $cloud): ?array
    {
        try {
            $json = ($this->fetcher)($cloud . '/agent/release/manifest');
        } catch (BootstrapException $e) {
            return null;
        }
        if ($json === null) {
            return null;
        }
        $descriptor = json_decode($json, true);
        if (!is_array($descriptor) || !isset($descriptor['manifest'], $descriptor['signature'], $descriptor['zip_url'])) {
            return null;
        }

        return $descriptor;
    }

    /**
     * Has Guard Cloud actually reached this site, and how recently?
     *
     * This is the question the setup screen should lead with. Whether a
     * crontab entry exists is a detail of HOW work arrives; whether work
     * arrives at all is the thing the user cares about, and it is answerable
     * because the agent stamps every verified inbound command (push) and every
     * completed mailbox poll (pull).
     *
     * `reachable` is deliberately "has it ever happened", not "recently".
     * Pushes are work-driven, so a perfectly healthy site with nothing to do
     * can go days without one, and a freshness threshold would turn that into
     * a false alarm. The timestamps are returned so the screen can say when,
     * and the caller can draw its own conclusions.
     *
     * @return array{reachable:bool, channel:?string, last_push_at:?int, last_tick_at:?int}|null
     *   null before the agent is installed, when there is nothing to ask
     */
    public function deliveryStatus(): ?array
    {
        if (!$this->isInstalled()) {
            return null;
        }

        $push = $this->stateInt('delivery.last_push_at');
        $tick = $this->stateInt('delivery.last_tick_at');
        if ($push === null && $tick === null) {
            return ['reachable' => false, 'channel' => null, 'last_push_at' => null, 'last_tick_at' => null];
        }

        return [
            'reachable' => true,
            // Whichever channel spoke to us most recently is the one carrying
            // this site's work today.
            'channel' => ($tick ?? 0) >= ($push ?? 0) ? 'tick' : 'push',
            'last_push_at' => $push,
            'last_tick_at' => $tick,
        ];
    }

    /**
     * Read one key from the agent's state DB.
     *
     * The DB has a randomized filename (so it stays unfetchable even where
     * .htaccess is ignored), recorded in the PHP-guarded data/config.php —
     * so resolve it the same way the agent's own entry points do rather than
     * guessing at a path.
     */
    private function stateInt(string $key): ?int
    {
        $autoload = $this->guardDir . '/src/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        require_once $autoload;
        if (!class_exists(\GravGuard\Agent\State\StateStore::class)) {
            return null;
        }

        $config = @include $this->guardDir . '/data/config.php';
        $db = (is_array($config) && !empty($config['db'])) ? (string)$config['db'] : 'agent.db';
        $path = $this->guardDir . '/data/' . $db;
        if (!is_file($path)) {
            return null;
        }

        try {
            $value = (new \GravGuard\Agent\State\StateStore($path))->get($key);
        } catch (\Throwable $e) {
            // A locked or unreadable state DB is not worth failing the page
            // over — the screen just shows "not seen yet".
            return null;
        }

        return ($value === null || $value === '') ? null : (int)$value;
    }

    /**
     * Download, verify, unpack, and pair. Returns:
     *   ['code' => '…', 'key_id' => '…', 'endpoint_path' => '/_guard/agent.php', 'ttl' => 900]
     *
     * @param string|null $siteUrl the site's own base URL as Grav sees it,
     *   pinned in the agent's config so no signed command can point the
     *   agent's self-calls (health probe, scheduler webhook) anywhere else
     * @return array<string,mixed>
     */
    public function install(string $cloudUrl, ?string $siteUrl = null): array
    {
        if (!extension_loaded('sodium')) {
            throw new BootstrapException('PHP ext-sodium is required (the agent fails closed without it).');
        }
        if (!extension_loaded('zip')) {
            throw new BootstrapException('PHP ext-zip is required to unpack the agent.');
        }
        if ($this->isInstalled()) {
            throw new BootstrapException('The Guard Agent is already installed at _guard/.');
        }
        if (!is_dir($this->gravRoot) || !is_writable($this->gravRoot)) {
            throw new BootstrapException('The Grav root is not writable — cannot install the agent here.');
        }

        $cloud = rtrim($cloudUrl, '/');
        [$zip, $manifest] = $this->download($cloud);
        $this->unpack($zip, $manifest);

        return $this->pair($cloud, $siteUrl);
    }

    /**
     * Fetch the signed descriptor, verify it, then fetch the zip and check it
     * against the signed hash. Returns [zip bytes, verified manifest].
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function download(string $cloud): array
    {
        $json = ($this->fetcher)($cloud . '/agent/release/manifest');
        if ($json === null) {
            throw new BootstrapException("Couldn't reach the release service at $cloud.");
        }
        $descriptor = json_decode($json, true);
        if (!is_array($descriptor) || !isset($descriptor['manifest'], $descriptor['signature'], $descriptor['zip_url'])) {
            throw new BootstrapException('The release manifest is malformed.');
        }
        $problem = ReleaseVerifier::problem($descriptor['manifest'], $descriptor['signature'], $this->releaseKey);
        if ($problem !== null) {
            throw new BootstrapException($problem);
        }
        $manifest = $descriptor['manifest'];

        // The zip comes from the cloud that served the descriptor, nowhere else.
        $zipUrl = (string) $descriptor['zip_url'];
        if (self::origin($zipUrl) !== self::origin($cloud)) {
            throw new BootstrapException("The agent release is not served from $cloud — refusing to install it.");
        }
        $zip = ($this->fetcher)($zipUrl);
        if ($zip === null) {
            throw new BootstrapException('The agent download failed.');
        }
        if (!hash_equals((string) $manifest['zip_sha256'], hash('sha256', $zip))) {
            throw new BootstrapException('Agent sha256 mismatch — refusing to install a tampered release.');
        }

        return [$zip, $manifest];
    }

    private static function origin(string $url): string
    {
        $p = (array) parse_url($url);

        return strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
    }

    /**
     * Unpack into a staging dir, verify every file against the signed
     * manifest, then move the release into _guard/.
     *
     * @param array<string,mixed> $manifest verified
     */
    private function unpack(string $zipBytes, array $manifest): void
    {
        // install() only runs when the agent isn't installed, so anything in
        // _guard is leftover from a failed attempt. Start clean — otherwise a
        // retry leaves the old files in place and the release lands nested in
        // its own subdir (the flatten below only fires for a single entry),
        // so the stale autoloader keeps loading.
        if (is_dir($this->guardDir)) {
            self::rmrf($this->guardDir);
        }
        $staging = $this->gravRoot . '/.guard-install-' . bin2hex(random_bytes(4));
        $tmp = $staging . '.zip';
        if (!mkdir($staging, 0700) || file_put_contents($tmp, $zipBytes) === false) {
            @unlink($tmp);
            throw new BootstrapException('Could not write the release archive.');
        }

        $archive = new ZipArchive();
        if ($archive->open($tmp) !== true || !$archive->extractTo($staging)) {
            @unlink($tmp);
            self::rmrf($staging);
            throw new BootstrapException('Could not unpack the agent release.');
        }
        $archive->close();
        @unlink($tmp);

        // Release zips have a single top-level dir; that dir is the release.
        $entries = array_values(array_diff(scandir($staging) ?: [], ['.', '..']));
        $root = (count($entries) === 1 && is_dir($staging . '/' . $entries[0])) ? $staging . '/' . $entries[0] : $staging;

        // Every file, against the signed manifest: modified, missing and extra
        // files all fail, exactly as the agent's self-update checks.
        $expected = $manifest['files'];
        ksort($expected, SORT_STRING);
        if (ReleaseVerifier::treeHashes($root) !== $expected) {
            self::rmrf($staging);
            throw new BootstrapException('The unpacked release does not match its signed manifest — refusing to install it.');
        }
        if (!rename($root, $this->guardDir)) {
            self::rmrf($staging);
            throw new BootstrapException('Could not create the _guard directory.');
        }
        if (is_dir($staging)) {
            self::rmrf($staging);
        }
        @chmod($this->guardDir, 0755);

        // ZipArchive::extractTo does not carry unix permission bits across, so
        // the bin/ helpers land non-executable and `_guard/bin/pair` fails as a
        // bare command — which is exactly what a customer gets told to run when
        // their pairing code expires.
        foreach (glob($this->guardDir . '/bin/*') ?: [] as $binFile) {
            if (is_file($binFile)) {
                @chmod($binFile, 0755);
            }
        }

        if (!is_file($this->guardDir . '/agent.php') || !is_file($this->guardDir . '/src/autoload.php')) {
            throw new BootstrapException('The unpacked release is missing expected files.');
        }
    }

    /** Recursively delete a directory and its contents. */
    private static function rmrf(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? self::rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * In-process pairing — drives the agent's own classes (loaded from the
     * unpacked release) so no subprocess is needed. Pins grav_root + cloud_url
     * in the PHP-guarded config and starts a single-use pairing window.
     *
     * @return array<string,mixed>
     */
    private function pair(string $cloud, ?string $siteUrl = null): array
    {
        [$keyClass, $guardClass, $stateClass, $pairClass] = $this->loadAgentClasses();

        $dataDir = $this->guardDir . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new BootstrapException('Could not create the agent data directory.');
        }
        $guardClass::apply($dataDir);

        $storage = new $keyClass($this->guardDir . '/keys/agent.key.php');
        $agentKey = $storage->exists() ? $storage->load() : $storage->generate();

        // Same guarded config.php that bin/pair writes (kept in sync).
        $config = [
            'cloud_url' => $cloud,
            'grav_root' => $this->gravRoot,
            'db' => 'agent-' . bin2hex(random_bytes(8)) . '.db',
        ];
        $site = self::siteUrl($siteUrl);
        if ($site !== null) {
            $config['site_url'] = $site;
        }
        file_put_contents(
            $dataDir . '/config.php',
            "<?php /* Guard Agent config — pinned host-side; do not expose. */ return "
                . var_export($config, true) . ";\n"
        );

        return $this->beginPairing($dataDir, $config, $agentKey, $stateClass, $pairClass);
    }

    /**
     * A base URL worth pinning: absolute http(s), no credentials, query or
     * fragment, no trailing slash. Null otherwise. Guard Cloud never sets
     * this URL; without it the agent's scheduler webhook fallback stays off.
     */
    private static function siteUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $p = parse_url(trim($url));
        if (!is_array($p) || !isset($p['scheme'], $p['host'])
            || !in_array(strtolower($p['scheme']), ['http', 'https'], true)
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }

        return rtrim(trim($url), '/');
    }

    /**
     * Start a FRESH pairing window on an already-installed agent, reusing its
     * existing key + config. The original code is single-use, so this is how a
     * super-admin gets a new one to enter in Guard Cloud (e.g. they navigated
     * away before pairing, or it expired).
     *
     * It is also how an agent installed before 1.0.4 gets its site URL: those
     * agents have none in their config, and their bin/pair predates
     * --site-url. Opening a pairing window does not touch an existing
     * pairing, so on a paired site the code can simply be ignored.
     *
     * @param string|null $siteUrl pinned into the config when it has none yet
     *   (agents installed before the Helper recorded it)
     * @return array<string,mixed>
     */
    public function repair(?string $siteUrl = null): array
    {
        if (!$this->isInstalled()) {
            throw new BootstrapException('The Guard Agent is not installed yet — set it up first.');
        }

        [$keyClass, , $stateClass, $pairClass] = $this->loadAgentClasses();

        $dataDir = $this->guardDir . '/data';
        $configFile = $dataDir . '/config.php';
        if (!is_file($configFile)) {
            throw new BootstrapException('The agent configuration is missing — re-run setup.');
        }
        $config = require $configFile;
        if (!is_array($config) || !isset($config['db'])) {
            throw new BootstrapException('The agent configuration is unreadable — re-run setup.');
        }
        $site = self::siteUrl($siteUrl);
        if (!isset($config['site_url']) && $site !== null) {
            $config['site_url'] = $site;
            file_put_contents(
                $configFile,
                "<?php /* Guard Agent config — pinned host-side; do not expose. */ return "
                    . var_export($config, true) . ";\n"
            );
        }

        $agentKey = (new $keyClass($this->guardDir . '/keys/agent.key.php'))->load();

        return $this->beginPairing($dataDir, $config, $agentKey, $stateClass, $pairClass);
    }

    /**
     * Load the agent classes this installer drives from the unpacked release,
     * failing clearly if the release predates one of them.
     *
     * @return array{0:string,1:string,2:string,3:string} [KeyStorage, DirGuard, StateStore, PairingManager]
     */
    private function loadAgentClasses(): array
    {
        require_once $this->guardDir . '/src/autoload.php';

        $classes = [
            '\\GravGuard\\Agent\\Crypto\\KeyStorage',
            '\\GravGuard\\Agent\\Install\\DirGuard',
            '\\GravGuard\\Agent\\State\\StateStore',
            '\\GravGuard\\Agent\\Pairing\\PairingManager',
        ];
        foreach ($classes as $needed) {
            if (!class_exists($needed)) {
                throw new BootstrapException(
                    'The downloaded agent release is out of date or incompatible with this installer (missing '
                    . ltrim($needed, '\\') . '). Try again later, or update the Guard Helper plugin.'
                );
            }
        }

        return $classes;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function beginPairing(string $dataDir, array $config, object $agentKey, string $stateClass, string $pairClass): array
    {
        $manager = new $pairClass(new $stateClass($dataDir . '/' . $config['db']), $agentKey);

        return [
            'code' => $manager->begin(),
            'key_id' => $agentKey->keyId,
            'endpoint_path' => $this->endpointPath(),
            'ttl' => $pairClass::CODE_TTL,
        ];
    }

    /**
     * Default fetcher: a plain HTTPS GET. Returns the body, or null on any
     * failure.
     *
     * Redirects are never followed, on either path. The descriptor and the
     * zip both come from the configured cloud, and a redirect from it — to
     * http://127.0.0.1:…, say — would make this site request whatever the
     * cloud names, inside its own network, before any signature has been
     * checked. A 3xx is a failure with its own message, so a mistyped cloud
     * URL (http, or www vs apex) is easy to spot.
     *
     * @throws BootstrapException when the cloud answers with a redirect
     */
    private static function httpGet(string $url): ?string
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            self::refuseRedirect($url, $status);

            return ($body !== false && $status >= 200 && $status < 300) ? (string) $body : null;
        }

        $ctx = stream_context_create([
            'http' => ['follow_location' => 0, 'timeout' => 60, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        self::refuseRedirect($url, $status);

        return ($body !== false && $status >= 200 && $status < 300) ? $body : null;
    }

    /** @throws BootstrapException on a 3xx */
    private static function refuseRedirect(string $url, int $status): void
    {
        if ($status >= 300 && $status < 400) {
            throw new BootstrapException(
                "Guard Cloud answered $url with a redirect (HTTP $status). The installer does not follow redirects — "
                . 'check that the Guard Cloud URL in the plugin settings is exact (https, and the right host).'
            );
        }
    }
}
