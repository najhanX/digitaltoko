"""Portable launcher regression tests. All ADB responses are fake; no HP is contacted."""
import importlib.util
import io
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('packager', HERE / 'package-forwarder.py')
packager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packager)

MOCK_CS = r'''
using System;
using System.IO;
class FakeAdb {
    static int Main(string[] args) {
        string mode = Environment.GetEnvironmentVariable("FAKE_ADB_MODE");
        string command = String.Join(" ", args);
        File.AppendAllText(Environment.GetEnvironmentVariable("FAKE_ADB_LOG"), command + "\n");
        if (command == "devices") {
            Console.WriteLine("List of devices attached");
            if (mode != "none") Console.WriteLine("PHONE\t" + (mode == "unauthorized" || mode == "offline" ? mode : "device"));
            if (mode == "multiple") Console.WriteLine("OTHER\tdevice");
        } else if (command.Contains("pm path")) {
            if (mode != "missing-app") Console.WriteLine("package:/data/app/base.apk");
        } else if (command.Contains("allow_listener")) {
            if (mode == "denied") { Console.WriteLine("Permission denied"); return 1; }
        } else if (command.Contains("enabled_notification_listeners")) {
            Console.WriteLine(mode == "unverified" ? "null" : "id.hansdev.forwarder/id.hansdev.forwarder.ForwardListener");
        } else if (!command.Contains("am start")) return 2;
        return 0;
    }
}
'''
MOCK_SH = '''#!/bin/sh
printf '%s\\n' "$*" >> "$FAKE_ADB_LOG"
case "$*" in
devices)
    printf 'List of devices attached\\n'
    case "$FAKE_ADB_MODE" in
      none) ;; unauthorized|offline) printf 'PHONE\\t%s\\n' "$FAKE_ADB_MODE";;
      multiple) printf 'PHONE\\tdevice\\nOTHER\\tdevice\\n';;
      *) printf 'PHONE\\tdevice\\n';;
    esac;;
*'pm path'*) [ "$FAKE_ADB_MODE" = missing-app ] || printf 'package:/data/app/base.apk\\n';;
*allow_listener*) [ "$FAKE_ADB_MODE" != denied ] || exit 1;;
*enabled_notification_listeners*)
    if [ "$FAKE_ADB_MODE" = unverified ]; then printf 'null\\n';
    else printf 'id.hansdev.forwarder/id.hansdev.forwarder.ForwardListener\\n'; fi;;
*'am start'*) ;; *) exit 2;;
esac
exit 0
'''


class LauncherTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='HansDev portable ! ')
        cls.root = Path(cls.temp.name)
        cls.bash = Path('C:/Program Files/Git/bin/bash.exe') if os.name == 'nt' else Path('/bin/sh')
        cls.windows = cls.root / 'Windows tools !'
        cls.windows.mkdir()
        if os.name == 'nt':
            source = cls.root / 'FakeAdb.cs'
            source.write_text(MOCK_CS, encoding='utf-8')
            compiler = 'C:/Windows/Microsoft.NET/Framework64/v4.0.30319/csc.exe'
            subprocess.run([compiler, '/nologo', '/out:' + str(cls.windows / 'adb.exe'), str(source)], check=True, capture_output=True)
            shutil.copyfile(HERE / 'aktifkan-listener.cmd', cls.windows / 'aktifkan-listener.cmd')

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def test_launchers_use_local_adb_and_handle_failure_modes(self):
        for platform in ['windows', 'macos', 'linux']:
            if platform == 'windows' and os.name != 'nt':
                continue
            folder = self.windows if platform == 'windows' else self.root / (platform + ' tools !')
            folder.mkdir(exist_ok=True)
            name, script = packager.LAUNCHERS[platform]
            launcher = folder / name
            launcher.write_bytes((HERE / script).read_bytes())
            if platform != 'windows':
                (folder / 'adb').write_text(MOCK_SH, encoding='utf-8', newline='\n')
                (folder / 'adb').chmod(0o755)
            for mode in ['success', 'none', 'unauthorized', 'offline', 'multiple', 'missing-app', 'denied', 'unverified']:
                with self.subTest(platform=platform, mode=mode):
                    log = self.root / 'calls.txt'
                    log.write_text('')
                    env = dict(os.environ, FAKE_ADB_MODE=mode, FAKE_ADB_LOG=log.as_posix())
                    if platform == 'windows':
                        command = ['cmd.exe', '/d', '/c', str(launcher), '--no-pause']
                    else:
                        command = [str(self.bash), str(launcher), '--no-pause']
                    result = subprocess.run(command, env=env, cwd=self.root.parent, capture_output=True, timeout=15)
                    output = result.stdout.decode(errors='replace')
                    self.assertEqual(result.returncode == 0, mode == 'success', output)
                    self.assertEqual('BERHASIL:' in output, mode == 'success', output)
                    calls = log.read_text().splitlines()
                    self.assertEqual(calls[0], 'devices')
                    self.assertTrue(all(line.startswith('-s PHONE ') for line in calls[1:]))
                    if mode in ['none', 'unauthorized', 'offline', 'multiple', 'missing-app']:
                        self.assertFalse(any('allow_listener' in line for line in calls))
            adb = folder / ('adb.exe' if platform == 'windows' else 'adb')
            backup = adb.with_suffix('.saved')
            adb.rename(backup)
            try:
                result = subprocess.run(command, env=env, cwd=self.root.parent, capture_output=True, timeout=15)
                self.assertNotEqual(result.returncode, 0)
            finally:
                backup.rename(adb)

    def test_bundle_preserves_original_files_and_executable_modes(self):
        source = self.root / 'original.zip'
        with zipfile.ZipFile(source, 'w') as bundle:
            bundle.writestr('HansDev-Forwarder.apk', b'unchanged-apk')
            for platform in packager.LAUNCHERS:
                nested = io.BytesIO()
                with zipfile.ZipFile(nested, 'w') as sdk:
                    sdk.writestr('platform-tools/' + ('adb.exe' if platform == 'windows' else 'adb'), b'original-adb')
                    sdk.writestr('platform-tools/NOTICE.txt', b'original-license')
                bundle.writestr(f'platform-tools-latest-{platform}.zip', nested.getvalue())
        before = source.read_bytes()
        output = self.root / 'release.zip'
        packager.package(source, output)
        self.assertEqual(source.read_bytes(), before)
        with zipfile.ZipFile(output) as bundle:
            self.assertEqual(bundle.read('HansDev-Forwarder.apk'), b'unchanged-apk')
            for platform, (name, _) in packager.LAUNCHERS.items():
                with zipfile.ZipFile(io.BytesIO(bundle.read(f'platform-tools-latest-{platform}.zip'))) as sdk:
                    self.assertEqual(sdk.read('platform-tools/NOTICE.txt'), b'original-license')
                    self.assertEqual(sdk.read('platform-tools/' + ('adb.exe' if platform == 'windows' else 'adb')), b'original-adb')
                    info = sdk.getinfo('platform-tools/' + name)
                    self.assertEqual((info.external_attr >> 16) & 0o777, 0o644 if platform == 'windows' else 0o755)
                    data = sdk.read(info)
                    self.assertEqual(b'\r\n' in data, platform == 'windows')
        with self.assertRaises(ValueError):
            packager.package(source, source)


if __name__ == '__main__':
    unittest.main()
