#!/usr/bin/env python3
"""
MuseSys MCP Server
==================
stdio JSON-RPC 2.0 bridge exposing the MuseSys / WooCommerce backend
(wegmansparts.com) to LLMs and AI agents, per STATE_OF_THE_APP.md Part 2.

Resources:
    musesys://orders/active
    musesys://orders/detail/{order_id}
    musesys://requests/pending
    musesys://assets/store/{store_id}

Tools: 15 (see spec section 3).

Config resolution (per-key, env wins):
    MUSESYS_API_URL, MUSESYS_CONSUMER_KEY, MUSESYS_CONSUMER_SECRET,
    MUSESYS_WP_USERNAME, MUSESYS_WP_APP_PASSWORD
falls back to UACP settings.json (active_site schema) located beside this
script, in the UACP repo root, or in the current working directory.

Run: python musesys_mcp.py   (stdio transport)
"""

from __future__ import annotations

import base64
import json
import os
import re
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Optional

import requests
from mcp.server.fastmcp import FastMCP

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

_ENV_MAP = {
    "api_url": "MUSESYS_API_URL",
    "consumer_key": "MUSESYS_CONSUMER_KEY",
    "consumer_secret": "MUSESYS_CONSUMER_SECRET",
    "wp_username": "MUSESYS_WP_USERNAME",
    "wp_app_password": "MUSESYS_WP_APP_PASSWORD",
}

_SETTINGS_KEY_MAP = {
    "api_url": "api_url",
    "consumer_key": "consumer_key",
    "consumer_secret": "consumer_secret",
    "wp_username": "WP_USERNAME",
    "wp_app_password": "WP_APP_PASSWORD",
}


def _find_settings_file() -> Optional[Path]:
    here = Path(__file__).resolve().parent
    candidates = [
        here / "settings.json",
        here.parent.parent / "settings.json",   # modules/musesys_mcp -> UACP root
        Path.cwd() / "settings.json",
    ]
    for c in candidates:
        if c.is_file():
            return c
    return None


def load_config() -> Dict[str, str]:
    cfg: Dict[str, Optional[str]] = {k: os.getenv(v) or None for k, v in _ENV_MAP.items()}

    if not all(cfg.values()):
        settings_path = _find_settings_file()
        if settings_path:
            try:
                data = json.loads(settings_path.read_text(encoding="utf-8-sig"))
                site = data.get("sites", {}).get(data.get("active_site", ""), {})
                for key, skey in _SETTINGS_KEY_MAP.items():
                    if not cfg[key] and site.get(skey):
                        cfg[key] = site[skey]
            except Exception as exc:  # malformed settings: report, keep env values
                print(f"[musesys-mcp] WARN: could not parse {settings_path}: {exc}", file=sys.stderr)

    missing = [k for k in ("api_url", "consumer_key", "consumer_secret") if not cfg[k]]
    if missing:
        raise RuntimeError(
            f"Missing required config: {missing}. "
            "Set MUSESYS_* environment variables or provide a UACP settings.json."
        )

    # Normalize base URL: strip trailing slash and any wp-json suffix
    base = cfg["api_url"].rstrip("/")
    for suffix in ("/wp-json/wc/v3", "/wp-json/wc/v2", "/wp-json"):
        if base.endswith(suffix):
            base = base[: -len(suffix)]
    cfg["api_url"] = base
    return {k: (v or "") for k, v in cfg.items()}


CONFIG = load_config()
BASE_URL = CONFIG["api_url"]

# ---------------------------------------------------------------------------
# Validation gates (spec section 4)
# ---------------------------------------------------------------------------

VALID_ORDER_STATUSES = {
    "pending", "processing", "on-hold", "completed",
    "cancelled", "refunded", "failed", "partially-shipped",
}
VALID_LOGISTICS_STATUSES = {"in_stock", "backordered", "replaced", "discontinued"}
VALID_ASSET_FIELDS = {
    "description", "manufacturer", "model",
    "serial_number", "commission_date", "department",
}


def validate_wo(value: Any) -> str:
    """Work Order numbers: exactly 7 digits, nothing else."""
    s = str(value).strip()
    if not re.fullmatch(r"[0-9]{7}", s):
        raise ValueError(f"Invalid Work Order number {value!r}: must be exactly 7 digits.")
    return s


