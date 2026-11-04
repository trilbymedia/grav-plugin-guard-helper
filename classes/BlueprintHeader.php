<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper;

/**
 * The top-level `name` and `version` of a blueprints.yaml, read line by line.
 *
 * Parsing the whole file as YAML to learn two words is slow on a site with a
 * few dozen plugins and themes, and the security check does it for every
 * package. These two keys are plain `key: value` lines at the start of a
 * line, so a regex finds them; keys nested deeper (a dependency listed as
 * `- { name: ... }`) are indented and never match.
 *
 * Whatever is not clearly a plain string (a number, a boolean, an escape, a
 * multi-line value, a block scalar) is not guessed at: the reader says it
 * could not tell, and the caller parses that one file as YAML.
 */
final class BlueprintHeader
{
    /**
     * @return array{name:string, version:string}|null null when either key is
     *   missing or its value needs a real YAML parser to read
     */
    public static function read(string $source): ?array
    {
        $name = self::scalar($source, 'name');
        $version = self::scalar($source, 'version');

        return ($name === null || $version === null) ? null : ['name' => $name, 'version' => $version];
    }

    /**
     * The string value of the first top-level `$key:` line, or null when
     * there is none or it is not safely a plain string.
     */
    public static function scalar(string $source, string $key): ?string
    {
        if (!preg_match('/^' . preg_quote($key, '/') . ':[ \t]*(.*?)[ \t\r]*$/m', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $value = $m[1][0];
        $end = $m[0][1] + strlen($m[0][0]);

        // A value that carries on over the next indented line is a multi-line scalar.
        if (preg_match('/\G\R[ \t]+\S/', $source, $_, 0, $end)) {
            return null;
        }

        if ($value === '') {
            return null;
        }
        if ($value[0] === "'") {
            if (!preg_match("/^'((?:[^']|'')*)'[ \\t]*(?:#.*)?\$/", $value, $q)) {
                return null;
            }

            return str_replace("''", "'", $q[1]);
        }
        if ($value[0] === '"') {
            // An escape sequence needs YAML's rules to decode.
            if (!preg_match('/^"([^"\\\\]*)"[ \t]*(?:#.*)?$/', $value, $q)) {
                return null;
            }

            return $q[1];
        }

        return self::plain($value);
    }

    /** An unquoted value, or null when YAML might read it as anything but a string. */
    private static function plain(string $value): ?string
    {
        $value = (string) preg_replace('/[ \t]+#.*$/', '', $value);
        if ($value === '' || strpos($value, ': ') !== false || substr($value, -1) === ':') {
            return null;
        }
        // Indicators that start a list, map, block scalar, anchor, tag, alias or comment.
        if (strpos("-?[]{}|>&*!%@`#,'\"", $value[0]) !== false) {
            return null;
        }
        // Numbers, booleans and null are not strings to YAML.
        if (preg_match('/^[-+]?(?:[0-9][0-9_]*)?\.?[0-9_]*(?:[eE][-+]?[0-9]+)?$/', $value)
            || preg_match('/^[-+]?0[xXoObB]/', $value)
            || preg_match('/^[-+]?\.(?:inf|nan)$/i', $value)
            || in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off', 'y', 'n', 'null', '~'], true)
        ) {
            return null;
        }

        return $value;
    }
}
