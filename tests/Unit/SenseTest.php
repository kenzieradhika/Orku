<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\ThinkCore\Sense;
use PHPUnit\Framework\TestCase;

final class SenseTest extends TestCase
{
    public function testMemoryLimitsAreParsedWithoutGuessing(): void
    {
        foreach (['128M' => 134217728, '1G' => 1073741824, '512K' => 524288, '1024' => 1024, '0' => 0] as $input => $expected) {
            self::assertSame($expected, Sense::memoryBytes((string) $input));
        }
        foreach (['-1', '', 'garbage', '1.5G', '9223372036854775807G', '-2', '1T'] as $input) {
            self::assertNull(Sense::memoryBytes($input));
        }
    }

    public function testObserveIsReadOnlyAndMarksUnavailableResources(): void
    {
        $before = ini_get('memory_limit');
        $report = Sense::observe();
        self::assertSame('observe', $report['mode']);
        self::assertSame(PHP_VERSION, $report['php']);
        self::assertSame($before, ini_get('memory_limit'));
        self::assertNull($report['effective_cpu_quota']);
        self::assertNull($report['effective_memory_bytes']);
        self::assertNotSame('', $report['resource_unavailable_reason']);
    }
}
