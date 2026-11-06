<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\ThinkCore\Cgroup;
use Orku\ThinkCore\Cpu;
use Orku\ThinkCore\Sense;
use PHPUnit\Framework\TestCase;

final class ObservationTest extends TestCase
{
    public function testMeasurementStatusesDoNotConfuseUnlimitedWithFailures(): void
    {
        $mount = '31 22 0:28 / /cg rw - cgroup2 cgroup rw';
        foreach ([['1024', 'available', 1024], ['max', 'unlimited', null], [false, 'unreadable', null], ['-1', 'invalid', null], ['99999999999999999999999', 'invalid', null]] as [$value, $status, $limit]) {
            self::assertSame(['value' => $limit, 'status' => $status], Cgroup::report('0::/', $mount, fn () => $value, 'memory.max', Sense::memoryBytes(...)));
        }
        self::assertSame('unsupported', Cgroup::report('1:memory:/app', $mount, fn () => '1024', 'memory.max', Sense::memoryBytes(...))['status']);
        self::assertSame('invalid', Cgroup::report('0::/../app', $mount, fn () => '1024', 'memory.max', Sense::memoryBytes(...))['status']);
        self::assertSame('unmapped', Cgroup::report('0::/app', '', fn () => '1024', 'memory.max', Sense::memoryBytes(...))['status']);
        self::assertSame(['value' => null, 'status' => 'unreadable'], Cgroup::report('0::/app', $mount, fn (string $path) => $path === '/cg/app/memory.max' ? '1024' : false, 'memory.max', Sense::memoryBytes(...)));
    }

    public function testObserveIncludesIndependentMeasurementStatuses(): void
    {
        $report = Sense::observe();
        self::assertArrayHasKey('measurement_status', $report);
        foreach (['php_memory_limit', 'cgroup_memory', 'cgroup_cpu', 'cpu_affinity'] as $name) {
            self::assertContains($report['measurement_status'][$name], ['available', 'unlimited', 'unreadable', 'invalid', 'unsupported', 'unmapped']);
        }
        self::assertSame('unknown', $report['measurement_status']['effective_capacity']);
    }

    public function testCpuUnlimitedRequiresAValidPeriod(): void
    {
        $mount = '31 22 0:28 / /cg rw - cgroup2 cgroup rw';
        foreach ([['max 100000', 'unlimited', null], ['max 0', 'invalid', null], ['max', 'invalid', null], ['50000 100000', 'available', 0.5], [false, 'unreadable', null]] as [$value, $status, $limit]) {
            self::assertSame(['value' => $limit, 'status' => $status], Cgroup::report('0::/', $mount, fn () => $value, 'cpu.max', Cpu::quota(...)));
        }
    }
}
