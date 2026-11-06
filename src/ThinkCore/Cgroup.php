<?php

declare(strict_types=1);

namespace Orku\ThinkCore;

final class Cgroup
{
    /**
     * @param callable(string): (string|false) $read
     * @param callable(string): (int|float|null) $parse
     */
    public static function ceiling(string $membership, string $mountinfo, callable $read, string $file, callable $parse): int|float|null
    {
        if (!in_array($file, ['memory.max', 'cpu.max'], true)) {
            return null;
        }
        preg_match_all('/^0::([^\r\n]+)$/m', $membership, $members);
        if (count($members[1]) !== 1 || !self::validPath($members[1][0])) {
            return null;
        }
        $group = $members[1][0];
        foreach (explode("\n", $mountinfo) as $line) {
            $parts = explode(' - ', $line, 2);
            if (!isset($parts[1]) || !str_starts_with($parts[1], 'cgroup2 ')) {
                continue;
            }
            $fields = explode(' ', $parts[0]);
            if (count($fields) < 6) {
                continue;
            }
            $root = self::decode($fields[3]);
            $mount = self::decode($fields[4]);
            if (!self::validPath($root) || !self::validPath($mount) || ($root !== '/' && $group !== $root && !str_starts_with($group, $root.'/'))) {
                continue;
            }
            $relative = $root === '/' ? $group : substr($group, strlen($root));
            $directory = rtrim($mount, '/').($relative === '/' ? '' : $relative);
            $boundary = rtrim($mount, '/');
            $minimum = null;
            while (true) {
                $value = $read($directory.'/'.$file);
                if ($value === false) {
                    return null;
                }
                $value = trim($value);
                if ($value !== 'max') {
                    if (($bytes = $parse($value)) === null) {
                        return null;
                    }
                    $minimum = $minimum === null ? $bytes : min($minimum, $bytes);
                }
                if ($directory === $boundary) {
                    return $minimum;
                }
                $directory = dirname($directory);
                if ($directory === '/') {
                    $directory = '';
                }
            }
        }

        return null;
    }

    private static function decode(string $path): string
    {
        return strtr($path, ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
    }

    private static function validPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_contains($path, "\0") && ($path === '/' || !str_ends_with($path, '/')) && !preg_match('~/(?:\.{1,2}(?:/|$)|/)~', $path);
    }
}
