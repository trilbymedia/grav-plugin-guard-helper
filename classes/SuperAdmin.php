<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

/**
 * Who counts as a super-admin for Guard Helper: an account with `admin.super`
 * (classic admin, and accounts migrated from Grav 1.7) or `api.super` (what
 * Grav 2 gives a super-admin, e.g. `bin/plugin login new-user`). Either one,
 * granted directly or through a group.
 *
 * The admin API's own routes still apply its stricter rule (GuardController
 * asks the API plugin, which also applies an API key's scope); this is for the
 * checks the plugin makes itself: the widget, the banner and the setup page.
 */
final class SuperAdmin
{
    public const PERMISSIONS = ['admin.super', 'api.super'];

    /**
     * @param mixed $user a Grav user object, or null
     * @param bool $authenticated true when the caller has already authenticated
     *   this user (the admin API hands its events an authenticated user, but
     *   does not mark the object as a logged-in session). False for a session
     *   user, who must be logged in and past 2FA, which authorize() checks.
     */
    public static function check($user, bool $authenticated = false): bool
    {
        if (!is_object($user)) {
            return false;
        }
        // A session user has to be logged in; authorize() checks this too,
        // but the setup page always has, so it stays explicit.
        if (!$authenticated && !$user->authenticated) {
            return false;
        }

        foreach (self::PERMISSIONS as $permission) {
            if ($authenticated) {
                if (method_exists($user, 'get') && self::granted($user->get('access.' . $permission))) {
                    return true;
                }
                // Group grants; the "test" scope evaluates the account's
                // permissions without asking for a session.
                if (self::authorize($user, $permission, 'test')) {
                    return true;
                }
            } elseif (self::authorize($user, $permission, null)) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $user */
    private static function authorize($user, string $permission, ?string $scope): bool
    {
        if (!method_exists($user, 'authorize')) {
            return false;
        }
        try {
            return ($scope === null ? $user->authorize($permission) : $user->authorize($permission, $scope)) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @param mixed $value */
    private static function granted($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
