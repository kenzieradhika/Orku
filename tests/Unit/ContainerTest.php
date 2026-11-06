<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Container\CleanupException;
use Orku\Container\Container;
use Orku\Container\Resolver;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testRetainedResolverHasNoStaleCycle(): void
    {
        $container = new Container();
        $container->bind('lazy', fn (Resolver $resolver) => (object) ['resolver' => $resolver], 'singleton');
        $value = $container->get('lazy');
        self::assertInstanceOf(\stdClass::class, $value);
        self::assertInstanceOf(Resolver::class, $value->resolver);
        self::assertSame($value, $value->resolver->get('lazy'));
    }

    public function testInvalidFactoryResultIsNotCached(): void
    {
        $attempts = 0;
        $bad = new Container();
        $factory = function () use (&$attempts) {
            ++$attempts;

            return 'invalid';
        };
        /** @var callable(Resolver): object $factory */
        $bad->bind('invalid', $factory, 'singleton');
        for ($i = 0; $i < 2; ++$i) {
            try {
                $bad->get('invalid');
                self::fail('Expected invalid factory result');
            } catch (\TypeError) {
                self::assertSame($i + 1, $attempts);
            }
        }
    }

    public function testCannotCloseScopeDuringConstruction(): void
    {
        $container = new Container();
        $container->bind('closing', function () use (&$scope): object {
            $scope->close();

            return new \stdClass();
        }, 'request');
        $scope = $container->scope();
        $this->expectException(\LogicException::class);
        try {
            $scope->get('closing');
        } finally {
            $scope->close();
        }
    }

    public function testLifetimesAndIsolation(): void
    {
        $container = new Container();
        foreach (['singleton', 'request', 'transient'] as $lifetime) {
            $container->bind($lifetime, fn () => new \stdClass(), $lifetime);
        }
        $first = $container->scope();
        $second = $container->scope();
        self::assertSame($first->get('singleton'), $second->get('singleton'));
        self::assertSame($first->get('request'), $first->get('request'));
        self::assertNotSame($first->get('request'), $second->get('request'));
        self::assertNotSame($first->get('transient'), $first->get('transient'));
        $reference = \WeakReference::create($first->get('request'));
        $first->close();
        self::assertNull($reference->get());
        $second->close();
        $third = $container->scope();
        self::assertInstanceOf(\stdClass::class, $third->get('request'));
        $third->close();
    }

    public function testFailedFactoryRetriesAndCyclesUnwind(): void
    {
        $container = new Container();
        $attempts = 0;
        $container->bind('retry', function () use (&$attempts): object {
            if (++$attempts === 1) {
                throw new \RuntimeException('first attempt');
            }

            return new \stdClass();
        });
        $container->bind('cycle', fn (Resolver $r) => $r->get('cycle'));
        try {
            $container->get('retry');
            self::fail('Expected factory failure');
        } catch (\RuntimeException) {
            self::assertInstanceOf(\stdClass::class, $container->get('retry'));
        }
        $this->expectException(\LogicException::class);
        $container->get('cycle');
    }

    public function testInvalidResolutionAndRegistration(): void
    {
        foreach (['root', 'capture', 'indirect', 'unknown', 'duplicate', 'freeze', 'worker', 'empty', 'closed'] as $case) {
            $container = new Container();
            $container->bind('request', fn () => new \stdClass(), 'request');
            $container->bind('transient', fn (Resolver $r) => $r->get('request'));
            $container->bind('singleton', fn (Resolver $r) => $r->get($case === 'indirect' ? 'transient' : 'request'), 'singleton');
            try {
                match ($case) {
                    'root' => $container->get('request'),
                    'capture', 'indirect' => $container->scope()->get('singleton'),
                    'unknown' => $container->get('missing'),
                    'duplicate' => $container->bind('request', fn () => new \stdClass()),
                    'freeze' => (function () use ($container): void {
                        $container->scope();
                        $container->bind('later', fn () => new \stdClass());
                    })(),
                    'worker' => $container->bind('worker', fn () => new \stdClass(), 'worker'),
                    'empty' => $container->bind('', fn () => new \stdClass()),
                    'closed' => (function () use ($container): void {
                        $scope = $container->scope();
                        $scope->close();
                        $scope->get('request');
                    })(),
                };
                self::fail('Expected rejection: '.$case);
            } catch (\LogicException|\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testCleanupContinuesInReverseOrderAndRunsOnce(): void
    {
        $container = new Container();
        $released = [];
        foreach (['first', 'second'] as $id) {
            $container->bind($id, fn () => new \stdClass(), 'request', function (object $value) use (&$released, $id): void {
                $released[] = $id;
                if ($id === 'second') {
                    throw new \RuntimeException('release failed');
                }
            });
        }
        $scope = $container->scope();
        $scope->get('first');
        $reference = \WeakReference::create($scope->get('second'));
        try {
            $scope->close();
            self::fail('Expected cleanup failure');
        } catch (CleanupException $error) {
            self::assertCount(1, $error->causes);
        }
        $scope->close();
        self::assertSame(['second', 'first'], $released);
        unset($error);
        self::assertNull($reference->get());
    }
}
