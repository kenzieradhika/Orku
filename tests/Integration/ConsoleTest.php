<?php

declare(strict_types=1);

namespace Orku\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    private function command(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/bin/orku', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testHelpListsOnlyImplementedCommands(): void
    {
        [$status, $out] = $this->command(['help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('serve', $out);
        self::assertStringContainsString('routes', $out);
        self::assertStringContainsString('doctor', $out);
    }

    public function testDoctorReportsCapabilitiesWithoutSecrets(): void
    {
        [$status, $out, $err] = $this->command(['doctor']);
        self::assertSame(0, $status);
        self::assertSame('', $err);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(PHP_VERSION, $report['php']);
        self::assertTrue($report['checks']['supported_php']);
        self::assertArrayHasKey('debug_disabled', $report['checks']);
        self::assertArrayNotHasKey('environment', $report);
    }

    public function testRouteListUsesRealApplication(): void
    {
        [$status, $out] = $this->command(['routes', 'rest-api']);
        self::assertSame(0, $status);
        self::assertStringContainsString('POST /api/v1/greetings', $out);
        self::assertStringContainsString('GET /api/v1/users/{id}', $out);
    }

    public function testThinkCoreObserveOutputsReadOnlyReport(): void
    {
        [$status, $out] = $this->command(['thinkcore:observe']);
        self::assertSame(0, $status);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('observe', $report['mode']);
        self::assertNull($report['effective_cpu_quota']);
    }

    public function testUnknownCommandsAndUnsafeArgumentsFail(): void
    {
        foreach ([['doctor', 'extra'], ['routes', '../..'], ['serve', 'hello-world', '127.0.0.1:99999'], ['serve', 'hello-world', '127.0.0.1:8000;id'], ['serve', 'missing'], ['help', 'extra']] as $arguments) {
            [$status, , $err] = $this->command($arguments);
            self::assertNotSame(0, $status);
            self::assertNotSame('', $err);
        }
    }
}
