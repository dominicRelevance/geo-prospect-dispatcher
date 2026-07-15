"""Maps a Jobs-sheet row to the inputs dict geo_client.py's --inputs-file
expects.

Field names/shapes here are taken directly from geo_client.py's own
interactive input-collection code (Build 3.23.3), not guessed:
  - business_description {what, where, market_position} and the
    client_context join: _edit_business_description
  - competitor_domains / competitor_names / competitor_names_supplied /
    competitor_user_variants: _edit_competitors
  - geographic_focus (default "Global"): _edit_geographic_focus
  - category_descriptor: _edit_category_descriptor (must be a real
    operator-meaningful 2-4 token phrase — it drives Digital Visibility
    query generation directly, no LLM fallback in headless mode)
  - brand_variants: _edit_brand_variants
  - namesake_exclusion_terms: _edit_namesake_exclusions
  - custom_queries: _edit_custom_queries
  - samples_per_query / n_samples: _edit_sample_depth ("1=Fast,
    3=Standard, 5=Thorough")

The hard-required minimum per _validate_loaded_inputs_or_reason is just
client_domain (domain-shaped) + at least one competitor_domains entry —
everything else has a sane default — but category_descriptor is treated
as required here too since a blank one is documented as poisoning
downstream generation rather than failing cleanly.

brand_identities is deliberately NOT set here — that's populated by a
separate remote pre-step (see remote_scripts/discover_identities.py)
because it requires a live Claude call against the discovered domains.
"""
from __future__ import annotations

import datetime as dt

from . import sheets

SAMPLE_DEPTH_MAP = {"fast": 1, "standard": 3, "thorough": 5}


class InputsError(Exception):
    """Raised when a sheet row can't be turned into a valid inputs dict."""


def clean_domain(raw: str) -> str:
    d = (raw or "").strip()
    d = d.replace("https://", "").replace("http://", "").replace("www.", "")
    return d.strip("/").split("/")[0].lower()


def split_list(raw: str) -> list[str]:
    if not raw:
        return []
    parts = raw.split("\n") if "\n" in raw else raw.split(",")
    return [p.strip() for p in parts if p.strip()]


def build_inputs(row: list[str], col_map: dict[str, int]) -> dict:
    def g(*names, default=""):
        return sheets.get_cell(row, col_map, *names, default=default)

    client_domain = clean_domain(g("domain"))
    if not client_domain:
        raise InputsError("Domain column is blank")

    client_name = g("client (company name)", "client") or client_domain

    what = g("what they do")
    where = g("where they operate")
    market_position_parts = [g("market position")]
    known_for = g("anything they're particularly known for")
    if known_for:
        market_position_parts.append(known_for)
    market_position = " ".join(p for p in market_position_parts if p).strip()

    business_description = {
        "what": what, "where": where, "market_position": market_position,
    }
    client_context = " ".join(s for s in (what, where, market_position) if s).strip()

    competitor_domains: list[str] = []
    competitor_names: list[str] = []
    for i in range(1, 6):
        d = clean_domain(g(f"competitor {i} url"))
        if not d:
            continue
        competitor_domains.append(d)
        competitor_names.append(g(f"competitor {i} name"))
    if not competitor_domains:
        raise InputsError("No competitor URLs supplied (need at least 1 of 5)")

    geographic_focus = g("geographic focus") or "Global"

    category_descriptor = g("category descriptor")
    if not category_descriptor:
        raise InputsError(
            "Category descriptor column is blank — required, drives "
            "Digital Visibility queries directly")

    depth_label = g("sample depth (fast / standard / thorough)", "sample depth").lower()
    samples_per_query = SAMPLE_DEPTH_MAP.get(depth_label, 1)

    return {
        "client_domain": client_domain,
        "client_name": client_name,
        "client_name_supplied": client_name,
        "business_description": business_description,
        "client_context": client_context,
        "client_contact": "",
        "report_month": dt.date.today().strftime("%B %Y"),
        "competitor_domains": competitor_domains,
        "competitor_names": competitor_names,
        "competitor_names_supplied": competitor_names,
        "competitor_user_variants": {d: [] for d in competitor_domains},
        "geographic_focus": geographic_focus,
        "category_descriptor": category_descriptor,
        "brand_variants": split_list(g("brand variants")),
        "namesake_exclusion_terms": split_list(g("namesake exclusions")),
        "custom_queries": split_list(g("custom queries")),
        "samples_per_query": samples_per_query,
        "n_samples": samples_per_query,
        "pre_deployment_notes": "",
    }
