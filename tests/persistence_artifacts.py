#!/usr/bin/env python3
"""Tiny synthetic input trees only; no PHP build or real source archive."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import sys
import tarfile
import tempfile
import unittest

HELPER = Path(__file__).resolve().parents[1] / 'scripts/persistence_artifacts.py'
spec = importlib.util.spec_from_file_location('archive_helper', HELPER)
artifacts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(artifacts)


class SourceArchiveTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.build = self.root / 'build'
        self.source = self.root / 'original'
        self.assets = self.root / 'assets'
        self.producers = self.root / 'saved-producers'
        for root in (self.build, self.source, self.assets, self.producers):
            root.mkdir()
        self.write(self.source / 'buildconf', '#!/bin/sh\ntrue\n', 0o755)
        self.write(self.source / 'ext/cache.c', '/* measured PHP input */\n')
        (self.source / 'cache-link').symlink_to('ext/cache.c')
        php_inventory = self.inventory(self.source)
        shutil.copytree(self.source, self.build / 'php-src', symlinks=True)
        for name, contents in {'main.go': 'package main // bench\n', 'scale.go': 'package main // scale\n',
                               'adapter.patch': 'synthetic adapter\n', 'bench.php': '<?php echo 1;\n',
                               'user_cache_bench.h': '/* wrapper */\n'}.items():
            self.write(self.assets / name, contents)
        asset_inventory = self.inventory(self.assets)
        self.write(self.build / 'php-source-manifest.json', json.dumps(php_inventory, indent=2))
        self.write(self.build / 'assets-manifest.json', json.dumps(asset_inventory, indent=2))
        for source, target in [('main.go', 'frankenphp-src/cmd/user-cache-bench/main.go'),
                               ('scale.go', 'frankenphp-src/cmd/user-cache-scale/main.go'),
                               ('bench.php', 'fixtures/bench.php'), ('user_cache_bench.h', 'frankenphp-src/user_cache_bench.h')]:
            destination = self.build / target
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(self.assets / source, destination)
        self.write(self.build / 'php-build/config.nice', '#!/bin/sh\n./configure\n', 0o755)
        self.write(self.build / 'frankenphp-src/frankenphp.c', '/* patched host */\n')
        self.write(self.build / 'bin/frankenphp-bench', 'not a real binary')
        recorded = ['php-source-manifest.json', 'assets-manifest.json', 'fixtures/bench.php',
                    'php-build/config.nice', 'frankenphp-src/user_cache_bench.h', 'frankenphp-src/frankenphp.c', 'bin/frankenphp-bench']
        manifest = {'schema': 1, 'inputs': {'php_tree_sha256': artifacts.inventory_digest(php_inventory),
                                           'assets_sha256': artifacts.inventory_digest(asset_inventory)},
                    'php_source': {'path': str(self.source), 'tree_sha256': artifacts.inventory_digest(php_inventory)},
                    'artifacts': {name: artifacts.digest_file(self.build / name) for name in recorded}}
        self.write(self.build / 'manifest.json', json.dumps(manifest, indent=2))
        for name in ('benchmark_persistence.py', 'render_persistence_report.py'):
            self.write(self.producers / name, '# exact measured producer\n', 0o755)
        self.producer_files = {path.name: path for path in self.producers.iterdir()}
        self.expected_producers = artifacts.capture_producer_inventory(self.producer_files)
        self.manifest_sha = artifacts.digest_file(self.build / 'manifest.json')

    def tearDown(self):
        self.temp.cleanup()

    @staticmethod
    def write(path, contents, mode=0o644):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(contents)
        path.chmod(mode)

    @staticmethod
    def inventory(root):
        rows = {}
        for path in sorted(root.rglob('*')):
            name = str(path.relative_to(root))
            if path.is_symlink():
                rows[name] = {'symlink': os.readlink(path)}
            elif path.is_file():
                rows[name] = {'sha256': artifacts.digest_file(path), 'mode': path.stat().st_mode & 0o777}
        return rows

    def archive(self, name='out'):
        return artifacts.archive_source_inputs(self.build, self.root / name, assets_dir=self.assets,
            producer_files=self.producer_files, expected_producers=self.expected_producers,
            expected_build_manifest_sha256=self.manifest_sha)

    def test_exact_inputs_modes_symlinks_config_producers_and_metadata(self):
        result = self.archive()
        path = self.root / 'out' / result['file']
        self.assertEqual(artifacts.digest_file(path), result['sha256'])
        self.assertEqual(path.stat().st_size, result['bytes'])
        with tarfile.open(path) as archive:
            names = set(archive.getnames())
            self.assertIn('php-source/ext/cache.c', names)
            self.assertIn('harness-assets/adapter.patch', names)
            self.assertIn('build/php-build/config.nice', names)
            self.assertIn('build/frankenphp-src/cmd/user-cache-bench/main.go', names)
            self.assertIn('producers/benchmark_persistence.py', names)
            self.assertNotIn('build/bin/frankenphp-bench', names)
            self.assertEqual(archive.getmember('php-source/buildconf').mode, 0o755)
            self.assertTrue(archive.getmember('php-source/cache-link').issym())
            self.assertEqual(archive.getmember('php-source/cache-link').linkname, 'ext/cache.c')
            inventory = json.load(archive.extractfile('archive-manifest.json'))
            self.assertEqual(inventory['build_manifest_sha256'], self.manifest_sha)
            self.assertEqual(archive.extractfile('php-source/ext/cache.c').read(), b'/* measured PHP input */\n')

    def test_buildconf_mutated_snapshot_falls_back_to_whole_original(self):
        self.write(self.build / 'php-src/buildconf', 'mutated by configure\n', 0o755)
        result = self.archive()
        self.assertEqual(result['php_source_root'], str(self.source))
        with tarfile.open(self.root / 'out' / result['file']) as archive:
            manifest = json.load(archive.extractfile('archive-manifest.json'))
            self.assertEqual(len(manifest['rejected_php_roots']), 1)
            self.assertEqual(archive.extractfile('php-source/buildconf').read(), b'#!/bin/sh\ntrue\n')

    def test_no_mixing_partial_roots(self):
        self.write(self.build / 'php-src/buildconf', 'changed\n', 0o755)
        self.write(self.source / 'ext/cache.c', 'also changed\n')
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()
        self.assertFalse((self.root / 'out/source-inputs.tar.gz').exists())

    def test_mode_symlink_and_producer_hash_mismatches_are_rejected(self):
        (self.build / 'php-src/buildconf').chmod(0o644)
        (self.source / 'buildconf').chmod(0o644)
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()
        for root in (self.build / 'php-src', self.source):
            (root / 'buildconf').chmod(0o755)
            (root / 'cache-link').unlink()
            (root / 'cache-link').symlink_to('buildconf')
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()
        for root in (self.build / 'php-src', self.source):
            (root / 'cache-link').unlink()
            (root / 'cache-link').symlink_to('ext/cache.c')
        self.write(self.producers / 'benchmark_persistence.py', '# later producer\n', 0o755)
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()

    def test_asset_and_built_host_changes_rejected(self):
        self.write(self.assets / 'adapter.patch', 'changed adapter\n')
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()
        self.write(self.assets / 'adapter.patch', 'synthetic adapter\n')
        self.write(self.build / 'frankenphp-src/cmd/user-cache-bench/main.go', 'changed host\n')
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()

    def test_wrong_measured_manifest_and_inventory_are_rejected(self):
        self.manifest_sha = '0' * 64
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()
        self.manifest_sha = artifacts.digest_file(self.build / 'manifest.json')
        self.write(self.build / 'php-source-manifest.json', '{}')
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive()

    def test_archive_is_deterministic_and_never_overwrites(self):
        first, second = self.archive('first'), self.archive('second')
        self.assertEqual(first['sha256'], second['sha256'])
        with self.assertRaises(artifacts.ArtifactMismatch):
            self.archive('first')


if __name__ == '__main__':
    unittest.main()
