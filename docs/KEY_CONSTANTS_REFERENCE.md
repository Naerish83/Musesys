# musesys-mega Key & Constants Reference
_Last updated: 2026-06-17_

This document serves as an exhaustive reference of all custom post meta keys, option keys, order meta keys (HPOS-aware), and named constants discovered across all modules of the `musesys-mega` WordPress plugin.

## Post meta keys
| Key | Module | Description | Type |
|-----|--------|-------------|------|
| `Muse_eam` | RETURN_REQUEST_FORM, wegmans-brand-filter | Product EAM (Enterprise Asset Management) identification number. | string |
| `Muse_partnumber` | wegmans-brand-filter | Product manufacturer part number. | string |
| `_asset_manual_files` | muse-asset-profile | Array of uploaded asset manual file attachments. | serialized array |
| `_install_store_count` | mu-plugins/muse-tracking-core.php | Count of unique installation stores associated with the order. | integer |
| `_install_stores` | mu-plugins/muse-tracking-core.php, musesys-account-dashboard | Stores data list representing installation locations. | serialized array |
| `_muse_contact_phone` | muse-checkout-orchestrator | Customer contact telephone number associated with the order. | string |
| `_muse_install_store` | musesys-account-dashboard | Primary install store number. | string |
| `_muse_manual_file` | musesys-manual-search | Local file path metadata for manual attachment. | string |
| `_muse_manual_model_raw` | musesys-manual-search | Extracted raw model name from the manual file. | string |
| `_muse_manual_url` | musesys-manual-search | Public download/viewing URL for the manual file. | string |
| `_muse_pr_id` | mu-plugins/muse-tracking-core.php, muse-email-manager, muse-product-request | B2B Product Request display ID (e.g. 'PR-1002'). | string |
| `_muse_pr_status` | muse-exporter, muse-product-request, musesys-reports | Lifecycle status of the Product Request (e.g. 'pending', 'completed'). | string |
| `_muse_pr_type` | muse-exporter, muse-product-request, musesys-reports | Category of Product Request (e.g. 'request', 'quote', 'special'). | string |
| `_muse_return_images` | RETURN_REQUEST_FORM | URLs of uploaded images for return request confirmation. | serialized array |
| `_muse_return_items` | RETURN_REQUEST_FORM | List of items included in the return request. | serialized array |
| `_muse_return_order_id` | RETURN_REQUEST_FORM | WooCommerce order ID associated with the return. | integer |
| `_muse_return_order_number` | RETURN_REQUEST_FORM | WooCommerce order number associated with the return. | string |
| `_muse_return_primary_work_order` | RETURN_REQUEST_FORM | Primary work order number string for the return request. | string |
| `_muse_return_reason` | RETURN_REQUEST_FORM | Customer-provided reason text for the return. | string |
| `_muse_return_submitted_at` | RETURN_REQUEST_FORM | MySQL datetime of return submission. | string |
| `_muse_return_user_id` | RETURN_REQUEST_FORM | WordPress User ID of the customer who submitted the return. | integer |
| `_muse_return_work_orders` | RETURN_REQUEST_FORM | Work orders array linked to the return request. | serialized array |
| `_muse_scwo_number` | muse-product-request | Single Consolidated Work Order number for product request. | string |
| `_muse_ship_store` | muse-product-request | Direct order zero-padded 3-digit ship-to store number. | string |
| `_muse_ship_to` | muse-product-request | Shipping destination store description or number. | string |
| `_muse_shipping_speed` | muse-product-request | Selected shipment delivery speed priority. | string |
| `_muse_store_global` | mu-plugins/muse-tracking-core.php, muse-checkout-orchestrator, muse-wo-consolidation, musesys-account-dashboard, musesys-welcome-email | Global install store number. | string |
| `_muse_track_primary` | muse-email-manager | Primary shipment tracking number. | string |
| `_muse_track_status` | muse-email-manager | Shipment tracking delivery status. | string |
| `_muse_wo_global` | mu-plugins/muse-tracking-core.php, muse-checkout-orchestrator, musesys-account-dashboard | Master/Global work order number. | string |
| `_musesys_customer_email` | muse-email-manager, muse-exporter, muse-product-request, musesys-reports | Email address of the customer for product request notifications. | string |
| `_musesys_install_store` | musesys-account-dashboard | Legacy install store number fallback. | string |
| `_musesys_pr_product_id` | muse-product-request | WC Product ID associated with product request. | integer |
| `_musesys_product_items` | muse-email-manager, muse-exporter, muse-product-request, musesys-reports | Serialized array of requested products and details. | serialized array |
| `_musesys_work_order` | musesys-account-dashboard | Legacy work order number fallback. | string |
| `_order_token` | muse-product-request | Authentication/verification token for B2B product request order placement. | string |
| `_primary_install_store` | mu-plugins/muse-tracking-core.php | Primary install store number. | string |
| `_primary_work_order` | RETURN_REQUEST_FORM, mu-plugins/muse-tracking-core.php, muse-product-request | Primary work order display string. | string |
| `_searchable_wo` | mu-plugins/muse-tracking-core.php | Index of work order number for search lookup optimization. | string |
| `_tracking_display` | muse-product-request | Formatted shipment tracking number representation. | string |
| `_tracking_number` | muse-product-request | Shipment tracking number. | string |
| `_tracking_type` | muse-product-request | Carrier or shipment type tracking code. | string |
| `_trx_order_number` | mu-plugins/muse-tracking-core.php, muse-email-manager | Transaction reference number. | string |
| `_work_order_count` | mu-plugins/muse-tracking-core.php, muse-product-request, musesys-account-dashboard | Total count of work orders associated with the post/order. | integer |
| `_work_orders` | mu-plugins/muse-tracking-core.php, mu-plugins/muse-wo-migration.php, muse-email-manager, muse-product-request, muse-wo-consolidation, musesys-account-dashboard, musesys-welcome-email | Associated work orders data array. | serialized array |
| `brand` | muse-exporter, muse-product-request, musesys-reports | Requested product brand. | string |
| `fulfilled_order_id` | muse-product-request | WooCommerce order ID generated upon product request fulfillment. | integer |
| `model` | muse-exporter, muse-product-request, musesys-reports | Requested product model. | string |
| `muse_global_work_order` | musesys-account-dashboard | Legacy global work order fallback. | string |
| `part` | muse-product-request | Requested product part description. | string |
| `self::$PARTNO_META` | Musesys-order-ops | Product part number (resolves to 'muse_part_number'). | string |
| `self::META_CHEMS` | musesys-activity-tracker | Prop 65 warning chemicals (resolves to '_muse_prop65_chems'). | string |
| `self::META_ENABLED` | musesys-activity-tracker | Prop 65 warning status (resolves to '_muse_prop65_enabled'). | string |
| `self::META_MESSAGE` | musesys-activity-tracker | Prop 65 custom warning message (resolves to '_muse_prop65_message'). | string |
| `self::VMETA_CHEMS` | musesys-activity-tracker | Prop 65 variation warning chemicals (resolves to '_muse_prop65_chems_var'). | string |
| `self::VMETA_ENABLED` | musesys-activity-tracker | Prop 65 variation warning status (resolves to '_muse_prop65_enabled_var'). | string |
| `self::VMETA_MESSAGE` | musesys-activity-tracker | Prop 65 variation warnings custom message (resolves to '_muse_prop65_message_var'). | string |

