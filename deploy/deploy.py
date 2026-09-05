#!/usr/bin/env python3
"""Deploy the SEW Dropbox to NextGEN Picker plugin to a WordPress site over FTP.

Standard library only -- no venv needed:

    python3 deploy/deploy.py              # upload, health check, activate
    python3 deploy/deploy.py --no-activate
    python3 deploy/deploy.py --check      # report site health and plugin state only

Configuration comes from .env in the repo root (see .env.example). The FTP and
WordPress credentials can be given in two ways:

  a) directly in this repo's .env (WP_URL, WP_USER, WP_PWD, FTP_IP, FTP_USER,
     FTP_PWD, FTP_FOLDER), or
  b) via SUB_ENV_PATH=<directory or file>: a second .env (for example the one of
     the music_blog repo) whose values fill in whatever this repo's .env leaves
     out. Values in this repo's .env win; real environment variables win over both.

The Dropbox App_key / App_secret (and ACCESS_TOKEN_FOR_TESTING, if present) are
rendered into plugin/<slug>/sew-dnp-config.php on the way up, so they never sit
in git. Remote files are backed up to deploy/.remote-backup/<timestamp>/ first;
if the live site shows a fatal error afterwards the previous version is restored.
"""

from __future__ import annotations

import argparse
import base64
import datetime as dt
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path, PurePosixPath

sys.path.insert(0, str(Path(__file__).resolve().parent))
import ftp  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parent.parent
PLUGIN_SLUG = "sew-dropbox-ngg-picker"
PLUGIN_LOCAL = REPO_ROOT / "plugin" / PLUGIN_SLUG
PLUGIN_REMOTE = f"wp-content/plugins/{PLUGIN_SLUG}"
CONFIG_FILENAME = "sew-dnp-config.php"
BACKUP_ROOT = Path(__file__).resolve().parent / ".remote-backup"

FATAL_MARKERS = ("Fatal error", "Parse error", "There has been a critical error", "Es gab einen kritischen Fehler")


class ConfigError(RuntimeError):
    pass


# --------------------------------------------------------------------------- env

def parse_env(path: Path) -> dict[str, str]:
    """Minimal .env parser: KEY=value, optional quotes, # comments, 'export ' prefix."""
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[len("export "):]
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        elif " #" in value:
            value = value.split(" #", 1)[0].rstrip()
        values[key] = value
    return values


def load_settings(env_file: Path) -> dict[str, str]:
    """Own .env, filled in from SUB_ENV_PATH, overridden by the real environment."""
    own = parse_env(env_file)
    merged: dict[str, str] = {}
    sub_path = os.environ.get("SUB_ENV_PATH") or own.get("SUB_ENV_PATH") or ""
    if sub_path:
        candidate = Path(sub_path).expanduser()
        if candidate.is_dir():
            candidate = candidate / ".env"
        if not candidate.is_file():
            raise ConfigError(f"SUB_ENV_PATH points to {candidate}, which does not exist")
        merged.update(parse_env(candidate))
        merged["_sub_env_file"] = str(candidate)
    merged.update({k: v for k, v in own.items() if v != ""})
    for key in list(merged):
        if key in os.environ and os.environ[key] != "":
            merged[key] = os.environ[key]
    return merged


def pick(settings: dict[str, str], *keys: str, default: str | None = None) -> str:
    for key in keys:
        value = settings.get(key, "")
        if value and value.strip():
            return value.strip()
    if default is not None:
        return default
    raise ConfigError("missing setting: " + " / ".join(keys))


