<?php
$tracking_display = gerhardt_get_muse_track_status($pr_id);
$items = get_post_meta($pr_id, '_musesys_product_items', true) ?: [];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .tracking { font-weight: bold; color: #0073aa; }
        .item-list { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .item-list th, .item-list td { border: 1px solid #eee; padding: 10px; text-align: left; }
        .header { background: #f6f6f6; padding: 20px; text-align: center; }
        .content { padding: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Product Request Received</h2>
    </div>
    <div class="content">
        <p>Your request <span class="tracking"><?php echo $tracking_display; ?></span> has been received.</p>
        <p>We are reviewing your request and will update you shortly.</p>
        
        <h3>Request Details</h3>
        <table class="item-list">
            <thead>
                <tr>
                    <th>Item / Part</th>
                    <th>WO #</th>
                    <th>Qty</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($item['part_num'] ?? ''); ?></strong><br>
                        <small><?php echo esc_html($item['desc'] ?? ''); ?></small>
                    </td>
                    <td><?php echo esc_html($item['wo_number'] ?? ''); ?></td>
                    <td><?php echo esc_html($item['qty'] ?? 1); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
