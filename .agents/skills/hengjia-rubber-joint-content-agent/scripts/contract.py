#!/usr/bin/env python3
"""Deterministic contract checks shared by the candidate-only CLIs."""

from __future__ import annotations

import hashlib
import json
import re
from datetime import date
from typing import Any, Iterable


SCRIPT_INTERFACE = "internal-module"
SCRIPT_INTERFACE_REASON = "Imported by validate_content_package.py and map_channel_candidates.py."

PACKAGE_SCHEMA = "hengjia-content-package/v1"
VALIDATION_SCHEMA = "hengjia-content-validation/v1"
CHANNEL_SCHEMA = "hengjia-channel-candidates/v1"

EVIDENCE_STATUSES = {
    "public_record_verified",
    "internal_confirmed_public",
    "competitor_self_claim",
    "third_party_claim",
    "ai_observation",
    "conflict",
    "not_found",
}
STRONG_EVIDENCE = {"public_record_verified", "internal_confirmed_public"}
PUBLIC_PERMISSIONS = {"internal_only", "publishable", "restricted", "prohibited"}
SUPPORTED_CHANNELS = {"wordpress", "baidu-aicaigou", "1688", "sohu", "baijiahao"}
PUBLISHABLE_MEDIA_RIGHTS = {"owned", "licensed", "approved_public"}
SUITABILITY = {"suitable", "conditional", "not_suitable"}

REQUIRED_TOP_LEVEL = (
    "schema_version",
    "page",
    "seo",
    "claims",
    "body_sections",
    "parameters",
    "applications",
    "faq",
    "sources",
    "internal_links",
    "media",
    "schema_nodes",
    "prohibited_claims",
    "blockers",
    "prompt_meta",
)
QUALIFICATION_FIELDS = (
    "qualification_name",
    "certificate_number",
    "issuer",
    "certification_scope",
    "applicable_products",
    "issued_at",
    "valid_until",
    "official_lookup_url",
    "file_sha256",
    "public_permission",
    "reviewer",
)
SENSITIVE_KEYS = {
    "cookie",
    "cookies",
    "session",
    "sessionid",
    "session_id",
    "token",
    "access_token",
    "refresh_token",
    "api_key",
    "apikey",
    "password",
    "passwd",
    "secret",
    "authorization",
    "credential",
    "credentials",
}
SECRET_VALUE_PATTERNS = (
    re.compile(r"\bBearer\s+[A-Za-z0-9._~+/-]{12,}", re.IGNORECASE),
    re.compile(r"\b(?:cookie|session(?:id)?|token|password|api[_-]?key)\s*[=:]\s*\S{8,}", re.IGNORECASE),
)
INJECTION_PATTERNS = (
    re.compile(r"ignore\s+(?:all\s+)?previous\s+instructions", re.IGNORECASE),
    re.compile(r"忽略(?:以上|此前|之前|所有)?(?:规则|指令|要求)"),
    re.compile(r"(?:调用|执行).{0,12}(?:工具|命令).{0,12}(?:发布|登录|绕过)"),
    re.compile(r"(?:绕过|跳过).{0,8}(?:验证码|登录|审核)"),
    re.compile(r"(?:立即|现在|直接|自动).{0,12}(?:登录|提交|发布).{0,24}(?:发布|提交|上线)?"),
    re.compile(r"(?:登录|进入).{0,16}(?:并|后|然后).{0,8}(?:发布|提交|上线)"),
    re.compile(r"(?:遇到|出现|碰到)?.{0,6}验证码.{0,10}(?:继续|照常|不要停止|仍然发布)"),
    re.compile(r"(?:log\s*in|login).{0,24}(?:publish|submit)", re.IGNORECASE),
    re.compile(r"captcha.{0,20}(?:continue|proceed|publish)", re.IGNORECASE),
)
HIGH_RISK_PATTERNS = (
    ("absolute_rank", re.compile(r"(?:全国|行业|市场)?(?:第一|唯一|最大|最佳|顶级|领先)")),
    ("guarantee", re.compile(r"(?:100%|百分之百|永久|终身|绝对|保证)")),
    ("unverified_commercial", re.compile(r"(?:现货充足|当天发货|固定价格|最低价|市场占有率)")),
)
QUALIFICATION_ASSERTION_PATTERN = re.compile(
    r"(?:恒佳|本公司|我公司|我们).{0,24}(?:通过|拥有|具备|持有|获得).{0,32}(?:ISO\s*\d+|认证|资质|证书|专利|荣誉)",
    re.IGNORECASE,
)
PRECISE_FACT_ASSERTION_PATTERN = re.compile(
    r"(?:恒佳|本公司|我公司|我们|本产品|产品|橡胶软接头).{0,40}(?:耐压|压力|温度|口径|寿命|产能|库存|交期).{0,24}\d",
    re.IGNORECASE,
)
ISO_TOKEN_PATTERN = re.compile(r"ISO\s*\d+", re.IGNORECASE)
PRECISE_TOKEN_PATTERN = re.compile(
    r"(?:DN|PN)?\s*\d+(?:\.\d+)?\s*(?:MPA|BAR|KPA|MM|CM|M|℃|°C|年|天|小时|吨|台|件)?",
    re.IGNORECASE,
)


