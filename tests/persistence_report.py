#!/usr/bin/env python3
"""Validate pairing, failure handling and report semantics without running PHP/Go."""
import copy
from html.parser import HTMLParser
import json
import os
import signal
import time
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPTS = Path(__file__).resolve().parents[1] / 'scripts'
sys.path.insert(0, str(SCRIPTS))
import benchmark_persistence as runner
import render_persistence_report as renderer
import persistence_artifacts as archive_helper


def micro_rows(*, pairs=2, aa=False):
    case_id, case = next((i, c) for i, c in runner.cases().items() if c['payload'] == 'null')
    meta = dict(type='metadata', session=1, pairs=pairs, aa=aa, quick=True, mode='worker')
    rows = [meta]
    for pair in range(pairs):
        backends = ['reference', 'reference' if aa else 'user_cache']
        if not aa and pair == 0:
            backends.append('raw')
        for order, backend in enumerate(backends):
            row = dict(case, type='sample', case_id=case_id, pair=pair, order=order, session=meta['session'], backend=backend,
                       operations=100, elapsed_ns=1000 + order * 100, ns_per_operation=10 + order,
                       thread_cpu_ns=900, http_elapsed_ns=20000, http_status=200,
                       checksum=0, expected_checksum=0, mutation_isolated=True, graph_pins=0)
            for field in ('php_heap_after_reset', 'php_heap_after_seed', 'php_heap_after_warm', 'php_heap_before', 'php_heap_after', 'php_peak_heap', 'reference_persistent_after_reset', 'reference_persistent_after_seed', 'shared_used'):
                row[field] = 1024
            rows.append(row)
    return rows


def validate_micro(rows, aa=False):
    return runner.validate_micro(rows, expected_cases=runner.cases(['null']), pairs=2, session=1, aa=aa, quick=True)


def http_rows():
    meta = dict(type='metadata', kind='scalar', backend='user_cache', workers=2, clients=4, operations_per_http=100, mixed=True, ttl=0, key_count=32, closed_loop=True)
    samples = [dict(type='sample', sequence=i, reads=99, writes=1, failures=0, checksum=99 * 307, batch_ns=1000, thread_cpu_ns=800, http_latency_ns=2000 + i * 100, http_status=200) for i in range(4)]
    summary = dict(type='summary', elapsed_ns=1000000, offered_window_ns=900000, requests=4, reads=396, writes=4, failures=0, validation_errors=0, http_errors=0,
                   requests_per_second=4000, operations_per_second=400000, http_p50_ns=2100, http_p95_ns=2200, http_p99_ns=2200,
                   batch_ns=4000, thread_cpu_ns=3200, status=dict(pins=0, http_status=200, shared_used=2048, reference_persistent_bytes=0, php_heap=4096))
    return [meta, summary] + samples


def validate_http(rows):
    return runner.validate_http(rows, kind='scalar', backend='user_cache', workers=2, operations=100, mixed=True)


