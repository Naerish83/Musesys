# MuseSys MCP Server

stdio MCP server bridging LLM agents to the MuseSys/WooCommerce backend. Implements STATE_OF_THE_APP.md Part 2 in full: 4 resources, 15 tools, validation gates, exponential backoff on 429/5xx.

## Install

```powershell
pip install -r requirements.txt
```

Requires Python 3.10+ (the `mcp` package floor; spec said 3.8+ but the official SDK won't go that low).

## Configuration

Per-key resolution, env wins:

1. `MUSESYS_API_URL`, `MUSESYS_CONSUMER_KEY`, `MUSESYS_CONSUMER_SECRET`, `MUSESYS_WP_USERNAME`, `MUSESYS_WP_APP_PASSWORD`
2. UACP `settings.json` (`active_site` schema) — searched beside the script, then UACP repo root (`../../settings.json`), then cwd.

Zero-config when the module lives at `UACP/modules/musesys_mcp/` — it finds the repo's `settings.json` on its own.

## One-click install (Claude Desktop)

Drag `dist/musesys-mcp-1.0.0.mcpb` (or the identical `.dxt` copy) onto Claude Desktop → Settings → Extensions, then fill in the 5 credential fields. Uses the `uv` runtime — deps install automatically, secrets land in the OS keychain. Rebuild after editing the server:

```powershell
cp musesys_mcp.py bundle/src/server.py
npx @anthropic-ai/mcpb pack bundle dist/musesys-mcp-1.0.0.mcpb
```

## Manual client registration (any MCP client)

```json
{
  "mcpServers": {
    "musesys": {
      "command": "python",
      "args": ["C:\\Projects\\UACP\\modules\\musesys_mcp\\musesys_mcp.py"]
    }
  }
}
```

## Surface

**Resources**: `musesys://orders/active`, `musesys://orders/detail/{order_id}`, `musesys://requests/pending`, `musesys://assets/store/{store_id}`

**Tools**: `musesys_get_orders`, `musesys_get_order`, `musesys_update_order_fields`, `musesys_update_order_logistics`, `musesys_update_order_line_item`, `musesys_ship_lines`, `musesys_get_product_requests`, `musesys_complete_product_request`, `musesys_search_catalog`, `musesys_create_product`, `musesys_update_product`, `musesys_get_assets`, `musesys_update_asset`, `musesys_export_orders`, `musesys_export_aq`

## Safe gates enforced server-side

- WO numbers: `^[0-9]{7}$`, hard reject otherwise
- Store numbers: digits, zero-padded to 3 (`24` → `024`)
- Dates: `/` and `.` sanitized to `-`, parsed, re-emitted strictly zero-padded `YYYY-MM-DD`; impossible dates rejected
- Order statuses normalized to the canonical 7 (+ `partially-shipped` for active-order filtering); `wc-` prefixes stripped
- `logistics_status=replaced` requires `replacement_part`
- `ship_lines` rejects empty/malformed lines and `ship_qty < 1`
- Asset updates whitelist-checked against the 6 allowed fields
- All list fetches cache-busted (`_=timestamp`) to defeat Cloudways/Nginx caching
- 429/5xx → exponential backoff (1s/2s/4s, honors `Retry-After`), 4 attempts

## Notes

- `closeout` on `ship_lines` defers order completion to the `/ship-lines` endpoint, preserving the UACP status-sync guard against premature completion emails.
- Custom `musesys/v1` routes authenticate via WP Application Password; WC core routes via consumer key/secret — mirrors `app/api_client.py` behavior.
- `wo_number`/`store_number` filters on `musesys_get_orders` are client-side over the fetched page (checks current + legacy meta keys); raise `limit` when hunting an older order.
