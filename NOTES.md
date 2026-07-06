# Geo Prospect — Project Notes
_Last updated: 2026-07-06_

---

## System Architecture

```
Crontab (SiteGround, every 30 min)
  → dispatcher.php
    → reads Google Sheet (tab: "Jobs")
    → finds rows where Status col = "RUN"
    → POST /generate-report → Railway API
    → saves PDF to SiteGround /reports/
    → updates sheet: DONE, PDF URL, timestamp
    → emails PDF link to client
```

### Repos
| Repo | Purpose |
|------|---------|
| dominicRelevance/geo-prospect-dispatcher | PHP dispatcher (this code) |
| geo_prospect_api/ (local) | Python FastAPI prototype — live on Railway |
| alexai-lab/geo-prospect | Alex's newer version — PRIVATE, need access |

### Live endpoints
- **Cron path:** `php /home/u65-1fm4wrgjtvly/www/apicaution.relevanceweb.com/dispatcher/dispatcher.php`
- **Railway API:** https://geo-prospect-api-prototype-production.up.railway.app/
- **Google Sheet:** ID `1co2Y-o4RoiMs4Y-ElRH_hatUTJPvNHcWCIChMILYwCA`, tab `Jobs`

---

## Current Status (2026-07-06)

### Railway API: WORKING ✓
`/health` returns `{"status":"ok"}` — API is alive.

### Code fixes applied locally (need deploying to live):

**1. Environment auto-detection** — `config.php` now detects local vs live via `getenv('HOME')`.
Same file works on both. No more wrong paths.
- Local REPORTS_DIR → `__DIR__ . '/reports/'`
- Live REPORTS_DIR → `/home/u65-1fm4wrgjtvly/www/apicaution.relevanceweb.com/reports/`

**2. Column lookup by header name** — `dispatcher.php` no longer uses hardcoded column indexes.
It reads the header row and finds columns by name (`status`, `client`, `data`, `email`, `pdf report`, `date`).
Works regardless of how many extra columns the client adds.

**3. Log file** — every run now appends to `dispatcher.log` (same directory as the script on live).
Live log: `/home/u65-1fm4wrgjtvly/www/apicaution.relevanceweb.com/dispatcher/dispatcher.log`

**4. SMTP_FROM constants** — now defined in config.php. Email won't crash PHP.

---

## TODO List

- [ ] **Deploy to live** — copy updated `config.php` and `dispatcher.php` to SiteGround
  - `scp config.php dispatcher.php user@apicaution.relevanceweb.com:~/www/apicaution.relevanceweb.com/dispatcher/`
  - Or use SiteGround File Manager
- [ ] **Verify sheet header names** — open the Google Sheet and confirm the headers in row 1 match exactly: `Client`, `Data`, `Status`, `PDF Report`, `Date`, `Email` (case-insensitive)
  - If headers are different, update the `colIndex($colMap, ...)` calls in the dispatcher main loop
- [ ] **Test on live** — set a row to RUN, wait for cron (or run manually via SSH), check the log file
- [ ] **Get access to alexai-lab/geo-prospect repo** — Alex to add you as collaborator
- [ ] **Clone and run Alex's version locally** (see plan below)
- [ ] **Deploy Alex's version to Railway** and update `GEO_PROSPECT_API_URL` in config.php

---

## Plan: Testing Alex's System

1. **Get access** — ask Alex to add you as collaborator on alexai-lab/geo-prospect
2. **Clone and run locally:**
   ```bash
   git clone https://github.com/alexai-lab/geo-prospect
   cd geo-prospect
   pip install -r requirements.txt
   cp .env.example .env   # fill in keys
   uvicorn main:app --reload
   ```
3. **Check API contract** — Alex's API must accept:
   - POST `/generate-report` (or similar)
   - Body: `{"client": "...", "data": "..."}`
   - Response: `{"pdf_base64": "..."}`
   - If different, update dispatcher.php `callGeoProspect()`
4. **Test end-to-end locally** — point `GEO_PROSPECT_API_URL` at `http://localhost:8000/generate-report`, set a sheet row to RUN, run dispatcher manually
5. **Deploy to Railway** — Railway CLI: `railway up` from Alex's repo dir (or use Railway dashboard)
6. **Update live config.php** with new Railway URL

---

## Useful Commands

```bash
# Run dispatcher manually (local)
php /Applications/XAMPP/xamppfiles/htdocs/rel_geo_tool/dispatcher.php

# Run dispatcher manually (live via SSH)
php /home/u65-1fm4wrgjtvly/www/apicaution.relevanceweb.com/dispatcher/dispatcher.php

# Check Railway API
curl https://geo-prospect-api-prototype-production.up.railway.app/health

# Read current sheet data (local debug tool)
php /Applications/XAMPP/xamppfiles/htdocs/rel_geo_tool/read_sheet.php
```
