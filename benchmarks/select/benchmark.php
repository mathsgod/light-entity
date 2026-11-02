<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;
use PhpEntity\DbContext;
use PhpEntity\Entity;
use PhpEntity\Table;

#[Table('select_records')]
final class SelectRecord extends Entity {}

final class LightSelectRecord extends Light\Db\Model {
    protected static $_table = 'select_records';
}

function encodeRows(string $library, array $rows): string {
    // Both ORMs use JsonSerializable; Laminas already returns arrays.
    return json_encode($rows, JSON_THROW_ON_ERROR);
}

function connection(): PDO {
    return new PDO(getenv('BENCH_DSN') ?: 'mysql:host=127.0.0.1;port=33388;dbname=php_entity_select_bench;charset=utf8mb4', 'root', 'select_bench', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
}
function fetchRows(string $library, mixed $client): array {
    if ($library === 'light-db') {
        return LightSelectRecord::Query()->toArray();
    }
    if ($library === 'php-entity') {
        return $client->set(SelectRecord::class)->asNoTracking()->toArray();
    }
    $sql = new Sql($client);
    $statement = $sql->prepareStatementForSqlObject($sql->select('select_records'));
    $result = $statement->execute();
    // Fully materialize all records, as php-entity toArray() does.
    return iterator_to_array($result, false);
}

$action = $argv[1] ?? 'run';
$recordCount = filter_var(getenv('BENCH_ROWS') ?: '10000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]);
if ($recordCount === false) throw new InvalidArgumentException('BENCH_ROWS must be between 1 and 10000000');
if ($action === 'seed') {
    $pdo = connection();
    $pdo->exec('CREATE TABLE IF NOT EXISTS select_records (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL, age INT NOT NULL, balance DECIMAL(12,2) NOT NULL, created_at DATETIME NOT NULL, payload VARCHAR(255) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('TRUNCATE TABLE select_records');
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO select_records VALUES (?, ?, ?, ?, ?, ?, ?)');
    for ($i = 1; $i <= $recordCount; $i++) {
        $insert->execute([$i, "User $i", "user$i@example.test", 18 + $i % 63, sprintf('%d.%02d', $i % 10000, $i % 100), '2026-01-01 12:00:00', str_repeat('x', 128)]);
    }
    $pdo->commit();
    echo json_encode(['rows' => (int)$pdo->query('SELECT COUNT(*) FROM select_records')->fetchColumn(), 'mysql' => $pdo->query('SELECT VERSION()')->fetchColumn()]), "\n";
    exit;
}
if ($action === 'worker') {
    $library = $argv[2];
    $mode = $argv[3];
    $pdo = connection(); // Connection and autoload excluded from timings.
    $client = match ($library) {
        'php-entity' => new DbContext($pdo),
        'laminas' => new Adapter(new Laminas\Db\Adapter\Driver\Pdo\Pdo($pdo)),
        'light-db' => new Light\Db\Adapter(new Laminas\Db\Adapter\Driver\Pdo\Pdo($pdo)),
        default => throw new InvalidArgumentException('Unknown library'),
    };
    if ($library === 'light-db') {
        LightSelectRecord::SetAdapter($client);
    }
    if ($mode === 'warm') {
        $warmup = fetchRows($library, $client);
        $warmupJson = encodeRows($library, $warmup);
        unset($warmupJson);
        unset($warmup);
        gc_collect_cycles();
    }
    memory_reset_peak_usage();
    $before = memory_get_usage(false);
    $start = hrtime(true);
    $rows = fetchRows($library, $client);
    $fetchEnd = hrtime(true);
    $json = encodeRows($library, $rows);
    $elapsed = (hrtime(true) - $start) / 1e6;
    $fetchMs = ($fetchEnd - $start) / 1e6;
    $retained = memory_get_usage(false) - $before;
    $peak = memory_get_peak_usage(false) - $before;
    $processPeak = memory_get_peak_usage(true);
    // Validate every field of every row outside the measured region.
    $normalized = [];
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (count($decoded) !== $recordCount) throw new RuntimeException('Unexpected JSON row count');
    foreach ($decoded as $row) {
        $values = [];
        foreach (['id', 'name', 'email', 'age', 'balance', 'created_at', 'payload'] as $field) {
            $values[] = (string)(is_array($row) ? $row[$field] : $row->$field);
        }
        $normalized[(int)$values[0]] = $values;
    }
    ksort($normalized);
    if (count($rows) !== $recordCount || count($normalized) !== $recordCount || array_sum(array_keys($normalized)) !== (int)($recordCount * ($recordCount + 1) / 2)) {
        throw new RuntimeException('Unexpected dataset');
    }
    echo json_encode(['library' => $library, 'mode' => $mode, 'ms' => $elapsed, 'fetch_ms' => $fetchMs, 'json_ms' => $elapsed - $fetchMs, 'json_bytes' => strlen($json), 'json_checksum' => hash('sha256', $json), 'retained_bytes' => $retained, 'peak_delta_bytes' => $peak, 'php_allocated_peak_bytes' => $processPeak, 'rows' => count($rows), 'checksum' => hash('sha256', json_encode($normalized))]), "\n";
    exit;
}
if ($action !== 'run') throw new InvalidArgumentException('Use seed, worker or run');
$iterations = (int)($argv[2] ?? 15);
if ($iterations < 3) throw new InvalidArgumentException('At least 3 iterations required');
$samples = [];
$checksum = null;
$jsonChecksum = null;
for ($i = 0; $i < $iterations; $i++) {
    $cases = [['laminas', 'cold'], ['php-entity', 'cold'], ['light-db', 'cold'], ['laminas', 'warm'], ['php-entity', 'warm'], ['light-db', 'warm']];
    shuffle($cases);
    foreach ($cases as [$library, $mode]) {
        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, 'worker', $library, $mode];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start worker');
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($error . $output);
        $sample = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $checksum ??= $sample['checksum'];
        if ($sample['checksum'] !== $checksum) throw new RuntimeException('Result mismatch');
        $jsonChecksum ??= $sample['json_checksum'];
        if ($sample['json_checksum'] !== $jsonChecksum) throw new RuntimeException('JSON result mismatch');
        $samples[] = $sample;
    }
    fwrite(STDERR, sprintf("\rCompleted %d/%d rounds", $i + 1, $iterations));
}
fwrite(STDERR, "\n");
function percentile(array $values, float $p): float {
    sort($values);
    $index = (count($values) - 1) * $p;
    $lower = (int)floor($index); $upper = (int)ceil($index);
    return $values[$lower] + ($values[$upper] - $values[$lower]) * ($index - $lower);
}
$summary = [];
printf("%-12s %-5s %10s %10s %12s %12s\n", 'Library', 'Mode', 'Median ms', 'P95 ms', 'Retained MiB', 'Peak +MiB');
foreach (['cold', 'warm'] as $mode) foreach (['laminas', 'php-entity', 'light-db'] as $library) {
    $group = array_values(array_filter($samples, fn($s) => $s['library'] === $library && $s['mode'] === $mode));
    $row = ['library' => $library, 'mode' => $mode, 'median_ms' => percentile(array_column($group, 'ms'), .5), 'p95_ms' => percentile(array_column($group, 'ms'), .95), 'retained_mib' => percentile(array_column($group, 'retained_bytes'), .5) / 1048576, 'peak_delta_mib' => percentile(array_column($group, 'peak_delta_bytes'), .5) / 1048576];
    printf("%-12s %-5s %10.3f %10.3f %12.3f %12.3f\n", ...array_values($row));
    $row['median_fetch_ms'] = percentile(array_column($group, 'fetch_ms'), .5);
    $row['median_json_ms'] = percentile(array_column($group, 'json_ms'), .5);
    $summary[] = $row;
}
$pdo = connection();
$packages = [];
foreach (['laminas/laminas-db', 'mathsgod/php-entity', 'mathsgod/php-linq', 'mathsgod/light-db'] as $package) {
    $packages[$package] = ['version' => Composer\InstalledVersions::getPrettyVersion($package), 'reference' => Composer\InstalledVersions::getReference($package)];
}
file_put_contents(__DIR__ . '/results.json', json_encode(['workload' => 'select-to-json', 'php' => PHP_VERSION, 'mysql' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'packages' => $packages, 'created_at' => gmdate(DATE_ATOM), 'iterations' => $iterations, 'record_count' => $recordCount, 'summary' => $summary, 'samples' => $samples], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