## Option keys
| Key | Module | Description | Default |
|-----|--------|-------------|---------|
| `MUSESYS_ADDR_STORES` | musesys_shipping_custom | Option key for store addresses (resolves to 'musesys_addresses_stores'). | [] |
| `MUSESYS_ADDR_WAREHOUSES` | musesys_shipping_custom | Option key for warehouse addresses (resolves to 'musesys_addresses_warehouses'). | [] |
| `MUSE_GUARD_OPTION_MANUAL_BLACKLIST` | musesys-login-guard | IP blacklist for login guard (resolves to 'muse_guard_ip_blacklist'). | '' |
| `MUSE_GUARD_OPTION_USERNAME_BLOCKLIST` | musesys-login-guard | Blocked usernames for authentication guard (resolves to 'muse_guard_username_blocklist'). | '' |
| `MUSE_GUARD_OPTION_VERSION` | musesys-login-guard | Stored version of login guard module (resolves to 'muse_guard_version'). | none |
| `MUSE_GUARD_OPTION_WHITELIST` | musesys-login-guard | IP whitelist for login guard (resolves to 'muse_guard_ip_whitelist'). | '' |
| `admin_email` | RETURN_REQUEST_FORM, muse-product-request, musesys-login-guard | WordPress standard administrator email (read-only usage). | none |
| `date_format` | muse-logistics-manager | WordPress standard date format (read-only usage). | none |
| `home` | mu-plugins/local-by-flywheel-live-link-helper.php | WordPress standard home URL (read-only usage). | none |
| `musesys_addresses_json` | musesys-favdebug | Raw address import database JSON string. | '' |
| `musesys_addresses_stores` | muse-product-request, musesys_address_api, musesys_favorites | List of normalized store addresses. | [] |
| `musesys_addresses_warehouses` | musesys_address_api, musesys_favorites | List of normalized warehouse addresses. | [] |
| `musesys_endpoints_flushed` | musesys-account-dashboard | Indicates if custom WC account endpoints have been flushed. | not set |
| `musesys_locations_json` | musesys_favorites | Combined JSON representation of all locations. | [] |
| `musesys_report_recipients` | musesys-reports | Email addresses of report recipients. | array of defaults |
| `musesys_reports_active` | muse-exporter, musesys-reports | Status of the reports module. | 'yes' |
| `musesys_store_addresses` | musesys-favdebug | Addresses of store locations (debug utility). | [] |
| `musesys_warehouse_addresses` | musesys-favdebug | Addresses of warehouse locations (debug utility). | [] |
| `self::OPTION_VER` | musesys-activity-tracker-legacy | Version indicator of legacy activity tracker (resolves to 'musesys_activity_tracker_legacy_ver'). | none |