class Target:
    def __init__(self, settings: dict[str, str]) -> None:
        missing: list[str] = []

        def need(*keys: str) -> str:
            try:
                return pick(settings, *keys)
            except ConfigError:
                missing.append(" / ".join(keys))
                return ""

        self.wp_url = need("WP_URL").rstrip("/")
        self.wp_user = need("WP_USER")
        self.wp_password = need("WP_PWD", "WP_PASSWORD", "WP_APP_PASSWORD")
        ftp_raw = need("FTP_IP", "FTP_HOST")
        self.ftp_user = need("FTP_USER")
        self.ftp_password = need("FTP_PWD", "FTP_PASSWORD")
        self.ftp_folder = pick(settings, "FTP_FOLDER", default="public_html")
        self.health_url = pick(settings, "HEALTH_URL", default=self.wp_url + "/")
        self.app_key = pick(settings, "App_key", "DROPBOX_APP_KEY", default="")
        self.app_secret = pick(settings, "App_secret", "DROPBOX_APP_SECRET", default="")
        self.test_token = pick(settings, "ACCESS_TOKEN_FOR_TESTING", "DROPBOX_TEST_TOKEN", default="")
        if missing:
            source = settings.get("_sub_env_file")
            where = f".env (and {source})" if source else ".env (set SUB_ENV_PATH to borrow them from another repo's .env)"
            raise ConfigError("missing in " + where + ":\n  - " + "\n  - ".join(missing))
        self.ftp_host, self.ftp_port = ftp.split_host(ftp_raw)


# --------------------------------------------------------------------------- helpers

def step(msg: str) -> None:
    print(f"==> {msg}")


def detail(msg: str) -> None:
    print(f"    {msg}")


def human(n: int) -> str:
    return f"{n / 1024:.1f} KB" if n >= 1024 else f"{n} B"


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def render_config(target: Target) -> bytes | None:
    if not target.app_key or not target.app_secret:
        return None
    lines = [
        "<?php",
        "// Generated by deploy/deploy.py from the repo's .env -- do not edit on the server, do not commit.",
        "if (!defined('ABSPATH')) { exit; }",
        f"define('SEW_DNP_APP_KEY', {php_string(target.app_key)});",
        f"define('SEW_DNP_APP_SECRET', {php_string(target.app_secret)});",
    ]
    if target.test_token:
        lines.append(f"define('SEW_DNP_TEST_TOKEN', {php_string(target.test_token)});")
    return ("\n".join(lines) + "\n").encode("utf-8")


