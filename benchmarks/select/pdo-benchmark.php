<?php

declare(strict_types=1);

// No ORM or Composer autoload: the only database layer here is PDO.
final class PdoBenchmarkRecord
{
    public $id;
    public $name;
    public $email;
    public $age;
    public $balance;
    public $created_at;
    public $payload;
}

function pdoConnection(): PDO
{
    return new PDO(
        getenv('BENCH_DSN') ?: 'mysql:host=127.0.0.1;port=33388;dbname=php_entity_select_bench;charset=utf8mb4',
        'root',
        'select_bench',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true],
    );
}

function pdoFetch(PDOStatement $statement, string $mode): array
{
    return $mode === 'assoc'
        ? $statement->fetchAll(PDO::FETCH_ASSOC)
        : $statement->fetchAll(PDO::FETCH_CLASS, PdoBenchmarkRecord::class);
}

function pdoPercentile(array $values, float $p): float
{
    sort($values);
    $index = (count($values) - 1) * $p;
    $lower = (int)floor($index);
    $upper = (int)ceil($index);
    return $values[$lower] + ($values[$upper] - $values[$lower]) * ($index - $lower);
}

$recordCount = filter_var(getenv('BENCH_ROWS') ?: '10000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]);
if ($recordCount === false) throw new InvalidArgumentException('Invalid BENCH_ROWS');
$action = $argv[1] ?? 'run';
$sql = 'SELECT * FROM select_records ORDER BY id';

if ($action === 'seed') {
    $pdo = pdoConnection();
    $pdo->exec('CREATE TABLE IF NOT EXISTS select_records (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL, age INT NOT NULL, balance DECIMAL(12,2) NOT NULL, created_at DATETIME NOT NULL, payload VARCHAR(255) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('TRUNCATE TABLE select_records');
    $pdo->beginTransaction();
    // Batch inserts affect setup time only; every measured query reads the same dataset.
    for ($offset = 0; $offset < $recordCount; $offset += 500) {
        $batchSize = min(500, $recordCount - $offset);
        $parameters = [];
        for ($i = $offset + 1; $i <= $offset + $batchSize; $i++) {
            array_push($parameters, $i, "User $i", "user$i@example.test", 18 + $i % 63,
                sprintf('%d.%02d', $i % 10000, $i % 100), '2026-01-01 12:00:00', str_repeat('x', 128));
        }
        $pdo->prepare('INSERT INTO select_records VALUES '.implode(',', array_fill(0, $batchSize, '(?,?,?,?,?,?,?)')))->execute($parameters);
    }
    $pdo->commit();
    echo json_encode(['rows' => (int)$pdo->query('SELECT COUNT(*) FROM select_records')->fetchColumn(),
        'mysql' => $pdo->query('SELECT VERSION()')->fetchColumn()], JSON_THROW_ON_ERROR), "\n";
    exit;
}

if ($action === 'worker') {
    $mode = $argv[2] ?? '';
    $workload = $argv[3] ?? '';
    if (!in_array($mode, ['assoc', 'class'], true) || !in_array($workload, ['fetch', 'json'], true)) {
        throw new InvalidArgumentException('Invalid worker mode/workload');
    }
    $pdo = pdoConnection();
    // Warm the server and this process once, then release the complete result.
    $warmStatement = $pdo->prepare($sql);
    $warmStatement->execute();
    $warmRows = pdoFetch($warmStatement, $mode);
    $warmJson = $workload === 'json' ? json_encode($warmRows, JSON_THROW_ON_ERROR) : null;
    $warmStatement->closeCursor();
    unset($warmRows, $warmJson, $warmStatement);
    gc_collect_cycles();

    memory_reset_peak_usage();
    $before = memory_get_usage(false);
    $start = hrtime(true);
    $statement = $pdo->prepare($sql);
    $statement->execute();
    $executed = hrtime(true);
    $rows = pdoFetch($statement, $mode);
    $statement->closeCursor();
    $fetched = hrtime(true);
    $json = $workload === 'json' ? json_encode($rows, JSON_THROW_ON_ERROR) : null;
    $end = hrtime(true);
    $retained = memory_get_usage(false) - $before;
    $peak = memory_get_peak_usage(false) - $before;

    // Validation and hashing happen after all timing/memory snapshots.
    $json ??= json_encode($rows, JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (count($decoded) !== $recordCount || array_column($decoded, 'id') !== range(1, $recordCount)) {
        throw new RuntimeException('Unexpected dataset');
    }
    echo json_encode(['mode' => $mode, 'workload' => $workload, 'rows' => count($rows),
        'ms' => ($end - $start) / 1e6, 'execute_ms' => ($executed - $start) / 1e6,
        'fetch_ms' => ($fetched - $executed) / 1e6, 'json_ms' => ($end - $fetched) / 1e6,
        'retained_bytes' => $retained, 'peak_delta_bytes' => $peak,
        'json_bytes' => strlen($json), 'checksum' => hash('sha256', $json)], JSON_THROW_ON_ERROR), "\n";
    exit;
}

if ($action !== 'run') throw new InvalidArgumentException('Use seed, worker or run');
$iterations = filter_var($argv[2] ?? '30', FILTER_VALIDATE_INT, ['options' => ['min_range' => 3]]);
if ($iterations === false) throw new InvalidArgumentException('At least 3 rounds required');
$samples = [];
$checksum = null;
for ($i = 0; $i < $iterations; $i++) {
    $cases = [['assoc', 'fetch'], ['class', 'fetch'], ['assoc', 'json'], ['class', 'json']];
    shuffle($cases);
    foreach ($cases as [$mode, $workload]) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, 'worker', $mode, $workload],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start worker');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($error.$output);
        $sample = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $checksum ??= $sample['checksum'];
        if ($sample['checksum'] !== $checksum) throw new RuntimeException('JSON response mismatch');
        $samples[] = $sample;
    }
    fwrite(STDERR, sprintf("\rCompleted %d/%d PDO rounds", $i + 1, $iterations));
}
fwrite(STDERR, "\n");
$summary = [];
printf("%-6s %-8s %10s %10s %10s %10s %10s %10s\n", 'Mode', 'Workload', 'Total ms', 'P95 ms', 'Fetch ms', 'JSON ms', 'RAM +MiB', 'Peak +MiB');
foreach (['fetch', 'json'] as $workload) foreach (['assoc', 'class'] as $mode) {
    $group = array_values(array_filter($samples, fn($s) => $s['mode'] === $mode && $s['workload'] === $workload));
    $row = ['mode' => $mode, 'workload' => $workload,
        'median_ms' => pdoPercentile(array_column($group, 'ms'), .5),
        'p95_ms' => pdoPercentile(array_column($group, 'ms'), .95),
        'median_fetch_ms' => pdoPercentile(array_column($group, 'fetch_ms'), .5),
        'median_json_ms' => pdoPercentile(array_column($group, 'json_ms'), .5),
        'retained_mib' => pdoPercentile(array_column($group, 'retained_bytes'), .5) / 1048576,
        'peak_delta_mib' => pdoPercentile(array_column($group, 'peak_delta_bytes'), .5) / 1048576];
    printf("%-6s %-8s %10.3f %10.3f %10.3f %10.3f %10.3f %10.3f\n", ...array_values($row));
    $row['median_execute_ms'] = pdoPercentile(array_column($group, 'execute_ms'), .5);
    $summary[] = $row;
}
$pdo = pdoConnection();
file_put_contents(__DIR__."/results-pdo-$recordCount.json", json_encode([
    'php' => PHP_VERSION, 'mysql' => $pdo->query('SELECT VERSION()')->fetchColumn(),
    'created_at' => gmdate(DATE_ATOM), 'iterations' => $iterations, 'record_count' => $recordCount,
    'sql' => $sql, 'class' => 'Seven declared untyped public properties; no constructor, setters or JsonSerializable',
    'summary' => $summary, 'samples' => $samples,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
