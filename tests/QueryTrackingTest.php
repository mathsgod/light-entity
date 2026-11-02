<?php

declare(strict_types=1);

namespace PhpEntity\Tests\QueryTracking;

use PDO;
use PhpEntity\{DbContext, DynamicEntity, Entity, EntityState, QueryTrackingBehavior, SaveChangesInterceptor, Table};
use PhpLinq\Expr;
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class ReadUser extends Entity {}
#[Table('clients')]
final class ReadClient extends Entity {}
#[Table('orders')]
final class ReadOrder extends Entity {}
#[Table('memberships')]
final class ReadMembership extends Entity {}
final class PlainReadAccount
{
    public int $id;
    public string $name;
}

final class QueryTrackingTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("CREATE TABLE users(user_id INTEGER PRIMARY KEY, name TEXT, label TEXT GENERATED ALWAYS AS (name || '!') STORED); CREATE TABLE clients(id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE orders(id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id), status TEXT); CREATE TABLE notes(id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(id), body TEXT); CREATE TABLE memberships(a INTEGER, b INTEGER, name TEXT, PRIMARY KEY(a,b)); CREATE TABLE accounts(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users(user_id,name) VALUES(1,'Mary'),(2,'John'),(3,'Ada'); INSERT INTO clients VALUES(1,'Client'),(2,'Empty'); INSERT INTO orders VALUES(10,1,'pending'),(11,1,'pending'),(12,NULL,'pending'); INSERT INTO notes VALUES(20,10,'note'); INSERT INTO memberships VALUES(1,2,'Member'); INSERT INTO accounts VALUES(1,'Plain')");
        $this->db = new DbContext($this->pdo);
        $this->db->set(ReadClient::class);
        $this->db->set(ReadOrder::class);
    }

    public function testDefaultTracksAndNoTrackingBypassesTrackedIdentityAndPendingValues(): void
    {
        self::assertSame(QueryTrackingBehavior::TrackAll, $this->db->changeTracker->queryTrackingBehavior);
        $users = $this->db->set(ReadUser::class);
        $tracked = $users->find(1);
        $tracked->name = 'Pending';
        $read = $users->asNoTracking()->find(1);
        self::assertNotSame($tracked, $read);
        self::assertSame('Mary', $read->name);
        self::assertSame('Mary!', $read->label);
        self::assertSame(EntityState::Detached, $this->db->entry($read)->state);
        self::assertCount(1, $this->db->changeTracker->entries());
        self::assertSame($tracked, $users->find(1));
        self::assertNotSame($read, $users->asNoTracking()->find(1));
        $read->name = 'Ignored';
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Pending', $this->pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
    }

    public function testNoTrackingEditsAreNotSavedOrExposedToInterceptors(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public array $entries = [];
            public function savingChanges(DbContext $context): void { $this->entries = $context->changeTracker->entries(); }
        };
        $this->db->addInterceptors($hook);
        $users = $this->db->set(ReadUser::class)->asNoTracking()->toArray();
        foreach ($users as $user) { $user->name = 'Ignored'; }
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertSame(0, $this->db->saveChanges());
        self::assertSame([], $hook->entries);
        self::assertSame('Mary', $this->pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
    }

    public function testContextDefaultIsResolvedAtExecutionAndExplicitLastOverrideWins(): void
    {
        $root = $this->db->set(ReadUser::class);
        $query = $root->where(Expr::eq(Expr::field('user_id'), 1));
        $this->db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        $read = $query->first();
        self::assertSame(EntityState::Detached, $this->db->entry($read)->state);
        $tracked = $root->asTracking()->asNoTracking()->asTracking()->find(1);
        self::assertSame(EntityState::Unchanged, $this->db->entry($tracked)->state);
        self::assertSame(EntityState::Detached, $this->db->entry($root->asTracking()->asNoTracking()->find(1))->state);
        self::assertNotSame($tracked, $root->find(1));
        // Changing the default does not detach objects that were already tracked.
        $tracked->name = 'Still tracked';
        self::assertSame(1, $this->db->saveChanges());
    }

    public function testOperatorsPreserveExplicitModeAndQueriesRemainImmutable(): void
    {
        $root = $this->db->set(ReadUser::class);
        $query = $root->asNoTracking()->where(Expr::gt(Expr::field('user_id'), 0))
            ->orderByDescending(Expr::field('user_id'))->skip(1)->take(1);
        $read = $query->first();
        self::assertSame(2, $read->user_id);
        self::assertSame(EntityState::Detached, $this->db->entry($read)->state);
        self::assertCount(1, $query->toArray());
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertSame(EntityState::Unchanged, $this->db->entry($root->find(1))->state);
    }

    public function testStreamingCapturesModeOnceEvenWhenContextDefaultChangesMidIteration(): void
    {
        $this->db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        $users = [];
        foreach ($this->db->set(ReadUser::class) as $user) {
            $users[] = $user;
            $this->db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::TrackAll;
        }
        self::assertCount(3, $users);
        self::assertSame([], $this->db->changeTracker->entries());
        foreach ($users as $user) { self::assertSame(EntityState::Detached, $this->db->entry($user)->state); }
    }

    public function testNoTrackingIncludesNestedRelationshipsAndPreservesCacheWithoutTracking(): void
    {
        $clients = $this->db->set(ReadClient::class)->asNoTracking()->include('Orders.Notes')->include('Orders.Client')->orderBy(Expr::field('id'))->toArray();
        $client = $clients[0];
        $orders = $client->getOrders();
        $order = $orders->first();
        $note = $order->getNotes()->first();
        self::assertInstanceOf(DynamicEntity::class, $note);
        self::assertSame('note', $note->body);
        self::assertSame($order, $client->getOrders()->first());
        self::assertSame(2, $orders->count());
        self::assertTrue($orders->any());
        self::assertSame([], $clients[1]->getOrders()->toArray());
        self::assertNotSame($client, $order->getClient()); // No context identity resolution.
        foreach ([$client, $order, $note, $order->getClient()] as $entity) {
            self::assertSame(EntityState::Detached, $this->db->entry($entity)->state);
        }
        self::assertSame([], $this->db->changeTracker->entries());
        $note->body = 'Ignored';
        self::assertSame(0, $this->db->saveChanges());
        self::assertSame('note', $this->pdo->query('SELECT body FROM notes')->fetchColumn());
    }

    public function testLazyGettersUseSourceModeDespiteContextDefaultChanges(): void
    {
        $client = $this->db->set(ReadClient::class)->asNoTracking()->find(1);
        $order = $client->getOrders()->where(Expr::eq(Expr::field('id'), 10))->first();
        $note = $order->getNotes()->first();
        self::assertSame(EntityState::Detached, $this->db->entry($order)->state);
        self::assertSame(EntityState::Detached, $this->db->entry($note)->state);
        self::assertSame(EntityState::Detached, $this->db->entry($order->getClient())->state);
        $this->db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        $trackedClient = $this->db->set(ReadClient::class)->asTracking()->find(1);
        $trackedOrder = $trackedClient->getOrders()->first();
        self::assertSame(EntityState::Unchanged, $this->db->entry($trackedOrder)->state);
        self::assertSame($trackedClient, $trackedOrder->getClient());
    }

    public function testSwitchingPreloadedCollectionModeRequeriesInsteadOfReusingWrongObjects(): void
    {
        $client = $this->db->set(ReadClient::class)->include('Orders')->find(1);
        $tracked = $client->getOrders()->first();
        $reads = $client->getOrders()->asNoTracking()->include('Notes')->toArray();
        self::assertNotSame($tracked, $reads[0]);
        self::assertSame(EntityState::Detached, $this->db->entry($reads[0])->state);
        self::assertSame(EntityState::Detached, $this->db->entry($reads[0]->getNotes()->first())->state);
        $readClient = $this->db->set(ReadClient::class)->asNoTracking()->include('Orders')->find(1);
        $cachedRead = $readClient->getOrders()->first();
        $trackedAgain = $readClient->getOrders()->asTracking()->first();
        self::assertNotSame($cachedRead, $trackedAgain);
        self::assertSame($tracked, $trackedAgain);
    }

    public function testExplicitTrackingOverrideIncludesChildrenEvenUnderGlobalNoTracking(): void
    {
        $this->db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        $client = $this->db->set(ReadClient::class)->asTracking()->include('Orders.Notes')->find(1);
        self::assertSame(EntityState::Unchanged, $this->db->entry($client)->state);
        $order = $client->getOrders()->first();
        $note = $order->getNotes()->first();
        self::assertSame(EntityState::Unchanged, $this->db->entry($order)->state);
        self::assertSame(EntityState::Unchanged, $this->db->entry($note)->state);
        $note->body = 'Saved';
        self::assertSame(1, $this->db->saveChanges());
    }

    public function testExplicitStateAttachmentChangesFutureGetterModeAndRejectsOldCache(): void
    {
        $client = $this->db->set(ReadClient::class)->asNoTracking()->include('Orders')->find(1);
        $read = $client->getOrders()->first();
        $this->db->entry($client)->state = EntityState::Unchanged;
        $tracked = $client->getOrders()->first();
        self::assertNotSame($read, $tracked);
        self::assertSame(EntityState::Unchanged, $this->db->entry($tracked)->state);
        self::assertSame(EntityState::Detached, $this->db->entry($read)->state);
    }

    public function testCacheInvalidationDoesNotChangeNoTrackingOrigins(): void
    {
        $client = $this->db->set(ReadClient::class)->asNoTracking()->include('Orders')->find(1);
        $originalOrder = $client->getOrders()->first();
        $user = $this->db->set(ReadUser::class)->find(1);
        $user->name = 'Saved';
        self::assertSame(1, $this->db->saveChanges());
        $order = $client->getOrders()->first();
        self::assertNotSame($originalOrder, $order);
        self::assertSame(EntityState::Detached, $this->db->entry($order)->state);
        self::assertCount(1, $this->db->changeTracker->entries());
    }

    public function testNoTrackingDynamicEntitiesCanBeExplicitlyUpdatedOrDeleted(): void
    {
        $order = $this->db->set(ReadOrder::class)->asNoTracking()->find(10);
        $notes = $order->getNotes();
        $note = $notes->first();
        self::assertSame('notes', $this->db->entry($note)->metadata->table);
        self::assertSame(EntityState::Detached, $this->db->entry($note)->state);
        $note->body = 'Explicit update';
        $this->db->entry($note)->state = EntityState::Modified;
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Explicit update', $this->pdo->query('SELECT body FROM notes')->fetchColumn());
        $notes->remove($note); // Explicit remove registers deletion regardless of query mode.
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn());
    }

    public function testExplicitAddStillTracksUnderNoTrackingDefault(): void
    {
        $this->db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        $user = new ReadUser();
        $user->name = 'New';
        $this->db->set(ReadUser::class)->asNoTracking()->add($user);
        self::assertSame(EntityState::Added, $this->db->entry($user)->state);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(4, $user->user_id);
    }

    public function testNoTrackingSupportsCompositeFindAndPlainClasses(): void
    {
        $members = $this->db->set(ReadMembership::class)->asNoTracking();
        $member = $members->find(['a'=>1, 'b'=>2]);
        self::assertSame('Member', $member->name);
        self::assertNotSame($member, $members->find(['a'=>1, 'b'=>2]));
        self::assertSame(EntityState::Detached, $this->db->entry($member)->state);
        $account = $this->db->set(PlainReadAccount::class, 'accounts')->asNoTracking()->find(1);
        self::assertSame('Plain', $account->name);
        self::assertSame(EntityState::Detached, $this->db->entry($account)->state);
        self::assertNull($members->find(['a'=>2, 'b'=>2]));
        self::assertSame([], $this->db->changeTracker->entries());
    }

    public function testNoTrackingHandlesNullMissingAndChangedForeignKeys(): void
    {
        $order = $this->db->set(ReadOrder::class)->asNoTracking()->include('Client')->find(12);
        self::assertNull($order->getClient());
        $order->client_id = 1;
        self::assertSame(EntityState::Detached, $this->db->entry($order->getClient())->state);
        self::assertNull($this->db->set(ReadUser::class)->asNoTracking()->find(99));
        self::assertSame([], $this->db->set(ReadUser::class)->asNoTracking()->take(0)->toArray());
        self::assertSame([], $this->db->changeTracker->entries());
    }
}
