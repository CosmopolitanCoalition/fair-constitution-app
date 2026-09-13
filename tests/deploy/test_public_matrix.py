"""Isolated deploy orchestration. All Docker commands are replaced by a local stub.

Run: python3 -m unittest discover -s tests/deploy -p 'test_public_matrix.py' -v
Requires Bash and PyYAML (both are present in the Synapse runtime).
"""

import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

import yaml


REPO = Path(__file__).resolve().parents[2]
CHECK = REPO / "scripts/deploy/check_public_matrix.py"
HOST = "node.example.invalid"


def write_bundle(root, development=False):
    root = Path(root)
    values = {
        "MATRIX_AS_TOKEN": "cga_as_" + "a" * 64,
        "MATRIX_HS_TOKEN": "cga_hs_" + "b" * 64,
        "OIDC_MAS_CLIENT_ID": "mas-upstream",
        "OIDC_MAS_CLIENT_SECRET": "c" * 64,
        "OIDC_MAS_REDIRECT_URIS": f"https://auth.{HOST}/upstream/callback/SYNTHETICPROVIDER",
        "LIVEKIT_API_KEY": "cga_123456789abc",
        "LIVEKIT_API_SECRET": "d" * 48,
    }
    if development:
        values["MATRIX_AS_TOKEN"] = "cga_dev_as_token_fixture"
    env = root / ".env"
    env.parent.mkdir(parents=True, exist_ok=True)
    existing = env.read_text() if env.exists() else ""
    # Match the real command's upsert semantics while retaining deploy settings.
    lines = [line for line in existing.splitlines() if line.partition("=")[0] not in values]
    env.write_text("\n".join(lines + [f"{k}={v}" for k, v in values.items()]) + "\n")
    configs = {
        "docker/matrix/mas/config.yaml": {
            "matrix": {"homeserver": HOST, "secret": "e" * 32},
            "http": {"issuer": f"https://auth.{HOST}/", "public_base": f"https://auth.{HOST}/"},
            "secrets": {"encryption": "f" * 64, "keys": [{"key": "synthetic signing key"}]},
            "upstream_oauth2": {"providers": [{
                "id": "SYNTHETICPROVIDER", "issuer": f"https://{HOST}",
                "discovery_mode": "oidc", "client_id": values["OIDC_MAS_CLIENT_ID"],
                "client_secret": values["OIDC_MAS_CLIENT_SECRET"],
            }]},
        },
        "docker/matrix/appservice/registration.yaml": {
            "as_token": values["MATRIX_AS_TOKEN"], "hs_token": values["MATRIX_HS_TOKEN"],
        },
        "docker/matrix/conf.d/20-mas.yaml": {"matrix_authentication_service": {"secret": "e" * 32}},
        "docker/livekit/livekit.yaml": {"keys": {values["LIVEKIT_API_KEY"]: values["LIVEKIT_API_SECRET"]}},
    }
    for name, data in configs.items():
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(yaml.safe_dump(data))


DOCKER_STUB = r'''#!/usr/bin/env python3
import json, os, pathlib, runpy, subprocess, sys
a = sys.argv[1:]
with open(os.environ['DOCKER_LOG'], 'a') as f:
    f.write(json.dumps(a) + '\n')
if a[:2] == ['volume', 'ls']:
    if os.environ.get('HAVE_MATRIX_VOLUME') == '1': print('fixture_matrix_data')
    sys.exit(0)
if a and a[0] == 'run':
    i = a.index('/deploy-check.py')
    mode, source, hostname = a[i+1:i+4]
    mounts = [a[n+1] for n, v in enumerate(a) if v == '--mount']
    mapping = {}
    for mount in mounts:
        fields = dict(part.split('=', 1) for part in mount.split(',') if '=' in part)
        mapping[fields['dst']] = fields['src']
    if mode == 'name':
        source = os.environ['MATRIX_STATE_FILE']
    else:
        source = mapping['/bundle']
    command = [sys.executable, mapping['/deploy-check.py'], mode, source, hostname] + a[i+4:]
    sys.exit(subprocess.run(command).returncode)
if a and a[0] == 'compose':
    if 'matrix:setup' in a:
        print('SYNTHETIC_CREDENTIAL_DO_NOT_LOG')
        behavior = os.environ.get('GENERATION_MODE', 'valid')
        if behavior == 'failure': sys.exit(42)
        if behavior == 'missing': sys.exit(0)
        target = next(v.split('=', 1)[1] for v in a if v.startswith('--env-path='))
        stage = pathlib.Path.cwd() / target.removeprefix('/var/www/html/')
        fixture = runpy.run_path(os.environ['FIXTURE_TEST_MODULE'])
        fixture['write_bundle'](stage.parent, development=behavior == 'development')
        sys.exit(0)
    if 'psql' in a: print('1')
    # No command, artisan call, service or frontend build is actually executed.
    sys.exit(0)
raise SystemExit('Unexpected docker call: ' + repr(a))
'''


class PublicMatrixDeployTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="cga-public-matrix-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "scripts/deploy").mkdir(parents=True)
        (self.root / "public").mkdir()
        shutil.copyfile(REPO / "deploy.sh", self.root / "deploy.sh")
        shutil.copyfile(CHECK, self.root / "scripts/deploy/check_public_matrix.py")
        (self.root / ".env.example").write_text("APP_KEY=example-fixture-key\nCGA_DEV_TIME=false\n")
        (self.root / ".env").write_text("APP_KEY=existing-fixture-key\nCGA_DEV_TIME=false\nCOMPOSE_PROJECT_NAME=fixture\n")
        write_bundle(self.root, development=True)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        (self.bin / "docker").write_text(DOCKER_STUB)
        (self.bin / "docker").chmod(0o755)
        self.log = self.root / "docker.jsonl"
        self.state = self.root / "homeserver.yaml"
        self.env = {**os.environ, "PATH": str(self.bin) + os.pathsep + os.environ["PATH"],
                    "DOCKER_LOG": str(self.log), "MATRIX_STATE_FILE": str(self.state),
                    "FIXTURE_TEST_MODULE": str(Path(__file__).resolve()), "HAVE_MATRIX_VOLUME": "0"}

    def deploy(self):
        result = subprocess.run(["bash", "deploy.sh", "--public-url", f"https://{HOST}",
                                 "--media-ip", "192.0.2.1", "--project", "fixture"],
                                cwd=self.root, env=self.env, capture_output=True, text=True, timeout=30)
        self.assertNotIn("SYNTHETIC_CREDENTIAL_DO_NOT_LOG", result.stdout + result.stderr)
        return result

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def assert_no_room_start(self):
        self.assertFalse(any("up" in a and ("mas" in a or "matrix" in a) for a in self.calls()))

    def assert_no_destruction(self):
        text = self.log.read_text()
        self.assertNotIn("DROP DATABASE", text)
        self.assertNotIn('"volume", "rm"', text)
        self.assertFalse(any("rm" in a and ("mas" in a or "matrix" in a) for a in self.calls()))

    def test_fresh_public_install_validates_before_starting_rooms(self):
        result = self.deploy()
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        calls = self.calls()
        generated = next(i for i, a in enumerate(calls) if "matrix:setup" in a)
        started = next(i for i, a in enumerate(calls) if "up" in a and "mas" in a)
        validated = [i for i, a in enumerate(calls) if "bundle" in a]
        self.assertTrue(generated < validated[-1] < started)
        self.assert_no_destruction()
        self.assertFalse(list(self.root.glob(".matrix-deploy.*")))

    def test_safe_rerun_keeps_existing_authentication_keys(self):
        write_bundle(self.root)
        self.env["HAVE_MATRIX_VOLUME"] = "1"
        self.state.write_text(f"server_name: '{HOST}'\n")
        config = self.root / "docker/matrix/mas/config.yaml"
        before = config.read_bytes()
        result = self.deploy()
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertFalse(any("matrix:setup" in a for a in self.calls()))
        self.assertEqual(before, config.read_bytes())
        self.assert_no_destruction()

    def test_empty_existing_volume_still_supports_a_fresh_install(self):
        self.env["HAVE_MATRIX_VOLUME"] = "1"
        result = self.deploy()
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertTrue(any("matrix:setup" in a for a in self.calls()))
        self.assert_no_destruction()

    def test_invalid_stored_config_refuses_before_config_changes(self):
        self.env["HAVE_MATRIX_VOLUME"] = "1"
        self.state.write_text("server_name: [invalid\n")
        before = (self.root / ".env").read_bytes()
        result = self.deploy()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, (self.root / ".env").read_bytes())
        self.assertFalse(any(a[0] == "compose" for a in self.calls()))
        self.assert_no_room_start()
        self.assert_no_destruction()

    def test_mismatched_hostname_refuses_before_any_config_changes(self):
        for name in ("old.example.invalid", "prefix." + HOST):
            with self.subTest(name=name):
                self.env["HAVE_MATRIX_VOLUME"] = "1"
                self.state.write_text(f"server_name: {name}\n")
                before = (self.root / ".env").read_bytes()
                result = self.deploy()
                self.assertNotEqual(0, result.returncode)
                self.assertIn("server name differs", result.stderr)
                self.assertEqual(before, (self.root / ".env").read_bytes())
                self.assertFalse(any(a[0] == "compose" for a in self.calls()))
                self.assert_no_room_start()
                self.assert_no_destruction()

    def test_failed_missing_or_development_generation_never_installs_or_starts(self):
        config = self.root / "docker/matrix/mas/config.yaml"
        for mode in ("failure", "missing", "development"):
            with self.subTest(mode=mode):
                self.env["GENERATION_MODE"] = mode
                before = config.read_bytes()
                result = self.deploy()
                self.assertNotEqual(0, result.returncode)
                self.assertEqual(before, config.read_bytes())
                self.assert_no_room_start()
                self.assert_no_destruction()
                self.assertFalse(list(self.root.glob(".matrix-deploy.*")))

    def test_existing_invalid_bundle_requires_repair_without_rotating_keys(self):
        self.env["HAVE_MATRIX_VOLUME"] = "1"
        self.state.write_text(f"server_name: {HOST}\n")
        result = self.deploy()
        self.assertNotEqual(0, result.returncode)
        self.assertIn("Existing Matrix configuration needs repair", result.stderr)
        self.assertFalse(any("matrix:setup" in a for a in self.calls()))
        self.assert_no_room_start()
        self.assert_no_destruction()


