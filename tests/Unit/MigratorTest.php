<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Data\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testMigrationsRunOnceAndRejectChangedSql(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $migrator = new Migrator($pdo);
        $steps = ['001_users' => 'CREATE TABLE users (name TEXT NOT NULL)'];
        self::assertSame(['001_users'], $migrator->up($steps));
        self::assertSame([], $migrator->up($steps));
        $this->expectException(\LogicException::class);
        $migrator->up(['001_users' => 'CREATE TABLE different (id INTEGER)']);
    }

    public function testBatchFailureRollsBackSchemaAndHistory(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $migrator = new Migrator($pdo);
        try {
            $migrator->up(['001_users' => 'CREATE TABLE users (id INTEGER)', '002_broken' => 'INVALID SQL']);
            self::fail('Expected SQL failure');
        } catch (\PDOException) {
            self::assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('users', 'orku_migrations')")->fetchColumn());
        }
        self::assertSame(['001_users'], $migrator->up(['001_users' => 'CREATE TABLE users (id INTEGER)']));
    }

    public function testInvalidInputDoesNotCreateHistory(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $migrator = new Migrator($pdo);
        foreach ([['bad id' => 'SELECT 1'], ['002' => 'SELECT 1', '001' => 'SELECT 1'], ['001_empty' => '']] as $steps) {
            try {
                $migrator->up($steps);
                self::fail('Expected input rejection');
            } catch (\InvalidArgumentException) {
                self::assertFalse($pdo->inTransaction());
            }
        }
        self::assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'orku_migrations'")->fetchColumn());
    }

    public function testCallerTransactionIsNotChanged(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->beginTransaction();
        try {
            (new Migrator($pdo))->up(['001_test' => 'CREATE TABLE test (id INTEGER)']);
            self::fail('Expected nested transaction rejection');
        } catch (\LogicException) {
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }
}
