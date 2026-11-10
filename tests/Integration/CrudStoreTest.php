<?php

declare(strict_types=1);

namespace Orku\Tests\Integration;

use Orku\CrudBench\CrudError;
use Orku\CrudBench\CrudStore;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/benchmarks/crud-api/CrudStore.php';

final class CrudStoreTest extends TestCase
{
    private CrudStore $store;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite required');
        }
        $this->store = CrudStore::connect(':memory:');
        $this->store->reset();
    }

    public function testResetIsDeterministicAndListIsBounded(): void
    {
        self::assertSame(['rows' => 200], $this->store->reset());
        $page = $this->store->list(['limit' => '2', 'offset' => '0']);
        self::assertSame(2, $page['count']);
        self::assertSame(['id' => 1, 'title' => 'Seed item 0001', 'body' => 'Seed body 0001', 'version' => 1, 'client_key' => 'seed-0001'], $page['data'][0]);
        self::assertSame(['rows' => 200, 'max_version' => 1, 'version_sum' => 200], $this->store->state());
    }

    public function testValidationRejectsUnknownFieldsWrongTypesAndOversizedValues(): void
    {
        foreach ([
            ['title' => '', 'body' => '', 'client_key' => 'k'],
            ['title' => 't', 'body' => '', 'client_key' => 'bad key'],
            ['title' => 't', 'body' => '', 'client_key' => 'k', 'extra' => 1],
            ['title' => 't', 'body' => str_repeat('x', 2001), 'client_key' => 'k'],
            ['title' => 't', 'body' => '', 'client_key' => 'k', 'version' => 1],
            ['title' => 't', 'body' => '', 'client_key' => "k\xFF"],
        ] as $payload) {
            try {
                $this->store->create($payload);
                self::fail('Expected validation failure');
            } catch (CrudError $error) {
                self::assertSame(422, $error->status);
            }
        }
    }

    public function testCreateUpdateDeleteAndConflictSemantics(): void
    {
        $created = $this->store->create(['title' => 'New', 'body' => 'Body', 'client_key' => 'new-1']);
        self::assertSame(201, $created['id']);
        self::assertSame(1, $created['version']);

        try {
            $this->store->create(['title' => 'New', 'body' => 'Body', 'client_key' => 'new-1']);
            self::fail('Expected duplicate conflict');
        } catch (CrudError $error) {
            self::assertSame(409, $error->status);
        }

        $updated = $this->store->replace('201', ['title' => 'Updated', 'body' => 'Body', 'version' => 1]);
        self::assertSame(2, $updated['version']);
        self::assertSame('Updated', $updated['title']);
        try {
            $this->store->replace('201', ['title' => 'Stale', 'body' => 'Body', 'version' => 1]);
            self::fail('Expected stale conflict');
        } catch (CrudError $error) {
            self::assertSame(409, $error->status);
        }
        try {
            $this->store->delete('201', '1');
            self::fail('Expected stale delete conflict');
        } catch (CrudError $error) {
            self::assertSame(409, $error->status);
        }
        self::assertNull($this->store->delete('201', '2'));
        try {
            $this->store->get('201');
            self::fail('Expected missing resource');
        } catch (CrudError $error) {
            self::assertSame(404, $error->status);
        }
    }

    public function testSQLShapedStringsRemainData(): void
    {
        $title = "Ada'); DROP TABLE items; --";
        $created = $this->store->create(['title' => $title, 'body' => "' OR 1=1 --", 'client_key' => 'sql-1']);
        self::assertSame($title, $created['title']);
        self::assertSame(201, $this->store->state()['rows']);
    }

    public function testFailureAfterInsertRollsBack(): void
    {
        try {
            $this->store->create(['title' => 'T', 'body' => 'B', 'client_key' => 'rollback-1'], true);
            self::fail('Expected simulated failure');
        } catch (\RuntimeException $error) {
            self::assertSame('simulated failure after insert', $error->getMessage());
        }
        self::assertSame(200, $this->store->state()['rows']);
    }
}
