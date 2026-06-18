<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\Shield\ShieldFilter;
use Grav\Plugin\GuardHelper\Shield\ShieldRules;
use PHPUnit\Framework\TestCase;

/**
 * Guard Shield's two trust-critical pieces: request matching (what gets blocked)
 * and signature verification (the boundary that stops a forged ruleset). Both
 * must fail safe — matching never throws on a bad regex, verification rejects
 * anything that doesn't check out.
 */
final class ShieldTest extends TestCase
{
    private function rule(array $over = []): array
    {
        return array_merge([
            'rule_id' => 'SHIELD-1',
            'action' => 'block',
            'methods' => ['POST'],
            'path_pattern' => '^/admin/tools/backup',
            'param_patterns' => [],
        ], $over);
    }

    public function testMatchesMethodAndPath(): void
    {
        $hit = ShieldFilter::match('POST', '/admin/tools/backup', [], [$this->rule()]);
        $this->assertNotNull($hit);
        $this->assertSame('SHIELD-1', $hit['rule_id']);
    }

    public function testMethodMismatchDoesNotMatch(): void
    {
        $this->assertNull(ShieldFilter::match('GET', '/admin/tools/backup', [], [$this->rule()]));
    }

    public function testPathMismatchDoesNotMatch(): void
    {
        $this->assertNull(ShieldFilter::match('POST', '/blog', [], [$this->rule()]));
    }

    public function testEmptyMethodsMatchesAnyMethod(): void
    {
        $rule = $this->rule(['methods' => []]);
        $this->assertNotNull(ShieldFilter::match('DELETE', '/admin/tools/backup', [], [$rule]));
    }

    public function testParamPatternRequired(): void
    {
        $rule = $this->rule(['param_patterns' => ['cmd' => 'rm|cat']]);
        $this->assertNotNull(ShieldFilter::match('POST', '/admin/tools/backup', ['cmd' => 'rm -rf'], [$rule]));
        $this->assertNull(ShieldFilter::match('POST', '/admin/tools/backup', ['cmd' => 'ls'], [$rule]));
        $this->assertNull(ShieldFilter::match('POST', '/admin/tools/backup', [], [$rule])); // param absent
    }

    public function testParamArrayValueMatchesAnyElement(): void
    {
        $rule = $this->rule(['param_patterns' => ['x' => 'evil']]);
        $this->assertNotNull(ShieldFilter::match('POST', '/admin/tools/backup', ['x' => ['ok', 'evil']], [$rule]));
    }

    public function testFirstMatchingRuleWins(): void
    {
        $rules = [
            $this->rule(['rule_id' => 'A', 'path_pattern' => '^/nope']),
            $this->rule(['rule_id' => 'B']),
        ];
        $this->assertSame('B', ShieldFilter::match('POST', '/admin/tools/backup', [], $rules)['rule_id']);
    }

    public function testBadRegexFailsSafe(): void
    {
        $rule = $this->rule(['path_pattern' => '([unclosed']);
        $this->assertNull(ShieldFilter::match('POST', '/admin/tools/backup', [], [$rule]));
    }

    // --- signature verification ------------------------------------------

    public function testVerifyAcceptsGenuineSignature(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($pair);
        $pk = sodium_crypto_sign_publickey($pair);

        $payload = '{"version":1,"rules":[]}';
        $sig = base64_encode(sodium_crypto_sign_detached($payload, $sk));

        $this->assertTrue(ShieldRules::verify($payload, $sig, base64_encode($pk)));
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($pair);
        $pk = sodium_crypto_sign_publickey($pair);

        $sig = base64_encode(sodium_crypto_sign_detached('{"rules":[]}', $sk));

        $this->assertFalse(ShieldRules::verify('{"rules":[{"rule_id":"injected"}]}', $sig, base64_encode($pk)));
    }

    public function testVerifyRejectsWrongKey(): void
    {
        $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $otherPk = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());

        $payload = '{"rules":[]}';
        $sig = base64_encode(sodium_crypto_sign_detached($payload, $sk));

        $this->assertFalse(ShieldRules::verify($payload, $sig, base64_encode($otherPk)));
    }

    public function testVerifyRejectsGarbage(): void
    {
        $pk = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $this->assertFalse(ShieldRules::verify('', 'x', $pk));
        $this->assertFalse(ShieldRules::verify('{}', 'not-base64!!', $pk));
        $this->assertFalse(ShieldRules::verify('{}', base64_encode('tooshort'), $pk));
    }
}