def validate_store(value: Any) -> str:
    """Store numbers: exactly 3 digits, zero-padded (e.g. '024')."""
    s = str(value).strip()
    if not s.isdigit() or len(s) > 3:
        raise ValueError(f"Invalid store number {value!r}: must be 1-3 digits (zero-padded to 3).")
    return s.zfill(3)


def validate_date(value: Any, field: str = "date") -> str:
    """Dates: strict zero-padded YYYY-MM-DD; sanitizes '/' and '.' separators."""
    s = str(value).strip().replace("/", "-").replace(".", "-")
    try:
        dt = datetime.strptime(s, "%Y-%m-%d")
    except ValueError:
        raise ValueError(f"Invalid {field} {value!r}: must be a real date in YYYY-MM-DD format.")
    return dt.strftime("%Y-%m-%d")


def normalize_order_status(value: str) -> str:
    s = str(value).strip().lower()
    if s.startswith("wc-"):
        s = s[3:]
    aliases = {"on_hold": "on-hold", "onhold": "on-hold",
               "partial": "partially-shipped", "partially_shipped": "partially-shipped"}
    s = aliases.get(s, s)
    if s not in VALID_ORDER_STATUSES:
        raise ValueError(
            f"Invalid order status {value!r}. Allowed: {sorted(VALID_ORDER_STATUSES)}"
        )
    return s


def validate_logistics_status(value: str) -> str:
    s = str(value).strip().lower()
    if s not in VALID_LOGISTICS_STATUSES:
        raise ValueError(
            f"Invalid logistics status {value!r}. Allowed: {sorted(VALID_LOGISTICS_STATUSES)}"
        )
    return s


# ---------------------------------------------------------------------------
# HTTP layer with 429/5xx exponential backoff (spec section 4.5)
# ---------------------------------------------------------------------------

MAX_ATTEMPTS = 4
BACKOFF_BASE = 1.0  # seconds: 1, 2, 4


def _wc_headers() -> Dict[str, str]:
    creds = f"{CONFIG['consumer_key']}:{CONFIG['consumer_secret']}"
    return {
        "Authorization": f"Basic {base64.b64encode(creds.encode()).decode()}",
        "Content-Type": "application/json",
    }


def _wp_headers() -> Dict[str, str]:
    """WP Application Password auth — required for musesys/v1 custom routes."""
    if CONFIG["wp_username"] and CONFIG["wp_app_password"]:
        creds = f"{CONFIG['wp_username']}:{CONFIG['wp_app_password']}"
        return {
            "Authorization": f"Basic {base64.b64encode(creds.encode()).decode()}",
            "Content-Type": "application/json",
        }
    # Fall back to WC keys if no app password configured
    return _wc_headers()


def _request(
    method: str,
    url: str,
    *,
    headers: Dict[str, str],
    params: Optional[Dict] = None,
    json_body: Optional[Dict] = None,
    timeout: int = 30,
) -> Any:
    delay = BACKOFF_BASE
    last_exc: Optional[Exception] = None
    for attempt in range(MAX_ATTEMPTS):
        try:
            resp = requests.request(
                method, url, headers=headers, params=params, json=json_body, timeout=timeout
            )
        except requests.ConnectionError as exc:
            last_exc = exc
            if attempt < MAX_ATTEMPTS - 1:
                time.sleep(delay)
                delay *= 2
                continue
            raise RuntimeError(f"Connection failed after {MAX_ATTEMPTS} attempts: {exc}") from exc

        if resp.status_code == 429 or resp.status_code >= 500:
            if attempt < MAX_ATTEMPTS - 1:
                retry_after = resp.headers.get("Retry-After")
                time.sleep(float(retry_after) if retry_after else delay)
                delay *= 2
                continue

        if resp.status_code >= 400:
            raise RuntimeError(
                f"{method} {url} failed [{resp.status_code}]: {resp.text[:500]}"
            )

        if not resp.content:
            return None
        # Tolerate UTF-8 BOM occasionally emitted by WordPress/PHP
        return json.loads(resp.content.decode("utf-8-sig"))

    raise RuntimeError(f"{method} {url} exhausted retries: {last_exc}")