def issue(code: str, path: str, message: str) -> dict[str, str]:
    return {"code": code, "path": path, "message": message}


def blank(value: Any) -> bool:
    if value is None:
        return True
    if isinstance(value, str):
        return not value.strip()
    if isinstance(value, (list, dict, tuple, set)):
        return len(value) == 0
    return False


def canonical_hash(payload: dict[str, Any]) -> str:
    encoded = json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def unique_issues(items: Iterable[dict[str, str]]) -> list[dict[str, str]]:
    output: list[dict[str, str]] = []
    seen: set[tuple[str, str, str]] = set()
    for item in items:
        key = (item.get("code", ""), item.get("path", ""), item.get("message", ""))
        if key not in seen:
            seen.add(key)
            output.append(item)
    return output


def iter_values(value: Any, path: str = "") -> Iterable[tuple[str, Any]]:
    if isinstance(value, dict):
        for key, child in value.items():
            child_path = f"{path}.{key}" if path else str(key)
            yield child_path, child
            yield from iter_values(child, child_path)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            child_path = f"{path}.{index}" if path else str(index)
            yield child_path, child
            yield from iter_values(child, child_path)


def parse_date(value: Any) -> date | None:
    if not isinstance(value, str) or not value.strip():
        return None
    try:
        return date.fromisoformat(value.strip()[:10])
    except ValueError:
        return None


def require_fields(
    obj: Any,
    fields: Iterable[str],
    path: str,
    errors: list[dict[str, str]],
) -> dict[str, Any]:
    if not isinstance(obj, dict):
        errors.append(issue("invalid_object", path, "字段必须是对象。"))
        return {}
    for field in fields:
        if field not in obj or blank(obj.get(field)):
            errors.append(issue("missing_field", f"{path}.{field}", "字段不能为空。"))
    return obj


def validate_qualification(
    claim: dict[str, Any],
    path: str,
    as_of: date,
    blockers: list[dict[str, str]],
) -> None:
    qualification = claim.get("qualification")
    if not isinstance(qualification, dict):
        qualification = {}
    for field in QUALIFICATION_FIELDS:
        if blank(qualification.get(field)):
            blockers.append(issue("incomplete_qualification", f"{path}.qualification.{field}", "资质字段不完整，禁止宣传。"))
    sha = str(qualification.get("file_sha256", ""))
    if sha and re.fullmatch(r"[a-f0-9]{64}", sha) is None:
        blockers.append(issue("invalid_qualification_hash", f"{path}.qualification.file_sha256", "资质文件哈希必须是 64 位小写十六进制。"))
    valid_until = parse_date(qualification.get("valid_until"))
    if qualification.get("valid_until") and valid_until is None:
        blockers.append(issue("invalid_qualification_date", f"{path}.qualification.valid_until", "资质有效期必须使用 YYYY-MM-DD。"))
    elif valid_until is not None and valid_until < as_of:
        blockers.append(issue("expired_qualification", f"{path}.qualification.valid_until", "资质在生成日已过期，禁止宣传。"))
    if qualification.get("public_permission") != "publishable":
        blockers.append(issue("qualification_not_publishable", f"{path}.qualification.public_permission", "资质未获公开许可。"))


def scan_sensitive_and_injected(payload: dict[str, Any], blockers: list[dict[str, str]]) -> None:
    for path, value in iter_values(payload):
        if path.startswith("prohibited_claims."):
            continue
        key = path.rsplit(".", 1)[-1].lower().replace("-", "_")
        if key in SENSITIVE_KEYS:
            blockers.append(issue("sensitive_credential_field", path, "内容包含敏感凭据字段；已阻断且不得回显。"))
            continue
        if isinstance(value, str):
            if any(pattern.search(value) for pattern in SECRET_VALUE_PATTERNS):
                blockers.append(issue("sensitive_credential_value", path, "内容包疑似包含敏感凭据；已阻断且不得回显。"))
            if any(pattern.search(value) for pattern in INJECTION_PATTERNS):
                blockers.append(issue("untrusted_instruction", path, "输入包含越权或提示注入文字；不得执行。"))