class PublicMatrixBundleTest(unittest.TestCase):
    def test_mismatched_and_known_development_secrets_are_refused(self):
        spec = importlib.util.spec_from_file_location("matrix_deploy_check", CHECK)
        checker = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(checker)
        for mutation in ("registration", "encryption", "synapse", "livekit", "missing"):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                write_bundle(root)
                if mutation == "registration":
                    path = root / "docker/matrix/appservice/registration.yaml"
                    data = yaml.safe_load(path.read_text())
                    data["as_token"] = "different_token_" + "z" * 32
                elif mutation == "encryption":
                    path = root / "docker/matrix/mas/config.yaml"
                    data = yaml.safe_load(path.read_text())
                    data["secrets"]["encryption"] = "a04f624dc7888b3eee4b3a3b1f2701bfbec4dbd7ceb5b2f0a2e957476bc3df85"
                elif mutation == "synapse":
                    path = root / "docker/matrix/conf.d/20-mas.yaml"
                    data = yaml.safe_load(path.read_text())
                    data["matrix_authentication_service"]["secret"] = "jrMzG1lUJWsj8PsQRzBwU2WScSbinZ4p"
                elif mutation == "livekit":
                    path = root / "docker/livekit/livekit.yaml"
                    data = {"keys": {"devkey": "secret"}}
                else:
                    path = root / "docker/matrix/conf.d/20-mas.yaml"
                    data = {}
                path.write_text(yaml.safe_dump(data))
                with self.assertRaises(checker.InvalidConfiguration):
                    checker.check_bundle(root, HOST, "https://" + HOST)


if __name__ == "__main__":
    unittest.main()
