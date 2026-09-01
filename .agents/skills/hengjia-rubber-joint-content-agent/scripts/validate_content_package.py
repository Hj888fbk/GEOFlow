#!/usr/bin/env python3
"""Validate one local hengjia-content-package/v1 without network or publication."""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path

from contract import validate_content_package


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate a candidate Hengjia content package; never publish it.")
    parser.add_argument("package", type=Path, help="Local JSON file to read.")
    parser.add_argument("--as-of", default=date.today().isoformat(), help="Validation date in YYYY-MM-DD form.")
    parser.add_argument("--compact", action="store_true", help="Emit compact JSON.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        as_of = date.fromisoformat(args.as_of)
        payload = json.loads(args.package.read_text(encoding="utf-8"))
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(json.dumps({"error": "input_read_failed", "message": str(exc)}, ensure_ascii=False), file=sys.stderr)
        return 1
    report = validate_content_package(payload, as_of)
    print(json.dumps(report, ensure_ascii=False, indent=None if args.compact else 2, sort_keys=True))
    return 0 if report["valid"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
