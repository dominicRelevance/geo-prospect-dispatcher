# Geo Prospect Dispatcher — Project Notes
_Last updated: 2026-07-15_

---

## System Architecture (current — Python rewrite)

```
Railway Cron Job service (this repo, dispatcher.py)
  → reads Google Sheet (tab: "Jobs")
  → picks the highest-"Importance of audit" row where Run Status = RUN
  → flips that row to Run Status = PROCESSING / Status = RUNNING (concurrency guard)
  → builds inputs dict from the row (lib/inputs_builder.py)
  → `railway ssh` into Alex's geo-prospect worker (same Railway project, different service):
      1. uploads inputs JSON to /data
      2. uploads + runs remote_scripts/discover_identities.py (brand-identity
         pre-step — required, geo_client.py's --non-interactive path does
         NOT populate this itself)
      3. runs `python3 geo_client.py --inputs-file <path> --non-interactive`
         (blocking, 30-40 min)
  → reads back the *_audit_status.json sidecar geo_client.py always writes
  → on success: downloads the PDF (base64 over railway ssh — no web
    server to fetch it from), emails it via Mailjet, writes DONE + PDF
    path + per-platform status back to the sheet
  → on failure: writes ERROR + the sidecar's review_reason back to the sheet
```

This replaced an earlier PHP prototype (`dispatcher.php`, cron on
SiteGround) that assumed a synchronous HTTP POST to a Railway API
returning a PDF within 120s. That doesn't match reality: audits take
30-40 min, and Alex's actual deployed worker has **no web server at
all** — it's an idle container invoked via `railway ssh`, not HTTP.
See the two load-bearing findings in `lib/railway_exec.py`'s docstring
(`railway run` executes locally, not remotely; `railway ssh --` does
not preserve argv word boundaries — both confirmed empirically
2026-07-15) before touching that file.

### Repos
| Repo | Purpose |
|------|---------|
| dominicRelevance/geo-prospect-dispatcher | This repo — Python dispatcher |
| alexai-relevance/geo-prospect | Alex's geo-prospect worker (audit tool) — do not modify from here |

### Key facts
- **Google Sheet:** ID `1co2Y-o4RoiMs4Y-ElRH_hatUTJPvNHcWCIChMILYwCA`, tab `Jobs`. Columns matched case-insensitively by header name (see `lib/sheets.py` / `lib/inputs_builder.py` for the full mapping).
- **Railway project:** `optimistic-rebirth` (workspace `alexai-relevance's Projects`), environment `production`, service `geo-prospect` — this dispatcher targets that service via `railway ssh -s geo-prospect`.
- **Sheets auth:** service account `geo-dispatcher@aigeoprospect.iam.gserviceaccount.com`, key at `credentials/service-account.json` (gitignored). Must have Editor access on the Jobs sheet.
- **Email:** Mailjet SMTP relay (`in-v3.mailjet.com:587`), report PDF sent as an attachment (no PDF hosting/serving component exists now that the dispatcher isn't on SiteGround).

---

## Field mapping (sheet column → geo_client.py inputs key)

Verified against `geo_client.py`'s own interactive input-collection code
(Build 3.23.3), not guessed — see `lib/inputs_builder.py` docstring for
the exact source functions.

| Sheet column | inputs key |
|---|---|
| Client (Company Name) | `client_name` / `client_name_supplied` |
| Domain | `client_domain` |
| WHAT THEY DO | `business_description.what` |
| WHERE THEY OPERATE | `business_description.where` |
| MARKET POSITION + "Anything they're particularly known for" | `business_description.market_position` |
| (all three above, joined) | `client_context` |
| Competitor 1-5 URL/Name | `competitor_domains`, `competitor_names`, `competitor_names_supplied` |
| Geographic focus | `geographic_focus` (default "Global") |
| Category descriptor | `category_descriptor` (required — no LLM fallback in headless mode) |
| Brand variants | `brand_variants` |
| Custom queries | `custom_queries` |
| Sample depth (Fast/Standard/Thorough) | `samples_per_query` / `n_samples` (1/3/5) |
| Namesake exclusions | `namesake_exclusion_terms` |
| Importance of audit | not sent to geo_client.py — used by the dispatcher to pick which RUN row to process next |
| Exhibiting at | not sent to geo_client.py — dispatcher-only context |
| Run Status | dispatcher trigger/lock: operator sets `RUN`; dispatcher flips to `PROCESSING` on pickup, `DONE`/`ERROR` on completion |
| Status | dispatcher-written human-readable progress |
| PDF_report, Date | dispatcher-written output |
| EmailReport | recipient address for the finished PDF |
| Perplexity/ChatGPT/Gemini/Claude status | dispatcher-written from the sidecar's `platforms.{perplexity,chatgpt,google,claude}.status` (note: sidecar key is `google`, sheet column is "Gemini status") |
| Data | legacy/unused, ignored |

`brand_identities` is **not** a sheet column — it's populated by
`remote_scripts/discover_identities.py` as a required pre-step before
every audit run (see architecture above).

---

## TODO / open items

- [ ] Dedicated SSH key + `RAILWAY_TOKEN` so this dispatcher can run
      `railway ssh` non-interactively once it's itself deployed as a
      Railway Cron Job (currently only tested using Dominic's own
      logged-in `railway` CLI session on his Mac).
- [ ] `railway.json` cron schedule + container setup (needs the
      `railway` CLI binary available inside the dispatcher's own
      container — not currently a pip package).
- [ ] End-to-end test against a real sheet row.
- [ ] Concurrency: two overlapping cron ticks can pick two different RUN
      rows and run them in parallel — fine for now, revisit if API
      cost/rate-limits make that unacceptable.
- [ ] Stale-lock recovery: if the dispatcher process dies mid-job after
      clearing Run Status but before writing DONE/ERROR, that row is
      stuck. Not handled yet.

---

## Useful commands

```bash
# Run the dispatcher manually (local, needs .env or exported vars +
# `railway login` / `railway link` done once in this shell)
cd /Applications/XAMPP/xamppfiles/htdocs/geo-prospect-dispatcher
python3 dispatcher.py

# Check the live Railway service status
railway status  # (run from geo-prospect-alex/, which is linked to the project)

# Read the Jobs sheet directly (sanity check credentials/sharing)
python3 -c "
from lib.sheets import get_sheets_service, get_rows
rows = get_rows(get_sheets_service(), '1co2Y-o4RoiMs4Y-ElRH_hatUTJPvNHcWCIChMILYwCA', 'Jobs')
print(len(rows), 'rows')
"
```