def wc_get(endpoint: str, params: Optional[Dict] = None) -> Any:
    params = dict(params or {})
    params["_"] = int(time.time())  # cache-bust Cloudways/Nginx
    return _request("GET", f"{BASE_URL}/wp-json/wc/v3/{endpoint}", headers=_wc_headers(), params=params)


def wc_post(endpoint: str, data: Dict) -> Any:
    return _request("POST", f"{BASE_URL}/wp-json/wc/v3/{endpoint}", headers=_wc_headers(), json_body=data)


def muse_get(endpoint: str, params: Optional[Dict] = None) -> Any:
    params = dict(params or {})
    params["_"] = int(time.time())
    return _request("GET", f"{BASE_URL}/wp-json/musesys/v1/{endpoint}", headers=_wp_headers(), params=params)


def muse_post(endpoint: str, data: Dict) -> Any:
    return _request("POST", f"{BASE_URL}/wp-json/musesys/v1/{endpoint}", headers=_wp_headers(), json_body=data)


def _meta(meta: Dict[str, Any]) -> List[Dict[str, Any]]:
    return [{"key": k, "value": v} for k, v in meta.items()]


# ---------------------------------------------------------------------------
# MCP server
# ---------------------------------------------------------------------------

mcp = FastMCP("musesys")

# --- Resources -------------------------------------------------------------


@mcp.resource("musesys://orders/active")
def resource_active_orders() -> str:
    """All processing, pending, or partially-shipped orders."""
    orders = wc_get("orders", {"status": "processing,pending,partially-shipped", "per_page": 100})
    return json.dumps(orders, default=str)


@mcp.resource("musesys://orders/detail/{order_id}")
def resource_order_detail(order_id: str) -> str:
    """Full WooCommerce detail block for a single order, expanded custom metadata."""
    return json.dumps(wc_get(f"orders/{int(order_id)}"), default=str)


@mcp.resource("musesys://requests/pending")
def resource_pending_requests() -> str:
    """All unfulfilled product requests submitted by field technicians."""
    return json.dumps(muse_get("product-requests"), default=str)


@mcp.resource("musesys://assets/store/{store_id}")
def resource_store_assets(store_id: str) -> str:
    """All assets registered at the specified 3-digit store."""
    return json.dumps(muse_get("assets", {"store_id": validate_store(store_id)}), default=str)


# --- Tools: Orders ----------------------------------------------------------


@mcp.tool()
def musesys_get_orders(
    limit: int = 10,
    status: Optional[str] = None,
    wo_number: Optional[str] = None,
    store_number: Optional[str] = None,
) -> List[Dict]:
    """Retrieve order lists with optional filters (status, WO number, install store)."""
    params: Dict[str, Any] = {"per_page": max(1, min(int(limit), 100))}
    if status:
        params["status"] = normalize_order_status(status)
    orders = wc_get("orders", params)

    if wo_number:
        wo = validate_wo(wo_number)

        def _wo_match(o: Dict) -> bool:
            metas = {m.get("key"): str(m.get("value", "")) for m in o.get("meta_data", [])}
            if wo in (metas.get("_muse_wo_global"), metas.get("_trx_order_number"),
                      metas.get("_muse_workorder")):
                return True
            for li in o.get("line_items", []):
                li_meta = {m.get("key"): str(m.get("value", "")) for m in li.get("meta_data", [])}
                if wo in (li_meta.get("_muse_wo_line"), li_meta.get("WO Number"),
                          li_meta.get("_wo_number")):
                    return True
            return False

        orders = [o for o in orders if _wo_match(o)]

    if store_number:
        store = validate_store(store_number)

        def _store_match(o: Dict) -> bool:
            for li in o.get("line_items", []):
                li_meta = {m.get("key"): str(m.get("value", "")) for m in li.get("meta_data", [])}
                if store in (li_meta.get("_muse_store_global"), li_meta.get("Install Store #"),
                             li_meta.get("_install_store_id")):
                    return True
            return False

        orders = [o for o in orders if _store_match(o)]

    return orders


@mcp.tool()
def musesys_get_order(order_id: int) -> Dict:
    """Fetch full detail of a specific order by its WooCommerce ID."""
    return wc_get(f"orders/{int(order_id)}")


