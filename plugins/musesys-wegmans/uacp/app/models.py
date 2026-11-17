# ==============================================================================
# Title: UACP Data Models (models.py)
# Description: Central Pydantic models and parser logic representing orders, 
#              products, and metadata structures for WooCommerce sync.
# ==============================================================================

from typing import Dict, List, Any, Optional, Literal, Union
from pydantic import BaseModel, Field, validator
import re

# Carrier Options Definition for centralized use
CARRIER_OPTIONS = {
    '': '-- Select Method --',
    'UPS': 'UPS',
    'FEDEX': 'FedEx',
    'USPS': 'USPS',
    'DHL': 'DHL',
    'TECH_DELIVERY': 'Delivered by Technician',
    'CUSTOMER_PICKUP': 'Customer Pickup',
    'LTL': 'LTL Freight',
    'OTHER': 'Other'
}


class UserMeta(BaseModel):
    musesys_favorites: List[int] = Field(default_factory=list, description="List of favorite location IDs")
    muse_favorite_parts: List[int] = Field(default_factory=list, description="List of favorite part IDs")
    musesys_favorite_products: List[int] = Field(default_factory=list, description="List of favorite product IDs")
    contact_phone: Optional[str] = Field(None, description="User contact phone number")

class LineItem(BaseModel):
    id: int
    name: str = Field(..., description="Product Name (contains EAM#)")
    quantity: int
    wo_number: Optional[str] = Field(None, description="WO Number")
    install_store_id: Optional[str] = Field(None, description="Install Store #")
    asset_tag: Optional[str] = Field(None, description="Asset Tag")
    price: Optional[str] = Field(None, description="Unit Price / Total")
    shipped_qty: int = Field(0, description="Quantity already shipped")
    
    @validator('name')
    def extract_eam(cls, v):
        # Optional: Logic to extract EAM if needed, but for now just keeping the name
        return v

