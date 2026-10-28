<?php

// Just enough of the API plugin and PSR-7 for GuardController to load in a
// plain PHPUnit run. Each is declared only if the real one is not loaded, and
// AbstractApiController copies the scope-cap behaviour of the real class
// (grav-plugin-api, AbstractApiController::requirePermission / requireSuper).

namespace Psr\Http\Message {
    if (!interface_exists(ServerRequestInterface::class, false)) {
        interface ServerRequestInterface
        {
            public function getAttribute($name, $default = null);
        }
    }
    if (!interface_exists(ResponseInterface::class, false)) {
        interface ResponseInterface
        {
        }
    }
}

namespace Grav\Plugin\Api\Exceptions {
    if (!class_exists(ForbiddenException::class, false)) {
        class ForbiddenException extends \RuntimeException
        {
        }
    }
    if (!class_exists(ValidationException::class, false)) {
        class ValidationException extends \RuntimeException
        {
        }
    }
}

namespace Grav\Plugin\Api\Response {
    if (!class_exists(ApiResponse::class, false)) {
        class ApiResponse
        {
        }
    }
}

namespace Grav\Plugin\Api\Controllers {
    use Grav\Plugin\Api\Exceptions\ForbiddenException;
    use Psr\Http\Message\ServerRequestInterface;

    if (!class_exists(AbstractApiController::class, false)) {
        abstract class AbstractApiController
        {
            protected function getUser(ServerRequestInterface $request)
            {
                return $request->getAttribute('api_user');
            }

            protected function isSuperAdmin($user): bool
            {
                return !empty($user->super);
            }

            protected function scopeAllows(ServerRequestInterface $request, string $permission): bool
            {
                $scopes = $request->getAttribute('api_key_scopes');

                return !is_array($scopes) || $scopes === [] || in_array($permission, $scopes, true) || in_array('*', $scopes, true);
            }

            protected function requirePermission(ServerRequestInterface $request, string $permission): void
            {
                if (!$this->scopeAllows($request, $permission)) {
                    throw new ForbiddenException("API key is not authorized for: {$permission}");
                }
                if ($this->isSuperAdmin($this->getUser($request))) {
                    return;
                }
                throw new ForbiddenException("Missing required permission: {$permission}");
            }

            protected function requireSuper(ServerRequestInterface $request): void
            {
                $this->requirePermission($request, 'admin.super');
            }
        }
    }
}
