import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


DIRECTORY = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(DIRECTORY))
from run import benchmark_lock, catalog, select_cases
from render import render_report


class CatalogTest(unittest.TestCase):
    def test_every_migrated_workload_is_accounted_for(self):
        manifest = json.loads((DIRECTORY / 'coverage.json').read_text())
        cases = catalog()
        self.assertEqual(len(cases), 127)
        self.assertEqual({case.id for case in cases},
                         {case for row in manifest['workloads'] for case in row['cases']})
        self.assertEqual(manifest['suite_counts'], {
            'core': 38, 'review': 1, 'status': 21, 'lru': 12,
            'writers': 44, 'pools': 8, 'snapshot': 3,
        })
        self.assertEqual({case.cpus for case in cases if case.suite == 'writers'}, {1, 2, 4, 8})
        self.assertTrue(all((DIRECTORY / 'workers' / case.worker).is_file() for case in cases))

    def test_selection_never_silently_accepts_a_missing_case(self):
        selected = select_cases(catalog(), ['writers,snapshot'], ['writers/increment_same_*', 'snapshot/*'])
        self.assertEqual(len(selected), 7)
        with self.assertRaises(ValueError):
            select_cases(catalog(), ['core'], ['writers/*'])


class LockTest(unittest.TestCase):
    def test_ownership_and_inheritance(self):
        with tempfile.TemporaryDirectory() as temporary:
            lock = Path(temporary) / 'benchmark.lock'
            with patch.dict(os.environ, {'UC_BENCH_LOCK_DIR': str(lock), 'UC_BENCH_LOCK_HELD': '0'}):
                with benchmark_lock():
                    self.assertEqual((lock / 'pid').read_text().strip(), str(os.getpid()))
                    with self.assertRaises(RuntimeError):
                        with benchmark_lock():
                            pass
                    with patch.dict(os.environ, {'UC_BENCH_LOCK_HELD': '1'}):
                        with benchmark_lock() as inherited:
                            self.assertTrue(inherited['inherited'])
                        self.assertTrue(lock.is_dir())
                self.assertFalse(lock.exists())


class ReportTest(unittest.TestCase):
    def test_embedded_metadata_cannot_terminate_the_json_script(self):
        report = {'binaries': {'current': '</script><script>bad()</script>'},
                  'status': 'complete', 'quick': True, 'cases': {}}
        page = render_report(report)
        self.assertNotIn('</script><script>bad()', page)
        self.assertIn('\\u003c/script\\u003e', page)
        self.assertIn('Quick smoke run', page)
        self.assertNotIn('https://', page)

    def test_single_and_paired_runs_publish_all_samples_and_explicit_skips(self):
        # This executable emulates only the JSON transport. It never runs PHP or
        # measures performance; the test covers orchestration/report contracts.
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            php = root / 'fake-php'
            php.write_text('#!' + sys.executable + '\n' + '''
import json, sys
if '-r' in sys.argv:
    row = {'version':'fixture','zts':False,'int_size':8,'user_cache':True,'pcntl':False,'socket_pair':True}
elif any(arg.endswith('snapshot-memory.php') for arg in sys.argv):
    row = {'keys':0,'snapshot_retained_bytes':0}
else:
    row = {'case':'fetch_scalar_1','ns_per_operation':12,'elapsed_ns':12000,'iterations':1000,'errors':0}
print(json.dumps(row))
''')
            php.chmod(0o755)
            for paired in [False, True]:
                output = root / ('paired' if paired else 'single')
                command = [sys.executable, str(DIRECTORY / 'run.py'), '--php', str(php),
                           '--quick', '--rounds', '2', '--cases', 'core/fetch_scalar_1',
                           'snapshot/keys_0', 'writers/increment_disjoint_1',
                           '--output-dir', str(output), '--output', str(output / 'published.html')]
                if paired:
                    command += ['--before', str(php)]
                environment = dict(os.environ, UC_BENCH_LOCK_DIR=str(root / 'benchmark.lock'),
                                   UC_BENCH_LOCK_HELD='0')
                result = subprocess.run(command, capture_output=True, text=True, env=environment, timeout=15)
                self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
                report = json.loads((output / 'micro.json').read_text())
                self.assertEqual(report['status'], 'complete')
                self.assertEqual(report['cases']['writers/increment_disjoint_1']['status'], 'skipped')
                row = report['cases']['core/fetch_scalar_1']
                self.assertEqual(set(row['samples']), {'before', 'after'} if paired else {'current'})
                self.assertTrue(all(len(samples) == 2 for samples in row['samples'].values()))
                self.assertEqual((output / 'micro.html').read_text(), (output / 'published.html').read_text())
                self.assertFalse((root / 'benchmark.lock').exists())
                if paired:
                    self.assertEqual(row['ratio_after_before'], 1)
                    self.assertIsNone(report['cases']['snapshot/keys_0']['ratio_after_before'])


if __name__ == '__main__':
    unittest.main()
