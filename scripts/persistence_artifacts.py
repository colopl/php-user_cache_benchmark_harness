#!/usr/bin/env python3
"""Archive verified benchmark build inputs before measurement or from saved inputs.

No compilation or benchmark is performed. Callers must hold the benchmark/build
lock while using a live build directory. The archive deliberately excludes PHP
and Go binaries: their hashes stay in the embedded build manifest.
"""
from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import tarfile
import tempfile


class ArtifactMismatch(ValueError):
    pass


def check(condition, message):
    if not condition:
        raise ArtifactMismatch(message)


def digest_bytes(value):
    return hashlib.sha256(value).hexdigest()


def digest_file(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def inventory_digest(inventory):
    return digest_bytes(json.dumps(inventory, sort_keys=True, separators=(',', ':')).encode())


def relative_name(name):
    check(isinstance(name, str) and bool(name), 'empty/non-string archive input name')
    path = PurePosixPath(name)
    check(not path.is_absolute() and '..' not in path.parts and str(path) == name and name != '.',
          f'input name is not a normalized relative path: {name}')
    return name


def validate_inventory(inventory):
    check(isinstance(inventory, dict) and bool(inventory), 'empty/non-object input inventory')
    for name, expected in inventory.items():
        relative_name(name)
        check(isinstance(expected, dict), f'invalid inventory row: {name}')
        if set(expected) == {'symlink'}:
            target = expected['symlink']
            check(isinstance(target, str) and target and not os.path.isabs(target), f'invalid symlink target: {name}')
        else:
            check(set(expected) == {'sha256', 'mode'}, f'invalid file inventory row: {name}')
            sha = expected['sha256']
            check(isinstance(sha, str) and len(sha) == 64 and all(c in '0123456789abcdef' for c in sha), f'invalid sha256: {name}')
            check(type(expected['mode']) is int and 0 <= expected['mode'] <= 0o777, f'invalid file mode: {name}')
    return inventory


def input_path(root, name):
    name = relative_name(name)
    path = root / name
    # Keep the symlink itself for inventory/archive purposes, but never read an
    # unrelated path through a substituted parent directory or escaped target.
    check(path.parent.resolve().is_relative_to(root), f'input parent leaves source root: {name}')
    if path.is_symlink():
        check(path.resolve().is_relative_to(root), f'input symlink leaves source root: {name}')
    return path


def verify_input(root, name, expected):
    path = input_path(root, name)
    try:
        info = path.lstat()
    except FileNotFoundError as exc:
        raise ArtifactMismatch(f'missing input: {path}') from exc
    if 'symlink' in expected:
        check(stat.S_ISLNK(info.st_mode) and os.readlink(path) == expected['symlink'], f'symlink mismatch: {path}')
    else:
        check(stat.S_ISREG(info.st_mode), f'expected regular file: {path}')
        check(stat.S_IMODE(info.st_mode) == expected['mode'], f'file mode mismatch: {path}')
        check(digest_file(path) == expected['sha256'], f'file hash mismatch: {path}')
    return path


def capture_producer_inventory(producer_files):
    """Capture this before measurement, or from an independently saved snapshot."""
    result = {}
    for name, source in producer_files.items():
        relative_name(name)
        path = Path(source)
        info = path.lstat()
        check(stat.S_ISREG(info.st_mode), f'producer must be a regular file: {path}')
        result[name] = {'sha256': digest_file(path), 'mode': stat.S_IMODE(info.st_mode)}
    return validate_inventory(result)


def choose_php_root(candidates, inventory):
    failures = []
    seen = set()
    for candidate in candidates:
        if candidate is None:
            continue
        root = Path(candidate).resolve()
        if root in seen:
            continue
        seen.add(root)
        try:
            for name, expected in inventory.items():
                verify_input(root, name, expected)
            return root, failures
        except (ArtifactMismatch, OSError) as exc:
            failures.append({'root': str(root), 'reason': str(exc)})
    raise ArtifactMismatch('no complete PHP input tree matches the recorded build inventory: ' + json.dumps(failures))


def archive_source_inputs(build_dir, output_dir, *, assets_dir, producer_files,
                          expected_producers, expected_build_manifest_sha256, php_roots=None, filename='source-inputs.tar.gz'):
    """Return archive metadata; reject changed inputs rather than relabel them.

    producer_files maps archive-relative names to the exact saved/current files
    used by the measurement. expected_producers is the sha256/mode inventory
    captured for those files before measurement. php_roots, when provided,
    replaces the default whole-tree snapshot/original-source fallback list.
    """
    build_dir, output_dir = Path(build_dir).resolve(), Path(output_dir).resolve()
    assets_dir = Path(assets_dir).resolve()
    check(relative_name(filename) == Path(filename).name, 'archive filename must be a basename')
    target = output_dir / filename
    check(not target.exists(), f'refusing to replace source archive: {target}')
    manifest_path = build_dir / 'manifest.json'
    manifest_bytes = manifest_path.read_bytes()
    check(digest_bytes(manifest_bytes) == expected_build_manifest_sha256, 'build manifest does not match the measured run')
    manifest = json.loads(manifest_bytes)
    check(manifest.get('schema') == 1, 'unsupported build manifest')
    artifacts = manifest.get('artifacts', {})
    check(isinstance(artifacts, dict), 'missing build artifact inventory')
    inventories = {}
    for name, expected_hash_key in [('php-source-manifest.json', 'php_tree_sha256'), ('assets-manifest.json', 'assets_sha256')]:
        data = (build_dir / name).read_bytes()
        check(digest_bytes(data) == artifacts.get(name), f'inventory artifact changed: {name}')
        inventory = validate_inventory(json.loads(data))
        check(inventory_digest(inventory) == manifest.get('inputs', {}).get(expected_hash_key), f'input inventory fingerprint mismatch: {name}')
        inventories[name] = inventory
    php_inventory = inventories['php-source-manifest.json']
    assets_inventory = inventories['assets-manifest.json']
    check(inventory_digest(php_inventory) == manifest.get('php_source', {}).get('tree_sha256'), 'PHP source fingerprint disagrees with build inputs')
    candidates = php_roots if php_roots is not None else [build_dir / 'php-src', manifest.get('php_source', {}).get('path')]
    php_root, rejected_roots = choose_php_root(candidates, php_inventory)
    expected_producers = validate_inventory(expected_producers)
    check(set(producer_files) == set(expected_producers), 'producer file list differs from its saved inventory')
    entries = {}

    def add(root, name, expected, archive_name):
        check(archive_name not in entries, f'duplicate archive member: {archive_name}')
        path = verify_input(root, name, expected)
        entries[relative_name(archive_name)] = (path, expected, root, name)

    for name, expected in php_inventory.items():
        add(php_root, name, expected, 'php-source/' + name)
    for name, expected in assets_inventory.items():
        add(assets_dir, name, expected, 'harness-assets/' + name)
    for name, source in producer_files.items():
        path = Path(source).absolute()
        add(path.parent.resolve(), path.name, expected_producers[name], 'producers/' + relative_name(name))
    # Preserve built fixtures, patched upstream code, Go modules and PHP config.
    # Binaries are large generated outputs and remain identified by their hashes.
    for name, sha in artifacts.items():
        relative_name(name)
        if name.startswith(('bin/', 'install/bin/', 'install/lib/')):
            continue
        path = input_path(build_dir, name)
        check(path.is_file() and not path.is_symlink(), f'non-regular built source artifact: {name}')
        expected = {'sha256': sha, 'mode': stat.S_IMODE(path.stat().st_mode)}
        add(build_dir, name, expected, 'build/' + name)
    # These copied Go hosts are inputs to go build, not generated binary outputs.
    # Their expected hash AND mode come from the frozen asset input inventory.
    for filename_, host in [('main.go', 'bench'), ('scale.go', 'scale')]:
        check(filename_ in assets_inventory, f'asset inventory omits {filename_}')
        name = f'frankenphp-src/cmd/user-cache-{host}/main.go'
        add(build_dir, name, assets_inventory[filename_], 'build/' + name)
    # Verify copied fixtures and wrapper against the original asset records too.
    for name, expected in assets_inventory.items():
        if name.endswith('.php'):
            verify_input(build_dir, 'fixtures/' + name, expected)
        elif name == 'user_cache_bench.h':
            verify_input(build_dir, 'frankenphp-src/' + name, expected)
    archive_manifest = {
        'schema': 1, 'build_manifest_sha256': digest_bytes(manifest_bytes),
        'php_source_root': str(php_root), 'rejected_php_roots': rejected_roots,
        'php_tree_sha256': inventory_digest(php_inventory), 'assets_sha256': inventory_digest(assets_inventory),
        'producer_inventory': expected_producers,
        'verification': 'Input and producer inventories verify content, regular-file modes and symlink targets. Built source/config artifacts verify recorded content hashes; their modes are observed at archive time because the build artifact manifest does not record them.',
        'members': {name: expected for name, (_, expected, _, _) in entries.items()},
        'scope': 'Exact PHP input tree, verified harness assets, built source/config artifacts, and saved measurement producers. Generated executables/libraries are not included; build-manifest.json records their hashes.',
    }
    generated = {'archive-manifest.json': (json.dumps(archive_manifest, indent=2) + '\n').encode(),
                 'build-manifest.json': manifest_bytes}
    output_dir.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(prefix='.' + filename + '.', dir=output_dir)
    os.close(fd)
    temporary = Path(temporary_name)
    input_bytes = 0
    try:
        # Fixed timestamp/owners make the same selected bytes reproducible.
        with temporary.open('wb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as zipped, tarfile.open(fileobj=zipped, mode='w|') as archive:
            for name in sorted(entries):
                path, expected, root, relative = entries[name]
                # Recheck against the inventory while consuming the bytes. A
                # concurrent edit aborts this temporary archive, never publishes it.
                verify_input(root, relative, expected)
                info = tarfile.TarInfo(name)
                info.mtime = 0
                if 'symlink' in expected:
                    info.type = tarfile.SYMTYPE
                    info.linkname = expected['symlink']
                    info.mode = 0o777
                    archive.addfile(info)
                else:
                    with path.open('rb') as stream:
                        before = os.fstat(stream.fileno())
                        contents = stream.read()
                        after = os.fstat(stream.fileno())
                    check(stat.S_IMODE(before.st_mode) == expected['mode'] and stat.S_ISREG(before.st_mode), f'file type/mode changed while archiving: {path}')
                    check(before.st_mtime_ns == after.st_mtime_ns and before.st_size == after.st_size, f'file changed while archiving: {path}')
                    check(digest_bytes(contents) == expected['sha256'], f'file bytes changed while archiving: {path}')
                    info.mode = expected['mode']
                    info.size = len(contents)
                    input_bytes += info.size
                    archive.addfile(info, io.BytesIO(contents))
            for name, contents in generated.items():
                info = tarfile.TarInfo(name)
                info.mode, info.size, info.mtime = 0o644, len(contents), 0
                input_bytes += info.size
                archive.addfile(info, io.BytesIO(contents))
        check(manifest_path.read_bytes() == manifest_bytes, 'build manifest changed while archiving')
        # Do not overwrite an archive another invocation published meanwhile.
        os.link(temporary, target)
        temporary.unlink()
    except BaseException:
        temporary.unlink(missing_ok=True)
        raise
    return {'file': filename, 'sha256': digest_file(target), 'bytes': target.stat().st_size,
            'input_bytes': input_bytes, 'members': len(entries) + len(generated),
            'build_manifest_sha256': digest_bytes(manifest_bytes),
            'php_tree_sha256': inventory_digest(php_inventory), 'php_source_root': str(php_root),
            'assets_sha256': inventory_digest(assets_inventory), 'producer_inventory': expected_producers,
            'scope': archive_manifest['scope']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--build-dir', type=Path, required=True)
    parser.add_argument('--output-dir', type=Path, required=True)
    parser.add_argument('--build-manifest-sha256', required=True, help='build manifest hash recorded by the measured run')
    parser.add_argument('--assets-dir', type=Path, required=True)
    parser.add_argument('--producer', action='append', default=[], metavar='NAME=PATH')
    parser.add_argument('--producer-inventory', type=Path, required=True, help='saved sha256/mode inventory; do not substitute files from a later runner version')
    parser.add_argument('--php-root', action='append', type=Path, help='optional ordered whole-tree candidates')
    args = parser.parse_args()
    producers = {}
    for item in args.producer:
        name, separator, path = item.partition('=')
        if not separator or name in producers:
            parser.error('--producer requires distinct NAME=PATH mappings')
        producers[name] = Path(path)
    metadata = archive_source_inputs(args.build_dir, args.output_dir, assets_dir=args.assets_dir,
        producer_files=producers, expected_producers=json.loads(args.producer_inventory.read_text()), expected_build_manifest_sha256=args.build_manifest_sha256, php_roots=args.php_root)
    print(json.dumps(metadata, indent=2))


if __name__ == '__main__':
    main()
