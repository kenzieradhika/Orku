<?php

declare(strict_types=1);

namespace Orku\ThinkCore;

final class Sense
{
    public static function memoryBytes(string $value): ?int
    {
        if (!preg_match('/^([0-9]+)([KMG]?)$/Di', trim($value), $matches)) {
            return null;
        }
        $factor = match (strtoupper($matches[2])) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1,
        };
        $digits = ltrim($matches[1], '0');
        $maximum = (string) intdiv(PHP_INT_MAX, $factor);
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }

        return (int) $digits * $factor;
    }

    /** @return array{mode: string, php: string, platform: string, php_memory_limit_bytes: null|int, php_memory_limit_reason: null|string, cgroup_visible_memory_limit_bytes: null|int, effective_cpu_quota: null, effective_memory_bytes: null, resource_unavailable_reason: string} */
    public static function observe(): array
    {
        $limit = (string) ini_get('memory_limit');
        $bytes = self::memoryBytes($limit);
        $cgroupMemory = null;
        if (PHP_OS_FAMILY === 'Linux') {
            $membership = @file_get_contents('/proc/self/cgroup');
            $mountinfo = @file_get_contents('/proc/self/mountinfo');
            if ($membership !== false && $mountinfo !== false) {
                $cgroupMemory = CgroupMemory::ceiling($membership, $mountinfo, static fn (string $path): string|false => @file_get_contents($path));
            }
        }

        return [
            'mode' => 'observe', 'php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY,
            'php_memory_limit_bytes' => $bytes,
            'php_memory_limit_reason' => $bytes !== null ? null : (trim($limit) === '-1' ? 'PHP memory limit is unlimited; not an available RAM measurement.' : 'PHP memory limit format unsupported or overflowed.'),
            'cgroup_visible_memory_limit_bytes' => $cgroupMemory,
            'effective_cpu_quota' => null, 'effective_memory_bytes' => null,
            'resource_unavailable_reason' => 'Visible cgroup v2 memory ceiling only; hidden ancestors, host RAM and CPU affinity are unknown. Null visible ceiling means unavailable, unsupported, invalid or unlimited.',
        ];
    }
}
