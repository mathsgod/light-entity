#!/usr/bin/env python3
"""Compare cached JSON property metadata with the committed per-object scan."""
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
baseline_ref = sys.argv[2] if len(sys.argv) > 2 else '50eb97e'
if rounds < 3:
    raise SystemExit('At least 3 rounds required')
record_count = int(os.environ.get('BENCH_ROWS', '10000'))
os.environ['BENCH_ROWS'] = str(record_count)
if not (bench / 'vendor/autoload.php').exists():
    raise SystemExit('Run composer install in benchmarks/select first')
sources = {
    'per-object-scan': subprocess.check_output(['git', 'show', baseline_ref + ':src/Entity.php'], cwd=root, text=True),
    'cached-properties': (root / 'src/Entity.php').read_text(),
}
if 'serializationMetadata' not in sources['cached-properties']:
    raise SystemExit('Current Entity does not contain the metadata cache')


def php_path(path):
    return "'" + str(path).replace('\\', '\\\\').replace("'", "\\'") + "'"


compose = ['docker', 'compose', '-p', 'php-entity-json-cache-bench', '-f', str(bench / 'compose.yaml')]
samples = []
try:
    subprocess.run(compose + ['up', '-d', '--wait', '--wait-timeout', '150'], check=True)
    seed = subprocess.check_output(['php', str(bench / 'pdo-benchmark.php'), 'seed'], text=True)
    print(seed, end='', flush=True)
    mysql = json.loads(seed)['mysql']
    with tempfile.TemporaryDirectory(prefix='php-entity-json-cache-') as tmp:
        bootstraps = {}
        for variant, source in sources.items():
            folder = Path(tmp) / variant
            folder.mkdir()
            entity = folder / 'Entity.php'
            entity.write_text(source)
            bootstrap = folder / 'bootstrap.php'
            bootstrap.write_text('<?php\nrequire ' + php_path(bench / 'vendor/autoload.php')
                                 + ';\nrequire ' + php_path(entity) + ';\n')
            bootstraps[variant] = bootstrap
        for i in range(rounds):
            cases = [(variant, mode) for variant in sources for mode in ['cold', 'warm']]
            random.shuffle(cases)
            for variant, mode in cases:
                command = ['php', '-d', 'opcache.enable_cli=0', '-d',
                           'auto_prepend_file=' + str(bootstraps[variant]),
                           str(bench / 'benchmark.php'), 'worker', 'php-entity', mode]
                sample = json.loads(subprocess.check_output(command, text=True))
                sample.update(variant=variant, round=i)
                samples.append(sample)
            if (i + 1) % 5 == 0 or i + 1 == rounds:
                print(f'Completed {i + 1}/{rounds} JSON metadata-cache A/B rounds', flush=True)
    if len({s['json_checksum'] for s in samples}) != 1 or any(s['rows'] != record_count for s in samples):
        raise RuntimeError('Dataset / JSON mismatch')
    summary = []
    for mode in ['cold', 'warm']:
        for variant in sources:
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
                  baseline_ref=baseline_ref,
                  entity_sha256={k: hashlib.sha256(v.encode()).hexdigest() for k, v in sources.items()},
                  summary=summary, samples=samples)
    (bench / f'results-json-metadata-cache-{record_count}.json').write_text(json.dumps(output, indent=2) + '\n')
finally:
    subprocess.run(compose + ['down', '--volumes'], check=True)