## Order meta keys (HPOS)
| Key | Module | Description | Type |
|-----|--------|-------------|------|
| `EAM #` | RETURN_REQUEST_FORM | Product EAM number line item meta label. | string |
| `Install Store #` | mu-plugins/muse-tracking-core.php, muse-exporter, muse-product-request | Store number line item meta label. | string |
| `MUSE_IS_META_KEY` | muse-checkout-orchestrator | Global install store machine meta key (resolves to '_muse_store_global'). | string |
| `MUSE_IS_META_LABEL` | muse-checkout-orchestrator | Global install store user-friendly meta label (resolves to 'Install Store #'). | string |
| `MUSE_WO_FIELD_KEY` | musesys-workorder-column | Global work order meta key (resolves to '_muse_wo_global'). | string |
| `MUSE_WO_ITEM_META_NOTE` | muse-checkout-orchestrator, musesys-workorder-column | Work order line note label (resolves to '_muse_wo_note'). | string |
| `MUSE_WO_ITEM_META_WO` | muse-checkout-orchestrator, musesys-workorder-column | Work order line number label (resolves to '_muse_wo_line'). | string |
| `Prop 65` | musesys-activity-tracker | Prop 65 warning text on order line items. | string |
| `WO Number` | mu-plugins/muse-tracking-core.php, muse-email-manager, muse-exporter, muse-product-request | Work order number line item meta label. | string |
| `_install_store` | mu-plugins/muse-tracking-core.php | Legacy install store line item meta fallback. | string |
| `_install_stores` | muse-exporter | Serialized array of install stores. | serialized array |
| `_is_drop_ship` | order-fields | Flag indicating if order is a drop-ship delivery. | string / bool |
| `_line_install_store` | mu-plugins/muse-tracking-core.php | Legacy line install store fallback. | string |
| `_line_work_order` | mu-plugins/muse-tracking-core.php | Legacy line work order fallback. | string |
| `_muse_global_install_store` | muse-exporter | Legacy global install store key. | string |
| `_muse_global_work_order` | muse-exporter | Legacy global work order key. | string |
| `_muse_install_store` | muse-exporter, muse-product-request | Order-level install store number. | string |
| `_muse_lineitem_workorder` | muse-exporter | Legacy line item work order key. | string |
| `_muse_logistics_date` | muse-email-manager, muse-logistics-manager, musesys-reports | Shipping logistics target date. | string |
| `_muse_logistics_status` | muse-email-manager, muse-logistics-manager, musesys-reports | Logistics lifecycle status of order. | string |
| `_muse_scwo_number` | muse-product-request | Single Consolidated Work Order number. | string |
| `_muse_ship_store` | muse-product-request | Padded store number for direct order delivery. | string |
| `_muse_ship_to` | muse-product-request | Shipping destination identifier. | string |
| `_muse_shipments` | muse-email-manager, muse-exporter, musesys-reports | JSON array of shipment records (also via self::META_SHIPMENTS). | JSON string |
| `_muse_shipping_speed` | muse-product-request | Selected shipment delivery speed. | string |
| `_muse_source_pr_id` | muse-product-request | Product Request ID that generated this order. | integer |
| `_muse_store_global` | mu-plugins/muse-tracking-core.php, muse-exporter, muse-product-request | Global install store number. | string |
| `_muse_store_line` | muse-exporter | Line item install store number. | string |
| `_muse_track_primary` | muse-exporter | Primary shipment tracking number. | string |
| `_muse_wo_global` | RETURN_REQUEST_FORM, core, muse-aq-exporter, muse-email-manager, muse-exporter, muse-product-request | Master/Global work order number. | string |
| `_muse_wo_line` | mu-plugins/muse-tracking-core.php, muse-email-manager, muse-exporter, muse-product-request | Line item work order number. | string |
| `_muse_workorder` | muse-exporter | Legacy global work order key. | string |
| `_musesys_address_1` | musesys_checkout_glue | Store shipping address line 1. | string |
| `_musesys_address_id` | musesys_checkout_glue | Database reference ID of selected store address. | string |
| `_musesys_city` | musesys_checkout_glue | Store shipping city. | string |
| `_musesys_install_store` | muse-exporter, musesys_checkout_glue | Selected install store number at checkout. | string |
| `_musesys_phone` | musesys_checkout_glue | Selected store contact phone. | string |
| `_musesys_shipping_speed` | musesys_checkout_glue | Selected shipping speed at checkout. | string |
| `_musesys_source` | musesys_checkout_glue | Source location type ('store' or 'warehouse'). | string |
| `_musesys_state` | musesys_checkout_glue | Store shipping state. | string |
| `_musesys_store_name` | musesys_checkout_glue | Selected store/location name. | string |
| `_musesys_store_number` | musesys_checkout_glue | Selected store/location number. | string |
| `_musesys_technician_phone` | Musesys-order-ops | Technician telephone number. | string |
| `_musesys_work_order` | musesys_checkout_glue | Selected work order number at checkout. | string |
| `_musesys_zip` | musesys_checkout_glue | Store shipping zip code. | string |
| `_primary_work_order` | muse-exporter | Legacy primary work order key. | string |
| `_purchase_order_number` | order-fields | Purchase order number. | string |
| `_purchase_price` | core, muse-aq-exporter | Product custom cost/purchase price (accessed on $product). | float |
| `_tracking_display` | muse-product-request | Formatted shipment tracking number. | string |
| `_tracking_number` | muse-email-manager, muse-exporter, muse-product-request | Shipment tracking number. | string |
| `_tracking_type` | muse-product-request | Carrier or shipment type tracking code. | string |
| `_trx_order_number` | muse-exporter, musesys-reports, order-fields | Transaction reference number. | string |
| `_work_order` | mu-plugins/muse-tracking-core.php | Legacy line item work order fallback. | string |
| `_work_orders` | muse-email-manager, muse-exporter | Associated work orders array. | serialized array |
| `additional_install_store_number` | muse-exporter | Additional/install store number (also via self::$INSTALL_STORE_KEY). | string |
| `eam_num` | RETURN_REQUEST_FORM | Legacy EAM number line item meta key. | string |
| `muse_global_work_order` | muse-email-manager, muse-exporter | Legacy global work order fallback. | string |
| `self::$INSTALL_STORE_KEY` | Musesys-order-ops | Additional/install store number (resolves to 'additional_install_store_number'). | string |
| `self::META_LATEST_TRACKING` | musesys-partial-ship | Latest tracking number (resolves to '_muse_ship_track' in active / '_muse_track_primary' in backup). | string |
| `self::META_SHIPMENTS` | musesys-partial-ship | Shipment records JSON (resolves to '_muse_shipments'). | JSON string |
| `self::META_SHIPPING_STATUS` | musesys-partial-ship | Shipping delivery status (resolves to '_muse_track_status'). | string |

