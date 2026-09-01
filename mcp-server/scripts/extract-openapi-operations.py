#!/usr/bin/env python3
"""Extract every operation from the modular OPBX OpenAPI spec into JSON.

Usage (from the REPO ROOT, not mcp-server/):
    python3 mcp-server/scripts/extract-openapi-operations.py [out.json]

Used when auditing docs/opbx-api-inventory.md after OPBX API changes.
"""
import json
import pathlib
import sys

import yaml

ROOT = pathlib.Path("docs/opbx-openapi/paths")
METHODS = {"get", "post", "put", "patch", "delete", "head", "options"}

operations = []
errors = []

for f in sorted(ROOT.rglob("*.yaml")):
    try:
        doc = yaml.safe_load(f.read_text())
    except Exception as e:
        errors.append(f"{f}: YAML parse error: {e}")
        continue
    if not isinstance(doc, dict) or "paths" not in doc:
        continue
    for path, item in (doc["paths"] or {}).items():
        if not isinstance(item, dict):
            continue
        for method, op in item.items():
            if method.lower() not in METHODS or not isinstance(op, dict):
                continue
            operations.append(
                {
                    "method": method.upper(),
                    "path": path,
                    "operationId": op.get("operationId"),
                    "tags": op.get("tags") or [],
                    "summary": op.get("summary") or "",
                    "security": [list(s.keys()) for s in (op.get("security") or [])],
                    "file": str(f),
                    "deprecated": bool(op.get("deprecated", False)),
                }
            )

out = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else None
text = json.dumps(operations, indent=2)
if out:
    out.write_text(text)
    print(f"{len(operations)} operations -> {out}")
else:
    print(text)
missing_id = [o for o in operations if not o["operationId"]]
if missing_id:
    print(f"WARNING: {len(missing_id)} operations missing operationId:", file=sys.stderr)
    for o in missing_id:
        print(f"  {o['method']} {o['path']} ({o['file']})", file=sys.stderr)
for e in errors:
    print(e, file=sys.stderr)
