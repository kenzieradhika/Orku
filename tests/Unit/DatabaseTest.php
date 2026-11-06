<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Data\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testPreparedValuesAndRollback(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite required');
        }
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->execute('CREATE TABLE users (name TEXT NOT NULL)');
        $name = "Ada'); DROP TABLE users; --";
        $db->execute('INSERT INTO users (name) VALUES (?)', [$name]);
        self::assertSame([['name' => $name]], $db->query('SELECT name FROM users'));
        try {
            $db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO users (name) VALUES (?)', ['Bob']);
                throw new \RuntimeException('rollback');
            });
            self::fail('Expected failure');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }
        self::assertCount(1, $db->query('SELECT name FROM users'));
        self::assertSame('committed', $db->transaction(function (Database $db): string {
            $db->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'Bob']);

            return 'committed';
        }));
        self::assertCount(2, $db->query('SELECT name FROM users'));
    }

    public function testNestedTransactionRejectedAndOuterRolledBack(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite required');
        }
        $pdo = new \PDO('sqlite::memory:');
        $db = new Database($pdo);
        $this->expectException(\LogicException::class);
        try {
            $db->transaction(fn (Database $db) => $db->transaction(fn () => null));
        } finally {
            self::assertFalse($pdo->inTransaction());
        }
    }
}
