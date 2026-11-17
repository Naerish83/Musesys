<?php
if (!defined('ABSPATH')) exit;

class MuseSys_Reports_Orders_Report {
    
    /**
     * Get all flagged orders (30-day window)
     */
    public function get_flagged_orders() {
        $now = new DateTime('now', new DateTimeZone('America/New_York'));
        $thirty_days_ago = (clone $now)->modify('-30 days');
        
        // Get orders from last 30 days
        $orders = wc_get_orders([
            'date_created' => '>=' . $thirty_days_ago->getTimestamp(),
            'limit' => -1,
            'status' => ['processing', 'on-hold', 'pending', 'partially-shipped'],
        ]);
        
        $critical = [];
        $warning = [];
        $healthy = 0;
        
        foreach ($orders as $order) {
            $created = $order->get_date_created();
            $age_days = $now->diff($created)->days;
            
            // Get order meta
            $trx_number = $order->get_meta('_trx_order_number', true);
            $shipments = json_decode($order->get_meta('_muse_shipments', true), true);
            if (!is_array($shipments)) $shipments = [];
            
            // Check flag status
            $flag = $this->check_order_flag($trx_number, $shipments, $age_days);
            
            if ($flag === 'critical') {
                $critical[] = $this->format_order_data($order, $trx_number, $shipments, $age_days);
            } elseif ($flag === 'warning') {
                $warning[] = $this->format_order_data($order, $trx_number, $shipments, $age_days);
            } else {
                $healthy++;
            }
        }
        
        return [
            'timestamp' => $now->format('l, F j, Y \a\t g:i A T'),
            'critical' => $critical,
            'warning' => $warning,
            'healthy_count' => $healthy,
        ];
    }
    
    /**
     * Determine if order should be flagged
     */
    private function check_order_flag($trx_number, $shipments, $age_days) {
        // Scenario 1: No TRX number
        if (empty($trx_number)) {
            if ($age_days >= 10) return 'critical';
            if ($age_days >= 3) return 'warning';
            return 'healthy';
        }
        
        // Scenario 2: Has TRX, but no shipments
        if (empty($shipments)) {
            if ($age_days >= 10) return 'critical';
            if ($age_days >= 3) return 'warning';
            return 'healthy';
        }
        
        // Scenario 3: Has TRX and shipments, but no tracking numbers
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
            if ($age_days >= 10) return 'critical';
            if ($age_days >= 3) return 'warning';
        }
        
        return 'healthy';
    }
    
    /**
     * Format order data for email
     */
    private function format_order_data($order, $trx_number, $shipments, $age_days) {
        $has_tracking = false;
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
        
        // Determine status message
        if (empty($trx_number)) {
            $status_label = '❌ No TRX Number';
        } elseif (empty($shipments)) {
            $status_label = '⏳ TRX: ' . esc_html($trx_number) . ' - No Shipments';
        } elseif (!$has_tracking) {
            $status_label = '📦 ' . count($shipments) . ' Shipment(s) - Missing Tracking';
        } else {
            $status_label = '✅ Has Tracking (' . $tracking_count . ')';
        }
        
        return [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'age_days' => $age_days,
            'trx_number' => $trx_number,
            'shipment_count' => count($shipments),
            'has_tracking' => $has_tracking,
            'tracking_count' => $tracking_count,
            'status_label' => $status_label,
            'date_created' => $order->get_date_created()->date('M j, Y'),
            'link' => $order->get_edit_order_url(),
        ];
    }
}