class SamplesTest(unittest.TestCase):
    def test_complete_matrix_and_quick_defaults(self):
        self.assertEqual(len(runner.cases()), 33)
        args = runner.parse_args(['--quick'])
        self.assertEqual((args.pairs, args.sessions, args.rounds, args.seconds), (1, 1, 1, .15))
        self.assertTrue(args.build)
        self.assertEqual(args.mode, 'worker')
        self.assertEqual(runner.parse_args(['--mode', 'classic']).mode, 'classic')

    def test_valid_micro_and_raw_are_separated(self):
        _, paired, raw = validate_micro(micro_rows())
        self.assertEqual((len(paired), len(raw)), (4, 1))
        result = runner.summarize_micro(paired)[0]
        self.assertEqual(result['pairs'], 2)
        self.assertEqual(result['paired_ratio']['median'], 1.1)
        self.assertNotIn('raw', result['backends'])

    def test_classic_metadata_and_request_pins_are_mode_specific(self):
        rows = micro_rows()
        rows[0]['mode'] = 'classic'
        rows[1]['graph_pins'] = 2
        with self.assertRaises(runner.InvalidSamples):
            validate_micro(rows)
        runner.validate_micro(rows, expected_cases=runner.cases(['null']), pairs=2, session=1, aa=False, quick=True, mode='classic')
        rows[1]['graph_pins'] = -1
        with self.assertRaises(runner.InvalidSamples):
            runner.validate_micro(rows, expected_cases=runner.cases(['null']), pairs=2, session=1, aa=False, quick=True, mode='classic')

    def test_missing_duplicate_and_wrong_backend_pair_rejected(self):
        for transform in (lambda rows: rows[:-1], lambda rows: rows + [rows[1]], lambda rows: rows[:2] + [dict(rows[2], backend='reference')] + rows[3:]):
            with self.subTest(transform=transform), self.assertRaises(runner.InvalidSamples):
                validate_micro(transform(micro_rows()))

    def test_checksum_pins_mutation_nan_and_http_errors_rejected(self):
        for field, value in [('checksum', 1), ('expected_checksum', 1), ('graph_pins', 1), ('mutation_isolated', False), ('http_status', 500), ('ns_per_operation', float('nan')), ('operations', True), ('thread_cpu_ns', -1)]:
            rows = micro_rows()
            rows[1][field] = value
            with self.subTest(field=field), self.assertRaises(runner.InvalidSamples):
                validate_micro(rows)

    def test_incomplete_aa_and_aa_candidate_rejected(self):
        _, rows, raw = validate_micro(micro_rows(aa=True), aa=True)
        self.assertFalse(raw)
        self.assertEqual(runner.summarize_micro(rows, aa=True)[0]['paired_ratio']['median'], 1.1)
        invalid = micro_rows(aa=True)
        invalid[2]['backend'] = 'user_cache'
        with self.assertRaises(runner.InvalidSamples):
            validate_micro(invalid, aa=True)

    def test_raw_missing_is_incomplete(self):
        with self.assertRaises(runner.InvalidSamples):
            validate_micro([r for r in micro_rows() if r.get('backend') != 'raw'])

    def test_valid_http(self):
        _, summary = validate_http(http_rows())
        self.assertEqual(summary['reads'], 396)

    def test_http_missing_duplicate_checksum_and_summary_mismatch(self):
        mutations = [lambda rows: rows[:-1], lambda rows: rows + [rows[-1]],
                     lambda rows: rows[:2] + [dict(rows[2], checksum=0)] + rows[3:],
                     lambda rows: [rows[0], dict(rows[1], requests_per_second=8000)] + rows[2:],
                     lambda rows: [rows[0], dict(rows[1], http_p95_ns=2300)] + rows[2:],
                     lambda rows: [dict(rows[0], ttl=3600)] + rows[1:],
                     lambda rows: [rows[0], dict(rows[1], status=dict(rows[1]['status'], pins=1))] + rows[2:]]
        for transform in mutations:
            with self.subTest(transform=transform), self.assertRaises(runner.InvalidSamples):
                validate_http(transform(http_rows()))

    def test_http_backend_pair_is_required(self):
        meta, summary = validate_http(http_rows())
        with self.assertRaises(runner.InvalidSamples):
            runner.summarize_http([dict(stage='http', validated=True, metadata=meta, summary=summary, round=0)])

    def test_malformed_json_is_not_silently_skipped(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'sample.jsonl'
            path.write_text('{"type":"metadata"}\nnot json\n')
            with self.assertRaises(runner.InvalidSamples):
                list(runner.read_jsonl(path))


class ReportTest(unittest.TestCase):
    def report(self):
        _, paired, raw = validate_micro(micro_rows())
        return dict(schema_version=1, status='complete', smoke_only=True, configuration=dict(stage='micro'), environment={},
                    executions=[dict(label='micro', validated=True, returncode=0, raw_file='a.jsonl.gz', stderr_file='a.log')],
                    micro=runner.summarize_micro(paired), aa=[], raw_helper=runner.summarize_raw(raw), http=[], errors=[], upstream_helper=runner.UPSTREAM)

    def test_labels_are_escaped_and_smoke_and_raw_scope_are_explicit(self):
        report = self.report()
        report['micro'][0]['payload'] = '<img src=x onerror=alert(1)>'
        report['executions'][0]['label'] = '"<script>alert(1)</script>'
        page = renderer.render(report, Path('/tmp/results/result.json'), Path('/tmp/report.html'))
        self.assertNotIn('<script>', page)
        self.assertNotIn('<img src=x', page)
        self.assertIn('&lt;img', page)
        self.assertIn('SMOKE ONLY', page)
        self.assertIn('256 MiB, entries_hint at 65,536', page)
        self.assertIn('SHM at 32 MiB and memory_limit at 256 MiB', page)
        self.assertIn('Raw helper diagnostic — no cache speed ratio', page)
        self.assertIn('not a public native FrankenPHP cache API', page)

    def test_classic_report_does_not_claim_worker_pin_validation(self):
        report = self.report()
        report['configuration']['mode'] = 'classic'
        page = renderer.render(report, Path('/tmp/results/result.json'), Path('/tmp/report.html'))
        self.assertIn('PHP fetch batches — classic mode', page)
        self.assertIn('HTTP scaling always uses persistent workers', page)
        self.assertIn('does not verify their release after request shutdown', page)

    def test_embedded_json_roundtrips_provenance_without_script_termination(self):
        class EmbeddedParser(HTMLParser):
            def __init__(self):
                super().__init__()
                self.active = False
                self.contents = ''
                self.embedded_count = 0

            def handle_starttag(self, tag, attrs):
                attrs = dict(attrs)
                self.active = tag == 'script' and attrs.get('id') == 'embedded-result'
                if self.active:
                    self.embedded_count += 1
                    self.asserted_type = attrs.get('type')

            def handle_endtag(self, tag):
                if tag == 'script':
                    self.active = False

            def handle_data(self, text):
                if self.active:
                    self.contents += text

        report = self.report()
        report['build_manifest'] = {'revision': 'abc123', 'label': '</script><script>alert(1)</script> & provenance'}
        report['configuration']['note'] = '日本語 archived metadata'
        page = renderer.render(report, Path('/tmp/results/result.json'), Path('/tmp/report.html'))
        parser = EmbeddedParser()
        parser.feed(page)
        self.assertEqual(parser.embedded_count, 1)
        self.assertEqual(parser.asserted_type, 'application/json')
        self.assertEqual(json.loads(parser.contents), report)
        self.assertNotIn('</script><script>alert(1)', page)
        self.assertIn('Download embedded aggregate JSON', page)
        self.assertIn('URL.createObjectURL', page)
        self.assertIn('unavailable in an HTML-only publication', page)

    def test_source_archive_and_same_process_http_scope_are_visible(self):
        report = self.report()
        report['source_archive'] = dict(file='source-inputs.tar.gz', sha256='a' * 64, bytes=12345)
        page = renderer.render(report, Path('/tmp/results/result.json'), Path('/tmp/report.html'))
        self.assertIn('Download source-input archive (workspace artifact)', page)
        self.assertIn('12,345 bytes', page)
        self.assertIn('a' * 64, page)
        self.assertIn('same Go process and share its CPU affinity', page)
        self.assertIn('not total Go host/client CPU', page)

    def test_no_fabricated_success_for_unvalidated_or_empty_complete(self):
        for mutation in (lambda r: r['executions'][0].update(validated=False), lambda r: r.update(executions=[]), lambda r: r.update(micro=[])):
            report = self.report()
            mutation(report)
            with self.assertRaises(ValueError):
                renderer.validate_report(report)

    def test_failure_renders_as_failure_even_with_successful_partial_samples(self):
        report = self.report()
        report.update(status='failed', errors=['host <failed>'])
        page = renderer.render(report, Path('/tmp/results/result.json'), Path('/tmp/report.html'))
        self.assertIn('INCOMPLETE / FAILED', page)
        self.assertIn('host &lt;failed&gt;', page)
        self.assertNotIn('all selected samples validated', page)


class BuildAndRunnerTest(unittest.TestCase):
    def fake_build(self, root, exit_code=0):
        rows = micro_rows(pairs=1)
        host = '#!/usr/bin/env python3\nimport sys\n'
        host += 'assert int(sys.argv[sys.argv.index("-session") + 1]) > 0, "host requires positive session"\n'
        host += 'assert int(sys.argv[sys.argv.index("-pairs") + 1]) > 0, "host requires positive pairs"\n'
        host += 'rows = ' + repr('\n'.join(json.dumps(r) for r in rows)) + '\n'
        host += 'if "-aa" in sys.argv: rows = ' + repr('\n'.join(json.dumps(r) for r in micro_rows(pairs=1, aa=True))) + '\n'
        host += 'import json\n'
        host += 'parsed = [json.loads(row) for row in rows.splitlines()]\n'
        host += 'parsed[0]["mode"] = sys.argv[sys.argv.index("-mode") + 1]\n'
        host += 'assert parsed[0]["mode"] in ("classic", "worker")\n'
        host += 'print("\\n".join(json.dumps(row) for row in parsed))\n'
        host += f'sys.exit({exit_code})\n'
        files = {'bin/frankenphp-bench': host, 'bin/frankenphp-scale': host, 'install/lib/libphp.so': 'test placeholder'}
        files.update({'fixtures/' + name: '<?php /* synthetic fixture */' for name in ('bench.php', 'worker.php', 'scale.php', 'scale-scalar.php')})
        for relative, contents in files.items():
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(contents)
            if relative.startswith('bin/'):
                path.chmod(0o755)
        php_root = root / 'php-src'
        php_root.mkdir()
        (php_root / 'test.c').write_text('/* synthetic PHP input */')
        php_inventory = archive_helper.capture_producer_inventory({'test.c': php_root / 'test.c'})
        assets = SCRIPTS / 'frankenphp'
        asset_inventory = archive_helper.capture_producer_inventory({name: assets / name for name in ('main.go', 'scale.go')})
        for asset, host_name in [('main.go', 'bench'), ('scale.go', 'scale')]:
            copied = root / 'frankenphp-src/cmd' / ('user-cache-' + host_name) / 'main.go'
            copied.parent.mkdir(parents=True, exist_ok=True)
            __import__('shutil').copy2(assets / asset, copied)
        for name, inventory in [('php-source-manifest.json', php_inventory), ('assets-manifest.json', asset_inventory)]:
            (root / name).write_text(json.dumps(inventory))
            files[name] = ''
        manifest = dict(schema=1, artifacts={relative: runner.sha256(root / relative) for relative in files},
                        inputs=dict(php_tree_sha256=archive_helper.inventory_digest(php_inventory), assets_sha256=archive_helper.inventory_digest(asset_inventory)),
                        php_source=dict(path=str(php_root), tree_sha256=archive_helper.inventory_digest(php_inventory)))
        (root / 'manifest.json').write_text(json.dumps(manifest))
        return manifest

    def test_manifest_rejects_changed_fixture_and_missing_artifact(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = self.fake_build(root)
            self.assertEqual(runner.verify_build(root, manifest), root / 'fixtures')
            (root / 'fixtures/bench.php').write_text('changed')
            with self.assertRaises(runner.InvalidSamples):
                runner.verify_build(root, manifest)
            del manifest['artifacts']['bin/frankenphp-bench']
            with self.assertRaises(runner.InvalidSamples):
                runner.verify_build(root, manifest)

    def test_aa_command_uses_positive_session_and_explicit_stage(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            build = root / 'build'
            self.fake_build(build)
            output = root / 'out'
            result = subprocess.run([sys.executable, str(SCRIPTS / 'benchmark_persistence.py'), '--no-build', '--quick', '--stage', 'aa', '--mode', 'classic', '--payloads', 'null', '--build-dir', str(build), '--output-dir', str(output), '--output', str(root / 'report.html')], capture_output=True, text=True,
                                    env=dict(__import__('os').environ, UC_BENCH_LOCK_DIR=str(root / 'lock'), UC_BENCH_LOCK_HELD='0'))
            self.assertEqual(result.returncode, 0, result.stderr)
            report = json.loads((output / 'result.json').read_text())
            execution = report['executions'][0]
            self.assertEqual(execution['stage'], 'aa')
            self.assertEqual(execution['metadata']['mode'], 'classic')
            self.assertEqual(execution['command'][execution['command'].index('-mode') + 1], 'classic')
            self.assertIn('-aa', execution['command'])
            self.assertGreater(int(execution['command'][execution['command'].index('-session') + 1]), 0)
            self.assertTrue(report['aa'])
            self.assertFalse(report['micro'])

    def test_sigterm_stops_host_and_releases_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            build = root / 'build'
            manifest = self.fake_build(build)
            host = build / 'bin/frankenphp-bench'
            child_pid_file = root / 'host.pid'
            host.write_text('#!/usr/bin/env python3\nimport os, time\nfrom pathlib import Path\nPath(' + repr(str(child_pid_file)) + ').write_text(str(os.getpid()))\ntime.sleep(60)\n')
            manifest['artifacts']['bin/frankenphp-bench'] = runner.sha256(host)
            (build / 'manifest.json').write_text(json.dumps(manifest))
            output = root / 'out'
            process = subprocess.Popen([sys.executable, str(SCRIPTS / 'benchmark_persistence.py'), '--no-build', '--quick', '--stage', 'micro', '--payloads', 'null', '--build-dir', str(build), '--output-dir', str(output), '--output', str(root / 'report.html')], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                       env=dict(os.environ, UC_BENCH_LOCK_DIR=str(root / 'lock'), UC_BENCH_LOCK_HELD='0'))
            try:
                deadline = time.monotonic() + 5
                while not child_pid_file.exists() and time.monotonic() < deadline:
                    time.sleep(.01)
                self.assertTrue(child_pid_file.exists(), 'synthetic measured host did not start')
                process.send_signal(signal.SIGTERM)
                stdout, stderr = process.communicate(timeout=5)
                self.assertEqual(process.returncode, 1, stdout + stderr)
                report = json.loads((output / 'result.json').read_text())
                self.assertEqual(report['status'], 'failed')
                self.assertIn('SIGTERM', report['errors'][0])
                self.assertFalse((root / 'lock').exists())
                with self.assertRaises(ProcessLookupError):
                    os.kill(int(child_pid_file.read_text()), 0)
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
                if child_pid_file.exists():
                    try:
                        os.kill(int(child_pid_file.read_text()), signal.SIGKILL)
                    except ProcessLookupError:
                        pass

    def test_runner_archives_success_and_failure_without_actual_php(self):
        for exit_code in (0, 3):
            with self.subTest(exit_code=exit_code), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                build = root / 'build'
                self.fake_build(build, exit_code)
                output = root / 'out'
                result = subprocess.run([sys.executable, str(SCRIPTS / 'benchmark_persistence.py'), '--no-build', '--quick', '--stage', 'micro', '--payloads', 'null', '--build-dir', str(build), '--output-dir', str(output), '--output', str(root / 'report.html')], capture_output=True, text=True,
                                        env=dict(__import__('os').environ, UC_BENCH_LOCK_DIR=str(root / 'lock'), UC_BENCH_LOCK_HELD='0'))
                self.assertEqual(result.returncode, 0 if exit_code == 0 else 1, result.stderr)
                data = json.loads((output / 'result.json').read_text())
                self.assertEqual(data['status'], 'complete' if exit_code == 0 else 'failed')
                self.assertTrue((output / 'micro-s1-null.jsonl.gz').is_file())
                self.assertTrue((root / 'report.html').is_file())
                self.assertTrue((output / 'source-inputs.tar.gz').is_file())
                self.assertEqual(data['source_archive']['sha256'], runner.sha256(output / 'source-inputs.tar.gz'))
                self.assertFalse((root / 'lock').exists())


if __name__ == '__main__':
    unittest.main()
