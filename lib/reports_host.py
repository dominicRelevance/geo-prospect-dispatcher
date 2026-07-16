"""Uploads finished PDFs to the dedicated reports-hosting Railway
service (reports_server/) so the Jobs sheet can carry a real public link
instead of the raw path inside Alex's worker's volume — that path is
only reachable via `railway ssh`, not HTTP, so it was never a usable
link on its own.
"""
from __future__ import annotations

import os

import requests


class ReportsHostError(Exception):
    pass


def upload_pdf(pdf_bytes: bytes, filename: str) -> str:
    base_url = os.environ["REPORTS_HOST_URL"].rstrip("/")
    token = os.environ["REPORTS_UPLOAD_TOKEN"]
    resp = requests.post(
        f"{base_url}/upload",
        headers={"Authorization": f"Bearer {token}"},
        files={"file": (filename, pdf_bytes, "application/pdf")},
        timeout=60,
    )
    if resp.status_code != 200:
        raise ReportsHostError(f"upload failed ({resp.status_code}): {resp.text[:300]}")
    return resp.json()["url"]
