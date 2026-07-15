#!/usr/bin/env python3
"""Headless brand-identity discovery pre-step for dispatcher-triggered
runs.

geo_client.py's --non-interactive path hard-requires
inputs['brand_identities'] to already be populated — it's normally
filled in by the interactive _show_brand_identity_summary screen, which
--non-interactive skips entirely. Skipping this step is exactly what
makes a headless run abort immediately with
"stage: v2_pipeline, reason: brand_identities not supplied" (confirmed
against both wildswimmingtests.co.uk and charmeadventure.com).

This generalizes the one-off script Alex left on the volume as
/data/gen_identities.py (hardcoded to one client's inputs path) into a
reusable step: it imports geo_client.py as a module — same technique,
no changes to Alex's repo — and calls his own
_collect_or_load_identities(), then writes the result back into the
inputs file in place.

Usage: python3 discover_identities.py <inputs_json_path>
Exits 0 and prints DONE on success. Exits 1 with a traceback on stderr
on failure — the caller (dispatcher.py) treats anything but rc=0 and
"DONE" in stdout as a hard failure and does not proceed to the real
audit run.
"""
import importlib.util
import json
import sys
import traceback

if len(sys.argv) != 2:
    print("Usage: discover_identities.py <inputs_json_path>", file=sys.stderr)
    sys.exit(2)

INPUTS_PATH = sys.argv[1]

spec = importlib.util.spec_from_file_location("geo_client", "/app/geo_client.py")
gc = importlib.util.module_from_spec(spec)
sys.modules["geo_client"] = gc
spec.loader.exec_module(gc)

with open(INPUTS_PATH, "r", encoding="utf-8") as f:
    inputs = json.load(f)

claude = gc.Anthropic(api_key=gc.ANTHROPIC_API_KEY)

print("Discovering brand identities for:", inputs["client_domain"],
      "+", inputs["competitor_domains"], flush=True)

try:
    identities = gc._collect_or_load_identities(inputs, claude, use_fixture=False)
except Exception:
    traceback.print_exc()
    sys.exit(1)

inputs["brand_identities"] = {dom: ident for dom, ident in identities.items()}

with open(INPUTS_PATH, "w", encoding="utf-8") as f:
    json.dump(inputs, f, ensure_ascii=False, indent=2)

print("BRAND_IDENTITIES_KEYS=" + str(sorted(inputs["brand_identities"].keys())), flush=True)
print("DONE", flush=True)
