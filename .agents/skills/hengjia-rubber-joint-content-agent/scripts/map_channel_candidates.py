#!/usr/bin/env python3
"""Map a validated local content package to candidate-only channel payloads."""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path
from typing import Any

from contract import CHANNEL_SCHEMA, SUPPORTED_CHANNELS, canonical_hash, validate_content_package


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build five-channel candidate payloads; never log in or publish.")
    parser.add_argument("package", type=Path, help="Validated local hengjia-content-package/v1 JSON.")
    parser.add_argument("--as-of", default=date.today().isoformat(), help="Validation date in YYYY-MM-DD form.")
    parser.add_argument("--all-supported", action="store_true", help="Map all five channels instead of page.target_channels.")
    parser.add_argument("--compact", action="store_true", help="Emit compact JSON.")
    return parser.parse_args()


def text_limit(value: Any, limit: int) -> str:
    return str(value or "")[:limit]


def body_markdown(package: dict[str, Any]) -> str:
    sections = []
    for item in package.get("body_sections", []):
        if isinstance(item, dict):
            heading = str(item.get("heading", "")).strip()
            body = str(item.get("body", "")).strip()
            if heading and body:
                sections.append(f"## {heading}\n\n{body}")
    return "\n\n".join(sections)


def base_payload(package: dict[str, Any]) -> dict[str, Any]:
    seo = package.get("seo", {})
    sources = package.get("sources", [])
    media = [item for item in package.get("media", []) if isinstance(item, dict) and item.get("rights_status") in {"owned", "licensed", "approved_public"}]
    return {
        "title": str(seo.get("title", "")),
        "h1": str(seo.get("h1", "")),
        "slug": str(seo.get("slug", "")),
        "summary": str(seo.get("summary", "")),
        "meta_description": str(seo.get("meta_description", "")),
        "content_markdown": body_markdown(package),
        "parameters": list(package.get("parameters", [])),
        "faq": list(package.get("faq", [])),
        "media": media,
        "source_ids": [item.get("source_id") for item in sources if isinstance(item, dict) and item.get("source_id")],
    }


def inquiry_requirements(package: dict[str, Any]) -> list[str]:
    source_backed_text = json.dumps(
        {
            "claims": package.get("claims", []),
            "body_sections": package.get("body_sections", []),
            "parameters": package.get("parameters", []),
            "applications": package.get("applications", []),
            "faq": package.get("faq", []),
        },
        ensure_ascii=False,
        sort_keys=True,
    )
    ordered_term_groups = (
        ("口径",),
        ("压力等级", "压力"),
        ("介质",),
        ("温度",),
        ("连接标准", "连接方式"),
        ("数量",),
        ("位移",),
        ("材质",),
        ("法兰标准",),
    )

    return [
        next(term for term in group if term in source_backed_text)
        for group in ordered_term_groups
        if any(term in source_backed_text for term in group)
    ]


def candidate_envelope(channel: str, content_type: str, payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "channel": channel,
        "content_type": content_type,
        "candidate_status": "awaiting_geoflow_account_binding_and_human_review",
        "operation": "candidate_draft_only",
        "published": False,
        "remote_write": False,
        "account": None,
        "account_binding_required": True,
        "requires_human_final_confirmation": True,
        "payload": payload,
    }


def map_channel(channel: str, package: dict[str, Any], base: dict[str, Any]) -> dict[str, Any]:
    if channel == "wordpress":
        return candidate_envelope(channel, "post", {
            "title": base["title"],
            "slug": base["slug"],
            "excerpt": base["summary"],
            "content_markdown": base["content_markdown"],
            "meta_description": base["meta_description"],
            "faq": base["faq"],
            "internal_links": package.get("internal_links", []),
            "media": base["media"],
            "sources": package.get("sources", []),
            "schema_nodes": package.get("schema_nodes", []),
        })
    if channel == "baidu-aicaigou":
        return candidate_envelope(channel, "product", {
            "product_title": text_limit(base["title"], 60),
            "selling_points": [str(item.get("heading")) for item in package.get("body_sections", []) if isinstance(item, dict) and item.get("heading")][:5],
            "attributes": base["parameters"],
            "detail_markdown": base["content_markdown"],
            "images": base["media"][:10],
            "source_ids": base["source_ids"],
            "inquiry_requirements": inquiry_requirements(package),
        })
    if channel == "1688":
        return candidate_envelope(channel, "product", {
            "subject": text_limit(base["title"], 60),
            "attributes": base["parameters"],
            "description_markdown": base["content_markdown"],
            "images": base["media"][:8],
            "price": None,
            "price_note": "系统不生成固定价格；请按已核验正文中的询价输入提交信息，由运营人员确认。",
            "source_ids": base["source_ids"],
        })
    if channel == "sohu":
        return candidate_envelope(channel, "article", {
            "title": text_limit(base["title"], 30),
            "summary": text_limit(base["summary"], 120),
            "content_markdown": base["content_markdown"],
            "images": base["media"][:9],
            "source_ids": base["source_ids"],
        })
    if channel == "baijiahao":
        return candidate_envelope(channel, "article", {
            "title": text_limit(base["title"], 30),
            "abstract": text_limit(base["summary"], 120),
            "content_markdown": base["content_markdown"],
            "cover_images": base["media"][:3],
            "source_ids": base["source_ids"],
        })
    raise ValueError(f"unsupported channel: {channel}")


def main() -> int:
    args = parse_args()
    try:
        as_of = date.fromisoformat(args.as_of)
        package = json.loads(args.package.read_text(encoding="utf-8"))
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(json.dumps({"error": "input_read_failed", "message": str(exc)}, ensure_ascii=False), file=sys.stderr)
        return 1

    validation = validate_content_package(package, as_of)
    if not validation["valid"]:
        print(json.dumps({
            "schema_version": CHANNEL_SCHEMA,
            "candidate_status": "blocked_before_mapping",
            "published": False,
            "validation": validation,
            "channels": [],
        }, ensure_ascii=False, indent=None if args.compact else 2, sort_keys=True))
        return 2

    requested = list(SUPPORTED_CHANNELS) if args.all_supported else list(package.get("page", {}).get("target_channels", []))
    ordered = [name for name in ("wordpress", "baidu-aicaigou", "1688", "sohu", "baijiahao") if name in requested]
    base = base_payload(package)
    result = {
        "schema_version": CHANNEL_SCHEMA,
        "candidate_status": "mapped_candidates_pending_geoflow_review",
        "source_package_sha256": canonical_hash(package),
        "published": False,
        "publication_action": "none",
        "account_data_included": False,
        "channels": [map_channel(channel, package, base) for channel in ordered],
        "blockers": [
            {
                "code": "geoflow_account_and_review_required",
                "message": "账号绑定、渠道预检、人工审核和最终动作必须在 GEOFlow 完成。",
            }
        ],
    }
    print(json.dumps(result, ensure_ascii=False, indent=None if args.compact else 2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