class Order(BaseModel):
    # Standard Fields
    id: int
    status: str
    date_created: Optional[str] = ""
    total: Optional[Union[str, float]] = "0.00"
    customer_note: Optional[str] = None
    billing: Dict[str, Any] = Field(default_factory=dict)
    shipping: Dict[str, Any] = Field(default_factory=dict)
    
    # Line Items
    line_items: List[LineItem] = Field(default_factory=list)
    
    # Editable Meta Fields
    trx_order_number: Optional[str] = Field(None, description="TRX Order Number")
    purchase_order_number: Optional[str] = Field(None, description="Purchase Order Number")
    is_dropship: bool = Field(False, description="Is this a drop ship order?")
    logistics_status: Optional[str] = Field(None, description="Logistics Status Note")
    logistics_eta: Optional[str] = Field(None, description="Estimated Logistics Date")
    
    # PR Cross References
    source_pr_id: Optional[str] = Field(None, description="Source PR ID")
    install_store: Optional[str] = Field(None, description="Install Store from Direct Order")
    shipping_speed: Optional[str] = Field(None, description="Shipping Speed")
    ship_to: Optional[str] = Field(None, description="Ship-To Selection (store/warehouse)")
    
    # Transient / UI Fields for Tracking
    tracking_number: Optional[str] = Field(None, description="Tracking Number")
    carrier_name: Optional[str] = Field(None, description="Carrier Name")
    manual_total: Optional[Union[str, float]] = Field(None, description="Manual Job Total")
    
    # Other Meta
    date_modified: Optional[str] = "" 
    date_completed: Optional[str] = ""
    
    @classmethod
    def from_wc_data(cls, data: Dict[str, Any]) -> 'Order':
        # Helper to extract meta value safely from a list of potential keys
        def get_meta(key_list):
            for item in data.get('meta_data', []):
                if item.get('key') in key_list:
                    return item.get('value')
            return None

        # Extract Fields
        trx = get_meta(['_muse_wo_global', '_trx_order_number'])
        po = get_meta(['_purchase_order_number', '_purchase_order'])
        tracking = get_meta(['_muse_ship_track'])
        carrier = get_meta(['_muse_carrier'])
        other_carrier = get_meta(['_muse_other_carrier'])
        manual_total_val = get_meta(['_muse_order_total_manual'])
        manual_total = None
        if manual_total_val:
            try:
                manual_total = float(manual_total_val)
            except ValueError:
                pass
                
        logistics_status = get_meta(['_muse_logistics_status'])
        logistics_eta = get_meta(['_muse_logistics_date', '_muse_logistics_eta'])
        
        source_pr_id = get_meta(['_muse_source_pr_id'])
        install_store = get_meta(['_muse_install_store'])
        shipping_speed = get_meta(['_muse_shipping_speed'])
        ship_to = get_meta(['_muse_ship_to'])
        
        # Drop Ship Logic
        is_ds_val = get_meta(['_is_dropship', '_is_drop_ship'])
        is_dropship = False
        if is_ds_val and str(is_ds_val).lower() in ['yes', 'true', '1']:
            is_dropship = True
        elif po: # Fallback: If PO exists, assume drop ship
            is_dropship = True

        # Parse Shipments for shipped logic
        shipments_meta = get_meta(['_muse_shipments'])
        shipped_counts = {}
        if shipments_meta:
            import json
            try:
                ship_data = json.loads(shipments_meta)
                for shipment in ship_data:
                    for s_item_id, s_qty in shipment.get('items', {}).items():
                        s_item_id_str = str(s_item_id)
                        shipped_counts[s_item_id_str] = shipped_counts.get(s_item_id_str, 0) + int(s_qty)
            except:
                pass

        # Parse Line Items
        items = []
        for item in data.get('line_items', []):
            item_meta = {m['key']: m['value'] for m in item.get('meta_data', [])}
            
            # Handle potential key variations for WO Number (including exact API variations)
            wo_number = item_meta.get('_muse_wo_line') or item_meta.get('WO Number') or item_meta.get('_wo_number')
            
            # Handle Store # (including exact API variations)
            store_number = item_meta.get('_muse_store_global') or item_meta.get('Install Store #') or item_meta.get('_install_store_id') or item_meta.get('_musesys_install_store')

            # Handle Asset Tag
            asset_tag = item_meta.get('Asset Tag') or item_meta.get('_muse_asset_tag')
            shipped = shipped_counts.get(str(item['id']), 0)

            items.append(LineItem(
                id=item['id'],
                name=item['name'],
                quantity=item['quantity'],
                wo_number=wo_number,
                install_store_id=store_number,
                asset_tag=asset_tag,
                price=str(item.get('total', '')),
                shipped_qty=shipped
            ))
            
        # Determine final carrier name
        carrier_name = carrier
        if carrier == 'Other' and other_carrier:
            carrier_name = other_carrier
        
        return cls(
            id=data['id'],
            status=data['status'],
            date_created=data.get('date_created', ''),
            date_modified=data.get('date_modified', ''),
            date_completed=data.get('date_completed', ''), # Fixed missing field crash
            customer_note=data.get('customer_note'),
            billing=data.get('billing', {}),
            shipping=data.get('shipping', {}),
            line_items=items,
            trx_order_number=trx,
            purchase_order_number=po,
            is_dropship=is_dropship,
            logistics_status=logistics_status,
            logistics_eta=logistics_eta,
            source_pr_id=source_pr_id,
            install_store=install_store,
            shipping_speed=shipping_speed,
            ship_to=ship_to,
            tracking_number=tracking,
            carrier_name=carrier_name,
            manual_total=manual_total
        )

class ProductMeta(BaseModel):
    part_number: Optional[str] = Field(None, alias="_muse_part_number", description="Manufacturer Part Number")
    manual_file: Optional[str] = Field(None, alias="_muse_manual_file", description="Path to manual file")
    manual_url: Optional[str] = Field(None, alias="_muse_manual_url", description="URL to manual file")
    manual_model_raw: Optional[str] = Field(None, alias="_muse_manual_model_raw", description="Raw model string from manual filename")
    prop65_enabled: bool = Field(False, alias="_muse_prop65_enabled", description="Is Prop 65 warning enabled?")
    prop65_chems: Optional[str] = Field(None, alias="_muse_prop65_chems", description="Comma-separated list of chemicals")
    prop65_message: Optional[str] = Field(None, alias="_muse_prop65_message", description="Custom Prop 65 warning message")

class RequestMeta(BaseModel):
    brand: Optional[str] = Field(None, description="Product Brand")
    model: Optional[str] = Field(None, description="Product Model")
    eam: Optional[str] = Field(None, description="EAM Number")
    status: Literal["pending", "approved", "rejected", "completed"] = Field("pending", alias="_muse_pr_status", description="Product Request Status")
    customer_user_id: Optional[int] = Field(None, alias="_muse_customer_user_id", description="ID of the user who made the request")
    customer_email: Optional[str] = Field(None, alias="_muse_customer_email", description="Email of the customer")

class ProductRequest(BaseModel):
    request_id: int
    brand: Optional[str] = None
    part_number: Optional[str] = None
    eam_number: Optional[str] = None
    requester_name: Optional[str] = None
    requester_email: Optional[str] = None
    notes: Optional[str] = None
    request_status: str = "pending"
