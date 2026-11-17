<?php
if (!defined('ABSPATH')) exit;

class MuseSys_Reports_Orders_Report {

    /**
     * Get all flagged open orders
     */
    public function get_flagged_orders() {
        $now = new DateTime('now', new DateTimeZone('America/New_York'));

        $orders = wc_get_orders([
            'limit'        => -1,
            'status'       => ['processing', 'on-hold', 'pending', 'partially-shipped'],
        ]);

        $critical = [];
        $warning  = [];
        $healthy  = 0;

        foreach ($orders as $order) {
            $created  = $order->get_date_created();
            $age_days = $now->diff($created)->days;

            // Core order meta
            $trx_number = $order->get_meta('_trx_order_number', true);
            $shipments  = json_decode($order->get_meta('_muse_shipments', true), true);
            if (!is_array($shipments)) $shipments = [];

            // Logistics meta (from Muse Logistics Manager)
            $logistics_status = $order->get_meta('_muse_logistics_status', true) ?: 'in_stock';
            $logistics_date   = $order->get_meta('_muse_logistics_date', true);

            // Parse estimated date if present
            $estimated_date = null;
            if (!empty($logistics_date)) {
                $estimated_date = DateTime::createFromFormat('Y-m-d', $logistics_date, new DateTimeZone('America/New_York'));
            }

            // Backorder bypass: valid future date = suppress from report entirely
            if (
                $logistics_status === 'backordered' &&
                $estimated_date !== false &&
                $estimated_date !== null &&
                $estimated_date > $now
            ) {
                $healthy++;
                continue;
            }

            // Check flag status, passing logistics context
            [$flag, $flag_reason] = $this->check_order_flag($trx_number, $shipments, $age_days, $logistics_status, $estimated_date, $now);

            if ($flag === 'critical') {
                $critical[] = $this->format_order_data($order, $trx_number, $shipments, $age_days, $logistics_status, $logistics_date, $estimated_date, $now, $flag_reason);
            } elseif ($flag === 'warning') {
                $warning[]  = $this->format_order_data($order, $trx_number, $shipments, $age_days, $logistics_status, $logistics_date, $estimated_date, $now, $flag_reason);
            } else {
                $healthy++;
            }
        }

        return [
            'timestamp'     => $now->format('l, F j, Y \a\t g:i A T'),
            'critical'      => $critical,
            'warning'       => $warning,
            'healthy_count' => $healthy,
        ];
    }

    /**
     * Determine if order should be flagged.
     * Logistics context is evaluated before standard age-based rules.
     */
    private function check_order_flag($trx_number, $shipments, $age_days, $logistics_status, $estimated_date, $now) {

        // Backordered with no date = process violation, surface it
        if ($logistics_status === 'backordered' && empty($estimated_date)) {
            if ($age_days >= 10) return ['critical', 'backorder_no_date'];
            if ($age_days >= 3)  return ['warning', 'backorder_no_date'];
            return ['healthy', null];
        }

        // Backordered with a past date = expected date has elapsed, surface it
        if ($logistics_status === 'backordered' && $estimated_date !== null && $estimated_date <= $now) {
            if ($age_days >= 10) return ['critical', 'backorder_elapsed'];
            return ['warning', 'backorder_elapsed']; // Always at least a warning — the promise date has passed
        }

        // In Stock but has a future estimated date = data conflict, always surface as warning
        if ($logistics_status === 'in_stock' && $estimated_date !== null && $estimated_date > $now) {
            return ['warning', 'instock_future_date'];
        }

        // Standard flag logic
        if (empty($trx_number)) {
            if ($age_days >= 10) return ['critical', 'no_trx'];
            if ($age_days >= 3)  return ['warning', 'no_trx'];
            return ['healthy', null];
        }

        if (empty($shipments)) {
            if ($age_days >= 10) return ['critical', 'no_shipments'];
            if ($age_days >= 3)  return ['warning', 'no_shipments'];
            return ['healthy', null];
        }

        $has_tracking = false;
        foreach ($shipments as $shipment) {
            if (!empty($shipment['tracking_numbers']) && is_array($shipment['tracking_numbers'])) {
                foreach ($shipment['tracking_numbers'] as $tn) {
                    if (!empty(trim($tn))) {
                        $has_tracking = true;
                        break 2;
                    }
                }
            }
        }

        if (!$has_tracking) {
            if ($age_days >= 10) return ['critical', 'no_tracking'];
            if ($age_days >= 3)  return ['warning', 'no_tracking'];
        }

        return ['healthy', null];
    }

    /**
     * Format order data for email template.
     * Includes logistics status, estimated date, and conflict flags.
     */
    private function format_order_data($order, $trx_number, $shipments, $age_days, $logistics_status, $logistics_date, $estimated_date, $now, $flag_reason) {
        $has_tracking   = false;
        $tracking_count = 0;

        foreach ($shipments as $shipment) {
            if (!empty($shipment['tracking_numbers'])) {
                foreach ($shipment['tracking_numbers'] as $tn) {
                    if (!empty(trim($tn))) {
                        $has_tracking = true;
                        $tracking_count++;
                    }
                }
            }
        }

        // Core status label
        if (empty($trx_number)) {
            $status_label = '❌ No TRX Number';
        } elseif (empty($shipments)) {
            $status_label = '⏳ TRX: ' . esc_html($trx_number) . ' - No Shipments';
        } elseif (!$has_tracking) {
            $status_label = '📦 ' . count($shipments) . ' Shipment(s) - Missing Tracking';
        } else {
            $status_label = '✅ Has Tracking (' . $tracking_count . ')';
        }

        // Logistics note — surfaces data quality issues clearly
        $logistics_note = null;

        if ($logistics_status === 'backordered' && empty($logistics_date)) {
            $logistics_note = '⚠️ Backordered — No estimated date entered (update via UACP)';
        } elseif ($logistics_status === 'backordered' && $estimated_date !== null && $estimated_date <= $now) {
            $logistics_note = '🔴 Backordered — Estimated date of ' . esc_html($logistics_date) . ' has passed';
        } elseif ($logistics_status === 'in_stock' && $estimated_date !== null && $estimated_date > $now) {
            $logistics_note = '⚠️ Marked "In Stock" but has a future estimated date of ' . esc_html($logistics_date) . ' — please correct via UACP';
        } elseif ($logistics_status === 'backordered' && $estimated_date !== null && $estimated_date > $now) {
            // Shouldn't reach format if suppressed upstream, but belt-and-suspenders
            $logistics_note = '📋 Backordered until ' . esc_html($logistics_date);
        }

        return [
            'order_id'        => $order->get_id(),
            'order_number'    => $order->get_order_number(),
            'age_days'        => $age_days,
            'trx_number'      => $trx_number,
            'shipment_count'  => count($shipments),
            'has_tracking'    => $has_tracking,
            'tracking_count'  => $tracking_count,
            'status_label'    => $status_label,
            'logistics_note'  => $logistics_note,
            'flag_reason'     => $flag_reason,
            'date_created'    => $order->get_date_created()->date('M j, Y'),
            'link'            => $order->get_edit_order_url(),
        ];
    }
}
