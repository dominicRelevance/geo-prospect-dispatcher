"""Wraps the Railway CLI's `railway ssh` to run commands against Alex's
geo-prospect worker and move files to/from its /data volume.

Two things about `railway ssh` were confirmed empirically (2026-07-15)
and are load-bearing for how this module is written:

1. `railway run` executes a command LOCALLY, only injecting the linked
   service's environment variables — it does NOT run inside the deployed
   container. Only `railway ssh -- <command>` reaches the actual running
   container (and therefore the /data persistent volume). Alex's own
   idle-worker startup message suggests `railway run`, which is wrong for
   this purpose.

2. `railway ssh -- <args...>` does NOT preserve argv word boundaries the
   way `subprocess.run([...])` does. It flattens all trailing arguments
   into a single string (naively joined with spaces, no re-quoting) and
   the remote side re-parses that string through a shell. Any argument
   containing a space or shell-special character gets corrupted unless
   *we* pre-quote it first. Fix: build the remote command ourselves with
   shlex.join() and hand `railway ssh --` a single already-quoted string,
   so its trivial one-element "join" is a no-op and the remote shell sees
   exactly what we intended.

   (Verified: passing ["python3", "-c", "import sys; print(sys.argv[1:])",
   "hello world"] directly split "hello world" into two words on arrival;
   pre-joining with shlex.join() before handing it to `railway ssh --`
   fixed it.)
"""
from __future__ import annotations

import base64
import os
import shlex
import subprocess

RAILWAY_SERVICE = os.environ.get("RAILWAY_SERVICE", "geo-prospect")

# entrypoint.sh writes the dispatcher's dedicated deploy key here. Passed
# explicitly via -i rather than relying on `railway ssh`'s default
# ~/.ssh/ scan — that scan resolves via $HOME at runtime, which doesn't
# reliably match this hardcoded write path inside the container (seen
# empirically: key written successfully, then "No SSH keys found in your
# SSH agent or ~/.ssh/" from railway ssh itself on the very next call).
# Locally (Dominic's Mac), this path won't exist, so we fall back to
# default discovery (agent / ~/.ssh scan), which already works there.
RAILWAY_SSH_IDENTITY_FILE = os.environ.get(
    "RAILWAY_SSH_IDENTITY_FILE", "/root/.ssh/id_ed25519")


class RailwaySSHError(Exception):
    pass


def _run(args: list[str], stdin_bytes: bytes | None = None,
         timeout: int = 120) -> tuple[int, bytes, bytes]:
    remote_cmd = shlex.join(args)
    cmd = ["railway", "ssh", "-s", RAILWAY_SERVICE]
    if os.path.isfile(RAILWAY_SSH_IDENTITY_FILE):
        cmd += ["-i", RAILWAY_SSH_IDENTITY_FILE]
    cmd += ["--", remote_cmd]
    proc = subprocess.run(
        cmd,
        input=stdin_bytes,
        capture_output=True,
        timeout=timeout,
    )
    return proc.returncode, proc.stdout, proc.stderr


def run_remote(args: list[str], timeout: int = 120) -> tuple[int, str, str]:
    rc, out, err = _run(args, timeout=timeout)
    return rc, out.decode("utf-8", "replace"), err.decode("utf-8", "replace")


def upload_text(content_bytes: bytes, remote_path: str, timeout: int = 60) -> None:
    """Writes content_bytes to remote_path via `tee`, streamed over stdin
    so binary/text content never has to survive a round-trip through
    shell quoting."""
    rc, out, err = _run(["tee", remote_path], stdin_bytes=content_bytes, timeout=timeout)
    if rc != 0:
        raise RailwaySSHError(
            f"upload to {remote_path} failed (rc={rc}): {err.decode('utf-8', 'replace')}")


def download_text(remote_path: str, timeout: int = 60) -> str:
    rc, out, err = _run(["cat", remote_path], timeout=timeout)
    if rc != 0:
        raise RailwaySSHError(
            f"download of {remote_path} failed (rc={rc}): {err.decode('utf-8', 'replace')}")
    return out.decode("utf-8", "replace")


def download_binary(remote_path: str, timeout: int = 180) -> bytes:
    """base64 round-trip — the SSH exec channel here is text-oriented via
    our own capture, so binary files (PDFs) go through base64 rather than
    raw stdout bytes."""
    rc, out, err = _run(["base64", remote_path], timeout=timeout)
    if rc != 0:
        raise RailwaySSHError(
            f"download of {remote_path} failed (rc={rc}): {err.decode('utf-8', 'replace')}")
    return base64.b64decode(out)


def find_latest(remote_dir: str, name_pattern: str, timeout: int = 30) -> str | None:
    """Returns the path of the most-recently-modified file matching
    name_pattern directly under remote_dir, or None if there's no match
    or the directory doesn't exist. Uses `find -printf` rather than a
    shell pipeline (ls -t | head -1) so nothing here depends on remote
    shell pipe/glob semantics — sorting happens locally in Python."""
    rc, out, err = run_remote([
        "find", remote_dir, "-maxdepth", "1", "-name", name_pattern,
        "-printf", "%T@\t%p\n",
    ], timeout=timeout)
    if rc != 0 or not out.strip():
        return None
    lines = [line for line in out.strip().split("\n") if line]
    parsed = []
    for line in lines:
        try:
            mtime_str, path = line.split("\t", 1)
            parsed.append((float(mtime_str), path))
        except ValueError:
            continue
    if not parsed:
        return None
    parsed.sort(key=lambda p: p[0], reverse=True)
    return parsed[0][1]
