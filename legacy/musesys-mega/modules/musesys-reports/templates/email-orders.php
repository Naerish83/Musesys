<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #e74c3c; margin-top: 30px; }
        h2.warning { color: #f39c12; }
        .timestamp { color: #7f8c8d; font-size: 14px; margin-bottom: 20px; }
        .order-item { background: #f8f9fa; border-left: 4px solid #e74c3c; padding: 15px; margin: 10px 0; border-radius: 4px; }
        .order-item.warning { border-left-color: #f39c12; }
        .order-link { color: #3498db; text-decoration: none; font-weight: bold; }
        .order-link:hover { text-decoration: underline; }
        .meta { color: #7f8c8d; font-size: 13px; }
        .summary { background: #ecf0f1; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .no-issues { background: #d4edda; color: #155724; padding: 20px; border-radius: 4px; text-align: center; font-size: 18px; }
        .logistics-note { margin-top: 8px; font-size: 13px; color: #856404; background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; padding: 6px 10px; }
    </style>
</head>
<body>
    <h1>📦 Wegmans Orders Report</h1>
    <div class="timestamp">Generated: <?php echo esc_html($data['timestamp']); ?></div>
    
    <?php if (empty($data['critical']) && empty($data['warning'])): ?>
        <div class="no-issues">
            ✅ <strong>All Clear!</strong><br>
            No orders require immediate attention.<br>
            <span class="meta"><?php echo esc_html($data['healthy_count']); ?> orders are on track.</span>
        </div>
    <?php else: ?>
        
        <div class="summary">
            <strong>Summary:</strong>
            🔴 <?php echo count($data['critical']); ?> Critical (10+ days) |
            ⚠️ <?php echo count($data['warning']); ?> Warning (3-10 days) |
            ✅ <?php echo esc_html($data['healthy_count']); ?> Healthy
        </div>
        
        <?php if (!empty($data['critical'])): ?>
            <h2>🔴 CRITICAL - Immediate Action Required (10+ Days)</h2>
            <?php foreach ($data['critical'] as $order): ?>
                <div class="order-item">
                    <a href="<?php echo esc_url($order['link']); ?>" class="order-link">Order <?php echo esc_html($order['order_number']); ?></a>
                    <div><strong><?php echo esc_html($order['status_label']); ?></strong></div>
                    <div class="meta">
                        Created: <?php echo esc_html($order['date_created']); ?> |
                        Age: <strong><?php echo esc_html($order['age_days']); ?> days</strong> |
                        Shipments: <?php echo esc_html($order['shipment_count']); ?>
                    </div>
                    <?php if (!empty($order['logistics_note'])): ?>
                        <div class="logistics-note"><?php echo esc_html($order['logistics_note']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($data['warning'])): ?>
            <h2 class="warning">⚠️ WARNING - Needs Attention (3-10 Days)</h2>
            <?php foreach ($data['warning'] as $order): ?>
                <div class="order-item warning">
                    <a href="<?php echo esc_url($order['link']); ?>" class="order-link">Order <?php echo esc_html($order['order_number']); ?></a>
                    <div><strong><?php echo esc_html($order['status_label']); ?></strong></div>
                    <div class="meta">
                        Created: <?php echo esc_html($order['date_created']); ?> |
                        Age: <strong><?php echo esc_html($order['age_days']); ?> days</strong> |
                        Shipments: <?php echo esc_html($order['shipment_count']); ?>
                    </div>
                    <?php if (!empty($order['logistics_note'])): ?>
                        <div class="logistics-note"><?php echo esc_html($order['logistics_note']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
    <?php endif; ?>
    
    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #7f8c8d; font-size: 12px;">
        <p>This is an automated report from MuseSys. Orders are flagged based on age, missing TRX/tracking information, and logistics status accuracy.</p>
    </div>
</body>
</html>
