"""FTP/FTPS helper for the deploy script.

Self-contained (standard library only). Handles the quirks met on shared
hosts: FTPS logins that then refuse every TLS data connection (falls back to
plain FTP), SIZE being refused (existence checks list the parent directory),
and FTP logins that do not land in the WordPress docroot (the docroot is
detected by looking for wp-load.php).
"""

from __future__ import annotations

import ftplib
import io
from contextlib import contextmanager
from pathlib import Path, PurePosixPath


class FTPError(RuntimeError):
    """Connecting to or writing over FTP failed."""


class _SessionReusingFTP_TLS(ftplib.FTP_TLS):
    """FTPS that reuses the control connection's TLS session for data transfers.

    Many servers refuse a data connection that negotiates a fresh TLS session;
    Python's ftplib does not reuse it by default.
    """

    def ntransfercmd(self, cmd, rest=None):
        conn, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            conn = self.context.wrap_socket(conn, server_hostname=self.host, session=self.sock.session)
        return conn, size


def split_host(raw: str) -> tuple[str, int]:
    """Turn ``ftp://203.0.113.10`` or ``host:2121`` into ``(host, port)``."""
    host = raw.strip()
    for scheme in ("ftps://", "ftp://"):
        if host.lower().startswith(scheme):
            host = host[len(scheme):]
            break
    host = host.rstrip("/")
    port = 21
    if ":" in host and not host.startswith("["):
        host, _, tail = host.rpartition(":")
        try:
            port = int(tail)
        except ValueError:
            host = f"{host}:{tail}"
    return host, port


def _basenames(connection: ftplib.FTP, path: str) -> set[str]:
    try:
        return {entry.rsplit("/", 1)[-1] for entry in connection.nlst(path)}
    except ftplib.all_errors:
        return set()


def _data_channel_works(connection: ftplib.FTP) -> bool:
    try:
        connection.nlst("/")
        return True
    except ftplib.all_errors:
        return False


def detect_docroot(connection: ftplib.FTP, hint: str) -> str:
    """Find the directory holding wp-load.php; ``hint`` (FTP_FOLDER) is only a hint."""
    hint = hint.strip("/")
    candidates: list[str] = []
    if hint:
        candidates += [f"/{hint}", f"/domains/{hint}"]
    for entry in sorted(_basenames(connection, "/domains")):
        if entry not in {".", ".."}:
            candidates.append(f"/domains/{entry}/{hint or 'public_html'}")
            candidates.append(f"/domains/{entry}/public_html")
    candidates += ["/public_html", "/htdocs", "/www", "/"]
    seen: set[str] = set()
    for candidate in candidates:
        candidate = "/" + candidate.strip("/") if candidate.strip("/") else "/"
        if candidate in seen:
            continue
        seen.add(candidate)
        if "wp-load.php" in _basenames(connection, candidate):
            return candidate
    return f"/{hint}" if hint else "/"


class Uploader:
    def __init__(self, connection: ftplib.FTP, root: str, *, secure: bool) -> None:
        self.ftp = connection
        self.root = PurePosixPath("/") / root.strip("/") if root.strip("/") else PurePosixPath("/")
        self.secure = secure
        self._known_dirs: set[str] = set()

    def resolve(self, remote: str | PurePosixPath) -> PurePosixPath:
        remote = PurePosixPath(remote)
        return remote if remote.is_absolute() else self.root / remote

    def exists(self, remote: str | PurePosixPath) -> bool:
        target = self.resolve(remote)
        try:
            self.ftp.cwd(str(target))
            return True
        except ftplib.all_errors:
            pass
        return target.name in _basenames(self.ftp, str(target.parent))

    def ensure_dir(self, remote: str | PurePosixPath) -> PurePosixPath:
        target = self.resolve(remote)
        for depth in range(1, len(target.parts)):
            candidate = PurePosixPath(*target.parts[: depth + 1])
            key = str(candidate)
            if key in self._known_dirs:
                continue
            try:
                self.ftp.mkd(key)
            except ftplib.error_perm as exc:
                if not str(exc).startswith(("550", "521")):  # 550 also means "exists"
                    raise FTPError(f"cannot create {key}: {exc}") from exc
            self._known_dirs.add(key)
        return target

    def upload_bytes(self, payload: bytes, remote: str | PurePosixPath) -> PurePosixPath:
        target = self.resolve(remote)
        self.ensure_dir(target.parent)
        self.ftp.storbinary(f"STOR {target}", io.BytesIO(payload), blocksize=1 << 16)
        return target

    def download(self, remote: str | PurePosixPath, local: Path) -> None:
        local.parent.mkdir(parents=True, exist_ok=True)
        with local.open("wb") as handle:
            self.ftp.retrbinary(f"RETR {self.resolve(remote)}", handle.write, blocksize=1 << 16)


def _login(host: str, port: int, user: str, password: str, *, secure: bool) -> ftplib.FTP:
    connection = _SessionReusingFTP_TLS() if secure else ftplib.FTP()
    connection.encoding = "utf-8"
    connection.connect(host, port, timeout=60)
    connection.login(user, password)
    if secure:
        connection.prot_p()
    connection.set_pasv(True)
    return connection


@contextmanager
def connect(host: str, port: int, user: str, password: str, folder_hint: str, *, prefer_tls: bool = True):
    """Yield an :class:`Uploader`, preferring FTPS and falling back to plain FTP."""
    connection, secure, tls_error = None, False, None
    if prefer_tls:
        try:
            candidate = _login(host, port, user, password, secure=True)
        except ftplib.all_errors as exc:
            tls_error = exc
        else:
            if _data_channel_works(candidate):
                connection, secure = candidate, True
            else:
                tls_error = "server accepted the FTPS login but refused the data channel"
                try:
                    candidate.close()
                except Exception:
                    pass
    if connection is None:
        try:
            connection = _login(host, port, user, password, secure=False)
        except ftplib.all_errors as exc:
            hint = f" (FTPS also failed: {tls_error})" if tls_error else ""
            raise FTPError(f"cannot log in to {host}:{port}: {exc}{hint}") from exc
    try:
        yield Uploader(connection, detect_docroot(connection, folder_hint), secure=secure)
    finally:
        try:
            connection.quit()
        except Exception:
            connection.close()
