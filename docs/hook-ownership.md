# MuseSys General — Hook & Meta Ownership

## Order Reference Seam
- `musesys_order_reference` (filter, args: `$value, $order`) — Returns tenant-specific order reference string (e.g., Wegmans Work Order #). Core modules read references only through this filter, never `_muse_wo_*` directly.
- `musesys_order_reference_label` (filter, args: `$label, $order`) — Returns display label for the order reference (defaults to `'Order Reference'`).

## Checkout & Locations (`modules/musesys-checkout/`, `modules/musesys-locations/`)
- Classic checkout shortcode (`[woocommerce_checkout]`) is required; admin notice warns if `wp:woocommerce/checkout` block is present.
- `template_redirect` — Blocks checkout when user lacks site membership, assigned guide, or active locations.
- `woocommerce_checkout_before_customer_details` — Renders payment terms banner and ship-to location `<select>` (`musesys_location_id`).
- `woocommerce_checkout_fields` (priority 20) — Populates & locks `shipping_*` fields (`readonly`), adds `musesys_shipping_speed` when configured on the guide.
- `woocommerce_checkout_process` — Validates `musesys_location_id` against user's active guide and `musesys_shipping_speed` if required.
- `woocommerce_checkout_posted_data` (priority 20) — Overwrites `shipping_*` from DB location record and `billing_*` from guide's configured billing profile.
- `woocommerce_before_calculate_totals` (priority 15) — Syncs `WC()->customer` shipping address from resolved location.
- `woocommerce_checkout_create_order` (priority 10) — Persists `_musesys_guide_id`, `_musesys_guide_name`, `_musesys_location_id`, `_musesys_location_code`, `_musesys_location_group`, `_musesys_location_label`, `_musesys_payment_terms`, `_musesys_shipping_speed`, and address snapshot meta.

## User Profile (`modules/musesys-user-profile/`)
- Single owner of `contact_phone` user meta (`show_user_profile`, `edit_user_profile`, `personal_options_update`, `edit_user_profile_update`, `woocommerce_checkout_get_value`).

## Prop 65 (`modules/musesys-prop65/`)
- Owns `_muse_prop65_enabled`, `_muse_prop65_chems`, `_muse_prop65_message` (product) and `_muse_prop65_enabled_var`, `_muse_prop65_chems_var`, `_muse_prop65_message_var` (variation).
- Frontend display and order-item meta attachment are gated by the active guide's `prop65_enabled` flag (off for Wegmans, available for Dunkin).

## Catalog Options (`modules/musesys-catalog-options/`)
- `wp` — Disables WooCommerce related products when the active guide's `hide_related_products` flag is enabled.
