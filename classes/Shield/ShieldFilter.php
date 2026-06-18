<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Shield;

/**
 * Guard Shield request matcher (plan §7/§9.3) — the endpoint-WAF core. Pure: it
 * decides whether a request matches any rule, independent of Grav and the
 * network. A rule matches when its method set (empty = any), path regex, and
 * every param regex all match. The first matching rule wins; the caller acts on
 * its action (block | log).
 *
 * Patterns come from a signature-verified bundle, but matching still fails safe:
 * an unparseable regex simply doesn't match rather than throwing.
 */
final class ShieldFilter
{
    /**
     * The first rule matching the request, or null.
     *
     * @param array<string,mixed> $params merged query + body params
     * @param array<int,array<string,mixed>> $rules
     * @return array<string,mixed>|null
     */
    public static function match(string $method, string $path, array $params, array $rules): ?array
    {
        $method = strtoupper($method);

        foreach ($rules as $rule) {
            $methods = array_map('strtoupper', (array)($rule['methods'] ?? []));
            if ($methods !== [] && !in_array($method, $methods, true)) {
                continue;
            }
            if (!self::regexMatches((string)($rule['path_pattern'] ?? ''), $path)) {
                continue;
            }
            if (!self::paramsMatch((array)($rule['param_patterns'] ?? []), $params)) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    /**
     * Every named param pattern must match a present param. A param value that
     * is an array matches if any element matches.
     *
     * @param array<string,string> $patterns
     * @param array<string,mixed> $params
     */
    private static function paramsMatch(array $patterns, array $params): bool
    {
        foreach ($patterns as $name => $regex) {
            if (!array_key_exists($name, $params)) {
                return false;
            }
            $value = $params[$name];
            $values = is_array($value) ? $value : [$value];
            $hit = false;
            foreach ($values as $v) {
                if (is_scalar($v) && self::regexMatches((string)$regex, (string)$v)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }

        return true;
    }

    /** Safe regex test: delimited with #, errors treated as no-match. */
    private static function regexMatches(string $pattern, string $subject): bool
    {
        if ($pattern === '') {
            return false;
        }
        $delimited = '#' . str_replace('#', '\\#', $pattern) . '#';

        return @preg_match($delimited, $subject) === 1;
    }
}
