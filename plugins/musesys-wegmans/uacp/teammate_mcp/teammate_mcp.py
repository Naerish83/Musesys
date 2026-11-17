"""
Teammate MCP Server - Exposes Cole's Wegmans order data via MCP
Connects to WooCommerce API through MuseSysAPIClient
"""

from mcp.server.fastmcp import FastMCP
from pydantic import BaseModel, Field
from typing import Optional, List, Dict, Any
import json
import sys
import os

# Add path to uacp modules
sys.path.append("C:\\Users\\chenderson\\Local Sites\\wegmans-20\\app\\public\\wp-content\\plugins\\musesys-mega")

from uacp.api_client import MuseSysAPIClient
from uacp.core.config import ConfigManager

# Initialize MCP Server
mcp = FastMCP("teammate_mcp")

# Global API client (initialized on first use)
_api_client = None

def get_api_client() -> MuseSysAPIClient:
    """Lazy-load the API client from settings."""
    global _api_client
    if _api_client is None:
        # Load settings from the absolute path
        settings_path = "C:\\Users\\chenderson\\Local Sites\\wegmans-20\\app\\public\\wp-content\\plugins\\musesys-mega\\settings.json"
        with open(settings_path, 'r') as f:
            settings = json.load(f)
        active = settings["active_site"]
        site = settings["sites"][active]
        
        _api_client = MuseSysAPIClient(
            base_url=site["api_url"],
            consumer_key=site["consumer_key"],
            consumer_secret=site["consumer_secret"],
            wp_username=site.get("WP_USERNAME"),
            wp_app_password=site.get("WP_APP_PASSWORD")
        )
    return _api_client


# ============================================================================
# TOOL INPUT MODELS
# ============================================================================

class SearchOrdersInput(BaseModel):
    """Search for orders with various filters."""
    order_number: Optional[str] = Field(None, description="Specific order number to find")
    status: Optional[str] = Field(None, description="Order status (processing, completed, etc.)")
    customer_email: Optional[str] = Field(None, description="Customer email address")
    limit: int = Field(default=20, description="Maximum number of orders to return", ge=1, le=100)

class GetOrderInput(BaseModel):
    """Get a specific order by ID."""
    order_id: int = Field(..., description="WooCommerce order ID", ge=1)

class SearchProductsInput(BaseModel):
    """Search for products by name, SKU, or EAM number."""
    query: str = Field(..., description="Search query (product name, SKU, or EAM#)", min_length=1)
    limit: int = Field(default=20, description="Maximum results", ge=1, le=50)


# ============================================================================
# TOOLS
# ============================================================================

@mcp.tool(
    name="teammate_search_orders",
    annotations={
        "title": "Search Wegmans Orders",
        "readOnlyHint": True,
        "destructiveHint": False,
        "idempotentHint": True,
        "openWorldHint": False
    }
)
async def search_orders(params: SearchOrdersInput) -> str:
    """
    Search for orders in the Wegmans order system.
    
    Returns order data including line items, pricing, store numbers, and WO numbers.
    Perfect for looking up orders to fill in missing PO data.
    
    Args:
        params: Search parameters including order_number, status, customer_email, limit
    
    Returns:
        JSON-formatted list of matching orders with complete details
    """
    try:
        client = get_api_client()
        
        # Build query parameters
        kwargs = {}
        if params.status:
            kwargs["status"] = params.status
        if params.customer_email:
            kwargs["customer"] = params.customer_email
        if params.order_number:
            kwargs["search"] = params.order_number
            
        # Fetch orders
        orders = client.get_orders(limit=params.limit, **kwargs)
        
        # Format response
        result = {
            "count": len(orders),
            "orders": []
        }
        
        for order in orders:
            # Extract key data
            order_data = {
                "id": order["id"],
                "number": order.get("number", ""),
                "status": order["status"],
                "date_created": order.get("date_created", ""),
                "total": order.get("total", "0.00"),
                "customer_note": order.get("customer_note", ""),
                "billing": {
                    "first_name": order.get("billing", {}).get("first_name", ""),
                    "last_name": order.get("billing", {}).get("last_name", ""),
                    "email": order.get("billing", {}).get("email", "")
                },
                "line_items": []
            }
            
            # Extract line items with pricing and metadata
            for item in order.get("line_items", []):
                # Get meta data
                meta_dict = {m["key"]: m["value"] for m in item.get("meta_data", [])}
                
                line_data = {
                    "id": item["id"],
                    "name": item["name"],
                    "sku": item.get("sku", ""),
                    "quantity": item["quantity"],
                    "subtotal": item.get("subtotal", "0.00"),
                    "total": item.get("total", "0.00"),
                    "price": item.get("price", "0.00"),
                    "wo_number": meta_dict.get("_wo_number") or meta_dict.get("_line_wo"),
                    "install_store": meta_dict.get("_install_store_id") or meta_dict.get("_line_store"),
                    "asset_tag": meta_dict.get("_asset_tag")
                }
                order_data["line_items"].append(line_data)
            
            result["orders"].append(order_data)
        
        return json.dumps(result, indent=2)
        
    except Exception as e:
        return json.dumps({"error": str(e)}, indent=2)


