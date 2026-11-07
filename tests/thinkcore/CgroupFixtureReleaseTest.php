<?php

declare(strict_types=1);

namespace Orku\Tests\ThinkCore;

use Orku\ThinkCore\Cpu;
use Orku\ThinkCore\Cgroup;
use Orku\ThinkCore\CgroupMemory;
use Orku\ThinkCore\Sense;
use PHPUnit\Framework\TestCase;

final class CgroupFixtureReleaseTest extends TestCase
{
    public function testMemoryLimitUnitsUnlimitedMalformedAndStatus(): void
    {
        $membership = "0::/team/app\n";
        $mount = '31 22 0:28 / /sys/fs/cgroup rw - cgroup2 cgroup rw';
        $files = ['/sys/fs/cgroup/memory.max' => '1048576',
            '/sys/fs/cgroup/team/memory.max' => '524288',
            '/sys/fs/cgroup/team/app/memory.max' => 'max'];
        $reader = static fn (string $path): string|false => $files[$path] ?? false;
        $parseMemory = static fn (string $value): ?int => preg_match('/^[0-9]+$/D', $value)
            ? Sense::memoryBytes($value) : null;
        self::assertSame(524288, CgroupMemory::ceiling($membership, $mount, $reader));
        self::assertSame(['value' => 524288, 'status' => 'available'],
            Cgroup::report($membership, $mount, $reader, 'memory.max', $parseMemory));
        self::assertSame(['value' => null, 'status' => 'unlimited'],
            Cgroup::report('0::/\n', $mount, static fn () => 'max', 'memory.max', $parseMemory));
        self::assertSame(['value' => null, 'status' => 'invalid'],
            Cgroup::report('0::/\n', $mount, static fn () => '1.5', 'memory.max', $parseMemory));
        self::assertSame(['value' => null, 'status' => 'unreadable'],
            Cgroup::report('0::/\n', $mount, static fn () => false, 'memory.max', $parseMemory));
    }

    public function testCpuFractionalQuotaAndUnlimited(): void
    {
        self::assertSame(0.5, Cpu::quota('50000 100000'));
        self::assertNull(Cpu::quota('0 100000'));
        $mount = '31 22 0:28 / /sys/fs/cgroup rw - cgroup2 cgroup rw';
        self::assertSame(['value' => 0.25, 'status' => 'available'],
            Cgroup::report('0::/\n', $mount, static fn () => '25000 100000', 'cpu.max', Cpu::quota(...)));
        self::assertSame(['value' => null, 'status' => 'unlimited'],
            Cgroup::report('0::/\n', $mount, static fn () => 'max 100000', 'cpu.max', Cpu::quota(...)));
    }

    public function testAffinityRangesAndMalformedValues(): void
    {
        self::assertSame(6, Cpu::affinity("Name:\ttest\nCpus_allowed_list:\t0-3,8,10\n"));
        foreach (["Cpus_allowed_list:\t0-3,2-4\n", "Cpus_allowed_list:\t1,,2\n",
                  "Cpus_allowed_list:\t-1\n", "Cpus_allowed_list:\t0-999999999999999999999\n"] as $status) {
            self::assertNull(Cpu::affinity($status));
        }
    }

    public function testUnavailableAndUnsupportedRemainExplicit(): void
    {
        $mount = '31 22 0:28 / /sys/fs/cgroup rw - cgroup2 cgroup rw';
        $parse = static fn (string $value): ?int => preg_match('/^[0-9]+$/D', $value) ? (int) $value : null;
        self::assertSame(['value' => null, 'status' => 'unsupported'],
            Cgroup::report('1:memory:/app', $mount, static fn () => '1', 'memory.max', $parse));
        self::assertSame(['value' => null, 'status' => 'unsupported'],
            Cgroup::report('0::/app', $mount, static fn () => '1', 'memory.current', $parse));
        self::assertSame(['value' => null, 'status' => 'unmapped'],
            Cgroup::report('0::/app', '31 22 0:28 /tenant /cg rw - cgroup2 cgroup rw',
                static fn () => '1', 'memory.max', $parse));
    }
}