## Constants
| Name | Value | Module | Purpose |
|------|-------|--------|---------|
| `CAP_VIEW_PRICES` | `'manage_woocommerce'` | mu-plugins/musesys-hide-prices.php | Permission capability required to view prices. |
| `CPT` | `'muse_return'` | RETURN_REQUEST_FORM | Custom Post Type identifier for return requests ('muse_return'). |
| `DB_ACTIVITY` | `'musesys_activity_log'` | musesys-activity-tracker-legacy | Database table name for activity log. |
| `DB_FLAGS` | `'musesys_login_flags_legacy'` | musesys-activity-tracker-legacy | Database table name for legacy login flags. |
| `DB_LOGINS` | `'musesys_login_log'` | musesys-activity-tracker-legacy | Database table name for login logs. |
| `DEFAULT_MSG` | `'WARNING: This product can expose you to chemicals including {chemicals}, which are known to the State of California to cause cancer and birth defects or other reproductive harm. For more information, visit www.P65Warnings.ca.gov.'` | musesys-activity-tracker | Default warning template message for Prop 65 warnings. |
| `FORM_SHORTCODE` | `'[muse_product_request_form]'` | musesys-no-results-request-injector | WordPress shortcode string to embed product request form. |
| `GE_BRAND_PARAM` | `'brand'` | wegmans-brand-filter | Query parameter name used for brand filtering. |
| `GE_BRAND_TAXONOMY` | `'product_brand'` | wegmans-brand-filter | WooCommerce taxonomy identifier for product brands. |
| `MAX_TOTAL_UPLOAD_BYTES` | `20971520` | muse-product-request | Maximum total file upload size allowed (20 MB). |
| `META_CHEMS` | `'_muse_prop65_chems'` | musesys-activity-tracker | Post meta key for Prop 65 warning chemicals. |
| `META_ENABLED` | `'_muse_prop65_enabled'` | musesys-activity-tracker | Post meta key for Prop 65 warning status. |
| `META_LATEST_TRACKING` | `'_muse_ship_track'` | musesys-partial-ship | Meta key for latest tracking number. |
| `META_MESSAGE` | `'_muse_prop65_message'` | musesys-activity-tracker | Post meta key for custom Prop 65 warning message. |
| `META_SHIPMENTS` | `'_muse_shipments'` | musesys-partial-ship | Meta key for JSON shipments array. |
| `META_SHIPPING_STATUS` | `'_muse_track_status'` | musesys-partial-ship | Meta key for order tracking status. |
| `MUSESYS_ADDR_OTHER` | `'musesys_addresses_other'` | musesys_shipping_custom | Option key for other addresses. |
| `MUSESYS_ADDR_STORES` | `'musesys_addresses_stores'` | musesys_shipping_custom | Option key for store addresses. |
| `MUSESYS_ADDR_WAREHOUSES` | `'musesys_addresses_warehouses'` | musesys_shipping_custom | Option key for warehouse addresses. |
| `MUSESYS_FAVORITES_MAX` | `15` | musesys_address_api | Maximum number of favorite locations allowed (default: 15). |
| `MUSESYS_MANUALS_LOADED` | `true` | musesys-manual-search | Flag indicating that manuals module is loaded. |
| `MUSESYS_MEGA_PATH` | `plugin_dir_path( __FILE__` | core | Absolute path to plugin base directory. |
| `MUSESYS_MEGA_URL` | `plugin_dir_url( __FILE__` | core | URL to plugin base directory. |
| `MUSESYS_REPORTS_PATH` | `plugin_dir_path(__FILE__` | muse-exporter, musesys-reports | Absolute path to reports module. |
| `MUSESYS_REPORTS_URL` | `plugin_dir_url(__FILE__` | muse-exporter, musesys-reports | URL to reports module. |
| `MUSESYS_REPORTS_VERSION` | `'1.0.6'` | muse-exporter, musesys-reports | Version indicator of reports module. |
| `MUSESYS_SELECTOR_URL` | `musesys_selector_page_url(` | musesys_selector | URL of the store selector page. |
| `MUSE_CO_GUARD` | `true` | muse-checkout-orchestrator | Lock variable preventing unauthorized execution of orchestrator. |
| `MUSE_CO_HIDE` | `[ 'shipping_company', 'shipping_address_2' ]` | muse-checkout-orchestrator | Checkout fields list to hide during checkout. |
| `MUSE_FAV_PROD_KEY` | `'musesys_favorite_products'` | favorites-bridge | User meta key for storing favorite products. |
| `MUSE_GASKET_DRAWER` | `235182` | custom-gaskets | WC Product ID reference for drawer gaskets. |
| `MUSE_GASKET_UNDERCOUNTER` | `235184` | custom-gaskets | WC Product ID reference for undercounter gaskets. |
| `MUSE_GASKET_UPRIGHT` | `235185` | custom-gaskets | WC Product ID reference for upright gaskets. |
| `MUSE_GASKET_WALKIN` | `235186` | custom-gaskets | WC Product ID reference for walk-in gaskets. |
| `MUSE_GUARD_MAX_ATTEMPTS` | `3` | musesys-login-guard | Max logins attempt limit before IP lockout. |
| `MUSE_GUARD_MIN_LOGIN_SECONDS` | `2` | musesys-login-guard | Throttle delay between consecutive login requests. |
| `MUSE_GUARD_OPTION_MANUAL_BLACKLIST` | `'muse_guard_ip_blacklist'` | musesys-login-guard | Option name for IP manual blacklist. |
| `MUSE_GUARD_OPTION_USERNAME_BLOCKLIST` | `'muse_guard_username_blocklist'` | musesys-login-guard | Option name for blocked usernames. |
| `MUSE_GUARD_OPTION_VERSION` | `'muse_guard_version'` | musesys-login-guard | Option name for login guard module version. |
| `MUSE_GUARD_OPTION_WHITELIST` | `'muse_guard_ip_whitelist'` | musesys-login-guard | Option name for IP whitelist. |
| `MUSE_GUARD_TRANSIENT_ALERT_PREFIX` | `'muse_guard_alert_'` | musesys-login-guard | Transient prefix for alert logs. |
| `MUSE_GUARD_TRANSIENT_FAIL_PREFIX` | `'muse_guard_fail_'` | musesys-login-guard | Transient prefix for failure counts. |
| `MUSE_GUARD_TRANSIENT_FORM_PREFIX` | `'muse_guard_form_'` | musesys-login-guard | Transient prefix for lock forms. |
| `MUSE_GUARD_TRANSIENT_SUSPICION_PREFIX` | `'muse_guard_suspicion_'` | musesys-login-guard | Transient prefix for suspicious behavior tracking. |
| `MUSE_GUARD_VERSION` | `'2.0.0'` | musesys-login-guard | Version number of login guard module. |
| `MUSE_IS_CART_KEY` | `'muse_install_store'` | muse-checkout-orchestrator | Cart session identifier for install store. |
| `MUSE_IS_FIELD_ROOT` | `'musesys_install_store'` | muse-checkout-orchestrator | Root field identifier for install store data. |
| `MUSE_IS_GLOBAL_FIELD_KEY` | `'muse_global_install_store'` | muse-checkout-orchestrator | POST field key for global install store. |
| `MUSE_IS_GLOBAL_TOGGLE_KEY` | `'muse_global_install_store_toggle'` | muse-checkout-orchestrator | POST field key for global install store toggle. |
| `MUSE_IS_LABEL` | `'Install Store #'` | muse-checkout-orchestrator | Human-readable label for install stores. |
| `MUSE_IS_MAX` | `200` | muse-checkout-orchestrator | Maximum allowed store number. |
| `MUSE_IS_META_KEY` | `'_muse_store_global'` | muse-checkout-orchestrator | Machine meta key for order line items. |
| `MUSE_IS_META_LABEL` | `'Install Store #'` | muse-checkout-orchestrator | Human label for order line items. |
| `MUSE_OH_DATA_FILE` | `dirname(ABSPATH) . '/private_html/muse-order-history/wegmans_order_history.json'` (overridable in `wp-config.php`) | muse-order-history, musesys-account-dashboard | File path to the order history JSON (customer PII). Must be outside the web root; the reader refuses paths under `ABSPATH`/`WP_CONTENT_DIR`. |
| `MUSE_OH_DIR` | `plugin_dir_path( __FILE__` | muse-order-history, musesys-account-dashboard | Path to the order history module. |
| `MUSE_OH_URL` | `plugin_dir_url( __FILE__` | muse-order-history, musesys-account-dashboard | URL of the order history module. |
| `MUSE_OH_VERSION` | `'1.0.0'` | muse-order-history, musesys-account-dashboard | Version number of order history module. |
| `MUSE_WO_FIELD_KEY` | `'muse_global_work_order'` | muse-checkout-orchestrator, musesys-workorder-column | Global work order field/meta key. |
| `MUSE_WO_ITEM_META_NOTE` | `'Line Note'` | muse-checkout-orchestrator, musesys-workorder-column | Work order line note label. |
| `MUSE_WO_ITEM_META_WO` | `'WO Number'` | muse-checkout-orchestrator, musesys-workorder-column | Work order line number label. |
| `MUSE_WO_ITEM_NOTE_KEY` | `'muse_line_note'` | muse-checkout-orchestrator, musesys-workorder-column | Cart session key for line notes. |
| `MUSE_WO_PROPAGATE_TO_ITEMS` | `true` | muse-checkout-orchestrator, musesys-workorder-column | Status flag to copy global WO# to line items. |
| `MUSE_WO_REGEX` | `'/^[A-Z0-9-]{4,20}$/i'` | muse-checkout-orchestrator, musesys-workorder-column | Regular expression pattern for validating work orders. |
| `MUSE_WO_REQUIRE_TOGGLE` | `true` | muse-checkout-orchestrator, musesys-workorder-column | Boolean toggle requirement for global WO mode. |
| `MUSE_WO_TOGGLE_KEY` | `'muse_global_work_order_toggle'` | muse-checkout-orchestrator, musesys-workorder-column | Form checkbox toggle for global WO mode. |
| `NAMESPACE` | `'musesys/api/v1'` | musesys-api | API endpoint route namespace. |
| `NONCE` | `'muse_return_ajax'` | RETURN_REQUEST_FORM | Ajax nonce name for return requests. |
| `OPTION_VER` | `'musesys_activity_tracker_legacy_ver'` | musesys-activity-tracker-legacy | Option key for storing the legacy activity tracker version. |
| `PR_ID_PREFIX` | `'PR-'` | muse-product-request | Prefix string for custom product request ID display. |
| `PR_STATUS_AWAITING_CONFIRM` | `'awaiting_confirm'` | muse-product-request | Lifecycle status constant for awaiting confirmation. |
| `PR_STATUS_COMPLETED` | `'completed'` | muse-product-request | Lifecycle status constant for completed requests. |
| `PR_STATUS_FULFILLED` | `'fulfilled'` | muse-product-request | Lifecycle status constant for fulfilled requests. |
| `PR_STATUS_PENDING` | `'pending'` | muse-product-request | Lifecycle status constant for pending requests. |
| `PR_STATUS_PROCESSING` | `'processing'` | muse-product-request | Lifecycle status constant for processing requests. |
| `PR_STATUS_REJECTED` | `'rejected'` | muse-product-request | Lifecycle status constant for rejected requests. |
| `REST_NS` | `'musesys/v1'` | musesys-partial-ship | REST API route namespace. |
| `SEARCH_CACHE_TTL` | `300` | RETURN_REQUEST_FORM | Cache lifespan (seconds) for return requests lookup. |
| `SEARCH_INDEX_LIMIT` | `250` | RETURN_REQUEST_FORM | Maximum number of search results returned. |
| `SEARCH_MIN_LENGTH` | `5` | RETURN_REQUEST_FORM | Minimum query length for return requests lookup. |
| `SHOW_PR_ORDER_LINK` | `true` | muse-product-request | Toggle link display to orders in admin request view. |
| `VERSION` | `'1.1.0-legacy'` | Musesys-order-ops, musesys-activity-tracker-legacy | Version identifier constant. |
| `VMETA_CHEMS` | `'_muse_prop65_chems_var'` | musesys-activity-tracker | Post meta key for Prop 65 variation warning chemicals. |
| `VMETA_ENABLED` | `'_muse_prop65_enabled_var'` | musesys-activity-tracker | Post meta key for Prop 65 variation warning status. |
| `VMETA_MESSAGE` | `'_muse_prop65_message_var'` | musesys-activity-tracker | Post meta key for custom variation Prop 65 message. |

