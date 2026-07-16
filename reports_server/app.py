"""Minimal PDF hosting service for geo-prospect reports.

Receives finished audit PDFs from the dispatcher (POST /upload, bearer-
token protected) and serves them back over a public URL
(GET /reports/<filename>), so the Jobs sheet can carry a real clickable
link instead of the raw path inside Alex's worker's volume (which is
only reachable via `railway ssh`, not HTTP).

Deliberately its own always-on Railway service, separate from both the
dispatcher (a cron job, not always running) and Alex's geo-prospect
worker (no web server by design — see lib/railway_exec.py in the
dispatcher for why that stays that way).
"""
import os
import secrets as _secrets
from pathlib import Path

from fastapi import FastAPI, File, Header, HTTPException, UploadFile
from fastapi.staticfiles import StaticFiles

REPORTS_DIR = Path(os.environ.get("REPORTS_DIR", "/data/reports"))
REPORTS_DIR.mkdir(parents=True, exist_ok=True)
UPLOAD_TOKEN = os.environ["REPORTS_UPLOAD_TOKEN"]

app = FastAPI()
app.mount("/reports", StaticFiles(directory=str(REPORTS_DIR)), name="reports")


def _base_url() -> str:
    explicit = os.environ.get("PUBLIC_BASE_URL", "").rstrip("/")
    if explicit:
        return explicit
    domain = os.environ.get("RAILWAY_PUBLIC_DOMAIN", "")
    if domain:
        return f"https://{domain}"
    return ""


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/upload")
async def upload(file: UploadFile = File(...), authorization: str = Header(None)):
    if authorization != f"Bearer {UPLOAD_TOKEN}":
        raise HTTPException(401, "bad or missing token")

    # Unguessable slug prefix — these are client business reports, not
    # meant to be enumerable even though they're not behind auth once
    # you have the link (matches "unlisted", not "public+indexed").
    slug = _secrets.token_hex(8)
    safe_name = "".join(c for c in file.filename if c.isalnum() or c in "._-") or "report.pdf"
    dest_name = f"{slug}_{safe_name}"
    dest_path = REPORTS_DIR / dest_name

    contents = await file.read()
    dest_path.write_bytes(contents)

    return {"filename": dest_name, "url": f"{_base_url()}/reports/{dest_name}"}
