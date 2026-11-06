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

    /** @return array{mode: string, php: string, platform: string, php_memory_limit_bytes: null|int, php_memory_limit_reason: null|string, cgroup_visible_memory_limit_bytes: null|int, cgroup_visible_cpu_quota: null|float, cpu_affinity_count: null|int, effective_cpu_quota: null, effective_memory_bytes: null, measurement_status: array<string, string>, resource_unavailable_reason: string} */
    public static function observe(): array
    {
        $limit = (string) ini_get('memory_limit');
        $bytes = self::memoryBytes($limit);
        $memory = $cpu = ['value' => null, 'status' => PHP_OS_FAMILY === 'Linux' ? 'unreadable' : 'unsupported'];
        $affinityStatus = PHP_OS_FAMILY === 'Linux' ? 'unreadable' : 'unsupported';
        $affinity = null;
        if (PHP_OS_FAMILY === 'Linux') {
            $status = @file_get_contents('/proc/self/status');
            $affinity = $status !== false ? Cpu::affinity($status) : null;
            if ($status !== false) {
                $affinityStatus = $affinity === null ? 'invalid' : 'available';
            }
            $membership = @file_get_contents('/proc/self/cgroup');
            $mountinfo = @file_get_contents('/proc/self/mountinfo');
            if ($membership !== false && $mountinfo !== false) {
                $read = static fn (string $path): string|false => @file_get_contents($path);
                $memory = Cgroup::report($membership, $mountinfo, $read, 'memory.max', static fn (string $value): ?int => preg_match('/^[0-9]+$/D', $value) ? self::memoryBytes($value) : null);
                $cpu = Cgroup::report($membership, $mountinfo, $read, 'cpu.max', Cpu::quota(...));
            }
        }

        return [
            'mode' => 'observe', 'php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY,
            'php_memory_limit_bytes' => $bytes,
            'php_memory_limit_reason' => $bytes !== null ? null : (trim($limit) === '-1' ? 'PHP memory limit is unlimited; not an available RAM measurement.' : 'PHP memory limit format unsupported or overflowed.'),
            'cgroup_visible_memory_limit_bytes' => is_int($memory['value']) ? $memory['value'] : null,
            'cgroup_visible_cpu_quota' => $cpu['value'] !== null ? (float) $cpu['value'] : null, 'cpu_affinity_count' => $affinity,
            'measurement_status' => [
                'php_memory_limit' => $bytes !== null ? 'available' : (trim($limit) === '-1' ? 'unlimited' : 'invalid'),
                'cgroup_memory' => $memory['status'], 'cgroup_cpu' => $cpu['status'],
                'cpu_affinity' => $affinityStatus, 'effective_capacity' => 'unknown',
            ],
            'effective_cpu_quota' => null, 'effective_memory_bytes' => null,
            'resource_unavailable_reason' => 'Visible cgroup v2 ceilings and process CPU affinity only; hidden ancestors and host RAM are unknown. Null cgroup ceiling means unavailable, unsupported, invalid or unlimited. These snapshots are not effective capacity guarantees.',
        ];
    }
}
