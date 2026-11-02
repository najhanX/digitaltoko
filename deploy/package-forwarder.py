"""Add portable listener launchers without changing the supplied SDK/APK files."""
import argparse
import copy
import hashlib
import io
import os
from pathlib import Path
import stat
import tempfile
import zipfile

LAUNCHERS = {
    'windows': ('aktifkan-listener.cmd', 'aktifkan-listener.cmd'),
    'macos': ('aktifkan-listener.command', 'aktifkan-listener.sh'),
    'linux': ('aktifkan-listener.sh', 'aktifkan-listener.sh'),
}


def package(source, output):
    source, output = Path(source).resolve(), Path(output).resolve()
    if source == output:
        raise ValueError('Source archive must be preserved; choose a different output.')
    scripts = Path(__file__).resolve().parent
    output.parent.mkdir(parents=True, exist_ok=True)
    handle, temporary = tempfile.mkstemp(dir=output.parent, suffix='.zip')
    os.close(handle)
    try:
        with zipfile.ZipFile(source) as original, zipfile.ZipFile(temporary, 'w') as bundle:
            expected = {'HansDev-Forwarder.apk'} | {
                f'platform-tools-latest-{platform}.zip' for platform in LAUNCHERS
            }
            if set(original.namelist()) != expected or original.testzip():
                raise ValueError('Unexpected or corrupt original bundle.')
            for entry in original.infolist():
                data = original.read(entry)
                for platform, (name, script) in LAUNCHERS.items():
                    if entry.filename != f'platform-tools-latest-{platform}.zip':
                        continue
                    nested = io.BytesIO()
                    with zipfile.ZipFile(io.BytesIO(data)) as sdk, zipfile.ZipFile(nested, 'w') as updated:
                        adb = 'adb.exe' if platform == 'windows' else 'adb'
                        if f'platform-tools/{adb}' not in sdk.namelist() or sdk.testzip():
                            raise ValueError(f'Missing ADB or corrupt SDK: {platform}')
                        target = name
                        for member in sdk.infolist():
                            if member.filename not in (target, f'platform-tools/{name}'):
                                updated.writestr(copy.copy(member), sdk.read(member))
                        content = (scripts / script).read_text(encoding='utf-8').replace('\r\n', '\n')
                        if platform == 'windows':
                            content = content.replace('\n', '\r\n')
                        launcher = zipfile.ZipInfo(target, (2026, 11, 1, 0, 0, 0))
                        launcher.create_system = 3
                        mode = 0o644 if platform == 'windows' else 0o755
                        launcher.external_attr = (stat.S_IFREG | mode) << 16
                        launcher.compress_type = zipfile.ZIP_DEFLATED
                        updated.writestr(launcher, content.encode('utf-8'))
                    data = nested.getvalue()
                bundle.writestr(copy.copy(entry), data)
        with zipfile.ZipFile(temporary) as built:
            if built.testzip():
                raise ValueError('Output bundle failed CRC verification.')
        os.replace(temporary, output)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return hashlib.sha256(output.read_bytes()).hexdigest()


def split_releases(bundle, directory):
    """Publish only the APK and one tools ZIP per OS; never extract arbitrary names."""
    directory = Path(directory).resolve()
    directory.mkdir(parents=True, exist_ok=True)
    names = {'HansDev-Forwarder.apk': 'HansDev-Forwarder.apk'}
    names.update({f'platform-tools-latest-{os}.zip': f'HansDev-Listener-{os}.zip' for os in LAUNCHERS})
    with zipfile.ZipFile(bundle) as archive:
        if set(archive.namelist()) != set(names) or archive.testzip():
            raise ValueError('Unexpected or corrupt bundle.')
        for entry, name in names.items():
            data = archive.read(entry)
            if entry.endswith('.zip'):
                platform = next(os for os in LAUNCHERS if entry == f'platform-tools-latest-{os}.zip')
                with zipfile.ZipFile(io.BytesIO(data)) as tools:
                    if tools.testzip() or LAUNCHERS[platform][0] not in tools.namelist():
                        raise ValueError('Missing portable launcher or corrupt tools: ' + platform)
            handle, temporary = tempfile.mkstemp(dir=directory, suffix='.tmp')
            try:
                with os.fdopen(handle, 'wb') as output:
                    output.write(data)
                os.replace(temporary, directory / name)
            finally:
                if os.path.exists(temporary):
                    os.unlink(temporary)
    return list(names.values())


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--split-dir', help='Publish APK and separate OS tools from the completed bundle')
    args = parser.parse_args()
    print(package(args.source, args.output))
    if args.split_dir:
        print(split_releases(args.output, args.split_dir))
