<?php
/**
 * Template: Legacy Order History Tab
 * Rendered via muse_oh_render_tab() — $orders array is in scope.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="muse-oh-wrap">

    <div class="muse-oh-header">
        <h3><?php esc_html_e( 'Original Order History', 'muse-oh' ); ?></h3>
        <p class="muse-oh-subtitle">
            <?php esc_html_e( 'Orders placed on the previous portal. For current orders, see ', 'muse-oh' ); ?>
            <a href="<?php echo esc_url( wc_get_account_endpoint_url('orders') ); ?>">
                <?php esc_html_e( 'Orders', 'muse-oh' ); ?>
            </a>.
        </p>
    </div>

    <?php if ( empty( $orders ) ) : ?>
        <p class="muse-oh-empty">
            <?php esc_html_e( 'No legacy order history found for your account.', 'muse-oh' ); ?>
        </p>
    <?php else : ?>

        <div class="muse-oh-filters">
            <input
                type="text"
                id="muse-oh-search"
                placeholder="Search by order #, SKU, work order, or store…"
                autocomplete="off"
            />
            <select id="muse-oh-status-filter">
                <option value="">All Statuses</option>
                <option value="Completed">Completed</option>
                <option value="Processing">Processing</option>
                <option value="Cancelled">Cancelled</option>
                <option value="On hold">On Hold</option>
            </select>
        </div>

        <table class="muse-oh-table shop_table shop_table_responsive" id="muse-oh-orders-table">
            <thead>
                <tr>
                    <th class="col-order">Order</th>
                    <th class="col-date">Date</th>
                    <th class="col-status">Status</th>
                    <th class="col-store">Store</th>
                    <th class="col-wo">Work Order</th>
                    <th class="col-items">Items</th>
                    <th class="col-tracking">Tracking</th>
                    <th class="col-toggle"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $orders as $order ) :
                $has_tracking  = ! empty( $order['tracking'] );
                $status_slug   = sanitize_html_class( strtolower( str_replace( ' ', '-', $order['status'] ) ) );
                $item_count    = count( $order['items'] );
                $row_id        = 'muse-oh-row-' . intval( $order['order_number'] );
            ?>
                <tr class="muse-oh-order-row"
                    data-order="<?php echo esc_attr( $order['order_number'] ); ?>"
                    data-status="<?php echo esc_attr( $order['status'] ); ?>"
                    data-store="<?php echo esc_attr( $order['store'] ); ?>"
                    data-wo="<?php echo esc_attr( $order['work_order'] ); ?>"
                    data-skus="<?php echo esc_attr( implode( ' ', array_column( $order['items'], 'sku' ) ) ); ?>"
                >
                    <td class="col-order" data-title="Order">
                        <strong>#<?php echo esc_html( $order['order_number'] ); ?></strong>
                    </td>
                    <td class="col-date" data-title="Date">
                        <?php echo esc_html( date( 'M j, Y', strtotime( $order['date'] ) ) ); ?>
                    </td>
                    <td class="col-status" data-title="Status">
                        <span class="muse-oh-status muse-oh-status--<?php echo esc_attr( $status_slug ); ?>">
                            <?php echo esc_html( $order['status'] ); ?>
                        </span>
                    </td>
                    <td class="col-store" data-title="Store">
                        <?php echo $order['store'] ? esc_html( '#' . $order['store'] ) : '<span class="muse-oh-na">—</span>'; ?>
                    </td>
                    <td class="col-wo" data-title="Work Order">
                        <?php echo $order['work_order'] ? esc_html( $order['work_order'] ) : '<span class="muse-oh-na">—</span>'; ?>
                    </td>
                    <td class="col-items" data-title="Items">
                        <?php echo esc_html( $item_count ); ?> item<?php echo $item_count !== 1 ? 's' : ''; ?>
                    </td>
                    <td class="col-tracking" data-title="Tracking">
                        <?php if ( $has_tracking ) : ?>
                            <?php foreach ( $order['tracking'] as $t ) : ?>
                                <span class="muse-oh-carrier muse-oh-carrier--<?php echo esc_attr( strtolower( $t['carrier'] ) ); ?>">
                                    <?php if ( ! empty( $t['url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $t['url'] ); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html( $t['carrier'] ); ?>: <?php echo esc_html( $t['number'] ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $t['carrier'] . ': ' . $t['number'] ); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <span class="muse-oh-na">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-toggle">
                        <button class="muse-oh-toggle button alt" data-target="<?php echo esc_attr( $row_id ); ?>" aria-expanded="false">
                            <span class="muse-oh-toggle-label">Details</span>
                            <span class="muse-oh-toggle-icon">▾</span>
                        </button>
                    </td>
                </tr>

                <tr class="muse-oh-detail-row" id="<?php echo esc_attr( $row_id ); ?>" hidden>
                    <td colspan="8">
                        <div class="muse-oh-detail-inner">

                            <?php if ( ! empty( $order['note'] ) ) : ?>
                                <div class="muse-oh-detail-note">
                                    <strong>Note:</strong> <?php echo esc_html( $order['note'] ); ?>
                                </div>
                            <?php endif; ?>

                            <div class="muse-oh-detail-ship">
                                <strong>Ship To:</strong>
                                <?php echo esc_html( $order['ship_address'] . ', ' . $order['ship_city_state_zip'] ); ?>
                            </div>

                            <table class="muse-oh-items-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>SKU</th>
                                        <th>Item</th>
                                        <th>Qty</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $order['items'] as $item ) : ?>
                                        <tr>
                                            <td><?php echo esc_html( $item['item_num'] ); ?></td>
                                            <td><code><?php echo esc_html( $item['sku'] ); ?></code></td>
                                            <td><?php echo esc_html( $item['name'] ); ?></td>
                                            <td><?php echo esc_html( $item['qty'] ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        </div>
                    </td>
                </tr>

            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="muse-oh-footer">
            <span id="muse-oh-result-count"><?php echo count( $orders ); ?> orders</span>
        </div>

    <?php endif; ?>

</div><!-- .muse-oh-wrap -->
