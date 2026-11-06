<?php

declare(strict_types=1);

namespace Orku\ThinkCore;

final class CgroupMemory
{
    /** @param callable(string): (string|false) $read */
    public static function ceiling(string $membership, string $mountinfo, callable $read): ?int
    {
        $limit = Cgroup::ceiling($membership, $mountinfo, $read, 'memory.max', static fn (string $value): ?int => preg_match('/^[0-9]+$/D', $value) ? Sense::memoryBytes($value) : null);

        return is_int($limit) ? $limit : null;
    }
}
