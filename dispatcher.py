#!/usr/bin/env python3
"""Geo Prospect dispatcher (Python rewrite).

Cron-triggered (intended to run as a Railway Cron Job service, see
railway.json). On each invocation: reads the Jobs sheet, picks at most
ONE row with Run Status == RUN (highest "Importance of audit" first),
and drives it through Alex's geo-prospect worker via `railway ssh` —
brand-identity discovery, then the real audit — then emails the
finished PDF and writes status back to the sheet.

Processing exactly one row per invocation, and clearing that row's
Run Status the moment it's picked up (before the 30-40 min remote work
starts), is the concurrency guard: it stops the SAME row being picked up
twice. It does NOT serialize separate rows against each other — if two
cron ticks fire close together, they can each pick a different RUN row
and run in parallel. That's accepted for now; if API costs/rate limits
make that unacceptable later, add a mutex row/lock file.

Known limitation: if the dispatcher process crashes or is killed mid-job
(after Run Status is cleared but before the row is marked DONE/ERROR),
that row is stuck and won't be retried automatically. Worth a stale-lock
timeout later; not implemented in this first version.
"""
from __future__ import annotations

import datetime as dt
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from lib import sheets, railway_exec, emailer, reports_host
from lib.inputs_builder import build_inputs, InputsError

SPREADSHEET_ID = os.environ.get(
    "SPREADSHEET_ID", "1co2Y-o4RoiMs4Y-ElRH_hatUTJPvNHcWCIChMILYwCA")
SHEET_NAME = os.environ.get("SHEET_NAME", "Jobs")
REMOTE_SCRIPTS_DIR = Path(__file__).resolve().parent / "remote_scripts"

DISCOVERY_TIMEOUT_S = int(os.environ.get("DISCOVERY_TIMEOUT_S", str(10 * 60)))
AUDIT_TIMEOUT_S = int(os.environ.get("AUDIT_TIMEOUT_S", str(45 * 60)))

RUN_STATUS_COL = ("run status",)
PROGRESS_COL = ("status",)
PDF_COL = ("pdf_report", "pdf report")
DATE_COL = ("date",)
EMAIL_COL = ("emailreport", "email report", "email")
PLATFORM_COLS = {
    "perplexity": ("perplexity status",),
    "chatgpt": ("chatgpt status",),
    "google": ("gemini status",),  # sidecar key is "google"; sheet column is "Gemini status"
    "claude": ("claude status",),
}


def safe_domain_slug(domain: str) -> str:
    return domain.replace(".", "_")


def pick_row(rows: list[list[str]], col_map: dict) -> tuple[int, list[str]] | None:
    """Returns (row_number, row) for the highest-"Importance of audit"
    RUN row, or None if there's nothing to do. row_number is 1-indexed
    matching the sheet (row 1 = header)."""
    candidates = []
    for i, row in enumerate(rows[1:]):
        row_number = i + 2  # +1 for 0-index, +1 because header is row 1
        status = sheets.get_cell(row, col_map, *RUN_STATUS_COL).upper()
        if status != "RUN":
            continue
        importance_raw = sheets.get_cell(row, col_map, "importance of audit")
        try:
            importance = float(importance_raw)
        except ValueError:
            importance = 0.0
        candidates.append((importance, row_number, row))
    if not candidates:
        return None
    candidates.sort(key=lambda c: (-c[0], c[1]))
    _, row_number, row = candidates[0]
    return row_number, row


def _read_latest_sidecar(client_domain: str) -> dict:
    """geo_client.py has TWO separate failure-reporting formats, not one:
    the newer *_audit_status.json sidecar (written for input-validation
    failures and successful completions — emit_audit_status_sidecar), and
    an older audit_failure_<timestamp>.json used for downstream pipeline
    aborts (query-generation tier imbalance, sanity-score rejections,
    etc — confirmed against a real run 2026-07-16). Both need checking;
    the second is normalised into the same shape main() already expects
    (overall_status/review_reason/platforms/pdf) so no caller-side
    branching changes are needed."""
    slug = safe_domain_slug(client_domain)
    remote_dir = f"/data/client_outputs/{slug}"

    path = railway_exec.find_latest(remote_dir, "*_audit_status.json")
    if path:
        return json.loads(railway_exec.download_text(path))

    path = railway_exec.find_latest(remote_dir, "audit_failure_*.json")
    if path:
        failure = json.loads(railway_exec.download_text(path))
        reason = failure.get("reason", "")
        actionable = failure.get("actionable_message", "")
        return {
            "overall_status": "failed",
            "review_reason": (
                f"[{failure.get('stage', 'unknown stage')}] {reason}"
                + (f" — {actionable}" if actionable else "")
            ),
            "platforms": {},
            "pdf": {"status": "failed", "path": ""},
        }

    raise RuntimeError(
        f"no audit_status sidecar or audit_failure dump found under {remote_dir}")


