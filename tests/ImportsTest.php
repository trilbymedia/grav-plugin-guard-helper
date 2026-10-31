<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every class the plugin names without a namespace has to resolve. PHP only
 * finds out when the line runs, and the main plugin file needs Grav to load,
 * so nothing else here would notice: 0.2.0-beta.9 shipped calling
 * CloudUrl::from() from guard-helper.php with no `use` for it, and the classic
 * Guard page and setup route died with "Class not found".
 *
 * Checked: `new X`, `X::`, `instanceof X` and `catch (X`, in guard-helper.php
 * and every file under classes/.
 */
final class ImportsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return array<string,array{string}> */
    public static function files(): array
    {
        $files = ['guard-helper.php' => [self::ROOT . '/guard-helper.php']];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/classes', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen(self::ROOT) + 1)] = [$file->getPathname()];
            }
        }
        ksort($files);

        return $files;
    }

    /** @dataProvider files */
    public function testEveryUnqualifiedClassResolves(string $file): void
    {
        $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)), static function ($t): bool {
            return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));

        $namespace = '';
        $imports = [];
        $declared = [];
        $used = [];
        $depth = 0;
        foreach ($tokens as $i => $t) {
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = self::nameAt($tokens, $i + 1);
            } elseif ($t[0] === T_USE && $depth === 0) {
                // `use A\B;` or `use A\B as C;` at file level (not closures' use)
                $name = self::nameAt($tokens, $i + 1);
                $alias = substr($name, (int) strrpos('\\' . $name, '\\'));
                $next = $tokens[$i + 1 + self::nameLength($tokens, $i + 1)] ?? null;
                if (is_array($next) && $next[0] === T_AS) {
                    $alias = $tokens[$i + 2 + self::nameLength($tokens, $i + 1)][1];
                }
                $imports[strtolower($alias)] = $name;
            } elseif (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING) {
                $prev = $tokens[$i - 1] ?? null;
                if (!(is_array($prev) && $prev[0] === T_DOUBLE_COLON)) {
                    $declared[strtolower($tokens[$i + 1][1])] = true;
                }
            } elseif ($t[0] === T_STRING) {
                $prev = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;
                $qualified = (is_array($prev) && $prev[0] === T_NS_SEPARATOR)
                    || (is_array($next) && $next[0] === T_NS_SEPARATOR);
                if ($qualified) {
                    continue;
                }
                $asClass = (is_array($next) && $next[0] === T_DOUBLE_COLON)
                    || (is_array($prev) && in_array($prev[0], [T_NEW, T_INSTANCEOF], true))
                    || ($prev === '(' && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_CATCH);
                if ($asClass) {
                    $used[$t[1]] = $t[2];
                }
            }
        }

        $missing = [];
        foreach ($used as $name => $line) {
            $key = strtolower($name);
            if (in_array($key, ['self', 'static', 'parent'], true) || isset($imports[$key]) || isset($declared[$key])) {
                continue;
            }
            if ($namespace === '' && (class_exists($name) || interface_exists($name))) {
                continue;
            }
            if (self::inPlugin($namespace . '\\' . $name)) {
                continue;
            }
            $missing[] = "$name (line $line) resolves to " . ltrim($namespace . '\\' . $name, '\\') . ', which does not exist';
        }

        $this->assertSame([], $missing, basename($file) . ' names a class it does not import');
    }

    /** A class in this plugin's own namespace, found where its autoloader would look. */
    private static function inPlugin(string $class): bool
    {
        $prefix = 'Grav\\Plugin\\GuardHelper\\';
        if (strpos($class, $prefix) !== 0) {
            return false;
        }

        return is_file(self::ROOT . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');
    }

    /** The dotted-out name starting at $i: T_STRING/T_NS_SEPARATOR runs on 7.4, single tokens on 8. */
    private static function nameAt(array $tokens, int $i): string
    {
        $name = '';
        for ($n = self::nameLength($tokens, $i), $j = $i; $j < $i + $n; $j++) {
            $name .= $tokens[$j][1];
        }

        return ltrim($name, '\\');
    }

    private static function nameLength(array $tokens, int $i): int
    {
        $parts = [T_STRING, T_NS_SEPARATOR];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
            if (defined($c)) {
                $parts[] = constant($c);
            }
        }
        $n = 0;
        while (is_array($tokens[$i + $n] ?? null) && in_array($tokens[$i + $n][0], $parts, true)) {
            $n++;
        }

        return $n;
    }
}
