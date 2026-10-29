<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\SuperAdmin;
use PHPUnit\Framework\TestCase;

/**
 * A native Grav 2 super-admin has `api.super` and no `admin.super`; a
 * classic or migrated one has `admin.super`. Guard Helper's own checks (the
 * widget, the banner and the setup page) must accept either, and nothing
 * less.
 */
final class SuperAdminTest extends TestCase
{
    /**
     * A stand-in for a Grav user: `access` as Grav stores it, plus the
     * permissions authorize() would grant (for group grants).
     *
     * @param array<string,mixed> $access
     * @param array<int,string> $granted
     */
    private function user(array $access, bool $loggedIn = true, array $granted = []): object
    {
        return new class($access, $loggedIn, $granted) {
            public $authenticated;
            private $access;
            private $granted;
            public $scopes = [];

            public function __construct(array $access, bool $loggedIn, array $granted)
            {
                $this->access = $access;
                $this->authenticated = $loggedIn;
                $this->granted = $granted;
            }

            public function get(string $path)
            {
                $node = ['access' => $this->access];
                foreach (explode('.', $path) as $part) {
                    if (!is_array($node) || !array_key_exists($part, $node)) {
                        return null;
                    }
                    $node = $node[$part];
                }

                return $node;
            }

            public function authorize(string $action, ?string $scope = null): ?bool
            {
                $this->scopes[] = $scope;
                if ($scope !== 'test' && !$this->authenticated) {
                    return false;
                }
                if (in_array($action, $this->granted, true)) {
                    return true;
                }

                return $this->get('access.' . $action) === true ? true : null;
            }
        };
    }

    public function testEitherSuperPermissionCounts(): void
    {
        foreach ([true, false] as $apiEvent) {
            $this->assertTrue(SuperAdmin::check($this->user(['admin' => ['super' => true]]), $apiEvent), 'admin.super');
            $this->assertTrue(SuperAdmin::check($this->user(['api' => ['super' => true]]), $apiEvent), 'api.super only (native Grav 2)');
            $this->assertTrue(SuperAdmin::check($this->user(['admin' => ['super' => true], 'api' => ['super' => true]]), $apiEvent), 'both (migrated)');
        }
    }

    public function testAnythingLessIsRefused(): void
    {
        foreach ([true, false] as $apiEvent) {
            $this->assertFalse(SuperAdmin::check(null, $apiEvent));
            $this->assertFalse(SuperAdmin::check($this->user([]), $apiEvent));
            $this->assertFalse(SuperAdmin::check($this->user(['api' => ['access' => true, 'system' => ['read' => true]]]), $apiEvent));
            $this->assertFalse(SuperAdmin::check($this->user(['admin' => ['login' => true, 'pages' => true]]), $apiEvent));
            $this->assertFalse(SuperAdmin::check($this->user(['admin' => ['super' => false], 'api' => ['super' => 'no']]), $apiEvent));
            // a branch of permissions is not a grant of super
            $this->assertFalse(SuperAdmin::check($this->user(['api' => ['super' => ['x' => true]]]), $apiEvent));
        }
    }

    public function testASessionUserMustBeLoggedIn(): void
    {
        $this->assertFalse(SuperAdmin::check($this->user(['admin' => ['super' => true]], false)));
        $this->assertFalse(SuperAdmin::check($this->user(['api' => ['super' => true]], false)));
    }

    /** The API authenticates its own users; their objects are not a session. */
    public function testAnApiEventUserIsCheckedWithoutASession(): void
    {
        $this->assertTrue(SuperAdmin::check($this->user(['api' => ['super' => true]], false), true));
    }

    public function testAGroupGrantCounts(): void
    {
        $session = $this->user([], true, ['api.super']);
        $this->assertTrue(SuperAdmin::check($session));

        $event = $this->user([], false, ['admin.super']);
        $this->assertTrue(SuperAdmin::check($event, true));
        $this->assertContains('test', $event->scopes, 'an API user\'s groups are asked without a session');
    }
}
