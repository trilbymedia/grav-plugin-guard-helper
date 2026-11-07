<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\Api\GuardController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

require_once __DIR__ . '/fixtures/api-stubs.php';

/**
 * Every Helper route is super-admin only, and a scoped API key minted on a
 * super-admin account must not get past that just because its owner is one.
 *
 * The API plugin is not installed in this suite, so a small stand-in for its
 * base class (tests/fixtures/api-stubs.php) supplies the scope cap; what is
 * tested is that GuardController::requireSuperAdmin() applies it.
 */
final class GuardControllerScopeTest extends TestCase
{
    private function request(bool $super, ?array $scopes): ServerRequestInterface
    {
        $user = new \stdClass();
        $user->super = $super;

        return new class($user, $scopes) implements ServerRequestInterface {
            private $attrs;

            public function __construct($user, $scopes)
            {
                $this->attrs = ['api_user' => $user, 'api_key_scopes' => $scopes];
            }

            public function getAttribute($name, $default = null)
            {
                return $this->attrs[$name] ?? $default;
            }
        };
    }

    private function call(ServerRequestInterface $request): void
    {
        $controller = (new \ReflectionClass(GuardController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(GuardController::class, 'requireSuperAdmin');
        $method->setAccessible(true);
        $method->invoke($controller, $request);
    }

    public function testAnUnscopedSuperAdminPasses(): void
    {
        $this->call($this->request(true, null));
        $this->call($this->request(true, []));
        $this->addToAssertionCount(1);
    }

    public function testAKeyCarryingAdminSuperOrEverythingPasses(): void
    {
        $this->call($this->request(true, ['admin.super']));
        $this->call($this->request(true, ['*']));
        $this->addToAssertionCount(1);
    }

    public function testAScopedKeyOnASuperAdminAccountIsRefused(): void
    {
        $this->expectException(\Grav\Plugin\Api\Exceptions\ForbiddenException::class);

        $this->call($this->request(true, ['api.pages.read']));
    }

    public function testANonSuperAccountIsRefusedWhateverItsScopes(): void
    {
        foreach ([null, ['admin.super'], ['*']] as $scopes) {
            try {
                $this->call($this->request(false, $scopes));
                $this->fail('a non-super account got through');
            } catch (\Grav\Plugin\Api\Exceptions\ForbiddenException $e) {
                $this->assertStringContainsString('super-admin', $e->getMessage());
            }
        }
    }

    /**
     * Every endpoint, Fix agent settings included, checks for a super-admin
     * before it does anything: these install software, change the agent's
     * config and approve its keys.
     */
    public function testEveryEndpointChecksForASuperAdminFirst(): void
    {
        $class = new \ReflectionClass(GuardController::class);
        $lines = file((string) $class->getFileName());
        $endpoints = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== GuardController::class) {
                continue;
            }
            $endpoints[] = $method->getName();
            $body = implode('', array_slice($lines, $method->getStartLine(), $method->getEndLine() - $method->getStartLine()));
            $first = preg_match('/\{\s*([^;]+;)/', $body, $m) ? trim($m[1]) : '';
            $this->assertSame('$this->requireSuperAdmin($request);', $first, $method->getName() . ' must check for a super-admin first');
        }
        $this->assertContains('fixSettings', $endpoints);

        $plugin = (string) file_get_contents(__DIR__ . '/../guard-helper.php');
        $this->assertStringContainsString("\$routes->post('/' . self::SLUG . '/fix-settings', [\$controller, 'fixSettings']);", $plugin);
    }
}
