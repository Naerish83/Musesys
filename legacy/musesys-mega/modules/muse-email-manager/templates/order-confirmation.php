<?php
$tracking_display = gerhardt_get_muse_track_status($order_id);
$order = wc_get_order($order_id);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .tracking { font-weight: bold; color: #0073aa; }
        .header { background: #f6f6f6; padding: 20px; text-align: center; }
        .content { padding: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Order Confirmed: <?php echo $tracking_display; ?></h2>
    </div>
    <div class="content">
        <p>Your order has been confirmed and is being processed.</p>
        <p><strong>Total:</strong> <?php echo $order->get_formatted_order_total(); ?></p>
        
        <h3>Items</h3>
        <ul>
            <?php foreach ($order->get_items() as $item): ?>
                <li>
                    <?php echo $item->get_quantity(); ?>x <?php echo $item->get_name(); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        
        <p><a href="<?php echo esc_url($order->get_view_order_url()); ?>">View Order Online</a></p>
    </div>
</body>
</html>