## Notes

### 2026-07-09 Wegmans Order Editor Additions
- **Module**: `modules/musesys-order-editor/index.php`
- **Admin submenu**: `admin.php?page=musesys-order-editor` under the existing MuseSys admin menu.
- **Admin bar node**: `musesys-order-editor`, linking to the Wegmans Order Editor settings page.
- **Option key**: `musesys_order_editor_settings` stores global enabled state, allowed role slugs, allowed user IDs, and the default customer note template.
- **Order action hook**: `admin_post_musesys_order_editor_action` handles add item, remove item, quantity update, metadata update, and customer note actions.
- **Edited order meta keys**: `_muse_wo_global`, `_muse_store_global`, `_muse_ship_store`, `_muse_ship_to`.
- **Completion lock rule**: the editor locks only when all three conditions are true: WooCommerce status is `completed`, `_trx_order_number` is populated, and `_muse_shipments` contains at least one non-empty `tracking_numbers` entry from the shipping manager.
- **Shipping fields updated through WooCommerce order setters**: shipping company, address 1, address 2, city, state, postcode, and phone when supported by the installed WooCommerce version.
- **Audit behavior**: every accepted mutation appends a private WooCommerce order note naming the WP User ID and exact old/new value or line-item action; customer notes are added only through the explicit customer note action.

