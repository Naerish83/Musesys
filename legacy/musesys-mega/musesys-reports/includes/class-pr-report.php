<?php

if (!defined('ABSPATH')) exit;



class MuseSys_Reports_PR_Report {

    

    /**

     * Get all flagged product requests

     */

    public function get_flagged_requests() {

        $now = new DateTime('now', new DateTimeZone('America/New_York'));

        

        // Get all product requests that are pending or processing

        $args = [

            'post_type' => 'musesys_pr',

            'posts_per_page' => -1,

            'post_status' => 'publish',

            'meta_query' => [

                [

                    'key' => '_muse_pr_status',

                    'value' => ['pending', 'processing'],

                    'compare' => 'IN'

                ]

            ],

            'orderby' => 'date',

            'order' => 'ASC'

        ];

        

        $query = new WP_Query($args);

        

        $critical = [];

        $warning = [];

        $healthy = 0;

        

        if ($query->have_posts()) {

            while ($query->have_posts()) {

                $query->the_post();

                $post_id = get_the_ID();

                

                $created = new DateTime(get_the_date('Y-m-d H:i:s'), new DateTimeZone('America/New_York'));

                $age_days = $now->diff($created)->days;

                

                // Check flag status

                $flag = $this->check_request_flag($age_days);

                

                if ($flag === 'critical') {

                    $critical[] = $this->format_request_data($post_id, $age_days);

                } elseif ($flag === 'warning') {

                    $warning[] = $this->format_request_data($post_id, $age_days);

                } else {

                    $healthy++;

                }

            }

            wp_reset_postdata();

        }

        

        return [

            'timestamp' => $now->format('l, F j, Y \a\t g:i A T'),

            'critical' => $critical,

            'warning' => $warning,

            'healthy_count' => $healthy,

        ];

    }

    

    /**

     * Determine if request should be flagged

     */

    private function check_request_flag($age_days) {

        if ($age_days >= 10) return 'critical';

        if ($age_days >= 3) return 'warning';

        return 'healthy';

    }

    

    /**

     * Format request data for email

     */

    private function format_request_data($post_id, $age_days) {

        $status = get_post_meta($post_id, '_muse_pr_status', true);

        $type = get_post_meta($post_id, '_muse_pr_type', true);

        $customer_email = get_post_meta($post_id, '_musesys_customer_email', true);

        $brand = get_post_meta($post_id, 'brand', true);

        $model = get_post_meta($post_id, 'model', true);

        

        // Get item count

        $items = get_post_meta($post_id, '_musesys_product_items', true);

        $item_count = is_array($items) ? count($items) : 0;

        

        // Status label

        $status_emoji = ($status === 'processing') ? '🔄' : '⏸️';

        $status_label = $status_emoji . ' ' . ucfirst($status);

        

        return [

            'request_id' => $post_id,

            'request_number' => '#' . $post_id,

            'age_days' => $age_days,

            'status' => $status,

            'status_label' => $status_label,

            'type' => $type,

            'brand' => $brand,

            'model' => $model,

            'item_count' => $item_count,

            'customer_email' => $customer_email,

            'date_created' => get_the_date('M j, Y', $post_id),

            'link' => get_edit_post_link($post_id),

        ];

    }

}