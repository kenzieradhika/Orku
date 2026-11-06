<?php

declare(strict_types=1);

namespace Orku\ThinkCore;

final class Cpu
{
    public static function quota(string $value): ?float
    {
        if (!preg_match('/^([0-9]+)\s+([0-9]+)$/D', trim($value), $parts)) {
            return null;
        }
        $quota = Sense::memoryBytes($parts[1]);
        $period = Sense::memoryBytes($parts[2]);

        return $quota !== null && $quota > 0 && $period !== null && $period > 0 ? $quota / $period : null;
    }

    public static function affinity(string $status): ?int
    {
        preg_match_all('/^Cpus_allowed_list:[ \t]*([^\r\n]*)$/m', $status, $fields);
        if (count($fields[1]) !== 1) {
            return null;
        }
        $count = 0;
        $previous = -1;
        foreach (explode(',', trim($fields[1][0])) as $range) {
            if (!preg_match('/^([0-9]+)(?:-([0-9]+))?$/D', $range, $parts)) {
                return null;
            }
            $start = Sense::memoryBytes($parts[1]);
            $end = Sense::memoryBytes($parts[2] ?? $parts[1]);
            if ($start === null || $end === null || $start <= $previous || $end < $start || $end - $start >= PHP_INT_MAX - $count) {
                return null;
            }
            $count += $end - $start + 1;
            $previous = $end;
        }

        return $count;
    }

    /** @param callable(string): (string|false) $read */
    public static function ceiling(string $membership, string $mountinfo, callable $read): ?float
    {
        $limit = Cgroup::ceiling($membership, $mountinfo, static function (string $path) use ($read): string|false {
            $value = $read($path);
            if ($value !== false && preg_match('/^max\s+([0-9]+)$/D', trim($value), $parts)) {
                $period = Sense::memoryBytes($parts[1]);

                return $period !== null && $period > 0 ? 'max' : false;
            }

            return $value !== false && trim($value) === 'max' ? false : $value;
        }, 'cpu.max', self::quota(...));

        return $limit !== null ? (float) $limit : null;
    }
}