def scan_high_risk_language(payload: dict[str, Any], blockers: list[dict[str, str]]) -> None:
    for root_key in ("seo", "claims", "body_sections", "parameters", "applications", "faq", "schema_nodes"):
        for path, value in iter_values(payload.get(root_key, {}), root_key):
            if not isinstance(value, str):
                continue
            for code, pattern in HIGH_RISK_PATTERNS:
                if pattern.search(value):
                    blockers.append(issue(f"high_risk_language:{code}", path, "发现需要人工证据和广告法复核的高风险表达。"))


def normalized_tokens(pattern: re.Pattern[str], value: str) -> set[str]:
    return {re.sub(r"\s+", "", match.group(0)).lower() for match in pattern.finditer(value)}


def scan_unstructured_factual_assertions(
    payload: dict[str, Any],
    claims: list[Any],
    parameters: list[Any],
    blockers: list[dict[str, str]],
) -> None:
    qualification_claims = [
        claim for claim in claims
        if isinstance(claim, dict) and claim.get("claim_type") == "qualification"
    ]
    qualification_text = json.dumps(qualification_claims, ensure_ascii=False, sort_keys=True)
    qualification_tokens = normalized_tokens(ISO_TOKEN_PATTERN, qualification_text)
    structured_text = json.dumps(
        {"claims": claims, "parameters": parameters},
        ensure_ascii=False,
        sort_keys=True,
    )
    structured_precise_tokens = normalized_tokens(PRECISE_TOKEN_PATTERN, structured_text)

    for root_key in ("seo", "body_sections", "applications", "faq", "schema_nodes"):
        for path, value in iter_values(payload.get(root_key, {}), root_key):
            if not isinstance(value, str):
                continue
            if QUALIFICATION_ASSERTION_PATTERN.search(value):
                visible_tokens = normalized_tokens(ISO_TOKEN_PATTERN, value)
                if not qualification_claims or not visible_tokens.issubset(qualification_tokens):
                    blockers.append(issue(
                        "unstructured_qualification_assertion",
                        path,
                        "企业资质表达未在完整、可追溯的 qualification 主张中得到同项支持。",
                    ))
            if PRECISE_FACT_ASSERTION_PATTERN.search(value):
                visible_tokens = normalized_tokens(PRECISE_TOKEN_PATTERN, value)
                if not visible_tokens or not visible_tokens.issubset(structured_precise_tokens):
                    blockers.append(issue(
                        "unstructured_precise_fact",
                        path,
                        "正文或 SEO 中的精确企业/产品事实未在结构化主张或参数中得到同值支持。",
                    ))