@mcp.tool(
    name="teammate_get_order",
    annotations={
        "title": "Get Specific Wegmans Order",
        "readOnlyHint": True,
        "destructiveHint": False,
        "idempotentHint": True,
        "openWorldHint": False
    }
)
async def get_order(params: GetOrderInput) -> str:
    """
    Get complete details for a specific order by its WooCommerce ID.
    
    Returns all order data including line items, pricing, metadata, and customer info.
    
    Args:
        params: Order ID to retrieve
    
    Returns:
        JSON-formatted order details
    """
    try:
        client = get_api_client()
        
        # Fetch single order
        order = client._get(f"orders/{params.order_id}")
        
        return json.dumps(order, indent=2)
        
    except Exception as e:
        return json.dumps({"error": str(e)}, indent=2)


@mcp.tool(
    name="teammate_search_products",
    annotations={
        "title": "Search Wegmans Products",
        "readOnlyHint": True,
        "destructiveHint": False,
        "idempotentHint": True,
        "openWorldHint": False
    }
)
async def search_products(params: SearchProductsInput) -> str:
    """
    Search for products by name, SKU, or EAM number.
    
    Useful for finding product pricing and details when building POs.
    
    Args:
        params: Search query and result limit
    
    Returns:
        JSON-formatted list of matching products with pricing
    """
    try:
        client = get_api_client()
        
        products = client.search_products(params.query)
        
        # Format response
        result = {
            "count": len(products),
            "products": []
        }
        
        for product in products:
            product_data = {
                "id": product["id"],
                "name": product["name"],
                "sku": product.get("sku", ""),
                "price": product.get("price", "0.00"),
                "regular_price": product.get("regular_price", "0.00"),
                "sale_price": product.get("sale_price", ""),
                "stock_status": product.get("stock_status", "instock"),
                "description": product.get("description", "")[:200]  # Truncate long descriptions
            }
            result["products"].append(product_data)
        
        return json.dumps(result, indent=2)
        
    except Exception as e:
        return json.dumps({"error": str(e)}, indent=2)


@mcp.tool(
    name="teammate_test_connection",
    annotations={
        "title": "Test Wegmans API Connection",
        "readOnlyHint": True,
        "destructiveHint": False,
        "idempotentHint": True,
        "openWorldHint": False
    }
)
async def test_connection() -> str:
    """
    Test the connection to the Wegmans WooCommerce API.
    
    Returns:
        Connection status message
    """
    try:
        client = get_api_client()
        
        if client.test_connection():
            return json.dumps({
                "status": "success",
                "message": "Successfully connected to Wegmans API",
                "base_url": client.base_url
            }, indent=2)
        else:
            return json.dumps({
                "status": "failed",
                "message": "Connection test failed"
            }, indent=2)
            
    except Exception as e:
        return json.dumps({
            "status": "error",
            "message": str(e)
        }, indent=2)


# ============================================================================
# SERVER ENTRY POINT
# ============================================================================

if __name__ == "__main__":
    # Run with stdio transport (for local MCP client)
    mcp.run()
