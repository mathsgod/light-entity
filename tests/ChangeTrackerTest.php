<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Tracking;

use PDO;
use Light\Entity\{DbContext, Entity, EntityEntry, EntityState, Table};
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class TrackedUser extends Entity {}
#[Table('clients')]
final class TrackedClient extends Entity {}

final class ChangeTrackerTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, name TEXT NOT NULL, label TEXT GENERATED ALWAYS AS (name || '!') STORED); CREATE TABLE clients(id INTEGER PRIMARY KEY); CREATE TABLE notes(id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id)); INSERT INTO users(user_id,name) VALUES(1,'Mary'); INSERT INTO clients VALUES(1); INSERT INTO notes VALUES(1,1)");
        $this->db = new DbContext($this->pdo);
    }

    public function testEntryIsLiveAndDetectsAndRevertsChangesWithoutDuplicateSnapshots(): void
    {
        $user = $this->db->set(TrackedUser::class)->find(1);
        $entry = $this->db->entry($user);
        self::assertInstanceOf(EntityEntry::class, $entry);
        self::assertSame($user, $entry->entity);
        self::assertSame($this->db, $entry->context);
        self::assertSame('users', $entry->metadata->table);
        self::assertSame(EntityState::Unchanged, $entry->state);
        $user->name = 'John';
        self::assertSame(EntityState::Modified, $entry->state);
        self::assertSame('Mary', $entry->originalValues['name']);
        self::assertSame('John', $entry->currentValues['name']);
        $this->db->changeTracker->detectChanges();
        self::assertSame(EntityState::Modified, $this->db->entry($user)->state);
        $user->name = 'Mary';
        self::assertSame(EntityState::Unchanged, $entry->state);
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testEntryOnNewEntityIsDetachedUntilStateIsAssigned(): void
    {
        $user = new TrackedUser();
        $user->name = 'New';
        $entry = $this->db->entry($user);
        self::assertSame(EntityState::Detached, $entry->state);
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertSame(0, $this->db->saveChanges());
        $entry->state = EntityState::Added;
        self::assertSame(EntityState::Added, $entry->state);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(2, $user->user_id);
        self::assertSame('New!', $user->label);
        self::assertSame(EntityState::Unchanged, $entry->state);
        self::assertSame($entry->currentValues, $entry->originalValues);
    }

    public function testEntriesReturnsStableFilteredList(): void
    {
        $user = $this->db->set(TrackedUser::class)->find(1);
        $client = $this->db->set(TrackedClient::class)->find(1);
        $user->name = 'Changed';
        self::assertCount(2, $this->db->changeTracker->entries());
        $entries = $this->db->changeTracker->entries(TrackedUser::class);
        self::assertCount(1, $entries);
        self::assertSame($user, $entries[0]->entity);
        self::assertSame(EntityState::Modified, $entries[0]->state);
        foreach ($this->db->changeTracker->entries() as $entry) { $entry->state = EntityState::Detached; }
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertSame(EntityState::Detached, $this->db->entry($client)->state);
    }

    public function testStateAssignmentCanAttachAndForceUpdateWithoutChangingValues(): void
    {
        $user = new TrackedUser();
        $user->user_id = 1;
        $user->name = 'Mary';
        $user->label = 'Mary!';
        $entry = $this->db->entry($user);
        $entry->state = EntityState::Modified;
        self::assertSame($user, $this->db->set(TrackedUser::class)->find(1));
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(EntityState::Unchanged, $entry->state);
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testAssigningUnchangedAcceptsCurrentValuesWithoutWriting(): void
    {
        $user = $this->db->set(TrackedUser::class)->find(1);
        $entry = $this->db->entry($user);
        $user->name = 'Local';
        $entry->state = EntityState::Unchanged;
        self::assertSame('Local', $entry->originalValues['name']);
        self::assertSame(0, $this->db->saveChanges());
        self::assertSame('Mary', $this->pdo->query('SELECT name FROM users')->fetchColumn());
    }

    public function testDeletingAddedEntityCancelsInsertionAndExistingDeletionDetachesAfterSave(): void
    {
        $user = new TrackedUser();
        $user->name = 'New';
        $users = $this->db->set(TrackedUser::class);
        $users->add($user);
        $entry = $this->db->entry($user);
        $users->remove($user);
        self::assertSame(EntityState::Detached, $entry->state);
        self::assertSame(0, $this->db->saveChanges());
        $existing = $users->find(1);
        $entry = $this->db->entry($existing);
        $entry->state = EntityState::Deleted;
        self::assertSame(EntityState::Deleted, $entry->state);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(EntityState::Detached, $entry->state);
        self::assertSame(EntityState::Detached, $this->db->entry($existing)->state);
        self::assertNull($users->find(1));
    }

    public function testDynamicEntryRetainsMetadataAfterDeletion(): void
    {
        $client = $this->db->set(TrackedClient::class)->include('Notes')->find(1);
        $notes = $client->getNotes();
        $note = $notes->first();
        self::assertSame('notes', $this->db->entry($note)->metadata->table);
        $notes->remove($note);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(EntityState::Detached, $this->db->entry($note)->state);
    }

    public function testStateRequiresEnum(): void
    {
        $entry = $this->db->entry($this->db->set(TrackedUser::class)->find(1));
        $this->expectException(\InvalidArgumentException::class);
        $entry->state = 'deleted';
    }

    public function testSnapshotsAreCopiesAndCannotBeReassigned(): void
    {
        $user = $this->db->set(TrackedUser::class)->find(1);
        $entry = $this->db->entry($user);
        $copy = $entry->originalValues;
        $copy['name'] = 'Tampered';
        self::assertSame('Mary', $entry->originalValues['name']);
        $this->expectException(\LogicException::class);
        $entry->originalValues = $copy;
    }

    public function testDuplicateIdentityCannotBeAttachedThroughState(): void
    {
        $this->db->set(TrackedUser::class)->find(1);
        $other = new TrackedUser();
        $other->user_id = 1;
        $other->name = 'Other';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Another entity');
        $this->db->entry($other)->state = EntityState::Unchanged;
    }

    public function testInvalidAttachAndTrackedKeyChangesAreRejected(): void
    {
        $user = new TrackedUser();
        $user->name = 'No ID';
        try { $this->db->entry($user)->state = EntityState::Modified; self::fail('Expected missing key'); }
        catch (\LogicException $error) { self::assertStringContainsString('primary key', $error->getMessage()); }
        $user = $this->db->set(TrackedUser::class)->find(1);
        $user->user_id = 2;
        $this->expectException(\LogicException::class);
        $this->db->entry($user)->state = EntityState::Unchanged;
    }
}