@mcp.tool()
def musesys_update_order_fields(
    order_id: int,
    trx_number: Optional[str] = None,
    po_number: Optional[str] = None,
    is_dropship: Optional[bool] = None,
    manual_total: Optional[float] = None,
) -> Dict:
    """Update order-level metadata: global WO/TRX #, PO #, dropship flag, manual job total."""
    meta: Dict[str, Any] = {}
    if trx_number is not None:
        meta["_muse_wo_global"] = validate_wo(trx_number)
    if po_number is not None:
        meta["_purchase_order_number"] = str(po_number).strip()
    if is_dropship is not None:
        meta["_is_dropship"] = "yes" if is_dropship else "no"
    if manual_total is not None:
        meta["_muse_order_total_manual"] = float(manual_total)
    if not meta:
        raise ValueError("No fields supplied; provide at least one of trx_number, po_number, is_dropship, manual_total.")
    return wc_post(f"orders/{int(order_id)}", {"meta_data": _meta(meta)})


@mcp.tool()
def musesys_update_order_logistics(
    order_id: int,
    logistics_status: str,
    logistics_eta: Optional[str] = None,
    replacement_part: Optional[str] = None,
) -> Dict:
    """Update shipping logistics status, ETA (YYYY-MM-DD), and replacement part SKU."""
    status = validate_logistics_status(logistics_status)
    if status == "replaced" and not replacement_part:
        raise ValueError("replacement_part is required when logistics_status is 'replaced'.")
    meta: Dict[str, Any] = {"_muse_logistics_status": status}
    if logistics_eta is not None:
        meta["_muse_logistics_date"] = validate_date(logistics_eta, "logistics_eta")
    if replacement_part is not None:
        meta["_muse_logistics_replacement_part"] = str(replacement_part).strip()
    return wc_post(f"orders/{int(order_id)}", {"meta_data": _meta(meta)})


@mcp.tool()
def musesys_update_order_line_item(
    order_id: int,
    line_item_id: int,
    wo_number: Optional[str] = None,
    install_store_id: Optional[str] = None,
    asset_tag: Optional[str] = None,
) -> Dict:
    """Update item-level Work Order #, Install Store #, or Asset Tag on a single line item."""
    meta: Dict[str, Any] = {}
    if wo_number is not None:
        meta["_muse_wo_line"] = validate_wo(wo_number)
    if install_store_id is not None:
        meta["_muse_store_global"] = validate_store(install_store_id)
    if asset_tag is not None:
        meta["_muse_asset_tag"] = str(asset_tag).strip()
    if not meta:
        raise ValueError("No fields supplied; provide at least one of wo_number, install_store_id, asset_tag.")
    payload = {"line_items": [{"id": int(line_item_id), "meta_data": _meta(meta)}]}
    return wc_post(f"orders/{int(order_id)}", payload)


@mcp.tool()
def musesys_ship_lines(
    order_id: int,
    carrier: str,
    lines: List[Dict[str, int]],
    tracking_number: Optional[str] = None,
    closeout: bool = False,
) -> Dict:
    """Post a partial or complete shipment with tracking; advances fulfillment status.
    lines: [{"line_item_id": int, "ship_qty": int}, ...]. closeout=True completes the order
    server-side (bundled with tracking to avoid premature completion emails)."""
    if not lines:
        raise ValueError("lines must contain at least one {line_item_id, ship_qty} object.")
    clean_lines = []
    for ln in lines:
        if "line_item_id" not in ln or "ship_qty" not in ln:
            raise ValueError(f"Malformed line {ln!r}: requires line_item_id and ship_qty.")
        qty = int(ln["ship_qty"])
        if qty < 1:
            raise ValueError(f"ship_qty must be >= 1 (got {qty} for line {ln['line_item_id']}).")
        clean_lines.append({"line_item_id": int(ln["line_item_id"]), "ship_qty": qty})
    payload = {
        "carrier": str(carrier).strip().upper(),
        "tracking_number": str(tracking_number).strip() if tracking_number else "",
        "lines": clean_lines,
        "closeout": bool(closeout),
    }
    result = muse_post(f"orders/{int(order_id)}/ship-lines", payload)
    return result if isinstance(result, dict) else {"status": "ok", "response": result}


