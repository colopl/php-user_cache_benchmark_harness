#!/usr/bin/env python3
"""Exercise builder snapshots and Git isolation without compiling PHP or Go."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
HARNESS = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    'frankenphp_builder', HARNESS / 'scripts/frankenphp/build.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


def git(directory, *args):
    return subprocess.check_output(
        ['git', '-c', 'user.name=Builder Test', '-c', 'user.email=builder@example.invalid',
         '-c', 'commit.gpgSign=false', '-c', 'core.hooksPath=/dev/null',
         '-C', str(directory), *args], stderr=subprocess.PIPE, text=True).strip()


def write(directory, name, contents):
    path = directory / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(contents)
    return path


@unittest.skipUnless(shutil.which('git') and shutil.which('tar'), 'requires git and tar')
class BuilderTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='uc-frankenphp-builder-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.source = self.base / 'php'
        self.source.mkdir()
        git(self.source, 'init', '-q')
        write(self.source, '.gitignore', '*.o\n*.lo\n*.so\nMakefile\n')
        buildconf = write(self.source, 'buildconf', '#!/bin/sh\nexit 0\n')
        buildconf.chmod(0o755)
        write(self.source, 'ext/user_cache/user_cache.c', 'original source\n')
        write(self.source, 'deleted.c', 'delete this tracked file\n')
        write(self.source, 'docs/notes.txt', 'documentation is excluded\n')
        self.harness = self.source / HARNESS.name
        write(self.harness, 'nested.txt', 'nested harness is excluded\n')
        git(self.source, 'add', '.')
        git(self.source, 'commit', '-qm', 'synthetic PHP baseline')
        write(self.source, 'ext/user_cache/user_cache.c', 'dirty current source\n')
        write(self.source, 'new implementation.c', 'untracked current source\n')
        write(self.source, 'generated.o', 'ignored compiler output\n')
        write(self.source, 'Makefile', 'ignored configured output\n')
        (self.source / 'deleted.c').unlink()
        self.work = self.harness / 'runtime/frankenphp'

        self.upstream = self.base / 'upstream'
        self.upstream.mkdir()
        git(self.upstream, 'init', '-q')
        write(self.upstream, 'frankenphp.c', '/* synthetic upstream */\n')
        write(self.upstream, 'zval.h', '/* unchanged helper */\n')
        write(self.upstream, 'go.mod', 'module example.invalid/synthetic\n\ngo ' + builder.GO_VERSION + '\n')
        write(self.upstream, 'go.sum', '')
        git(self.upstream, 'add', '.')
        git(self.upstream, 'commit', '-qm', 'synthetic FrankenPHP baseline')
        self.revision = git(self.upstream, 'rev-parse', 'HEAD')
        # The export must use the pinned commit, not an edited source checkout.
        write(self.upstream, 'frankenphp.c', 'uncommitted upstream change\n')

        self.assets = self.base / 'assets'
        self.assets.mkdir()
        for name in builder.ASSET_FILES:
            write(self.assets, name, f'synthetic asset {name}\n')
        write(self.assets, 'adapter.patch', '''diff --git a/frankenphp.c b/frankenphp.c
--- a/frankenphp.c
+++ b/frankenphp.c
@@ -1 +1,3 @@
 /* synthetic upstream */
+#define UC_BENCH_REFERENCE 1
+/* php_user_cache_opt_in integration */
''')
        self.commands = []
        self.real_run = builder.run
        self.real_capture = builder.capture
        self.go_version = 'go1.27.0'

    def fake_run(self, args, cwd, log, env=None):
        self.commands.append(args)
        if args[0] in {'git', 'tar'}:
            return self.real_run(args, cwd, log, env)
        if args[0] == './buildconf':
            write(self.work / 'php-src', 'configure', 'synthetic configure\n')
        elif Path(args[0]).name == 'configure':
            write(self.work, 'php-build/config.nice', json.dumps(args))
            write(self.work, 'php-build/main/php_config.h', '#define ZTS 1\n')
        elif args[:2] == ['make', 'install']:
            write(self.work, 'install/bin/php', 'synthetic PHP executable\n')
            write(self.work, 'install/bin/php-config', 'synthetic php-config\n')
            write(self.work, 'install/lib/libphp.so', 'synthetic PHP library\n')
        elif args[0] == 'make':
            pass
        elif args[:2] == ['go', 'build']:
            Path(args[args.index('-o') + 1]).write_text('synthetic Go host\n')
            self.assertIn('-buildvcs=false', args)
        else:
            self.fail(f'test unexpectedly tried to run a real build command: {args}')

    def fake_capture(self, args, cwd=None):
        if args[0] == 'cc':
            return 'synthetic C compiler 1'
        if Path(args[0]).name == 'php-config':
            return '-I' + str(self.work / 'install/include/php')
        if args == ['go', 'version']:
            return 'go version synthetic linux/test'
        if args[:3] == ['go', 'env', '-json']:
            self.assertEqual(cwd, self.work / 'toolchain')
            self.assertIn('go ' + builder.GO_VERSION, (cwd / 'go.mod').read_text())
            return json.dumps({'GOVERSION': self.go_version,
                               **{key: os.environ.get(key, '') for key in args[4:]}})
        return self.real_capture(args, cwd)

    def build(self):
        argv = ['build.py', '--php-src', str(self.source), '--frankenphp-src',
                str(self.upstream), '--work-dir', str(self.work), '--jobs', '1']
        with mock.patch.object(builder, 'ASSETS', self.assets), \
             mock.patch.object(builder, 'HARNESS', self.harness), \
             mock.patch.object(builder, 'REVISION', self.revision), \
             mock.patch.object(builder, 'run', side_effect=self.fake_run), \
             mock.patch.object(builder, 'capture', side_effect=self.fake_capture), \
             mock.patch.object(builder.shutil, 'which', return_value='/synthetic/prerequisite'), \
             mock.patch.object(builder.platform, 'system', return_value='Linux'), \
             mock.patch.object(sys, 'argv', argv), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            builder.main()

    def test_snapshot_uses_dirty_untracked_and_deleted_working_files(self):
        before = builder.inventory(self.source, builder.source_files(self.source))
        self.build()
        copied = self.work / 'php-src'
        self.assertEqual((copied / 'ext/user_cache/user_cache.c').read_text(), 'dirty current source\n')
        self.assertEqual((copied / 'new implementation.c').read_text(), 'untracked current source\n')
        for name in ['deleted.c', 'generated.o', 'Makefile', 'docs', HARNESS.name, '.git']:
            self.assertFalse((copied / name).exists(), name)
        self.assertEqual((copied / 'buildconf').stat().st_mode & 0o777, 0o755)
        rows = json.loads((self.work / 'php-source-manifest.json').read_text())
        self.assertEqual(rows, before[0])
        self.assertEqual(builder.inventory(self.source, builder.source_files(self.source)), before)
        self.assertEqual((self.upstream / 'frankenphp.c').read_text(), 'uncommitted upstream change\n')

    def test_adapter_is_applied_in_private_git_repo_inside_outer_repo(self):
        self.build()
        exported = self.work / 'frankenphp-src'
        self.assertEqual(Path(git(exported, 'rev-parse', '--show-toplevel')), exported)
        adapted = (exported / 'frankenphp.c').read_text()
        self.assertIn('/* synthetic upstream */', adapted)
        self.assertIn('UC_BENCH_REFERENCE', adapted)
        self.assertIn('php_user_cache_opt_in', adapted)
        self.assertNotIn('uncommitted upstream change', adapted)
        self.assertEqual((exported / 'zval.h').read_text(), '/* unchanged helper */\n')
        self.assertNotIn('Skipped patch', (self.work / 'logs/adapter-apply.log').read_text())

    def test_reuse_checks_outputs_and_current_source_and_fixture_content(self):
        self.build()
        self.commands.clear()
        self.build()
        self.assertEqual(self.commands, [], 'unchanged verified build should be reused')

        (self.work / 'bin/frankenphp-bench').write_text('damaged host\n')
        self.build()
        self.assertTrue(any(command[0] == './buildconf' for command in self.commands))
        self.assertEqual((self.work / 'bin/frankenphp-bench').read_text(), 'synthetic Go host\n')

        self.commands.clear()
        write(self.source, 'new implementation.c', 'new current source contents\n')
        self.build()
        self.assertTrue(any(command[0] == './buildconf' for command in self.commands))
        self.assertEqual((self.work / 'php-src/new implementation.c').read_text(), 'new current source contents\n')

        self.commands.clear()
        write(self.assets, 'worker.php', 'changed worker fixture\n')
        self.build()
        self.assertTrue(any(command[0] == './buildconf' for command in self.commands))
        self.assertEqual((self.work / 'fixtures/worker.php').read_text(), 'changed worker fixture\n')

    def test_nonempty_unowned_work_directory_is_preserved(self):
        write(self.work, 'unrelated.txt', 'keep this file\n')
        with self.assertRaisesRegex(RuntimeError, 'unowned nonempty'):
            self.build()
        self.assertEqual((self.work / 'unrelated.txt').read_text(), 'keep this file\n')
        self.assertEqual(self.commands, [])

    def test_relocated_build_is_rebuilt_with_its_new_prefix(self):
        self.build()
        original = self.work
        moved = self.base / 'relocated-build'
        shutil.copytree(original, moved)
        self.work = moved
        self.commands.clear()
        self.build()
        configure = next(command for command in self.commands if Path(command[0]).name == 'configure')
        self.assertIn('--prefix=' + str(moved / 'install'), configure)
        manifest = json.loads((moved / 'manifest.json').read_text())
        self.assertEqual(manifest['fixture_root'], str(moved / 'fixtures'))
        self.assertEqual(manifest['library_dir'], str(moved / 'install/lib'))
        self.assertTrue((original / 'manifest.json').is_file())

    def test_effective_go_configuration_changes_invalidate_reuse(self):
        self.build()
        for variable, value in [('GOFLAGS', '-race'), ('GOARCH', 'different-architecture'),
                                ('GOEXPERIMENT', 'synthetic-experiment')]:
            with self.subTest(variable=variable), mock.patch.dict(os.environ, {variable: value}):
                self.commands.clear()
                self.build()
                self.assertTrue(any(command[0] == './buildconf' for command in self.commands))
        self.commands.clear()
        self.go_version = 'go1.28.0'
        self.build()
        self.assertTrue(any(command[0] == './buildconf' for command in self.commands))

    def test_missing_provenance_or_incomplete_manifest_does_not_reuse(self):
        self.build()
        self.commands.clear()
        (self.work / 'php-source-manifest.json').unlink()
        self.build()
        self.assertTrue(any(command[0] == './buildconf' for command in self.commands))
        self.commands.clear()
        manifest_file = self.work / 'manifest.json'
        manifest = json.loads(manifest_file.read_text())
        del manifest['artifacts']['bin/frankenphp-scale']
        manifest_file.write_text(json.dumps(manifest))
        self.build()
        self.assertTrue(any(command[0] == './buildconf' for command in self.commands))


if __name__ == '__main__':
    unittest.main()
