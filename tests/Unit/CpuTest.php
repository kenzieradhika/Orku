<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\ThinkCore\Cpu;
use PHPUnit\Framework\TestCase;

final class CpuTest extends TestCase
{
    public function testFractionalQuotasRejectMalformedAndOverflowingValues(): void
    {
        self::assertSame(0.5, Cpu::quota('50000 100000'));
        self::assertSame(2.0, Cpu::quota("200000 100000\n"));
        foreach (['max 100000', 'max 0', '0 100000', '-1 100000', '100 0', '1.5 100', '100', '100 100 extra', '9999999999999999999999 1'] as $value) {
            self::assertNull(Cpu::quota($value));
        }
    }

    public function testAffinityCountsRangesWithoutExpandingCpuIds(): void
    {
        self::assertSame(6, Cpu::affinity("Name:\tphp\nCpus_allowed_list:\t0-3,8,10\n"));
        self::assertSame(1, Cpu::affinity("Cpus_allowed_list:\t5\n"));
        foreach (['', '3-1', '0-3,2', '1,1', '2,1', '1,,2', '-1', '999999999999999999999999', '0-9223372036854775807'] as $value) {
            self::assertNull(Cpu::affinity('Cpus_allowed_list: '.$value));
        }
        self::assertNull(Cpu::affinity("Name:\tphp\n"));
        self::assertNull(Cpu::affinity("Cpus_allowed_list: 0\nCpus_allowed_list: 1\n"));
    }

    public function testVisibleCpuQuotaUsesEveryAncestor(): void
    {
        $mount = '31 22 0:28 /tenant /cg\\040root rw - cgroup2 cgroup rw';
        $files = ['/cg root/app/cpu.max' => '200000 100000', '/cg root/cpu.max' => '50000 100000'];
        self::assertSame(0.5, Cpu::ceiling('0::/tenant/app', $mount, fn (string $path) => $files[$path] ?? false));
        self::assertSame(2.0, Cpu::ceiling('0::/tenant/app', $mount, fn (string $path) => str_contains($path, '/app/') ? '200000 100000' : 'max 100000'));
        self::assertNull(Cpu::ceiling('0::/tenant/app', $mount, fn () => 'max 100000'));
        self::assertNull(Cpu::ceiling('0::/../escape', $mount, fn () => '50000 100000'));
        self::assertSame(0.25, Cpu::ceiling('0::/', '31 22 0:28 / / rw - cgroup2 cgroup rw', fn (string $path) => $path === '/cpu.max' ? '25000 100000' : false));
        foreach ([false, 'invalid', 'max 0', '1 0', "max\n"] as $parent) {
            self::assertNull(Cpu::ceiling('0::/tenant/app', $mount, fn (string $path) => str_contains($path, '/app/') ? '200000 100000' : $parent));
        }
    }
}
