<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use LogicException;
use PDO;
use Light\Entity\DbContext;
use Light\Entity\Entity;
use Light\Entity\Table;
use PHPUnit\Framework\TestCase;

#[Table('accounts')]
final class Account extends Entity {}
final class Widget extends Entity {}
final class OtherWidget extends Entity {}

final class RuntimeMappingTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for database integration tests.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE accounts (
            account_id INTEGER PRIMARY KEY,
            name TEXT NOT NULL DEFAULT 'Anonymous',
            active BOOLEAN NOT NULL DEFAULT 1,
            score REAL DEFAULT 1.5,
            balance DECIMAL(12,2) DEFAULT 12.50,
            note TEXT NULL,
            label TEXT GENERATED ALWAYS AS (name || '!') STORED
        )");
        $this->pdo->exec('CREATE TABLE widgets (id TEXT PRIMARY KEY, quantity INTEGER NOT NULL)');
        $this->db = new DbContext($this->pdo);
    }

    public function testSchemaProvidesKeyDefaultsTypesAndComputedValues(): void
    {
        $account = new Account();
        $this->db->set(Account::class)->add($account);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(1, $account->account_id);
        self::assertSame('Anonymous', $account->name);
        self::assertTrue($account->active);
        self::assertSame(1.5, $account->score);
        self::assertIsString($account->balance);
        self::assertSame('Anonymous!', $account->label);
        self::assertNull($account->note);
    }

    public function testSchemaTrackedUpdateRefreshesComputedValuesAndHydrates(): void
    {
        $account = $this->insertAccount();
        $account->name = 'Schema';
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Schema!', $account->label);
        $found = (new DbContext($this->pdo))->set(Account::class)->find($account->account_id);
        self::assertInstanceOf(Account::class, $found);
        self::assertSame('Schema', $found->name);
        self::assertSame('Schema!', $found->label);
    }

    public function testComputedColumnsCannotBeModified(): void
    {
        $account = $this->insertAccount();
        $account->label = 'forbidden';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Computed columns cannot be modified');
        $this->db->saveChanges();
    }

    public function testSchemaTrackedEntityCanBeDeleted(): void
    {
        $account = $this->insertAccount();
        $accounts = $this->db->set(Account::class);
        $accounts->remove($account);
        self::assertSame(1, $this->db->saveChanges());
        self::assertNull($accounts->find($account->account_id));
    }

    public function testConventionalTableNameAndNonGeneratedKey(): void
    {
        $widget = $this->insertWidget();
        self::assertSame($widget, $this->db->set(Widget::class)->find('w1'));
        $found = (new DbContext($this->pdo))->set(Widget::class)->first();
        self::assertSame('w1', $found->id);
        self::assertSame(4, $found->quantity);
    }

    public function testExplicitTableOverride(): void
    {
        $this->insertWidget();
        $found = (new DbContext($this->pdo))->set(OtherWidget::class, 'widgets')->first();
        self::assertSame(4, $found->quantity);
    }

    public function testCompositeKeyIsDiscovered(): void
    {
        $this->pdo->exec('CREATE TABLE composite (a INTEGER, b INTEGER, PRIMARY KEY(a,b))');
        $this->db->set(OtherWidget::class, 'composite');
        $mapping = $this->db->model->getEntityType(OtherWidget::class);
        self::assertSame(['a', 'b'], $mapping->keys);
        self::assertFalse($mapping->generatedKey);
    }

    public function testSchemaCacheIsScopedToContext(): void
    {
        $widget = $this->insertWidget();
        $widgets = $this->db->set(Widget::class);
        $this->pdo->exec("ALTER TABLE widgets ADD COLUMN extra TEXT DEFAULT 'new'");
        self::assertSame($widget, $widgets->first());
        self::assertFalse($widget->hasValue('extra'));
        $updated = (new DbContext($this->pdo))->set(Widget::class)->first();
        self::assertSame('new', $updated->extra);
    }

    private function insertAccount(): Account
    {
        $account = new Account();
        $this->db->set(Account::class)->add($account);
        $this->db->saveChanges();
        return $account;
    }

    private function insertWidget(): Widget
    {
        $widget = new Widget();
        $widget->id = 'w1';
        $widget->quantity = 4;
        $this->db->set(Widget::class)->add($widget);
        $this->db->saveChanges();
        return $widget;
    }
}
