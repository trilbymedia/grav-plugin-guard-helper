<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\ReleaseVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The Helper verifies agent releases with its own copy of the agent's check
 * (it runs before any agent code is on the host). These tests hold that copy
 * to the real thing: the pinned key and the security floor are the agent's,
 * and the genuine signed descriptor Guard Cloud served for Guard Agent 1.0.3
 * (captured 2026-10-27 from /agent/release/manifest) passes the signature
 * check but is refused for being below the floor, while any edit to it fails
 * the signature. The positive path, a signed release at or above the floor,
 * is covered with a test key here and end to end in AgentBootstrapTest.
 */
final class ReleaseVerifierTest extends TestCase
{
    /** @return array<string,mixed> */
    private function descriptor(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/release-descriptor-1.0.3.json'), true);
    }

    public function testPinnedKeyIsTheAgentsReleaseKey(): void
    {
        $pins = __DIR__ . '/../../agent/src/Release/Pins.php';
        if (!is_file($pins)) {
            $this->markTestSkipped('agent source not available beside the helper repo');
        }
        $this->assertSame(1, preg_match("/RELEASE_PUBLIC_KEY_HEX =\\s*'([a-f0-9]{64})'/", (string) file_get_contents($pins), $m));
        $this->assertSame($m[1], ReleaseVerifier::RELEASE_PUBLIC_KEY_HEX);
    }

    public function testMinimumSecureVersionIsTheAgentsSecurityFloor(): void
    {
        $version = __DIR__ . '/../../agent/src/Version.php';
        if (!is_file($version)) {
            $this->markTestSkipped('agent source not available beside the helper repo');
        }
        $this->assertSame(1, preg_match("/SECURITY_FLOOR = '([^']+)'/", (string) file_get_contents($version), $m));
        $this->assertSame($m[1], ReleaseVerifier::MIN_SECURE_VERSION);
    }

    /**
     * Genuinely signed — the format the Helper reads is the one the release
     * ceremony produces — and still refused: 1.0.3 is below the floor, and a
     * hostile cloud could install it and use its holes once paired.
     */
    public function testTheRealSignedReleaseIsGenuineButBelowTheFloor(): void
    {
        $d = $this->descriptor();

        $this->assertTrue(sodium_crypto_sign_verify_detached(
            (string) hex2bin($d['signature']),
            ReleaseVerifier::signingString($d['manifest']),
            ReleaseVerifier::pinnedKey()
        ));
        $this->assertSame(
            'Guard Cloud offered Guard Agent 1.0.3, which is older than the minimum secure version '
                . ReleaseVerifier::MIN_SECURE_VERSION . ' — refusing to install it.',
            ReleaseVerifier::problem($d['manifest'], $d['signature'], ReleaseVerifier::pinnedKey())
        );
    }

    public function testASignedReleaseAtOrAboveTheFloorVerifies(): void
    {
        $pair = sodium_crypto_sign_keypair();
        foreach ([ReleaseVerifier::MIN_SECURE_VERSION => true, '1.0.5' => true, '1.0.3' => false, '0.9.0' => false] as $version => $accepted) {
            $manifest = $this->descriptor()['manifest'];
            $manifest['version'] = (string) $version;
            $signature = sodium_bin2hex(sodium_crypto_sign_detached(ReleaseVerifier::signingString($manifest), sodium_crypto_sign_secretkey($pair)));

            $problem = ReleaseVerifier::problem($manifest, $signature, sodium_crypto_sign_publickey($pair));
            $accepted ? $this->assertNull($problem, (string) $version) : $this->assertStringContainsString('minimum secure version', (string) $problem);
        }
    }

    public function testAnyEditToTheRealReleaseIsRefused(): void
    {
        $d = $this->descriptor();

        $unsigned = 'The release is not signed by the Guard release key — refusing to install it.';

        $edited = $d['manifest'];
        $edited['files']['agent.php'] = str_repeat('0', 64);
        $this->assertSame($unsigned, ReleaseVerifier::problem($edited, $d['signature'], ReleaseVerifier::pinnedKey()));

        // Raising the version to clear the floor is an edit like any other.
        $edited = $d['manifest'];
        $edited['version'] = '9.9.9';
        $this->assertSame($unsigned, ReleaseVerifier::problem($edited, $d['signature'], ReleaseVerifier::pinnedKey()));

        $edited = $d['manifest'];
        $edited['zip_sha256'] = str_repeat('0', 64);
        $this->assertNotNull(ReleaseVerifier::problem($edited, $d['signature'], ReleaseVerifier::pinnedKey()));

        $edited = $d['manifest'];
        $edited['files']['src/Evil.php'] = str_repeat('a', 64);
        $this->assertNotNull(ReleaseVerifier::problem($edited, $d['signature'], ReleaseVerifier::pinnedKey()));

        $this->assertNotNull(ReleaseVerifier::problem($d['manifest'], strrev($d['signature']), ReleaseVerifier::pinnedKey()));
        $this->assertNotNull(ReleaseVerifier::problem($d['manifest'], $d['signature'], sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
    }

    public function testUnsafeManifestPathsAreRefusedBeforeTheSignature(): void
    {
        $d = $this->descriptor();
        $edited = $d['manifest'];
        $edited['files']['../../index.php'] = str_repeat('a', 64);

        $this->assertSame('The release manifest is malformed.', ReleaseVerifier::problem($edited, $d['signature'], ReleaseVerifier::pinnedKey()));
    }
}
