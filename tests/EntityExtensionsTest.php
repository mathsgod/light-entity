<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, EntityMapping, EntityState, SaveChangesInterceptor, Table, ValueComparer, ValueConverter};
use Light\Entity\Schema\SchemaReader;
use PDO;
use PHPUnit\Framework\TestCase;

#[Table('extension_records')]
class ExtensionRecord extends Entity { public string $name; }
#[Table('extension_records')]
class DynamicExtensionRecord extends Entity {}
class ExtensionPlain { public int $id; public string $name; }

class EntityExtensionsTest extends TestCase
{
    protected function connection(): PDO
    {
        if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) { self::markTestSkipped('pdo_sqlite required.'); }
        return new PDO('sqlite::memory:');
    }
    private function fixture(bool $objects = false, string $class = ExtensionRecord::class): array
    {
        $pdo=$this->connection(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE extension_records(id INT PRIMARY KEY,name VARCHAR(60) UNIQUE,settings JSON,data VARCHAR(100))');
        $statement=$pdo->prepare('INSERT INTO extension_records VALUES(?,?,?,?)');
        $statement->execute([1,'Ada','{"tags":["first"]}','Column data']);
        $mapping=EntityMapping::fromSchema($class,'extension_records',(new SchemaReader($pdo))->columns('extension_records'),
            converters:['settings'=>new ValueConverter(fn ($v)=>json_encode($v,JSON_THROW_ON_ERROR),fn ($v)=>json_decode($v,!$objects,512,JSON_THROW_ON_ERROR))],
            comparers:['settings'=>new ValueComparer(fn ($a,$b)=>json_encode($a)===json_encode($b),fn ($v)=>json_decode(json_encode($v,JSON_THROW_ON_ERROR),!$objects,512,JSON_THROW_ON_ERROR))]);
        return [$pdo,$mapping,new DbContext($pdo,mappings:[$mapping])];
    }

    public function testReferenceAccessAndApplyValuesUseTheSameEntityStorage(): void
    {
        [$pdo,$mapping,$db]=$this->fixture();
        $record=$db->set(ExtensionRecord::class)->asNoTracking()->find(1);
        $original=$mapping->snapshotEntity($record,fromProvider:true);
        $record->settings['tags'][]='second';
        self::assertSame(['first'],$original['settings']['tags']);
        $mapping->applyValues($record,['name'=>'Updated','data'=>'Mapped data']);
        self::assertSame('Updated',$record->name);
        self::assertSame('Mapped data',$record->data);
        self::assertSame(['first','second'],$record->jsonSerialize()['settings']['tags']);
        $entry=$db->attach($record,$original);
        self::assertSame(EntityState::Modified,$entry->state);
        self::assertSame($record,$entry->entity);
        self::assertSame(1,$db->saveChanges());
        self::assertSame('Updated',$pdo->query('SELECT name FROM extension_records')->fetchColumn());
        self::assertSame(EntityState::Unchanged,$entry->state);
    }

    public function testAttachOriginalsCopiesObjectsAndDoesNotOverwriteCurrentValues(): void
    {
        [$pdo,$mapping,$db]=$this->fixture(true);
        $record=$db->set(ExtensionRecord::class)->asNoTracking()->find(1);
        $original=$mapping->snapshotEntity($record);
        $record->settings->tags[]='pending';
        $record->name='Pending';
        $entry=$db->attach($record,$original);
        $original['settings']->tags[]='external';
        self::assertSame(['first'],$entry->originalValues['settings']->tags);
        self::assertSame(['first','pending'],$record->settings->tags);
        self::assertSame('Pending',$record->name);
        $hook=new class($record) extends SaveChangesInterceptor {
            public bool $same=false;
            public function __construct(private object $expected) {}
            public function savingChanges(DbContext $context): void { $this->same=$context->changeTracker->entries()[0]->entity===$this->expected; }
        };
        $db->addInterceptors($hook);
        self::assertSame(1,$db->saveChanges());
        self::assertTrue($hook->same);
        self::assertSame(0,$db->saveChanges());
    }

    public function testInvalidOriginalsAndKeysAreRejectedWithoutTracking(): void
    {
        [$pdo,$mapping,$db]=$this->fixture();
        $record=$db->set(ExtensionRecord::class)->asNoTracking()->find(1);
        $original=$mapping->snapshotEntity($record);
        $invalid=[$original+['unknown'=>1],array_diff_key($original,['data'=>true]),array_replace($original,['id'=>null]),array_replace($original,['id'=>2])];
        foreach ($invalid as $values) {
            try { $db->attach($record,$values); self::fail('Invalid originals must fail.'); }
            catch (\LogicException) { self::assertSame([],$db->changeTracker->entries()); }
        }
        self::assertSame('Ada',$record->name);
        self::assertSame(EntityState::Detached,$db->entry($record)->state);
    }

    public function testAttachRejectsAlreadyTrackedEntitiesAndIdentityCollisions(): void
    {
        [$pdo,$mapping,$db]=$this->fixture();
        $first=$db->set(ExtensionRecord::class)->asNoTracking()->find(1);
        $second=$db->set(ExtensionRecord::class)->asNoTracking()->find(1);
        $db->attach($first);
        foreach ([$first,$second] as $record) {
            try { $db->attach($record); self::fail('Expected tracking conflict.'); }
            catch (\LogicException) { self::assertCount(1,$db->changeTracker->entries()); }
        }
        $db->entry($first)->state=EntityState::Detached;
        self::assertSame(EntityState::Unchanged,$db->attach($second)->state);
    }

    public function testProviderSnapshotCallbackIsOptionalAndPreservesArrayIsolation(): void
    {
        [$pdo,$mapping,$db]=$this->fixture(false,DynamicExtensionRecord::class);
        $copies=0; $providerCopies=0;
        $comparer=new ValueComparer(fn ($a,$b)=>$a===$b,
            function ($v) use (&$copies) { ++$copies; return $v; },
            function ($v) use (&$providerCopies) { ++$providerCopies; return $v; });
        $mapping=EntityMapping::fromSchema(DynamicExtensionRecord::class,'extension_records',(new SchemaReader($pdo))->columns('extension_records'),$mapping->converters,['settings'=>$comparer]);
        $db=new DbContext($pdo,mappings:[$mapping]);
        $record=$db->set(DynamicExtensionRecord::class)->asNoTracking()->find(1);
        $snapshot=$mapping->snapshotEntity($record,fromProvider:true);
        self::assertSame(1,$providerCopies); self::assertSame(0,$copies);
        $record->settings['tags'][]='new';
        self::assertSame(['first'],$snapshot['settings']['tags']);
        $mapping->snapshotEntity($record);
        self::assertSame(1,$copies);
        self::assertNull($comparer->snapshotFromProvider(null));
    }

    public function testApplyValuesValidatesUnknownFieldsAndTypedProperties(): void
    {
        $mapping=new EntityMapping(ExtensionPlain::class,'plain',['id','name'],generatedKey:false);
        $record=new ExtensionPlain(); $record->id=1; $record->name='Before';
        try { $mapping->applyValues($record,['name'=>'After','missing'=>1]); self::fail('Unknown field must fail.'); }
        catch (\InvalidArgumentException) { self::assertSame('Before',$record->name); }
        $this->expectException(\TypeError::class);
        $mapping->applyValues($record,['name'=>[]]);
    }

    public function testFailureRetainsCallerChangesAndCanRetryWithoutClones(): void
    {
        [$pdo,$mapping,$db]=$this->fixture();
        $record=$db->set(ExtensionRecord::class)->asNoTracking()->find(1);
        $original=$mapping->snapshotEntity($record);
        $pdo->exec("INSERT INTO extension_records(id,name) VALUES(2,'Taken')");
        $record->name='Taken'; $record->settings['tags'][]='pending';
        $db->attach($record,$original);
        try { $db->saveChanges(); self::fail('Expected uniqueness failure.'); }
        catch (\PDOException) {
            self::assertSame('Taken',$record->name);
            self::assertSame(['first','pending'],$record->settings['tags']);
            self::assertSame('Ada',$db->entry($record)->originalValues['name']);
        }
        $record->name='Retry'; self::assertSame(1,$db->saveChanges());
    }
}
