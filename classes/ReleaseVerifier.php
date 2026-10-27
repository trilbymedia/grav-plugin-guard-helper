<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Verifies a Guard Agent release against the OFFLINE release key, before the
 * browser install unpacks a single file into place.
 *
 * This is the same check the agent's self-update makes (agent
 * Release\Manifest + Release\Signer), restated here because nothing inside the
 * release can be trusted to check itself, and because this plugin ships
 * through GPM — a channel Guard Cloud does not control. The cloud serves the
 * signed descriptor and the zip; it cannot sign them, so a compromised cloud
 * cannot hand a new install its own code.
 *
 * Fails closed: ext-sodium is required (AgentBootstrap checks it first) and
 * there is no fallback.
 */
final class ReleaseVerifier
{
    /**
     * The agent's pinned release public key (agent src/Release/Pins.php,
     * docs/release-key.md — fingerprint a9c7-db28-66e7-1770-…).
     * tests/ReleaseVerifierTest.php checks it against the agent checkout.
     */
    public const RELEASE_PUBLIC_KEY_HEX = 'bc828ea518604113c423b26fbf107602fdea25bc9f4a550eac8d768dcb4d7d5f';

    /**
     * The oldest agent release this installer will lay down — the agent's
     * Version::SECURITY_FLOOR. A genuine older release still verifies against
     * the key, and it carries holes a hostile cloud could use straight after
     * pairing, so the signature alone is not enough.
     * tests/ReleaseVerifierTest.php checks it against the agent checkout.
     */
    public const MIN_SECURE_VERSION = '1.0.4';

    private const SEMVER = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.\-]+)?$/';

    /** @return string raw 32-byte key */
    public static function pinnedKey(): string
    {
        return (string) hex2bin(self::RELEASE_PUBLIC_KEY_HEX);
    }

    /**
     * Why a signed manifest is refused, or null when it verifies.
     *
     * @param mixed $manifest
     * @param mixed $signatureHex
     */
    public static function problem($manifest, $signatureHex, string $publicKey): ?string
    {
        if (!is_array($manifest) || !is_string($signatureHex)) {
            return 'The release descriptor is malformed.';
        }
        foreach (['format', 'version', 'channel', 'created', 'zip_sha256', 'files', 'min_replaces'] as $field) {
            if (!array_key_exists($field, $manifest)) {
                return 'The release manifest is malformed.';
            }
        }
        if ((int) $manifest['format'] !== 1
            || !is_string($manifest['version']) || preg_match(self::SEMVER, $manifest['version']) !== 1
            || !is_string($manifest['min_replaces']) || preg_match(self::SEMVER, $manifest['min_replaces']) !== 1
            || !in_array($manifest['channel'], ['stable', 'emergency'], true)
            || !is_string($manifest['zip_sha256']) || preg_match('/^[a-f0-9]{64}$/', $manifest['zip_sha256']) !== 1
            || !is_array($manifest['files']) || $manifest['files'] === []) {
            return 'The release manifest is malformed.';
        }
        foreach ($manifest['files'] as $path => $digest) {
            if (!is_string($path) || $path === '' || strpos($path, '..') !== false || $path[0] === '/'
                || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
                return 'The release manifest is malformed.';
            }
        }
        $signature = @hex2bin($signatureHex);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'The release signature is malformed.';
        }
        if (!sodium_crypto_sign_verify_detached($signature, self::signingString($manifest), $publicKey)) {
            return 'The release is not signed by the Guard release key — refusing to install it.';
        }
        if (version_compare($manifest['version'], self::MIN_SECURE_VERSION, '<')) {
            return 'Guard Cloud offered Guard Agent ' . $manifest['version'] . ', which is older than the minimum secure version '
                . self::MIN_SECURE_VERSION . ' — refusing to install it.';
        }

        return null;
    }

    /**
     * The exact bytes the release key signs: recursively key-sorted JSON with
     * unescaped slashes, hashed, behind the guard-release-v1 prefix.
     *
     * @param array<string,mixed> $manifest
     */
    public static function signingString(array $manifest): string
    {
        return "guard-release-v1\n" . hash('sha256', (string) json_encode(self::ksortRecursive($manifest), JSON_UNESCAPED_SLASHES));
    }

    /**
     * Per-file sha256 of an unpacked tree — relative paths, sorted — the map
     * the signed manifest pins.
     *
     * @return array<string,string>
     */
    public static function treeHashes(string $dir): array
    {
        $base = rtrim((string) realpath($dir), '/');
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1))] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ksortRecursive($item);
            }
        }

        return $value;
    }
}
