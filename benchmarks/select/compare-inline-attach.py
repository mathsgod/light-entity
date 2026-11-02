#!/usr/bin/env python3
"""Compare a temporary inline-attachment prototype with the committed post-fetch loop."""
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
rounds = int(sys.argv[1]) if len(sys.argv) > 1 else 60
baseline_ref = sys.argv[2] if len(sys.argv) > 2 else '50eb97e'
if rounds < 3:
    raise SystemExit('At least 3 rounds required')
record_count = int(os.environ.get('BENCH_ROWS', '10000'))
os.environ['BENCH_ROWS'] = str(record_count)
if not (bench / 'vendor/autoload.php').exists():
    raise SystemExit('Run composer install in benchmarks/select first')

baseline = {}
source_paths = ['src/SqlQueryProvider.php', 'src/DbContext.php', 'tests/integration/MySqlFetchClassTest.php']
for name in source_paths:
    baseline[name] = subprocess.check_output(['git', 'show', baseline_ref + ':' + name], cwd=root, text=True)
if 'PDO::FETCH_FUNC' not in baseline['src/SqlQueryProvider.php']:
    raise SystemExit('Baseline must use FETCH_FUNC so only attachment flow is compared')
result_path = Path(sys.argv[3]) if len(sys.argv) > 3 else bench / f'results-inline-attach-{record_count}.json'


def php_path(path):
    return "'" + str(path).replace('\\', '\\\\').replace("'", "\\'") + "'"


compose = ['docker', 'compose', '-p', 'php-entity-inline-attach-bench', '-f', str(bench / 'compose.yaml')]
samples = []
try:
    subprocess.run(compose + ['up', '-d', '--wait', '--wait-timeout', '150'], check=True)
    seed = subprocess.check_output(['php', str(bench / 'pdo-benchmark.php'), 'seed'], text=True)
    print(seed, end='', flush=True)
    mysql = json.loads(seed)['mysql']
    with tempfile.TemporaryDirectory(prefix='php-entity-attach-baseline-') as tmp:
        bootstraps = {}
        candidate = {}
        for variant in ['post-fetch-loop', 'inline-attach']:
            folder = Path(tmp) / variant
            for name, source in baseline.items():
                path = folder / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(source)
            if variant == 'inline-attach':
                subprocess.run(['patch', '--batch', '--silent', '-p1', '--input', str(bench / 'inline-attach.patch')],
                               cwd=folder, check=True)
                candidate = {name: (folder / name).read_text() for name in source_paths}
            code = '<?php\nrequire ' + php_path(bench / 'vendor/autoload.php') + ';\n'
            for name in source_paths[:2]:
                code += 'require ' + php_path(folder / name) + ';\n'
            bootstraps[variant] = folder / 'bootstrap.php'
            bootstraps[variant].write_text(code)
        for i in range(rounds):
            cases = [(variant, mode) for variant in ['post-fetch-loop', 'inline-attach'] for mode in ['cold', 'warm']]
            random.shuffle(cases)
            for variant, mode in cases:
                command = ['php', '-d', 'opcache.enable_cli=0']
                command += ['-d', 'auto_prepend_file=' + str(bootstraps[variant])]
                command += [str(bench / 'benchmark.php'), 'worker', 'php-entity', mode]
                sample = json.loads(subprocess.check_output(command, text=True))
                sample.update(variant=variant, round=i)
                samples.append(sample)
            if (i + 1) % 10 == 0 or i + 1 == rounds:
                print(f'Completed {i + 1}/{rounds} inline-attachment A/B rounds', flush=True)
    if len({s['json_checksum'] for s in samples}) != 1 or any(s['rows'] != record_count for s in samples):
        raise RuntimeError('Dataset / JSON mismatch')
    summary = []
    paired = []
    rng = random.Random(28116)
    for mode in ['cold', 'warm']:
        for variant in ['post-fetch-loop', 'inline-attach']:
            group = [s for s in samples if s['mode'] == mode and s['variant'] == variant]
            row = dict(mode=mode, variant=variant,
                       median_ms=statistics.median(s['ms'] for s in group),
                       median_fetch_ms=statistics.median(s['fetch_ms'] for s in group),
                       median_json_ms=statistics.median(s['json_ms'] for s in group),
                       peak_delta_mib=statistics.median(s['peak_delta_bytes'] for s in group) / 1048576)
            summary.append(row)
            print(json.dumps(row), flush=True)
        deltas = []
        for i in range(rounds):
            pair = {s['variant']: s for s in samples if s['mode'] == mode and s['round'] == i}
            deltas.append(pair['post-fetch-loop']['ms'] - pair['inline-attach']['ms'])
        boot = sorted(statistics.median(rng.choices(deltas, k=rounds)) for _ in range(3000))
        row = dict(mode=mode, median_paired_saved_ms=statistics.median(deltas),
                   bootstrap_95ci_ms=[boot[75], boot[2924]])
        paired.append(row)
        print(json.dumps(row), flush=True)
    output = dict(workload='select-to-json', iterations=rounds, record_count=record_count,
                  php=subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True), mysql=mysql,
                  baseline_ref=baseline_ref,
                  baseline_sha256={k: hashlib.sha256(v.encode()).hexdigest() for k, v in baseline.items()},
                  candidate_sha256={k: hashlib.sha256(v.encode()).hexdigest() for k, v in candidate.items()},
                  summary=summary, paired=paired, samples=samples)
    result_path.write_text(json.dumps(output, indent=2) + '\n')
finally:
    subprocess.run(compose + ['down', '--volumes'], check=True)
