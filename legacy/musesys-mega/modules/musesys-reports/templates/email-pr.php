<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #2c3e50; border-bottom: 3px solid #9b59b6; padding-bottom: 10px; }
        h2 { color: #e74c3c; margin-top: 30px; }
        h2.warning { color: #f39c12; }
        .timestamp { color: #7f8c8d; font-size: 14px; margin-bottom: 20px; }
        .request-item { background: #f8f9fa; border-left: 4px solid #e74c3c; padding: 15px; margin: 10px 0; border-radius: 4px; }
        .request-item.warning { border-left-color: #f39c12; }
        .request-link { color: #9b59b6; text-decoration: none; font-weight: bold; }
        .request-link:hover { text-decoration: underline; }
        .meta { color: #7f8c8d; font-size: 13px; }
        .summary { background: #ecf0f1; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .no-issues { background: #d4edda; color: #155724; padding: 20px; border-radius: 4px; text-align: center; font-size: 18px; }
    </style>
</head>
<body>
    <h1>📋 Product Requests Report</h1>
    <div class="timestamp">Generated: <?php echo esc_html($data['timestamp']); ?></div>
    
    <?php if (empty($data['critical']) && empty($data['warning'])): ?>
        <div class="no-issues">
            ✅ <strong>All Clear!</strong><br>
            No product requests require immediate attention.<br>
            <span class="meta"><?php echo esc_html($data['healthy_count']); ?> requests are on track.</span>
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
            <?php foreach ($data['critical'] as $request): ?>
                <div class="request-item">
                    <a href="<?php echo esc_url($request['link']); ?>" class="request-link">Request <?php echo esc_html($request['request_number']); ?></a>
                    <div><strong><?php echo esc_html($request['status_label']); ?></strong></div>
                    <?php if (!empty($request['brand']) || !empty($request['model'])): ?>
                        <div><?php echo esc_html($request['brand']); ?> <?php echo esc_html($request['model']); ?></div>
                    <?php endif; ?>
                    <div class="meta">
                        Created: <?php echo esc_html($request['date_created']); ?> |
                        Age: <strong><?php echo esc_html($request['age_days']); ?> days</strong> |
                        Items: <?php echo esc_html($request['item_count']); ?> |
                        Customer: <?php echo esc_html($request['customer_email']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($data['warning'])): ?>
            <h2 class="warning">⚠️ WARNING - Needs Attention (3-10 Days)</h2>
            <?php foreach ($data['warning'] as $request): ?>
                <div class="request-item warning">
                    <a href="<?php echo esc_url($request['link']); ?>" class="request-link">Request <?php echo esc_html($request['request_number']); ?></a>
                    <div><strong><?php echo esc_html($request['status_label']); ?></strong></div>
                    <?php if (!empty($request['brand']) || !empty($request['model'])): ?>
                        <div><?php echo esc_html($request['brand']); ?> <?php echo esc_html($request['model']); ?></div>
                    <?php endif; ?>
                    <div class="meta">
                        Created: <?php echo esc_html($request['date_created']); ?> |
                        Age: <strong><?php echo esc_html($request['age_days']); ?> days</strong> |
                        Items: <?php echo esc_html($request['item_count']); ?> |
                        Customer: <?php echo esc_html($request['customer_email']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
    <?php endif; ?>
    
    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #7f8c8d; font-size: 12px;">
        <p>This is an automated report from MuseSys. Product Requests are flagged based on age and status.</p>
    </div>
</body>
</html>