def http(url: str, *, method: str = "GET", auth: tuple[str, str] | None = None, json_body=None, timeout: int = 60):
    headers = {"User-Agent": "sew-dropbox-ngg-picker-deploy/1.0"}
    data = None
    if json_body is not None:
        data = json.dumps(json_body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    if auth:
        token = base64.b64encode(f"{auth[0]}:{auth[1]}".encode("utf-8")).decode("ascii")
        headers["Authorization"] = f"Basic {token}"
    request = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", "replace")
    except urllib.error.URLError as exc:
        return 0, f"unreachable: {exc.reason}"


def site_healthy(target: Target) -> tuple[bool, str]:
    status, body = http(target.health_url)
    markers = [m for m in FATAL_MARKERS if m in body]
    if status != 200 or markers:
        return False, f"HTTP {status}, markers {markers}" if status else body
    return True, f"HTTP {status}"


def rest(target: Target, method: str, route: str, json_body=None) -> tuple[int, dict | str]:
    """WordPress REST via ?rest_route= (works without pretty permalinks), then /wp-json/."""
    auth = (target.wp_user, target.wp_password)
    for url in (f"{target.wp_url}/?rest_route={urllib.parse.quote(route)}", f"{target.wp_url}/wp-json{route}"):
        status, body = http(url, method=method, auth=auth, json_body=json_body)
        try:
            return status, json.loads(body)
        except ValueError:
            continue
    return status, body[:300]


def plugin_state(target: Target) -> str:
    status, body = rest(target, "GET", f"/wp/v2/plugins/{PLUGIN_SLUG}/{PLUGIN_SLUG}")
    if status == 200 and isinstance(body, dict):
        return body.get("status", "?")
    message = body.get("message") if isinstance(body, dict) else body
    return f"unknown ({status}: {message})"


def activate(target: Target) -> bool:
    state = plugin_state(target)
    if state == "active":
        detail("plugin already active")
        return True
    if state.startswith("unknown"):
        detail(f"cannot read plugin state via REST -- {state}")
        detail(f"activate it in {target.wp_url}/wp-admin/plugins.php")
        return False
    status, body = rest(target, "PUT", f"/wp/v2/plugins/{PLUGIN_SLUG}/{PLUGIN_SLUG}", {"status": "active"})
    if status == 200 and isinstance(body, dict) and body.get("status") == "active":
        detail("plugin activated")
        return True
    detail(f"activation failed ({status}: {body.get('message') if isinstance(body, dict) else body})")
    return False


# --------------------------------------------------------------------------- main

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--no-activate", action="store_true", help="upload only, do not activate the plugin")
    parser.add_argument("--check", action="store_true", help="only report site health and plugin state")
    parser.add_argument("--env", default=str(REPO_ROOT / ".env"), help="path to the .env (default: <repo>/.env)")
    args = parser.parse_args()

    try:
        settings = load_settings(Path(args.env).expanduser())
        target = Target(settings)
    except ConfigError as exc:
        print(f"error: {exc}\nStart from .env.example: cp .env.example .env", file=sys.stderr)
        return 2
    if settings.get("_sub_env_file"):
        detail(f"credentials filled in from {settings['_sub_env_file']}")

    if args.check:
        healthy, message = site_healthy(target)
        step(f"site {'healthy' if healthy else 'BROKEN'} ({message})")
        detail(f"plugin: {plugin_state(target)}")
        return 0 if healthy else 1

    files = sorted(p for p in PLUGIN_LOCAL.rglob("*") if p.is_file() and not p.name.startswith(".") and p.name != CONFIG_FILENAME)
    if not files:
        print(f"error: nothing to upload in {PLUGIN_LOCAL}", file=sys.stderr)
        return 2
    uploads: list[tuple[str, bytes]] = [(p.relative_to(PLUGIN_LOCAL).as_posix(), p.read_bytes()) for p in files]
    rendered = render_config(target)
    if rendered:
        uploads.append((CONFIG_FILENAME, rendered))
    else:
        detail("no Dropbox App_key/App_secret in .env -- enter them on the plugin's settings page instead")

    backup_dir = BACKUP_ROOT / dt.datetime.now().strftime("%Y%m%d-%H%M%S")
    remote_root = PurePosixPath(PLUGIN_REMOTE)

    step(f"pushing {len(uploads)} file(s) to {target.ftp_host}:{remote_root}")
    with ftp.connect(target.ftp_host, target.ftp_port, target.ftp_user, target.ftp_password, target.ftp_folder) as uploader:
        detail(f"connected via {'FTPS' if uploader.secure else 'plain FTP'}, docroot {uploader.root}")
        saved: list[tuple[Path, PurePosixPath]] = []
        for relative, payload in uploads:
            remote = remote_root / relative
            if uploader.exists(remote):
                copy = backup_dir / relative
                uploader.download(remote, copy)
                saved.append((copy, remote))
            uploader.upload_bytes(payload, remote)
            detail(f"{relative} ({human(len(payload))}{', rendered from .env' if relative == CONFIG_FILENAME else ''})")

        healthy, message = site_healthy(target)
        if not healthy:
            step(f"site BROKEN ({message}) -- rolling back")
            for copy, remote in saved:
                uploader.upload_bytes(copy.read_bytes(), remote)
                detail(f"restored {remote}")
            if not saved:
                detail(f"nothing to restore; delete {remote_root} over FTP to recover")
            healthy, message = site_healthy(target)
            detail(f"after rollback: {'healthy' if healthy else 'STILL BROKEN'} ({message})")
            return 1

    step(f"site healthy ({message})")
    if saved:
        detail(f"previous version backed up to {backup_dir}")

    if not args.no_activate:
        step("activating")
        activate(target)
        healthy, message = site_healthy(target)
        step(f"site {'healthy' if healthy else 'BROKEN'} after activation ({message})")
        if not healthy:
            detail("deactivate the plugin in wp-admin or delete its folder over FTP")
            return 1
    detail(f"picker:   {target.wp_url}/wp-admin/admin.php?page=sew-dropbox-picker")
    detail(f"settings: {target.wp_url}/wp-admin/admin.php?page=sew-dropbox-picker-settings")
    return 0


if __name__ == "__main__":
    sys.exit(main())
