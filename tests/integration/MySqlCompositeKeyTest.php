<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\Tests\CompositeKeyTest;
use Light\Entity\Tests\CompositeItem;
use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Entity\DbContext;

final class MySqlCompositeKeyTest extends CompositeKeyTest
{
    use MySqlDatabase;

    protected function setUp(): void
    {
        $this->pdo = $this->connection();
        $this->pdo->exec("CREATE TABLE items (
            order_id VARCHAR(100) NOT NULL, product_id VARCHAR(100) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            doubled INT GENERATED ALWAYS AS (quantity * 2) STORED, label VARCHAR(100) NOT NULL UNIQUE,
            PRIMARY KEY (order_id, product_id)
        )");
        $this->openContext();
    }

    public function testAutoIncrementComponentIsAssignedAndReloadedWithFullTuple(): void
    {
        $this->pdo->exec('CREATE TABLE generated_items (
            tenant_id INT NOT NULL, id INT NOT NULL AUTO_INCREMENT,
            quantity INT NOT NULL DEFAULT 5, PRIMARY KEY (id, tenant_id)
        )');
        $db = new DbContext($this->pdo);
        $items = $db->set(CompositeItem::class, 'generated_items');
        self::assertSame('id', $db->model->getEntityType(CompositeItem::class)->generatedKeyProperty);
        $a = new CompositeItem();
        $a->tenant_id = 10;
        $b = new CompositeItem();
        $b->tenant_id = 10;
        $items->addRange([$a, $b]);
        self::assertSame(2, $db->saveChanges());
        self::assertIsInt($a->id);
        self::assertNotSame($a->id, $b->id);
        self::assertSame(5, $a->quantity);
        self::assertSame($a, $items->find(['tenant_id' => 10, 'id' => $a->id]));
        $a->quantity = 9;
        self::assertSame(1, $db->saveChanges());
        $items->remove($a);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(5, (new DbContext($this->pdo))->set(CompositeItem::class, 'generated_items')->first()->quantity);
    }

    public function testGeneratedCompositeIdsRemainUnsetAfterRollbackAndCanBeRetried(): void
    {
        $this->pdo->exec('CREATE TABLE generated_items (
            id INT NOT NULL AUTO_INCREMENT, tenant_id INT NOT NULL,
            label VARCHAR(100) NOT NULL UNIQUE, quantity INT NOT NULL DEFAULT 5,
            PRIMARY KEY (id, tenant_id)
        )');
        $db = new DbContext($this->pdo);
        $items = $db->set(CompositeItem::class, 'generated_items');
        $a = new CompositeItem();
        $a->tenant_id = 1;
        $a->label = 'duplicate';
        $b = new CompositeItem();
        $b->tenant_id = 1;
        $b->label = 'duplicate';
        $items->addRange([$a, $b]);
        try {
            $db->saveChanges();
            self::fail('Expected unique constraint failure.');
        } catch (\PDOException) {
            self::assertSame(0, $items->count());
        }
        self::assertFalse($a->hasValue('id'));
        self::assertFalse($a->hasValue('quantity'));
        $b->label = 'different';
        self::assertSame(2, $db->saveChanges());
        self::assertSame(5, $a->quantity);
        self::assertSame($a, $items->find(['id' => $a->id, 'tenant_id' => 1]));
    }
}
