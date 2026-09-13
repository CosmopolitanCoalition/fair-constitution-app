#!/usr/bin/env python3
"""Validate deployment files only. Run with Synapse's Python and PyYAML."""

import argparse
from pathlib import Path
import shlex
import sys

import yaml


class InvalidConfiguration(ValueError):
    pass


def require(condition, message):
    if not condition:
        raise InvalidConfiguration(message)


def read_yaml(path):
    require(path.is_file(), f"Missing configuration file: {path.name}")
    try:
        result = yaml.safe_load(path.read_text())
    except (OSError, yaml.YAMLError):
        raise InvalidConfiguration(f"Cannot read valid YAML from {path.name}") from None
    require(isinstance(result, dict), f"Invalid configuration structure: {path.name}")
    return result


def check_name(path, hostname):
    if not path.exists():
        print("fresh")
        return
    actual = read_yaml(path).get("server_name")
    require(isinstance(actual, str) and actual != "", "Stored Matrix server name is missing.")
    require(actual == hostname,
            "Stored Matrix server name differs from --public-url. Keep the original hostname "
            "or use a separate empty installation. Existing Matrix data must not be reset.")
    print("existing")


def read_env(path):
    require(path.is_file(), "Missing application environment file.")
    result = {}
    for line in path.read_text().splitlines():
        name, separator, raw = line.partition("=")
        name = name.strip()
        if not separator or name.startswith("#"):
            continue
        # Only inspect this bundle; unrelated multiline dotenv values are not our concern.
        if name not in {"MATRIX_AS_TOKEN", "MATRIX_HS_TOKEN", "LIVEKIT_API_KEY",
                        "LIVEKIT_API_SECRET", "OIDC_MAS_CLIENT_SECRET", "OIDC_MAS_CLIENT_ID",
                        "OIDC_MAS_REDIRECT_URIS"}:
            continue
        try:
            values = shlex.split(raw, comments=True)
        except ValueError:
            raise InvalidConfiguration(f"Invalid environment value for {name}") from None
        require(len(values) == 1, f"Missing or invalid environment value for {name}")
        result[name] = values[0]
    return result


def secret(value, label):
    # These are public development defaults in the repository, not deployment secrets.
    known_defaults = {
        "devkey", "secret", "jrMzG1lUJWsj8PsQRzBwU2WScSbinZ4p",
        "a04f624dc7888b3eee4b3a3b1f2701bfbec4dbd7ceb5b2f0a2e957476bc3df85",
    }
    require(isinstance(value, str) and len(value) >= 16,
            f"Missing or invalid deployment credential: {label}")
    require(not value.startswith("cga_dev_") and value not in known_defaults,
            f"Development credential remains in {label}")
    return value


def check_bundle(root, hostname, issuer):
    env = read_env(root / ".env")
    mas = read_yaml(root / "docker/matrix/mas/config.yaml")
    registration = read_yaml(root / "docker/matrix/appservice/registration.yaml")
    synapse = read_yaml(root / "docker/matrix/conf.d/20-mas.yaml")
    livekit = read_yaml(root / "docker/livekit/livekit.yaml")

    try:
        require(mas["matrix"]["homeserver"] == hostname, "MAS homeserver does not match the public hostname.")
        mas_issuer = "https://auth." + hostname + "/"
        require(mas["http"]["issuer"] == mas_issuer and mas["http"]["public_base"] == mas_issuer,
                "MAS public issuer does not match the public hostname.")
        providers = mas["upstream_oauth2"]["providers"]
        require(isinstance(providers, list) and len(providers) == 1, "Expected one application login provider.")
        provider = providers[0]
        require(provider["issuer"].rstrip("/") == issuer.rstrip("/"), "Application login issuer does not match --public-url.")
        require(provider["discovery_mode"] == "oidc", "Public login must use OIDC discovery.")
        require(provider["client_id"] == env["OIDC_MAS_CLIENT_ID"], "Application login client IDs do not match.")
        require(env["OIDC_MAS_REDIRECT_URIS"] == mas_issuer + "upstream/callback/" + provider["id"],
                "Application login callback does not match the MAS provider.")
        for yaml_key, env_key in (("as_token", "MATRIX_AS_TOKEN"), ("hs_token", "MATRIX_HS_TOKEN")):
            require(secret(registration[yaml_key], yaml_key) == secret(env[env_key], env_key),
                    "Application and Matrix registration credentials do not match.")
        require(secret(provider["client_secret"], "MAS client secret") == secret(env["OIDC_MAS_CLIENT_SECRET"], "OIDC client secret"),
                "Application and MAS login credentials do not match.")
        require(secret(mas["matrix"]["secret"], "MAS shared secret") == secret(synapse["matrix_authentication_service"]["secret"], "Synapse shared secret"),
                "Synapse and MAS credentials do not match.")
        secret(mas["secrets"]["encryption"], "MAS encryption key")
        require(isinstance(mas["secrets"]["keys"], list) and len(mas["secrets"]["keys"]) > 0,
                "MAS signing keys are missing.")
        require(not env["LIVEKIT_API_KEY"].startswith("cga_dev_") and env["LIVEKIT_API_KEY"] != "devkey",
                "Development credential remains in the LiveKit API key.")
        require(secret(livekit["keys"][env["LIVEKIT_API_KEY"]], "LiveKit configuration") == secret(env["LIVEKIT_API_SECRET"], "LiveKit environment"),
                "Application and LiveKit credentials do not match.")
    except (KeyError, TypeError, AttributeError):
        raise InvalidConfiguration("The public Matrix bundle is incomplete or has an invalid structure.") from None
    print("Public Matrix configuration is consistent.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("name", "bundle"))
    parser.add_argument("path", type=Path)
    parser.add_argument("hostname")
    parser.add_argument("issuer", nargs="?")
    args = parser.parse_args()
    try:
        if args.mode == "name":
            check_name(args.path, args.hostname)
        else:
            require(bool(args.issuer), "A public application issuer is required.")
            check_bundle(args.path, args.hostname, args.issuer)
    except (InvalidConfiguration, OSError) as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
