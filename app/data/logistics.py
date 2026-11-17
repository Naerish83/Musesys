import datetime

def evaluate_shipment_action(row: dict, today: datetime.date = None) -> dict:
    """
    Evaluates a single order/line-item row to determine if it should appear in the Shipment Action Queue.
    
    Returns a dict:
    {
        "show": bool,
        "severity": "hidden" | "info" | "warning" | "critical" | "data_issue",
        "reason_code": str,
        "reason_label": str,
        "sort_bucket": int,
    }
    """
    if today is None:
        today = datetime.date.today()

    # Normalize Woo Order Status
    raw_status = row.get("Order Status", "").lower()
    if raw_status.startswith("wc-"):
        raw_status = raw_status[3:]
    
    # Exclude closed states completely unless they have unresolved data issues 
    # (but generally we don't evaluate fully closed orders for the queue)
    closed_states = {"completed", "cancelled", "refunded", "failed"}
    if raw_status in closed_states:
        # Check if it was closed but lacks tracking? Usually WooCommerce completes it when shipped.
        # The prompt says: "If status is completed and tracking exists, hide from all default action queues."
        return _result(False, "hidden", "closed", "Order closed", 99)

    # Core data fields
    logistics_status = row.get("Logistics Status", "in_stock").lower()
    
    # Normalize obsolete/discontinued aliases
    if logistics_status == "discontinued":
        logistics_status = "obsolete"
        
    logistics_date_str = row.get("Logistics Date", "").strip()
    
    # Qty data
    try:
        ordered_qty = int(row.get("Ordered Qty", 0) or 0)
    except ValueError:
        ordered_qty = 0
        
    try:
        shipped_qty = int(row.get("Shipped Qty", 0) or 0)
    except ValueError:
        shipped_qty = 0
        
    remaining_qty = max(0, ordered_qty - shipped_qty)
    has_tracking_str = str(row.get("Has Tracking", "false")).lower()
    has_tracking = has_tracking_str == "true" or has_tracking_str == "1"
    
    # Date parsing
    eta_date = None
    if logistics_date_str:
        try:
            # Strictly YYYY-MM-DD
            if " " in logistics_date_str:
                logistics_date_str = logistics_date_str.split(" ")[0]
            eta_date = datetime.datetime.strptime(logistics_date_str, "%Y-%m-%d").date()
        except ValueError:
            return _result(True, "data_issue", "invalid_date", "Invalid ETA Date format", 2)

    # 10. Fully resolved: hide from default queue when tracking/shipment covers ordered qty 
    # and no open ETA, backorder, replacement issue, or review flag.
    # If remaining_qty is 0 and no specific review status, hide.
    if remaining_qty == 0 and logistics_status not in ["replaced", "obsolete"]:
        return _result(False, "hidden", "fully_resolved", "Fully resolved", 99)

    # 9. Replaced/Obsolete
    if logistics_status in ["replaced", "obsolete"]:
        reason_label = "Replacement resolution needed" if logistics_status == "replaced" else "Obsolete item needs review"
        return _result(True, "warning", logistics_status, reason_label, 7)

    # Evaluate Dates
    days_to_eta = None
    if eta_date:
        days_to_eta = (eta_date - today).days

    # 1, 2, 3, 4: Backordered rules
    if logistics_status == "backordered":
        if not eta_date:
            return _result(True, "warning", "backordered_no_eta", "Backordered but missing ETA", 3)
        
        if days_to_eta < 0:
            return _result(True, "critical", "backordered_eta_passed", "Backordered ETA has passed", 1)
            
        if 0 <= days_to_eta <= 14:
            sev = "warning" if days_to_eta <= 3 else "info"
            bucket = 5 if days_to_eta == 0 else 6
            return _result(True, sev, "backordered_due_soon", "Backorder ETA due soon", bucket)
            
        if days_to_eta > 14:
            return _result(False, "hidden", "backordered_future", "Future ETA (snoozed)", 8)

    # 5. In stock with future ETA
    if logistics_status == "in_stock" and eta_date and days_to_eta > 0:
        show = days_to_eta <= 14
        bucket = 2 # Data issue
        return _result(show, "data_issue", "in_stock_future_eta", "Marked In Stock but has future ETA — should be Backordered", bucket)

    # 7. ETA date passed and no tracking/shipment resolution
    if eta_date and days_to_eta < 0 and remaining_qty > 0:
        return _result(True, "critical", "eta_passed_no_shipment", "ETA passed with no shipment", 1)

    # 8. Partially shipped order/line with remaining quantity unresolved
    if shipped_qty > 0 and remaining_qty > 0:
        if not eta_date:
            return _result(True, "warning", "partial_missing_eta", "Partial shipment — remaining qty missing ETA", 3)
        if days_to_eta < 0:
            return _result(True, "critical", "partial_eta_passed", "Partial shipment — remaining ETA overdue", 1)
        if 0 <= days_to_eta <= 14:
            return _result(True, "warning", "partial_due_soon", "Partial shipment — remaining ETA due soon", 6)
        
        # If no ETA issue and missing tracking? Grace period applies below.

    # 6. In stock with no ETA and missing tracking (grace period)
    if logistics_status == "in_stock" and not eta_date and not has_tracking and remaining_qty > 0:
        # Check grace period (24 hours) based on order created date
        created_at_str = row.get("Order Created At", "")
        if created_at_str:
            try:
                # "2026-06-24 10:00:00"
                created_dt = datetime.datetime.strptime(created_at_str, "%Y-%m-%d %H:%M:%S")
                # Timezone naive comparison since both are probably local or UTC. 
                # Better: simple naive now.
                now = datetime.datetime.now()
                if (now - created_dt).total_seconds() > 86400: # 24 hours
                    return _result(True, "warning", "in_stock_missing_tracking", "In Stock but missing tracking", 4)
                else:
                    return _result(False, "hidden", "grace_period", "In Stock (Grace Period)", 99)
            except ValueError:
                pass
        
        # If we couldn't parse date, assume grace period expired to be safe
        return _result(True, "warning", "in_stock_missing_tracking", "In Stock but missing tracking", 4)

    # Default Catch-all for active orders that don't fit above
    if remaining_qty > 0 and not has_tracking:
        # Check grace period
        created_at_str = row.get("Order Created At", "")
        if created_at_str:
            try:
                created_dt = datetime.datetime.strptime(created_at_str, "%Y-%m-%d %H:%M:%S")
                now = datetime.datetime.now()
                if (now - created_dt).total_seconds() > 86400:
                    return _result(True, "warning", "needs_tracking", "Needs Tracking", 4)
            except ValueError:
                pass

    return _result(False, "hidden", "no_action_needed", "No action needed currently", 99)


def _result(show: bool, severity: str, reason_code: str, reason_label: str, sort_bucket: int) -> dict:
    return {
        "show": show,
        "severity": severity,
        "reason_code": reason_code,
        "reason_label": reason_label,
        "sort_bucket": sort_bucket,
    }