### 1. Duplication & Code Redundancy
- **musesys-reports module**: This module exists in three different places:
  - `modules/musesys-reports/` (Version `1.0.7`)
  - `musesys-reports/` at root (Version `1.0.6`)
  - `modules/muse-exporter/musesys-reports/` (Version `1.0.6`)
  - Redundant copies in `safe/musesys-mega/...`
  These folders contain duplicate files and differing versions of the reports logic, presenting a major maintenance risk.
- **muse-wo-consolidation module**: The file `muse-wo-consolidation.php` is duplicated:
  - `modules/muse-wo-consolidation/muse-wo-consolidation.php`
  - `modules/musesys-welcome-email/muse-wo-consolidation/muse-wo-consolidation.php`
- **muse-order-history module**: The file `muse-order-history.php` is duplicated:
  - `modules/muse-order-history/muse-order-history.php`
  - `modules/musesys-account-dashboard/muse-order-history/muse-order-history.php`

### 2. Functional & Constant Discrepancies
- **META_LATEST_TRACKING discrepancy**:
  - In `modules/musesys-partial-ship/index.php`, `META_LATEST_TRACKING` resolves to `_muse_ship_track`.
  - In `safe/musesys-mega/modules/musesys-partial-ship/index.php`, it resolves to `_muse_track_primary`.
