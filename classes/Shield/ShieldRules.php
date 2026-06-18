<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Shield;

use Grav\Common\Grav;

/**
 * Fetches, verifies, and caches the signed Guard Shield rule bundle. The
 * signature is the trust boundary (plan §7): rules are applied ONLY if the
 * detached Ed25519 signature verifies against the public key embedded in plugin
 * config — so a compromised CDN, MITM, or tampered cache can't inject a rule.
 * Any failure (offline, bad signature, malformed) yields no rules; Shield fails
 * open, never blocking legitimate traffic on a delivery problem.
 */
final class ShieldRules
{
    private const CACHE_KEY = 'guard-shield-rules';
    private const CACHE_TTL = 600;

    private Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Verified rules for this site. Cached (verified form) for a short TTL.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rules(string $bundleUrl, string $publicKeyB64): array
    {
        if ($publicKeyB64 === '') {
            return [];
        }

        $cache = $this->grav['cache'];
        $cached = $cache->fetch(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $rules = [];
        $raw = $this->httpGet($bundleUrl);
        if ($raw !== null) {
            $bundle = json_decode($raw, true);
            if (is_array($bundle)) {
                $payload = (string)($bundle['payload'] ?? '');
                $signature = (string)($bundle['signature'] ?? '');
                if (self::verify($payload, $signature, $publicKeyB64)) {
                    $decoded = json_decode($payload, true);
                    if (is_array($decoded) && is_array($decoded['rules'] ?? null)) {
                        $rules = $decoded['rules'];
                    }
                }
            }
        }

        $cache->save(self::CACHE_KEY, $rules, self::CACHE_TTL);

        return $rules;
    }

    /**
     * Verify a detached Ed25519 signature over the exact payload bytes. Pure and
     * fail-closed: missing libsodium, bad base64, or wrong key length all return
     * false rather than throwing.
     */
    public static function verify(string $payload, string $signatureB64, string $publicKeyB64): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached') || $payload === '') {
            return false;
        }
        $signature = base64_decode($signatureB64, true);
        $publicKey = base64_decode($publicKeyB64, true);
        if ($signature === false || $publicKey === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'header' => "User-Agent: GuardShield\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
