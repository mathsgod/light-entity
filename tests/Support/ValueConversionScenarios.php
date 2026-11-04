<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Support;

use Light\Entity\{DbContext, Entity, EntityMapping, EntityState, ValueComparer, ValueConverter};
use Light\Entity\Schema\SchemaReader;
use Light\Linq\Expr;
use PDO;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
}

final class ConversionSettings
{
    public function __construct(public string $theme, public object $nested) {}
}

final class DynamicConvertedAccount extends Entity {}

final class ArrayConvertedAccount extends Entity
{
    public ?array $settings;
}

final class ConvertedAccount extends Entity
{
    public ?ConversionSettings $settings;
    public bool $enabled;
    public ConversionStatus $status;
}

/** Shared storage assertions run against SQLite and MySQL with actual JSON columns. */
trait ValueConversionScenarios
{
    abstract protected function conversionConnection(): PDO;

    private function conversionContext(PDO $pdo): DbContext
    {
        $settingsConverter = new ValueConverter(
            toProvider: fn (ConversionSettings $v) => json_encode($v, JSON_THROW_ON_ERROR),
            fromProvider: static function (string $v): ConversionSettings {
                $decoded = json_decode($v, false, 512, JSON_THROW_ON_ERROR);
                return new ConversionSettings($decoded->theme, $decoded->nested);
            },
        );
        $settingsComparer = new ValueComparer(
            equals: fn (ConversionSettings $a, ConversionSettings $b) => $a == $b,
            snapshot: fn (ConversionSettings $v) => new ConversionSettings($v->theme, json_decode(json_encode($v->nested, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)),
        );
        $mapping = EntityMapping::fromSchema(ConvertedAccount::class, 'conversion_accounts', (new SchemaReader($pdo))->columns('conversion_accounts'),
            converters: [
                'settings' => $settingsConverter,
                'enabled' => new ValueConverter(fn (bool $v) => $v ? 'Y' : 'N', fn (string $v) => $v === 'Y'),
                'status' => new ValueConverter(fn (ConversionStatus $v) => $v->value, fn (string $v) => ConversionStatus::from($v)),
            ],
            comparers: [
                'settings' => $settingsComparer,
                'status' => new ValueComparer(fn ($a, $b) => $a === $b, fn ($v) => $v),
            ],
        );
        return new DbContext($pdo, mappings: [$mapping]);
    }

    private function seededConversionConnection(): PDO
    {
        $pdo = $this->conversionConnection();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pdo->exec('CREATE TABLE conversion_accounts (id '.($mysql ? 'INT AUTO_INCREMENT' : 'INTEGER').' PRIMARY KEY, name VARCHAR(60) UNIQUE, settings '.($mysql ? 'JSON' : 'TEXT').", enabled VARCHAR(1) NOT NULL DEFAULT 'N', status VARCHAR(20) NOT NULL DEFAULT 'pending')");
        $statement = $pdo->prepare('INSERT INTO conversion_accounts(name,settings) VALUES (?,?)');
        $statement->execute(['Ada', '{"theme":"light","nested":{"tags":["first"]}}']);
        return $pdo;
    }

    public function testConvertersHydrateTypedPropertiesAndDetectNestedMutations(): void
    {
        $pdo = $this->seededConversionConnection();
        $db = $this->conversionContext($pdo);
        $accounts = $db->set(ConvertedAccount::class);
        $account = $accounts->find(1);
        self::assertSame('light', $account->settings->theme);
        self::assertFalse($account->enabled);
        self::assertSame(ConversionStatus::Pending, $account->status);
        self::assertSame(EntityState::Unchanged, $db->entry($account)->state);
        self::assertSame(0, $db->saveChanges());
        $snapshot = $db->entry($account)->originalValues;
        $snapshot['settings']->nested->tags[] = 'external';
        self::assertSame(['first'], $db->entry($account)->originalValues['settings']->nested->tags);
        $account->settings->nested->tags[] = 'second';
        $account->enabled = true;
        $account->status = ConversionStatus::Active;
        self::assertSame(EntityState::Modified, $db->entry($account)->state);
        self::assertSame(['first'], $db->entry($account)->originalValues['settings']->nested->tags);
        self::assertSame(1, $db->saveChanges());
        self::assertSame('Y', $pdo->query('SELECT enabled FROM conversion_accounts')->fetchColumn());
        self::assertSame(EntityState::Unchanged, $db->entry($account)->state);
        $account->settings = new ConversionSettings('light', (object) ['tags'=>['first', 'second']]);
        self::assertSame(0, $db->saveChanges()); // Equal content, different object identity.
        $read = $accounts->asNoTracking()->find(1);
        self::assertSame(['first', 'second'], $read->settings->nested->tags);
        self::assertSame(EntityState::Detached, $db->entry($read)->state);
        self::assertCount(1, $db->changeTracker->entries());
    }

    public function testInsertDefaultsNullAndBulkUpdateUseConverters(): void
    {
        $pdo = $this->seededConversionConnection();
        $db = $this->conversionContext($pdo);
        $accounts = $db->set(ConvertedAccount::class);
        $new = new ConvertedAccount();
        $new->name = 'Bob';
        $new->settings = null;
        $accounts->add($new);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(2, $new->id);
        self::assertNull($new->settings);
        self::assertFalse($new->enabled);
        self::assertSame(ConversionStatus::Pending, $new->status);
        $settings = new ConversionSettings('dark', (object) ['tags'=>['bulk']]);
        self::assertSame(1, $accounts->where(['id'=>2])->executeUpdate(['settings'=>$settings, 'enabled'=>true, 'status'=>ConversionStatus::Active]));
        self::assertNull($new->settings); // Bulk operations do not update tracked objects.
        $read = $accounts->asNoTracking()->find(2);
        self::assertSame('dark', $read->settings->theme);
        self::assertTrue($read->enabled);
        self::assertSame(ConversionStatus::Active, $read->status);
        self::assertSame(1, $accounts->where(['id'=>2])->executeUpdate(['settings'=>null]));
        self::assertNull($accounts->asNoTracking()->find(2)->settings);
    }

    public function testWhereAndTerminalPredicatesBindModelValues(): void
    {
        $pdo = $this->seededConversionConnection();
        $db = $this->conversionContext($pdo);
        $accounts = $db->set(ConvertedAccount::class);
        self::assertSame(1, $accounts->where(['enabled'=>false, 'status'=>ConversionStatus::Pending])->count());
        self::assertSame(1, $accounts->where(['_or'=>[['status'=>['in'=>[ConversionStatus::Pending]]], ['enabled'=>true]]])->count());
        $pending = Expr::eq(Expr::field('status'), ConversionStatus::Pending);
        self::assertSame(1, $accounts->where($pending)->count());
        self::assertTrue($accounts->any($pending));
        self::assertTrue($accounts->all($pending));
        self::assertSame(1, $accounts->first($pending)->id);
        self::assertSame(1, $accounts->single($pending)->id);
        self::assertSame(1, $accounts->where($pending)->executeUpdate(['status'=>ConversionStatus::Active]));
        self::assertFalse($accounts->any($pending));
        self::assertSame(1, $accounts->where(['status'=>ConversionStatus::Active])->executeDelete());
    }

    public function testFailedSaveKeepsIndependentSnapshotAndCanRetry(): void
    {
        $pdo = $this->seededConversionConnection();
        $db = $this->conversionContext($pdo);
        $account = $db->set(ConvertedAccount::class)->find(1);
        $account->settings->theme = 'dark';
        $pdo->exec("INSERT INTO conversion_accounts(name) VALUES ('Taken')");
        $account->name = 'Taken';
        try {
            $db->saveChanges();
            self::fail('Unique constraint must fail.');
        } catch (\PDOException $error) {
            self::assertSame('light', $db->entry($account)->originalValues['settings']->theme);
            self::assertSame('dark', $account->settings->theme);
            self::assertSame(EntityState::Modified, $db->entry($account)->state);
        }
        $account->name = 'Ada';
        self::assertSame(1, $db->saveChanges());
        self::assertSame('dark', $db->set(ConvertedAccount::class)->asNoTracking()->find(1)->settings->theme);
        self::assertSame(0, $db->saveChanges());
    }
    public function testDynamicAndArrayConversionsApplyToStreamingAndRawEntityQueries(): void
    {
        $pdo = $this->seededConversionConnection();
        foreach ([DynamicConvertedAccount::class, ArrayConvertedAccount::class] as $class) {
            $mapping = EntityMapping::fromSchema($class, 'conversion_accounts', (new SchemaReader($pdo))->columns('conversion_accounts'),
                converters: ['settings'=>new ValueConverter(
                    fn ($v) => json_encode($v, JSON_THROW_ON_ERROR),
                    fn ($v) => json_decode($v, true, 512, JSON_THROW_ON_ERROR),
                )],
                comparers: ['settings'=>new ValueComparer(fn ($a,$b) => $a === $b, fn ($v) => $v)],
            );
            self::assertSame($class === DynamicConvertedAccount::class, $mapping->pdoFetchClassCompatible);
            $db = new DbContext($pdo, mappings: [$mapping]);
            $set = $db->set($class);
            $read = $set->asNoTracking()->toArray()[0];
            self::assertSame('light', $read->settings['theme']);
            self::assertSame([], $db->changeTracker->entries());
            $raw = $set->asNoTracking()->fromSqlRaw('SELECT * FROM conversion_accounts')->toArray()[0];
            self::assertSame('light', $raw->settings['theme']);
            $tracked = $set->find(1);
            // A PHP array of scalars/arrays has independent copy-on-write snapshots.
            if ($tracked instanceof ArrayConvertedAccount) {
                $tracked->settings['theme'] = 'dark';
            } else {
                $settings = $tracked->settings;
                $settings['theme'] = 'dark';
                $tracked->settings = $settings;
            }
            self::assertSame('light', $db->entry($tracked)->originalValues['settings']['theme']);
            self::assertSame(1, $db->saveChanges());
            self::assertSame(0, $db->saveChanges());
            self::assertSame('dark', $set->asNoTracking()->find(1)->settings['theme']);
            // Reset the fixture for the next mapping.
            $set->where(['id'=>1])->executeUpdate(['settings'=>['theme'=>'light','nested'=>['tags'=>['first']]]]);
        }
    }

    public function testSnapshotFailureRollsBackDatabaseWriteBeforeCommit(): void
    {
        $pdo = $this->seededConversionConnection();
        $base = $this->conversionContext($pdo)->model->getEntityType(ConvertedAccount::class);
        $comparers = $base->comparers;
        $comparers['settings'] = new ValueComparer(
            fn ($a,$b) => $a == $b,
            static function (ConversionSettings $value): ConversionSettings {
                if ($value->theme === 'fail-snapshot') { throw new \RuntimeException('snapshot failed'); }
                return new ConversionSettings($value->theme, clone $value->nested);
            },
        );
        $mapping = EntityMapping::fromSchema(ConvertedAccount::class, 'conversion_accounts', (new SchemaReader($pdo))->columns('conversion_accounts'), $base->converters, $comparers);
        $db = new DbContext($pdo, mappings: [$mapping]);
        $account = $db->set(ConvertedAccount::class)->find(1);
        $account->settings->theme = 'fail-snapshot';
        try {
            $db->saveChanges();
            self::fail('Snapshot failure must abort the save.');
        } catch (\RuntimeException $error) {
            self::assertSame('snapshot failed', $error->getMessage());
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame('light', json_decode($pdo->query('SELECT settings FROM conversion_accounts WHERE id=1')->fetchColumn())->theme);
        self::assertSame('light', $db->entry($account)->originalValues['settings']->theme);
        $account->settings->theme = 'retry';
        self::assertSame(1, $db->saveChanges());
    }
}