- **MUSE_WO_FIELD_KEY discrepancy**:
  - In `modules/muse-checkout-orchestrator/musesys-orchestrator.php`, `MUSE_WO_FIELD_KEY` is a class constant with value `'muse_global_work_order'` (used for the form field name).
  - In `modules/musesys-workorder-column/musesys-workorder-column.php`, `MUSE_WO_FIELD_KEY` is a global PHP constant with value `'_muse_wo_global'` (used for order meta lookup).
  This naming conflict creates high developer confusion.
- **MUSE_WO_REGEX discrepancies**:
  - Checkout Orchestrator validates WO using `/^[A-Z0-9-]{4,20}$/i` (flexible 4-20 chars).
  - Work Order Column assumes a strict pattern of `/^(\d{7}|\d{9})$/` (exactly 7 or 9 digits).
  Any alphanumeric work order entered at checkout (like `WO-1234`) will pass orchestrator validation but fail workorder column indexing/display logic.
- **Other Constant Reuses**:
  Several other constants show similar human-facing vs machine-facing reuse issues between the checkout orchestrator and the workorder column:
  - `MUSE_WO_ITEM_META_WO` (human: `'WO Number'` vs machine: `'_muse_wo_line'`)
  - `MUSE_WO_ITEM_META_NOTE` (human: `'Line Note'` vs machine: `'_muse_wo_note'`)
  - `MUSE_WO_ITEM_NOTE_KEY` (human: `'muse_line_note'` vs machine: `'_muse_wo_note'`)
  - `MUSE_WO_PROPAGATE_TO_ITEMS` (human: `true` vs machine: `'_muse_wo_global_propagate'`)
  - `MUSE_WO_REQUIRE_TOGGLE` (human: `true` vs machine: `'_muse_wo_global_required'`)
  - `MUSE_WO_TOGGLE_KEY` (human: `'muse_global_work_order_toggle'` vs machine: `'_muse_wo_global_toggle'`)

### 3. Deprecated & Legacy Keys
The codebase maintains multiple backwards-compatibility checks:
- `_musesys_work_order` and `muse_global_work_order` are legacy keys for `_muse_wo_global` (Work Order number).
- `_musesys_install_store` is a legacy fallback for `_muse_store_global` (Install Store number).
- `_primary_work_order` is used as a fallback for `_muse_wo_global` in several template files.
- `_tracking_number` and `_tracking_display` are fallbacks for shipment tracking data.

### 4. Dynamic & Variable Keys (Omitted from Tables)
The following variables were detected in key arguments and represent dynamically resolved metadata:
- `$key`, `$meta_key`, `$opt_key`, `$option_name`, `$this->opt_key`
- `$payload['dedupe_key']`, `$tracking_key`, `$trx_key`
