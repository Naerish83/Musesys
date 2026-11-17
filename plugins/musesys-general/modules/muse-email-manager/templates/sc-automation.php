<?php
/**
 * Service Channel automation email template.
 *
 * Available vars:
 * $order_id
 * $tracking_number
 * $sc_status_label
 * $status_message
 * $carrier_label
 * $track_numbers
 * $ship_date
 * $logistics_date
 */

$status_label = $sc_status_label ?? 'Status Update';
$status_message = $status_message ?? '';
$carrier_label = $carrier_label ?? '';
$track_numbers = $track_numbers ?? array();
$ship_date = $ship_date ?? '';
$logistics_date = $logistics_date ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color: #333; }
        .wrap { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1a3a5c; color: #fff; padding: 15px 20px; border-radius: 4px 4px 0 0; }
        .body { border: 1px solid #ddd; border-top: none; padding: 20px; border-radius: 0 0 4px 4px; }
        .field { margin-bottom: 12px; }
        .label { font-weight: bold; color: #555; font-size: 12px; text-transform: uppercase; }
        .value { font-size: 15px; margin-top: 2px; }
        .tracking { font-size: 16px; font-weight: bold; color: #1a3a5c; }
        .footer { margin-top: 20px; font-size: 11px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h2 style="margin:0; font-size:18px;">MuseSys Order Status Update</h2>
        <p style="margin:4px 0 0; font-size:13px; opacity:0.8;">Automated notification from Gerharz Equipment</p>
    </div>

    <div class="body">
        <div class="field">
            <div class="label">Service Channel Work Order</div>
            <div class="value tracking"><?php echo esc_html( $tracking_number ); ?></div>
        </div>

        <div class="field">
            <div class="label">Order Number</div>
            <div class="value">#<?php echo esc_html( $order_id ); ?></div>
        </div>

        <div class="field">
            <div class="label">Workflow Status</div>
            <div class="value"><?php echo esc_html( $status_label ); ?></div>
        </div>

        <?php if ( ! empty( $status_message ) ) : ?>
        <div class="field">
            <div class="label">Details</div>
            <div class="value"><?php echo esc_html( $status_message ); ?></div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $carrier_label ) ) : ?>
        <div class="field">
            <div class="label">Shipping Method</div>
            <div class="value"><?php echo esc_html( $carrier_label ); ?></div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $track_numbers ) ) : ?>
        <div class="field">
            <div class="label">Tracking Number(s)</div>
            <div class="value tracking"><?php echo esc_html( implode( ', ', $track_numbers ) ); ?></div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $logistics_date ) ) : ?>
        <div class="field">
            <div class="label">Estimated Date</div>
            <div class="value"><?php echo esc_html( $logistics_date ); ?></div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $ship_date ) ) : ?>
        <div class="field">
            <div class="label">Shipment Date</div>
            <div class="value"><?php echo esc_html( $ship_date ); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        Automated update via MuseSys / Gerharz Equipment / <?php echo date( 'Y-m-d H:i:s' ); ?>
    </div>
</div>
</body>
</html>
