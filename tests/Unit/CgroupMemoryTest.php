<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\ThinkCore\CgroupMemory;
use PHPUnit\Framework\TestCase;

final class CgroupMemoryTest extends TestCase
{
    public function testVisibleAncestorsAndMountMappings(): void
    {
        $mount = '31 22 0:28 / /sys/fs/cgroup rw - cgroup2 cgroup rw';
        $files = ['/sys/fs/cgroup/memory.max' => '4096', '/sys/fs/cgroup/team/memory.max' => '1024', '/sys/fs/cgroup/team/app/memory.max' => '2048'];
        self::assertSame(1024, CgroupMemory::ceiling("0::/team/app\n", $mount, fn (string $path) => $files[$path] ?? false));
        self::assertSame(1024, CgroupMemory::ceiling('0::/tenant/app', '31 22 0:28 /tenant /cg\\040root rw - cgroup2 cgroup rw', fn (string $path) => ['/cg root/memory.max' => '1024', '/cg root/app/memory.max' => 'max'][$path] ?? false));
        self::assertSame(512, CgroupMemory::ceiling('0::/', $mount, fn (string $path) => $path === '/sys/fs/cgroup/memory.max' ? '512' : false));
        self::assertSame(0, CgroupMemory::ceiling('0::/app', '31 22 0:28 / / rw - cgroup2 cgroup rw', fn (string $path) => $path === '/memory.max' ? '0' : 'max'));
    }

    public function testUnknownIsNeverReplacedWithPartialOrHostLimits(): void
    {
        $mount = '31 22 0:28 / /sys/fs/cgroup rw - cgroup2 cgroup rw';
        foreach (['max', '-1', 'garbage', '1.5', '999999999999999999999999999', false] as $value) {
            self::assertNull(CgroupMemory::ceiling('0::/app', $mount, fn (string $path) => str_ends_with($path, '/app/memory.max') ? $value : 'max'));
        }
        self::assertNull(CgroupMemory::ceiling('0::/app', $mount, fn (string $path) => str_ends_with($path, '/app/memory.max') ? '512' : false));
        foreach (['1:memory:/app', '0::/../app', '0::/app/../../escape', "0::/app\n0::/other", '0::/app//nested'] as $membership) {
            self::assertNull(CgroupMemory::ceiling($membership, $mount, fn () => '512'));
        }
        self::assertNull(CgroupMemory::ceiling('0::/tenant-other/app', '31 22 0:28 /tenant /cg rw - cgroup2 cgroup rw', fn () => '512'));
    }
}
