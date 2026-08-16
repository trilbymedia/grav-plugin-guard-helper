<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

use ZipArchive;

/**
 * Browser-only Guard Agent installer — the primary on-ramp for users with no
 * shell (the CLI installer/gi.php is the secondary, advanced path). Mirrors
 * what gi.php does, but runs inside Grav and pairs IN-PROCESS so it needs no
 * proc_open / crontab:
 *
 *   1. fetch <cloud>/install/release.json and the agent zip
 *   2. verify the zip's sha256 (the installer's integrity check) — refuse on mismatch
 *   3. unpack into <grav-root>/_guard/  (the only reliably-writable spot)
 *   4. generate the keypair + pin grav_root/cloud_url + start a pairing window,
 *      driving the agent's own KeyStorage/PairingManager classes directly
 *
 * The secrets land PHP-guarded (KeyStorage / DirGuard handle that). Returns the
 * single-use pairing code for the user to enter in Guard Cloud → Add Site.
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

    public function __construct(string $gravRoot, ?callable $fetcher = null)
    {
        $this->gravRoot = rtrim($gravRoot, '/');
        $this->guardDir = $this->gravRoot . '/_guard';
        $this->fetcher = $fetcher ?? [self::class, 'httpGet'];
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
     * Download, verify, unpack, and pair. Returns:
     *   ['code' => '…', 'key_id' => '…', 'endpoint_path' => '/_guard/agent.php', 'ttl' => 900]
     *
     * @return array<string,mixed>
     */
    public function install(string $cloudUrl): array
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
        $this->unpack($this->download($cloud));

        return $this->pair($cloud);
    }

    /** Fetch + verify the release zip; returns its bytes. */
    private function download(string $cloud): string
    {
        $manifestJson = ($this->fetcher)($cloud . '/install/release.json');
        if ($manifestJson === null) {
            throw new BootstrapException("Couldn't reach the release service at $cloud.");
        }
        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest) || !isset($manifest['zip_url'], $manifest['zip_sha256'])) {
            throw new BootstrapException('The release manifest is malformed.');
        }

        $zip = ($this->fetcher)((string) $manifest['zip_url']);
        if ($zip === null) {
            throw new BootstrapException('The agent download failed.');
        }
        if (!hash_equals(strtolower((string) $manifest['zip_sha256']), hash('sha256', $zip))) {
            throw new BootstrapException('Agent sha256 mismatch — refusing to install a tampered release.');
        }

        return $zip;
    }

    /** Unpack the release into _guard/, flattening the single top-level dir. */
    private function unpack(string $zipBytes): void
    {
        // install() only runs when the agent isn't installed, so anything in
        // _guard is leftover from a failed attempt. Start clean — otherwise a
        // retry leaves the old files in place and the release lands nested in
        // its own subdir (the flatten below only fires for a single entry),
        // so the stale autoloader keeps loading.
        if (is_dir($this->guardDir)) {
            self::rmrf($this->guardDir);
        }
        if (!mkdir($this->guardDir, 0755, true) && !is_dir($this->guardDir)) {
            throw new BootstrapException('Could not create the _guard directory.');
        }
        $tmp = $this->guardDir . '/.release-' . bin2hex(random_bytes(4)) . '.zip';
        if (file_put_contents($tmp, $zipBytes) === false) {
            throw new BootstrapException('Could not write the release archive.');
        }

        $archive = new ZipArchive();
        if ($archive->open($tmp) !== true || !$archive->extractTo($this->guardDir)) {
            @unlink($tmp);
            throw new BootstrapException('Could not unpack the agent release.');
        }
        $archive->close();
        @unlink($tmp);

        // GPM-style zips have a single top-level dir; flatten it into _guard/.
        $entries = array_values(array_diff(scandir($this->guardDir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($this->guardDir . '/' . $entries[0])) {
            $inner = $this->guardDir . '/' . $entries[0];
            foreach (array_diff(scandir($inner) ?: [], ['.', '..']) as $item) {
                rename("$inner/$item", $this->guardDir . '/' . $item);
            }
            rmdir($inner);
        }

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
    private function pair(string $cloud): array
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
        file_put_contents(
            $dataDir . '/config.php',
            "<?php /* Guard Agent config — pinned host-side; do not expose. */ return "
                . var_export($config, true) . ";\n"
        );

        return $this->beginPairing($dataDir, $config, $agentKey, $stateClass, $pairClass);
    }

    /**
     * Start a FRESH pairing window on an already-installed agent, reusing its
     * existing key + config. The original code is single-use, so this is how a
     * super-admin gets a new one to enter in Guard Cloud (e.g. they navigated
     * away before pairing, or it expired).
     *
     * @return array<string,mixed>
     */
    public function repair(): array
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

    /** Default fetcher: a plain GET. Returns the body or null on any failure. */
    private static function httpGet(string $url): ?string
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $ok = $body !== false && (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) < 400;

            return $ok ? (string) $body : null;
        }

        $body = @file_get_contents($url);

        return $body === false ? null : $body;
    }
}
