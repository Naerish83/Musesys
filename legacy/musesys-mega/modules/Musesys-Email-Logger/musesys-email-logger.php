<?php
/**
 * Plugin Name: MuseSys Email Logger
 * Description: Logs all outgoing WordPress emails to musesys_email_log
 * Version: 1.0.1
 * Author: Gerharz Equipment / MuseSys
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MuseSys_Email_Logger {

    public function __construct() {
        add_action( 'wp_mail_succeeded', [ $this, 'log_success' ] );
        add_action( 'wp_mail_failed',    [ $this, 'log_failure' ] );
        add_action( 'admin_menu',        [ $this, 'register_admin_page' ] );
        add_action( 'admin_init',        [ $this, 'handle_clear_log' ] );
    }

    public function log_success( $mail_data ) {
        $this->write_log( $mail_data, 'sent' );
    }

    public function log_failure( $error ) {
        $this->write_log( $error->get_error_data(), 'failed', $error->get_error_message() );
    }

    private function write_log( $data, $status, $error = null ) {
        global $wpdb;

        $recipient = '';
        if ( ! empty( $data['to'] ) ) {
            $recipient = is_array( $data['to'] )
                ? implode( ', ', $data['to'] )
                : $data['to'];
        }

        $wpdb->insert(
            'musesys_email_log',
            [
                'sent_at'       => current_time( 'mysql' ),
                'recipient'     => sanitize_text_field( $recipient ),
                'subject'       => sanitize_text_field( $data['subject'] ?? '' ),
                'trigger_hook'  => current_filter(),
                'status'        => $status,
                'error_message' => $error ? sanitize_text_field( $error ) : null,
                'body_preview'  => substr( wp_strip_all_tags( $data['message'] ?? '' ), 0, 500 ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public function register_admin_page() {
        add_submenu_page(
            'musesys',
            'Email Log',
            'Email Log',
            'manage_options',
            'musesys-email-log',
            [ $this, 'render_admin_page' ]
        );
    }

    public function handle_clear_log() {
        if (
            isset( $_POST['musesys_clear_email_log'] ) &&
            check_admin_referer( 'musesys_clear_log_action' ) &&
            current_user_can( 'manage_options' )
        ) {
            global $wpdb;
            $wpdb->query( "TRUNCATE TABLE musesys_email_log" );
            wp_redirect( add_query_arg( 'cleared', '1', admin_url( 'admin.php?page=musesys-email-log' ) ) );
            exit;
        }
    }

    public function render_admin_page() {
        global $wpdb;

        // Filters
        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $search        = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $per_page      = 50;
        $current_page  = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $offset        = ( $current_page - 1 ) * $per_page;

        $where_clauses = [ '1=1' ];
        $where_params  = [];

        if ( $status_filter ) {
            $where_clauses[] = 'status = %s';
            $where_params[]  = $status_filter;
        }
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where_clauses[] = '(recipient LIKE %s OR subject LIKE %s)';
            $where_params[]  = $like;
            $where_params[]  = $like;
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );

        if ( ! empty( $where_params ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM musesys_email_log $where_sql", ...$where_params ) );
            $query_params = array_merge( $where_params, [ $per_page, $offset ] );
            $logs  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM musesys_email_log $where_sql ORDER BY sent_at DESC LIMIT %d OFFSET %d",
                    ...$query_params
                )
            );
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM musesys_email_log" );
            $logs  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM musesys_email_log ORDER BY sent_at DESC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                )
            );
        }

        $total_pages = ceil( $total / $per_page );
        ?>
        <div class="wrap">
            <h1>MuseSys — Email Log</h1>

            <?php if ( isset( $_GET['cleared'] ) ) : ?>
                <div class="notice notice-success"><p>Log cleared successfully.</p></div>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; margin: 16px 0;">
                <form method="get" style="display:flex; gap:8px; align-items:center;">
                    <input type="hidden" name="page" value="musesys-email-log" />
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search recipient or subject..." class="regular-text" />
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="sent"   <?php selected( $status_filter, 'sent' ); ?>>Sent</option>
                        <option value="failed" <?php selected( $status_filter, 'failed' ); ?>>Failed</option>
                    </select>
                    <button type="submit" class="button">Filter</button>
                </form>

                <form method="post">
                    <?php wp_nonce_field( 'musesys_clear_log_action' ); ?>
                    <button type="submit" name="musesys_clear_email_log" class="button button-secondary"
                        onclick="return confirm('Clear the entire email log? This cannot be undone.')">
                        Clear Log
                    </button>
                </form>
            </div>

            <p style="color:#666;">Showing <?php echo count( $logs ); ?> of <?php echo $total; ?> entries</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:160px;">Sent At</th>
                        <th>Recipient</th>
                        <th>Subject</th>
                        <th style="width:120px;">Trigger Hook</th>
                        <th style="width:70px;">Status</th>
                        <th>Preview / Error</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="6" style="text-align:center; padding:20px; color:#999;">No emails logged yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log->sent_at ); ?></td>
                        <td><?php echo esc_html( $log->recipient ); ?></td>
                        <td><?php echo esc_html( $log->subject ); ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html( $log->trigger_hook ); ?></code></td>
                        <td>
                            <?php if ( $log->status === 'sent' ) : ?>
                                <span style="color:green; font-weight:bold;">? sent</span>
                            <?php else : ?>
                                <span style="color:red; font-weight:bold;">? failed</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px; color:#555;">
                            <?php if ( $log->error_message ) : ?>
                                <span style="color:red;"><?php echo esc_html( $log->error_message ); ?></span>
                            <?php else : ?>
                                <?php echo esc_html( $log->body_preview ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $current_page,
                        'total'   => $total_pages,
                    ]);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

new MuseSys_Email_Logger();