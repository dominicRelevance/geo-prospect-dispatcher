"""Google Sheets read/update helpers for the geo-prospect dispatcher.

Ports the header-name-based column lookup from the old dispatcher.php
(buildColMap / colIndex / colLetter) rather than hardcoded column
indexes, so the dispatcher keeps working if the sheet's column order
changes or the client adds columns.
"""
from __future__ import annotations

import os

from google.oauth2.service_account import Credentials
from googleapiclient.discovery import build

SCOPES = ["https://www.googleapis.com/auth/spreadsheets"]


def get_sheets_service():
    creds_path = os.environ["GOOGLE_SERVICE_ACCOUNT_FILE"]
    creds = Credentials.from_service_account_file(creds_path, scopes=SCOPES)
    return build("sheets", "v4", credentials=creds)


def get_rows(service, spreadsheet_id: str, sheet_name: str) -> list[list[str]]:
    result = service.spreadsheets().values().get(
        spreadsheetId=spreadsheet_id, range=sheet_name
    ).execute()
    return result.get("values", [])


def build_col_map(headers: list[str]) -> dict[str, int]:
    return {h.strip().lower(): i for i, h in enumerate(headers)}


def col_index(col_map: dict[str, int], *names: str) -> int | None:
    for name in names:
        if name.lower() in col_map:
            return col_map[name.lower()]
    return None


def col_letter(index: int) -> str:
    letter = ""
    index += 1
    while index > 0:
        index -= 1
        letter = chr(65 + (index % 26)) + letter
        index //= 26
    return letter


def get_cell(row: list[str], col_map: dict[str, int], *names: str, default: str = "") -> str:
    idx = col_index(col_map, *names)
    if idx is None or idx >= len(row):
        return default
    return (row[idx] or "").strip()


def update_cells(service, spreadsheet_id: str, sheet_name: str, row_number: int,
                  col_map: dict[str, int], updates: dict[tuple, str]) -> None:
    """updates: {(header_name_variant, ...): value}. Any key whose names
    aren't found in col_map is silently skipped (matches the old PHP
    dispatcher's colIndex behaviour) — a missing column shouldn't crash
    the whole job."""
    data = []
    for names, value in updates.items():
        idx = col_index(col_map, *names)
        if idx is None:
            continue
        col = col_letter(idx)
        data.append({
            "range": f"{sheet_name}!{col}{row_number}",
            "values": [[value]],
        })
    if not data:
        return
    body = {"valueInputOption": "RAW", "data": data}
    service.spreadsheets().values().batchUpdate(
        spreadsheetId=spreadsheet_id, body=body
    ).execute()
