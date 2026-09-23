#!/usr/bin/env python3
"""Build isolated ZTS PHP and pinned FrankenPHP benchmark hosts on Linux."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import shlex
import shutil
import subprocess
import sys
import time

REVISION = '51e6246e71f335b96ac22d7782f5b20392555109'
GO_VERSION = '1.27.0'  # The pinned upstream go.mod; checked against its export.
UPSTREAM = 'https://github.com/php/frankenphp.git'
ASSETS = Path(__file__).resolve().parent
HARNESS = ASSETS.parent.parent
MARKER = '.user-cache-frankenphp-build'
CONFIGURE = ['--disable-all', '--enable-cli', '--enable-embed=shared', '--enable-zts',
             '--disable-zend-signals', '--enable-zend-max-execution-timers',
             '--enable-pcntl', '--without-pcre-jit', '--disable-opcache-jit']
TAGS = 'nowatcher,nobrotli,nomercure'
ASSET_FILES = ['build.py', 'adapter.patch', 'user_cache_bench.h', 'main.go', 'scale.go',
               'bench.php', 'classic.php', 'worker.php', 'scale.php', 'scale-scalar.php']


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as f:
        for block in iter(lambda: f.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def capture(args, cwd=None):
    return subprocess.check_output(args, cwd=cwd, text=True).strip()


def run(args, cwd, log, env=None):
    print(f'[build] {log.name}', file=sys.stderr, flush=True)
    with log.open('wb') as output:
        result = subprocess.run(args, cwd=cwd, stdout=output, stderr=subprocess.STDOUT, env=env)
    if result.returncode:
        print(log.read_text(errors='replace')[-10000:], file=sys.stderr)
        raise RuntimeError(f'{args[0]} failed ({result.returncode}); see {log}')


def source_files(source):
    # Copy working files, not git blobs: tracked edits, additions and deletions
    # all affect the snapshot. Generated build outputs are excluded by Git.
    raw = subprocess.check_output(['git', '-C', str(source), 'ls-files', '-z',
                                   '--cached', '--others', '--exclude-standard'])
    paths = []
    for name in sorted(set(os.fsdecode(raw).split('\0'))):
        if not name or name.split('/')[0] in {'docs', HARNESS.name, '.git'}:
            continue
        path = source / name
        if path.is_symlink() or path.is_file():
            paths.append(name)
    if 'buildconf' not in paths or 'ext/user_cache/user_cache.c' not in paths:
        raise RuntimeError('PHP source must be a Git working tree containing user_cache and buildconf')
    return paths


def inventory(source, paths):
    rows = {}
    for name in paths:
        path = source / name
        rows[name] = {'symlink': os.readlink(path)} if path.is_symlink() else {
            'sha256': digest(path), 'mode': path.stat().st_mode & 0o777}
    encoded = json.dumps(rows, sort_keys=True, separators=(',', ':')).encode()
    return rows, hashlib.sha256(encoded).hexdigest()


def reusable(previous, inputs, work):
    artifacts = previous.get('artifacts', {})
    required = {'bin/frankenphp-bench', 'bin/frankenphp-scale', 'install/bin/php',
                'install/lib/libphp.so', 'php-source-manifest.json', 'assets-manifest.json'}
    required.update('fixtures/' + name for name in ASSET_FILES if name.endswith('.php'))
    return previous.get('inputs') == inputs and required <= artifacts.keys() and all(
        (work / name).is_file() and digest(work / name) == sha
        for name, sha in artifacts.items())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php-src', type=Path, default=HARNESS.parent,
                        help='current PHP Git working tree; never configured or modified')
    parser.add_argument('--frankenphp-src', type=Path,
                        help='read-only local Git object source containing the pinned commit')
    parser.add_argument('--work-dir', type=Path, default=HARNESS / 'runtime/frankenphp')
    parser.add_argument('--jobs', type=int, default=min(os.cpu_count() or 1, 4))
    parser.add_argument('--force', action='store_true', help='rebuild even if input fingerprints match')
    args = parser.parse_args()
    if platform.system() != 'Linux':
        parser.error('this builder currently supports Linux shared-embed PHP')
    if args.jobs < 1:
        parser.error('--jobs must be positive')
    source = args.php_src.resolve(strict=True)
    work = args.work_dir.resolve()
    if any(c.isspace() for c in str(work)):
        parser.error('--work-dir cannot contain whitespace (PHP/CGO build paths)')
    if source == work or work in source.parents or work == HARNESS or work in HARNESS.parents:
        parser.error('--work-dir must not contain the PHP source or harness')
    local_franken = args.frankenphp_src.resolve(strict=True) if args.frankenphp_src else None
    if local_franken and (local_franken == work or work in local_franken.parents):
        parser.error('--frankenphp-src must be outside the managed build directory')
    for tool in ['git', 'make', 'autoconf', 'cc', 'bison', 're2c', 'pkg-config', 'go', 'tar']:
        if not shutil.which(tool):
            raise RuntimeError(f'missing build prerequisite: {tool}')
    if work.exists() and any(work.iterdir()) and not (work / MARKER).is_file():
        raise RuntimeError(f'refusing to modify unowned nonempty work directory: {work}')
    work.mkdir(parents=True, exist_ok=True)
    (work / MARKER).write_text('Generated by scripts/build_frankenphp.sh\n')
    logs = work / 'logs'
    logs.mkdir(exist_ok=True)
    # Probe from a module requiring the pinned Go version, even on the first
    # build. Probing the harness directory would report only the launcher Go.
    toolchain = work / 'toolchain'
    toolchain.mkdir(exist_ok=True)
    (toolchain / 'go.mod').write_text('module example.invalid/user-cache-build\n\ngo ' + GO_VERSION + '\n')
    go_environment = json.loads(capture(
        ['go', 'env', '-json', 'GOVERSION', 'GOOS', 'GOARCH', 'GOAMD64', 'GOARM',
         'GOARM64', 'GOEXPERIMENT', 'GOFLAGS', 'GOTOOLCHAIN', 'GOWORK', 'CC', 'CXX'], toolchain))
    compiler = shlex.split(os.environ.get('CC') or 'cc')
    paths = source_files(source)
    # Do not recursively capture a custom generated build directory beneath PHP.
    paths = [p for p in paths if work not in (source / p).parents]
    rows, tree_hash = inventory(source, paths)
    asset_paths = ASSET_FILES
    asset_rows, asset_hash = inventory(ASSETS, sorted(asset_paths))
    inputs = {'php_tree_sha256': tree_hash, 'assets_sha256': asset_hash,
              'frankenphp_revision': REVISION, 'configure': CONFIGURE,
              'work_dir': str(work), 'go_environment': go_environment,
              'go_launcher': shutil.which('go'), 'compiler_command': compiler,
              'machine': platform.machine(), 'cc': capture(compiler + ['--version']).splitlines()[0],
              'build_env': {k: os.environ.get(k, '') for k in
                            ['CC', 'CFLAGS', 'CPPFLAGS', 'LDFLAGS', 'GOTOOLCHAIN',
                             'CGO_CPPFLAGS', 'CGO_CXXFLAGS']}}
    manifest_path = work / 'manifest.json'
    if manifest_path.exists() and not args.force:
        try:
            previous = json.loads(manifest_path.read_text())
        except json.JSONDecodeError:
            previous = {}
        if reusable(previous, inputs, work):
            print(str(manifest_path))
            return
    # Only remove our generated paths, after ownership validation above.
    for name in ['php-src', 'php-build', 'install', 'frankenphp-src', 'bin', 'fixtures']:
        path = work / name
        if path.is_symlink():
            path.unlink()
        elif path.exists():
            shutil.rmtree(path)
        path.mkdir()
    manifest_path.unlink(missing_ok=True)
    php_copy, php_build = work / 'php-src', work / 'php-build'
    for name in paths:
        dest = php_copy / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        original = source / name
        if original.is_symlink() and (os.path.isabs(os.readlink(original)) or
                                      source not in original.resolve().parents):
            raise RuntimeError(f'source symlink escapes the isolated snapshot: {name}')
        shutil.copy2(source / name, dest, follow_symlinks=False)
    copied_rows, copied_hash = inventory(php_copy, paths)
    if copied_hash != tree_hash:
        raise RuntimeError('PHP source changed during snapshot; rerun after edits settle')
    (work / 'php-source-manifest.json').write_text(json.dumps(copied_rows, indent=2) + '\n')
    (work / 'assets-manifest.json').write_text(json.dumps(asset_rows, indent=2) + '\n')
    (work / 'php-source.patch').write_bytes(subprocess.check_output(
        ['git', '-C', str(source), 'diff', '--binary', 'HEAD', '--', '.', ':!docs', ':!' + HARNESS.name]))
    php_revision = capture(['git', '-C', str(source), 'rev-parse', 'HEAD'])
    php_status = capture(['git', '-C', str(source), 'status', '--short', '--untracked-files=normal'])
    for name in asset_paths:
        if name.endswith('.php'):
            shutil.copy2(ASSETS / name, work / 'fixtures' / name)
    franken = work / 'frankenphp-src'
    if local_franken:
        git_source = ['git', '-C', str(local_franken)]
    else:
        objects = work / 'upstream.git'
        if not objects.exists():
            run(['git', 'init', '--bare', str(objects)], work, logs / 'git-init.log')
        git_source = ['git', '--git-dir=' + str(objects)]
        if subprocess.run(git_source + ['cat-file', '-e', REVISION + '^{commit}'],
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
            run(git_source + ['fetch', '--depth=1', UPSTREAM, REVISION], work, logs / 'git-fetch.log')
    actual_revision = capture(git_source + ['rev-parse', REVISION + '^{commit}'])
    if actual_revision != REVISION:
        raise RuntimeError('FrankenPHP source does not match the pinned revision')
    with (work / 'frankenphp-source.tar').open('wb') as out:
        subprocess.run(git_source + ['archive', REVISION], stdout=out, check=True)
    run(['tar', '-xf', str(work / 'frankenphp-source.tar'), '-C', str(franken)], work, logs / 'git-export.log')
    run(['git', 'init'], franken, logs / 'frankenphp-git-init.log')
    if not re.search(r'^go ' + re.escape(GO_VERSION) + r'$', (franken / 'go.mod').read_text(), re.M):
        raise RuntimeError('pinned upstream Go requirement differs from the toolchain probe')
    upstream_zval_hash = digest(franken / 'zval.h')
    run(['git', 'apply', '--check', str(ASSETS / 'adapter.patch')], franken, logs / 'adapter-check.log')
    run(['git', 'apply', str(ASSETS / 'adapter.patch')], franken, logs / 'adapter-apply.log')
    adapted = (franken / 'frankenphp.c').read_text()
    if 'UC_BENCH_REFERENCE' not in adapted or 'php_user_cache_opt_in' not in adapted:
        raise RuntimeError('adapter patch did not modify the exported FrankenPHP source')
    shutil.copy2(ASSETS / 'user_cache_bench.h', franken / 'user_cache_bench.h')
    for host, filename in [('bench', 'main.go'), ('scale', 'scale.go')]:
        target = franken / 'cmd' / ('user-cache-' + host)
        target.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ASSETS / filename, target / 'main.go')
    run(['./buildconf', '--force'], php_copy, logs / 'php-buildconf.log')
    configure = [str(php_copy / 'configure'), '--prefix=' + str(work / 'install')] + CONFIGURE
    run(configure, php_build, logs / 'php-configure.log')
    run(['make', '-j' + str(args.jobs)], php_build, logs / 'php-make.log')
    run(['make', 'install'], php_build, logs / 'php-install.log')
    php_config = work / 'install/bin/php-config'
    cflags = '-DUC_BENCH_REFERENCE ' + capture([str(php_config), '--includes'])
    library_dir = work / 'install/lib'
    env = os.environ.copy()
    env.update({'CGO_ENABLED': '1', 'CGO_CFLAGS': cflags,
                'CGO_LDFLAGS': f'-L{library_dir} -Wl,-rpath,{library_dir}'})
    for host in ['bench', 'scale']:
        run(['go', 'build', '-buildvcs=false', '-p', str(args.jobs), '-tags', TAGS,
             '-o', str(work / 'bin' / ('frankenphp-' + host)), './cmd/user-cache-' + host],
            franken, logs / ('go-' + host + '.log'), env)
    if digest(franken / 'zval.h') != upstream_zval_hash:
        raise RuntimeError('official zval.h unexpectedly changed')
    if inventory(ASSETS, sorted(asset_paths))[1] != asset_hash:
        raise RuntimeError('build assets changed during build; rerun after edits settle')
    artifacts = {}
    for path in list((work / 'bin').iterdir()) + list((work / 'fixtures').iterdir()) + [
        work / 'php-source-manifest.json', work / 'assets-manifest.json', work / 'php-source.patch',
        work / 'install/bin/php', work / 'install/lib/libphp.so', php_build / 'config.nice',
        php_build / 'main/php_config.h', franken / 'go.mod', franken / 'go.sum', franken / 'zval.h',
        franken / 'frankenphp.c', franken / 'user_cache_bench.h']:
        artifacts[str(path.relative_to(work))] = digest(path)
    manifest = {'schema': 1, 'built_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                'inputs': inputs, 'php_source': {'path': str(source), 'revision': php_revision,
                 'status': php_status, 'tree_sha256': tree_hash, 'manifest': 'php-source-manifest.json'},
                'frankenphp': {'repository': UPSTREAM, 'revision': REVISION,
                 'upstream_zval_sha256': upstream_zval_hash},
                'comparison': 'Synthetic benchmark cache around official persistent_zval helpers; not an official cache API',
                'configure_command': configure, 'go_version': capture(['go', 'version'], franken),
                'go_tags': TAGS, 'go_buildvcs': False, 'cgo_cflags': cflags, 'cgo_ldflags': env['CGO_LDFLAGS'],
                'fixture_root': str(work / 'fixtures'), 'library_dir': str(library_dir),
                'binaries': {h: str(work / 'bin' / ('frankenphp-' + h)) for h in ['bench', 'scale']},
                'artifacts': artifacts}
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n')
    print(str(manifest_path))


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        print(f'FrankenPHP build failed: {error}', file=sys.stderr)
        sys.exit(1)
