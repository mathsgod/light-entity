#!/usr/bin/env python3
"""Compare production FETCH_FUNC against a temporary FETCH_CLASS baseline."""
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
if rounds < 3:
    raise SystemExit('At least 3 rounds required')
record_count = int(os.environ.get('BENCH_ROWS', '10000'))
os.environ['BENCH_ROWS'] = str(record_count)
if not (bench / 'vendor/autoload.php').exists():
    raise SystemExit('Run composer install in benchmarks/select first')

source = (root / 'src/SqlQueryProvider.php').read_text()
start = source.index('            $class = $mapping->class;')
end = source.index('            if ($mapping->booleanProperties', start)
native_block = source[start:end]
if native_block.count('PDO::FETCH_FUNC') != 1 or native_block.count('initializeFromRow') != 1:
    raise SystemExit('Provider changed: review the FETCH_CLASS baseline before running')
variant_source = source[:start] + '            $entities = $statement->fetchAll(PDO::FETCH_CLASS, $mapping->class);\n' + source[end:]
compose = ['docker', 'compose', '-p', 'php-entity-fetch-func-bench', '-f', str(bench / 'compose.yaml')]
samples = []


def php_path(path):
    return "'" + str(path).replace('\\', '\\\\').replace("'", "\\'") + "'"


try:
    subprocess.run(compose + ['up', '-d', '--wait', '--wait-timeout', '150'], check=True)
    seed = subprocess.check_output(['php', str(bench / 'pdo-benchmark.php'), 'seed'], text=True)
    print(seed, end='', flush=True)
    mysql = json.loads(seed)['mysql']
    with tempfile.TemporaryDirectory(prefix='php-entity-fetch-class-baseline-') as tmp:
        provider = Path(tmp) / 'SqlQueryProvider.php'
        provider.write_text(variant_source)
        bootstrap = Path(tmp) / 'bootstrap.php'
        bootstrap.write_text('<?php\nrequire ' + php_path(bench / 'vendor/autoload.php')
                             + ';\nrequire ' + php_path(provider) + ';\n')
        for i in range(rounds):
            cases = [(variant, mode) for variant in ['fetch-class', 'fetch-func']
                     for mode in ['cold', 'warm']]
            random.shuffle(cases)
            for variant, mode in cases:
                command = ['php', '-d', 'opcache.enable_cli=0']
                if variant == 'fetch-class':
                    command += ['-d', 'auto_prepend_file=' + str(bootstrap)]
                command += [str(bench / 'benchmark.php'), 'worker', 'php-entity', mode]
                sample = json.loads(subprocess.check_output(command, text=True))
                sample.update(variant=variant, round=i)
                samples.append(sample)
            if (i + 1) % 5 == 0 or i + 1 == rounds:
                print(f'Completed {i + 1}/{rounds} Entity FETCH_FUNC A/B rounds', flush=True)
    if len({s['json_checksum'] for s in samples}) != 1:
        raise RuntimeError('JSON mismatch between variants')
    if any(s['rows'] != record_count for s in samples):
        raise RuntimeError('Unexpected record count')
    summary = []
    for mode in ['cold', 'warm']:
        for variant in ['fetch-class', 'fetch-func']:
            group = [s for s in samples if s['mode'] == mode and s['variant'] == variant]
            row = dict(mode=mode, variant=variant,
                       median_ms=statistics.median(s['ms'] for s in group),
                       median_fetch_ms=statistics.median(s['fetch_ms'] for s in group),
                       median_json_ms=statistics.median(s['json_ms'] for s in group),
                       retained_mib=statistics.median(s['retained_bytes'] for s in group) / 1048576,
                       peak_delta_mib=statistics.median(s['peak_delta_bytes'] for s in group) / 1048576)
            summary.append(row)
            print(json.dumps(row), flush=True)
    output = dict(workload='select-to-json', iterations=rounds, record_count=record_count,
                  php=subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True), mysql=mysql,
                  provider_sha256=hashlib.sha256(source.encode()).hexdigest(),
                  variant_sha256=hashlib.sha256(variant_source.encode()).hexdigest(),
                  summary=summary, samples=samples)
    (bench / f'results-entity-fetch-func-production-{record_count}.json').write_text(json.dumps(output, indent=2) + '\n')
finally:
    subprocess.run(compose + ['down', '--volumes'], check=True)