def run_job(inputs: dict) -> dict:
    """Drives one audit through Alex's worker end-to-end. Returns the
    final status sidecar dict. Raises on anything that stops us from
    reaching a sidecar at all (upload failure, discovery failure)."""
    slug = safe_domain_slug(inputs["client_domain"])
    remote_inputs_path = f"/data/dispatcher_{slug}_inputs.json"
    remote_discover_script = "/data/dispatcher_discover_identities.py"

    inputs_bytes = json.dumps(inputs, ensure_ascii=False, indent=2).encode("utf-8")
    railway_exec.upload_text(inputs_bytes, remote_inputs_path)

    discover_script_bytes = (REMOTE_SCRIPTS_DIR / "discover_identities.py").read_bytes()
    railway_exec.upload_text(discover_script_bytes, remote_discover_script)

    rc, out, err = railway_exec.run_remote(
        ["python3", remote_discover_script, remote_inputs_path],
        timeout=DISCOVERY_TIMEOUT_S,
    )
    if rc != 0 or "DONE" not in out:
        raise RuntimeError(f"brand-identity discovery failed (rc={rc}):\n{out}\n{err}")

    # geo_client.py's own "fail-loud guarantee" means it always writes a
    # sidecar (success or needs_review) — we read that back regardless of
    # this rc rather than trusting the subprocess return code alone.
    railway_exec.run_remote(
        ["python3", "geo_client.py", "--inputs-file", remote_inputs_path,
         "--non-interactive"],
        timeout=AUDIT_TIMEOUT_S,
    )

    return _read_latest_sidecar(inputs["client_domain"])


def main() -> None:
    service = sheets.get_sheets_service()
    rows = sheets.get_rows(service, SPREADSHEET_ID, SHEET_NAME)
    if not rows:
        print("No data in sheet.")
        return

    col_map = sheets.build_col_map(rows[0])

    picked = pick_row(rows, col_map)
    if picked is None:
        print("Nothing to do (no RUN rows).")
        return
    row_number, row = picked

    client_name = sheets.get_cell(row, col_map, "client (company name)", "client")
    print(f"Row {row_number}: '{client_name}' — status RUN, picking up.")

    # Clear Run Status immediately, before the long remote work starts —
    # this is the concurrency guard against the same row being picked up
    # twice by an overlapping cron tick.
    sheets.update_cells(service, SPREADSHEET_ID, SHEET_NAME, row_number, col_map, {
        RUN_STATUS_COL: "PROCESSING",
        PROGRESS_COL: "RUNNING",
    })

    try:
        inputs = build_inputs(row, col_map)
    except InputsError as e:
        sheets.update_cells(service, SPREADSHEET_ID, SHEET_NAME, row_number, col_map, {
            RUN_STATUS_COL: "ERROR",
            PROGRESS_COL: f"ERROR: {e}",
        })
        print(f"Row {row_number}: bad inputs — {e}")
        return

    try:
        sidecar = run_job(inputs)
    except Exception as e:
        sheets.update_cells(service, SPREADSHEET_ID, SHEET_NAME, row_number, col_map, {
            RUN_STATUS_COL: "ERROR",
            PROGRESS_COL: f"ERROR: {e}",
        })
        print(f"Row {row_number}: job failed — {e}")
        return

    updates: dict[tuple, str] = {DATE_COL: dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")}
    for platform, col in PLATFORM_COLS.items():
        updates[col] = sidecar.get("platforms", {}).get(platform, {}).get("status", "")

    overall_status = sidecar.get("overall_status")
    pdf_info = sidecar.get("pdf", {})

    if overall_status == "completed" and pdf_info.get("status") in ("shipped", "shipped_stamped"):
        pdf_path = pdf_info["path"]
        pdf_bytes = railway_exec.download_binary(pdf_path)
        pdf_filename = os.path.basename(pdf_path)

        # Publish to reports_server for a real clickable link. Best-effort:
        # the raw remote path (not itself a usable URL — only reachable
        # via railway ssh) is still what lands in the sheet if this fails,
        # so a hosting hiccup doesn't sink an otherwise-successful job.
        public_url = ""
        try:
            public_url = reports_host.upload_pdf(pdf_bytes, pdf_filename)
        except Exception as e:
            print(f"Row {row_number}: report hosting upload failed — {e}")

        email = sheets.get_cell(row, col_map, *EMAIL_COL)
        if email:
            emailer.send_report_email(email, client_name, pdf_bytes, pdf_filename, public_url)
            updates[PROGRESS_COL] = "DONE (emailed)"
        else:
            updates[PROGRESS_COL] = "DONE (no email address supplied)"
        updates[PDF_COL] = public_url or pdf_path
        updates[RUN_STATUS_COL] = "DONE"
    else:
        reason = sidecar.get("review_reason", "unknown failure")
        updates[PROGRESS_COL] = f"NEEDS_REVIEW: {reason}"
        updates[RUN_STATUS_COL] = "ERROR"

    sheets.update_cells(service, SPREADSHEET_ID, SHEET_NAME, row_number, col_map, updates)
    print(f"Row {row_number}: finished — {updates.get(PROGRESS_COL)}")


if __name__ == "__main__":
    main()
