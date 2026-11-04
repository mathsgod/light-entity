#!/usr/bin/env python3
"""SELECT -> JSON A/B: before converters, current disabled, one scalar converter."""
import hashlib
import json
import os
from pathlib import Path
import random
import statistics
import subprocess
import sys
import tempfile

bench = Path(__file__).resolve().parent
root = bench.parent.parent
rounds = int(sys.argv[1]) if len(sys.argv) > 1 else 30
baseline = sys.argv[2] if len(sys.argv) > 2 else '5a32e4c'
rows = int(os.environ.get('BENCH_ROWS', '10000'))
if rounds < 3 or rows < 1:
    raise SystemExit('Use at least 3 rounds and a positive BENCH_ROWS')
env = dict(os.environ, BENCH_ROWS=str(rows))
compose = ['docker', 'compose', '-p', 'light-entity-converter-bench', '-f', str(bench / 'compose.yaml')]
variants = ['before', 'current-disabled', 'current-one-converter']
samples = []

def php_string(path):
    return "'" + str(path).replace('\\', '\\\\').replace("'", "\\'") + "'"

def run(command, **kwargs):
    return subprocess.check_output(command, text=True, env=env, **kwargs)

try:
    subprocess.run(compose + ['up', '-d', '--wait', '--wait-timeout', '150'], check=True, env=env)
    with tempfile.TemporaryDirectory(prefix='light-entity-converter-bench-') as tmp:
        tmp = Path(tmp)
        old_src = tmp / 'before'
        for path in run(['git', 'ls-tree', '-r', '--name-only', baseline, '--', 'src'], cwd=root).splitlines():
            target = old_src / Path(path).relative_to('src')
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(run(['git', 'show', f'{baseline}:{path}'], cwd=root))
        bootstrap = tmp / 'before.php'
        bootstrap.write_text('<?php\nrequire ' + php_string(bench / 'vendor/autoload.php') + ';\n'
            + 'spl_autoload_register(static function ($class) {\n'
            + "  $prefix = 'Light\\\\Entity\\\\';\n"
            + '  if (str_starts_with($class, $prefix)) {\n'
            + '    $file = ' + php_string(old_src) + " . '/' . str_replace('\\\\', '/', substr($class, strlen($prefix))) . '.php';\n"
            + '    if (is_file($file)) { require $file; }\n  }\n}, true, true);\n')
        worker = tmp / 'worker.php'
        source = (bench / 'benchmark.php').read_text()
        source = source.replace("require __DIR__ . '/vendor/autoload.php';", 'require ' + php_string(bench / 'vendor/autoload.php') + ';')
        source = source.replace('function fetchRows(string $library, mixed $client)', 'function fetchRows(string $library, mixed &$client)')
        source = source.replace("        return $client->set(SelectRecord::class)->asNoTracking()->toArray();", '''        if (is_array($client)) {
            $pdo = $client['pdo'];
            $mapping = Light\\Entity\\EntityMapping::fromSchema(
                SelectRecord::class, 'select_records', (new Light\\Entity\\Schema\\SchemaReader($pdo))->columns('select_records'),
                converters: ['age' => new Light\\Entity\\ValueConverter(
                    toProvider: static fn (int $value): int => $value,
                    fromProvider: static fn (int $value): int => $value,
                )],
            );
            $client = new DbContext($pdo, mappings: [$mapping]);
        }
        return $client->set(SelectRecord::class)->asNoTracking()->toArray();''')
        source = source.replace("'php-entity' => new DbContext($pdo),", "'php-entity' => getenv('BENCH_ONE_CONVERTER') ? ['pdo' => $pdo] : new DbContext($pdo),")
        worker.write_text(source)
        seed = json.loads(run(['php', str(worker), 'seed']))
        print(json.dumps(seed), flush=True)
        rng = random.Random(20261104)
        for i in range(rounds):
            cases = [(v, m) for v in variants for m in ['cold', 'warm']]
            rng.shuffle(cases)
            for variant, mode in cases:
                env['BENCH_ONE_CONVERTER'] = '1' if variant == 'current-one-converter' else ''
                command = ['php', '-d', 'opcache.enable_cli=0']
                if variant == 'before':
                    command += ['-d', 'auto_prepend_file=' + str(bootstrap)]
                sample = json.loads(run(command + [str(worker), 'worker', 'php-entity', mode]))
                sample.update(variant=variant, round=i)
                samples.append(sample)
            if (i + 1) % 5 == 0:
                print(f'Completed {i + 1}/{rounds} rounds', flush=True)
        if len({s['json_checksum'] for s in samples}) != 1 or any(s['rows'] != rows for s in samples):
            raise RuntimeError('JSON or row-count mismatch')
        summary = []
        for mode in ['cold', 'warm']:
            for variant in variants:
                group = [s for s in samples if s['mode'] == mode and s['variant'] == variant]
                row = dict(mode=mode, variant=variant,
                    median_ms=statistics.median(s['ms'] for s in group),
                    median_fetch_ms=statistics.median(s['fetch_ms'] for s in group),
                    median_json_ms=statistics.median(s['json_ms'] for s in group),
                    retained_mib=statistics.median(s['retained_bytes'] for s in group) / 1048576,
                    peak_mib=statistics.median(s['peak_delta_bytes'] for s in group) / 1048576)
                summary.append(row)
                print(json.dumps(row), flush=True)
        comparisons = []
        for mode in ['cold', 'warm']:
            for left, right in [('before', 'current-disabled'), ('current-disabled', 'current-one-converter')]:
                a = {s['round']: s['ms'] for s in samples if s['mode'] == mode and s['variant'] == left}
                b = {s['round']: s['ms'] for s in samples if s['mode'] == mode and s['variant'] == right}
                differences = [b[i] - a[i] for i in range(rounds)]
                boots = sorted(statistics.median(rng.choices(differences, k=rounds)) for _ in range(3000))
                row = dict(mode=mode, comparison=f'{right} minus {left}', median_paired_delta_ms=statistics.median(differences),
                    bootstrap_95_interval_ms=[boots[75], boots[2924]])
                comparisons.append(row)
                print(json.dumps(row), flush=True)
        result = dict(workload='select-to-json', rounds=rounds, rows=rows, baseline_ref=baseline,
            current_ref=run(['git', 'rev-parse', 'HEAD'], cwd=root).strip(),
            php=run(['php', '-r', 'echo PHP_VERSION;']).strip(), mysql=seed['mysql'],
            worker_sha256=hashlib.sha256(source.encode()).hexdigest(),
            notes='NoTracking toArray, original seven-column fixture; one identity age converter, no JSON decoding. Cold enabled variant includes mapping/context setup; warm setup excluded.',
            summary=summary, comparisons=comparisons, samples=samples)
        output = bench / f'results-converters-{rows}.json'
        output.write_text(json.dumps(result, indent=2) + '\n')
        print(f'Results: {output}', flush=True)
finally:
    subprocess.run(compose + ['down', '--volumes'], check=True, env=env)