def validate_content_package(payload: Any, as_of: date) -> dict[str, Any]:
    errors: list[dict[str, str]] = []
    warnings: list[dict[str, str]] = []
    blockers: list[dict[str, str]] = []
    if not isinstance(payload, dict):
        errors.append(issue("invalid_root", "$", "内容包根节点必须是对象。"))
        payload = {}

    for field in REQUIRED_TOP_LEVEL:
        if field not in payload:
            errors.append(issue("missing_top_level", field, "缺少内容包顶层字段。"))
    if payload.get("schema_version") != PACKAGE_SCHEMA:
        errors.append(issue("invalid_schema_version", "schema_version", f"内容包版本必须为 {PACKAGE_SCHEMA}。"))

    page = require_fields(payload.get("page"), ("role", "audience", "intent", "target_channels"), "page", errors)
    target_channels = page.get("target_channels", [])
    if not isinstance(target_channels, list):
        errors.append(issue("invalid_target_channels", "page.target_channels", "目标渠道必须是数组。"))
    else:
        for index, channel in enumerate(target_channels):
            if channel not in SUPPORTED_CHANNELS:
                errors.append(issue("unsupported_channel", f"page.target_channels.{index}", "目标渠道不在首期五渠道中。"))

    seo = require_fields(payload.get("seo"), ("title", "h1", "slug", "primary_keyword", "summary", "meta_description"), "seo", errors)
    slug = str(seo.get("slug", ""))
    if slug and re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", slug) is None:
        errors.append(issue("invalid_slug", "seo.slug", "Slug 只能使用小写字母、数字和连字符。"))
    if not isinstance(seo.get("secondary_keywords", []), list):
        errors.append(issue("invalid_secondary_keywords", "seo.secondary_keywords", "次关键词必须是数组。"))

    claims = payload.get("claims", [])
    if not isinstance(claims, list):
        errors.append(issue("invalid_claims", "claims", "主张必须是数组。"))
        claims = []
    if not claims:
        warnings.append(issue("no_claims", "claims", "没有可发布主张，只能作为待补证据草稿。"))
        blockers.append(issue("missing_traceable_claims", "claims", "公开候选必须至少包含一条可追溯主张。"))
    for index, raw_claim in enumerate(claims):
        path = f"claims.{index}"
        claim = require_fields(raw_claim, ("claim_id", "claim_type", "text", "evidence_status", "public_permission", "scope", "source_ids"), path, errors)
        status = claim.get("evidence_status")
        permission = claim.get("public_permission")
        if status not in EVIDENCE_STATUSES:
            errors.append(issue("invalid_evidence_status", f"{path}.evidence_status", "证据状态不受支持。"))
        if permission not in PUBLIC_PERMISSIONS:
            errors.append(issue("invalid_public_permission", f"{path}.public_permission", "公开权限不受支持。"))
        if permission == "publishable" and status not in STRONG_EVIDENCE:
            blockers.append(issue("unsupported_public_claim", path, "同行、第三方、AI、冲突或未找到信息不得作为恒佳公开事实。"))
        if status in STRONG_EVIDENCE and permission == "publishable" and not claim.get("source_ids"):
            blockers.append(issue("missing_claim_source", f"{path}.source_ids", "可发布事实必须绑定来源 ID。"))
        if claim.get("claim_type") == "qualification":
            validate_qualification(claim, path, as_of, blockers)

    parameters = payload.get("parameters", [])
    if not isinstance(parameters, list):
        errors.append(issue("invalid_parameters", "parameters", "参数必须是数组。"))
        parameters = []
    for index, raw_row in enumerate(parameters):
        path = f"parameters.{index}"
        row = require_fields(raw_row, ("name", "value", "unit", "applicability", "evidence_status", "source_ids"), path, errors)
        status = row.get("evidence_status")
        if status not in EVIDENCE_STATUSES:
            errors.append(issue("invalid_evidence_status", f"{path}.evidence_status", "证据状态不受支持。"))
        value = str(row.get("value", ""))
        precise = bool(re.search(r"\d", value)) and not any(marker in value for marker in ("待确认", "按图纸", "询价"))
        if precise and status not in STRONG_EVIDENCE:
            blockers.append(issue("unsupported_parameter", path, "精确参数缺少恒佳同型号已核验证据。"))
        if precise and not row.get("source_ids"):
            blockers.append(issue("missing_parameter_source", f"{path}.source_ids", "精确参数必须绑定来源 ID。"))

    collection_contracts = {
        "body_sections": ("heading", "body", "applicability", "source_ids"),
        "applications": ("scenario", "suitability", "conditions", "source_ids"),
        "faq": ("question", "answer", "applicability", "source_ids"),
        "sources": ("source_id", "title", "evidence_status", "public_permission"),
        "internal_links": ("anchor", "target_role", "target_url"),
        "media": ("source_id", "usage", "alt", "rights_status"),
        "schema_nodes": ("type", "payload_json", "source_ids"),
    }
    for name, fields in collection_contracts.items():
        rows = payload.get(name, [])
        if not isinstance(rows, list):
            errors.append(issue(f"invalid_{name}", name, "字段必须是数组。"))
            continue
        for index, row in enumerate(rows):
            require_fields(row, fields, f"{name}.{index}", errors)

    for index, application in enumerate(payload.get("applications", []) if isinstance(payload.get("applications"), list) else []):
        if isinstance(application, dict) and application.get("suitability") not in SUITABILITY:
            errors.append(issue("invalid_suitability", f"applications.{index}.suitability", "适用性枚举无效。"))
    for index, media in enumerate(payload.get("media", []) if isinstance(payload.get("media"), list) else []):
        if isinstance(media, dict) and media.get("rights_status") not in PUBLISHABLE_MEDIA_RIGHTS:
            blockers.append(issue("media_rights_not_publishable", f"media.{index}.rights_status", "图片权利未获公开使用许可。"))
    for index, node in enumerate(payload.get("schema_nodes", []) if isinstance(payload.get("schema_nodes"), list) else []):
        if not isinstance(node, dict):
            continue
        if node.get("type") in {"Offer", "AggregateRating"}:
            blockers.append(issue("prohibited_schema_type", f"schema_nodes.{index}.type", "候选内容禁止生成 Offer 或 AggregateRating。"))
        raw_json = node.get("payload_json")
        if isinstance(raw_json, str) and raw_json.strip():
            try:
                json.loads(raw_json)
            except json.JSONDecodeError:
                errors.append(issue("invalid_schema_payload", f"schema_nodes.{index}.payload_json", "Schema payload_json 不是合法 JSON。"))

    sources = payload.get("sources", []) if isinstance(payload.get("sources"), list) else []
    source_index = {
        str(item.get("source_id")): item
        for item in sources
        if isinstance(item, dict) and item.get("source_id")
    }
    known_source_ids = set(source_index)
    if len(known_source_ids) != len([item for item in sources if isinstance(item, dict) and item.get("source_id")]):
        errors.append(issue("duplicate_source_id", "sources", "来源 ID 必须唯一。"))
    for path, value in iter_values(payload):
        if path.endswith("source_ids") and isinstance(value, list):
            for source_id in value:
                source_key = str(source_id)
                if source_key not in known_source_ids:
                    blockers.append(issue("unknown_source_id", path, f"引用了未登记来源 ID：{source_id}"))
                    continue
                source = source_index[source_key]
                if source.get("evidence_status") not in STRONG_EVIDENCE or source.get("public_permission") != "publishable":
                    blockers.append(issue(
                        "source_not_publishable_for_public_content",
                        path,
                        f"公开内容引用的来源 {source_key} 不是强证据且未获公开许可。",
                    ))
    for index, media in enumerate(payload.get("media", []) if isinstance(payload.get("media"), list) else []):
        if not isinstance(media, dict):
            continue
        source_key = str(media.get("source_id", ""))
        if source_key not in known_source_ids:
            blockers.append(issue("unknown_source_id", f"media.{index}.source_id", f"引用了未登记来源 ID：{source_key}"))
        elif source_index[source_key].get("evidence_status") not in STRONG_EVIDENCE or source_index[source_key].get("public_permission") != "publishable":
            blockers.append(issue(
                "source_not_publishable_for_public_content",
                f"media.{index}.source_id",
                f"公开媒体引用的来源 {source_key} 不是强证据且未获公开许可。",
            ))

    scan_unstructured_factual_assertions(payload, claims, parameters, blockers)

    prompt_meta = require_fields(payload.get("prompt_meta"), ("recipe_version", "input_hash", "model", "generated_at", "pipeline_versions"), "prompt_meta", errors)
    input_hash = str(prompt_meta.get("input_hash", ""))
    if input_hash and re.fullmatch(r"[a-f0-9]{64}", input_hash) is None:
        errors.append(issue("invalid_input_hash", "prompt_meta.input_hash", "输入哈希必须是 64 位小写十六进制。"))
    if not isinstance(prompt_meta.get("pipeline_versions", []), list):
        errors.append(issue("invalid_pipeline_versions", "prompt_meta.pipeline_versions", "流水线版本必须是数组。"))

    reported = payload.get("blockers", [])
    if isinstance(reported, list):
        for index, item in enumerate(reported):
            if isinstance(item, dict) and not blank(item.get("message")):
                blockers.append(issue(str(item.get("code") or "model_reported_blocker"), f"blockers.{index}", str(item["message"])))
            elif isinstance(item, str) and item.strip():
                blockers.append(issue("model_reported_blocker", f"blockers.{index}", item.strip()))
    else:
        errors.append(issue("invalid_blockers", "blockers", "blockers 必须是数组。"))

    scan_sensitive_and_injected(payload, blockers)
    scan_high_risk_language(payload, blockers)
    errors = unique_issues(errors)
    warnings = unique_issues(warnings)
    blockers = unique_issues(blockers)
    valid = not errors and not blockers
    status = "validated_candidate" if valid else ("invalid_candidate" if errors else "blocked_candidate")
    return {
        "schema_version": VALIDATION_SCHEMA,
        "candidate_status": status,
        "valid": valid,
        "errors": errors,
        "warnings": warnings,
        "blockers": blockers,
        "metrics": {
            "claim_count": len(claims),
            "parameter_count": len(parameters),
            "source_count": len(sources),
            "target_channel_count": len(target_channels) if isinstance(target_channels, list) else 0,
        },
        "package_sha256": canonical_hash(payload),
        "publication_state": "not_published",
    }