# --- Tools: Product Requests ------------------------------------------------


@mcp.tool()
def musesys_get_product_requests() -> List[Dict]:
    """Fetch all pending product requests from field technicians (cache-busted)."""
    return muse_get("product-requests")


@mcp.tool()
def musesys_complete_product_request(
    request_id: int,
    products: List[Dict[str, str]],
    action: str = "send_confirm",
) -> Dict:
    """Map catalog products to a technician request and mark it completed.
    products: [{"item_id": str, "link": str}, ...]."""
    if not products:
        raise ValueError("products must contain at least one {item_id, link} mapping.")
    for p in products:
        if "item_id" not in p:
            raise ValueError(f"Malformed product mapping {p!r}: requires item_id.")
    payload = {"items": products, "status": "completed", "action": action}
    result = muse_post(f"product-requests/{int(request_id)}/complete", payload)
    return result if isinstance(result, dict) else {"status": "completed", "response": result}


# --- Tools: Catalog ----------------------------------------------------------


@mcp.tool()
def musesys_search_catalog(query: str) -> List[Dict]:
    """Query the product catalog by SKU, description, or title."""
    if not str(query).strip():
        raise ValueError("query must be a non-empty string.")
    return wc_get("products", {"search": str(query).strip(), "per_page": 20})


@mcp.tool()
def musesys_create_product(
    name: str,
    sku: str,
    regular_price: str,
    meta_data: Optional[List[Dict[str, Any]]] = None,
) -> Dict:
    """Insert a new product into the WooCommerce catalog."""
    if not str(name).strip() or not str(sku).strip():
        raise ValueError("name and sku are required.")
    data: Dict[str, Any] = {
        "name": str(name).strip(),
        "sku": str(sku).strip(),
        "regular_price": str(regular_price),
    }
    if meta_data:
        data["meta_data"] = meta_data
    return wc_post("products", data)


@mcp.tool()
def musesys_update_product(product_id: int, data: Dict[str, Any]) -> Dict:
    """Modify an existing catalog product. data = map of WC product fields to update."""
    if not data:
        raise ValueError("data must contain at least one field to update.")
    return wc_post(f"products/{int(product_id)}", data)


# --- Tools: Assets ------------------------------------------------------------


@mcp.tool()
def musesys_get_assets(store_id: str) -> List[Dict]:
    """Retrieve all registered assets for a 3-digit store."""
    return muse_get("assets", {"store_id": validate_store(store_id)})


@mcp.tool()
def musesys_update_asset(asset_id: int, payload: Dict[str, Any]) -> Dict:
    """Update asset details. Allowed fields: description, manufacturer, model,
    serial_number, commission_date (YYYY-MM-DD), department."""
    if not payload:
        raise ValueError("payload must contain at least one field.")
    bad = set(payload) - VALID_ASSET_FIELDS
    if bad:
        raise ValueError(f"Unsupported asset fields {sorted(bad)}. Allowed: {sorted(VALID_ASSET_FIELDS)}")
    clean = dict(payload)
    if "commission_date" in clean:
        clean["commission_date"] = validate_date(clean["commission_date"], "commission_date")
    result = muse_post(f"assets/{int(asset_id)}", clean)
    return result if isinstance(result, dict) else {"status": "ok", "response": result}


# --- Tools: Exports ------------------------------------------------------------


@mcp.tool()
def musesys_export_orders(
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    export_all: bool = False,
) -> List[Dict]:
    """Flattened order/line-item export for a date range (or export_all=True)."""
    params: Dict[str, Any] = {}
    if start_date:
        params["start_date"] = validate_date(start_date, "start_date")
    if end_date:
        params["end_date"] = validate_date(end_date, "end_date")
    if params.get("start_date") and params.get("end_date") and params["start_date"] > params["end_date"]:
        raise ValueError("start_date must be on or before end_date.")
    if export_all:
        params["export_all"] = "true"
    return muse_get("export-orders", params)


@mcp.tool()
def musesys_export_aq(order_id: int) -> Dict:
    """Export AutoQuotes JSON payload for a single order."""
    return muse_get(f"export-aq/{int(order_id)}")


# ---------------------------------------------------------------------------

if __name__ == "__main__":
    mcp.run()  # stdio transport
