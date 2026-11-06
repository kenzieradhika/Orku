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
        return self::report($membership, $mountinfo, $read, $file, $parse)['value'];
    }

    /**
     * @param callable(string): (string|false) $read
     * @param callable(string): (int|float|null) $parse
     * @return array{value: int|float|null, status: string}
     */
    public static function report(string $membership, string $mountinfo, callable $read, string $file, callable $parse): array
    {
        if (!in_array($file, ['memory.max', 'cpu.max'], true)) {
            return ['value' => null, 'status' => 'unsupported'];
        }
        preg_match_all('/^0::([^\r\n]+)$/m', $membership, $members);
        if ($members[1] === []) {
            return ['value' => null, 'status' => 'unsupported'];
        }
        if (count($members[1]) !== 1 || !self::validPath($members[1][0])) {
            return ['value' => null, 'status' => 'invalid'];
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
                    return ['value' => null, 'status' => 'unreadable'];
                }
                $value = trim($value);
                $unlimited = $file === 'memory.max' && $value === 'max';
                if ($file === 'cpu.max' && preg_match('/^max\s+([0-9]+)$/D', $value, $parts)) {
                    $period = Sense::memoryBytes($parts[1]);
                    $unlimited = $period !== null && $period > 0;
                }
                if (!$unlimited) {
                    if (($bytes = $parse($value)) === null) {
                        return ['value' => null, 'status' => 'invalid'];
                    }
                    $minimum = $minimum === null ? $bytes : min($minimum, $bytes);
                }
                if ($directory === $boundary) {
                    return ['value' => $minimum, 'status' => $minimum === null ? 'unlimited' : 'available'];
                }
                $directory = dirname($directory);
                if ($directory === '/') {
                    $directory = '';
                }
            }
        }

        return ['value' => null, 'status' => 'unmapped'];
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
