<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

/**
 * The Guard Cloud base URL from the plugin config.
 *
 * Guard Cloud moved from gravguard.com to cloud.gravguard.com; gravguard.com is
 * now the marketing site. A site whose saved config still holds the old default
 * gets the new host, so its pairing, release downloads, advisory feed and Shield
 * rules keep reaching the cloud. Any other configured URL is used as written.
 */
final class CloudUrl
{
    public const DEFAULT = 'https://cloud.gravguard.com';
    private const MOVED = ['https://gravguard.com', 'https://www.gravguard.com'];

    public static function from(mixed $configured): string
    {
        $url = rtrim(trim((string) ($configured ?? '')), '/');
        if ($url === '' || in_array(strtolower($url), self::MOVED, true)) {
            return self::DEFAULT;
        }

        return $url;
    }
}
