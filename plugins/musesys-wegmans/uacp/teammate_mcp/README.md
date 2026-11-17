# Teammate MCP Server

**Your first custom MCP connector!**

This MCP server exposes your Wegmans order data from the Teammate app so Claude can query it programmatically.

## What It Does

Provides 4 tools:
- `teammate_search_orders` - Search orders by number, status, email
- `teammate_get_order` - Get complete order details by ID
- `teammate_search_products` - Search products by SKU/EAM/name
- `teammate_test_connection` - Test API connectivity

## Setup

### 1. Installation (Already Done!)
```bash
cd C:\Projects\teammate_mcp
python -m pip install -r requirements.txt
python -m pip install email-validator
```

### 2. Configure Claude to Use This Server

**Option A: Add to Claude Desktop Config (Recommended)**

Edit your Claude config file at:
`%APPDATA%\Claude\config.json`

Add this to the `mcpServers` section:
```json
{
  "mcpServers": {
    "teammate": {
      "command": "python",
      "args": ["C:\\Projects\\teammate_mcp\\teammate_mcp.py"],
      "env": {}
    }
  }
}
```

**Option B: Test Manually with MCP Inspector**
```bash
cd C:\Projects\teammate_mcp
npx @modelcontextprotocol/inspector python teammate_mcp.py
```

### 3. Restart Claude Desktop

After adding the config, restart the Claude desktop app. The Teammate connector should appear in your available tools.

## Usage Examples

Once configured, you can ask Claude:

- "Search for Wegmans order #236333"
- "Find all orders with status 'processing'"
- "Search for product SKU CALG4171-75"
- "Get pricing for EAM #39628"
- "Test the Wegmans API connection"

## How It Works

1. Connects to your WooCommerce REST API at `https://wegmansparts.com`
2. Uses credentials from `settings.json` (already configured)
3. Exposes order/product data through MCP tools
4. Claude can now query your Teammate data directly!

## Next Steps

Want to add more tools? Edit `teammate_mcp.py` and add new `@mcp.tool()` functions!

Some ideas:
- `teammate_update_pricing` - Update product prices
- `teammate_add_order_note` - Add notes to orders
- `teammate_get_customer` - Look up customer details
- `teammate_bulk_export` - Export orders to CSV

---

**Built with:** FastMCP, Pydantic, your existing uacp modules
**Status:** 🎉 READY TO USE
