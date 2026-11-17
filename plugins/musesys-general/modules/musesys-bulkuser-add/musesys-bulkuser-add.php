<?php
/**
 * Plugin Name: MuseSys — Bulk Add Users
 * Description: Upload a CSV to create multiple users, assign roles, and send welcome/reset emails.
 * Version: 1.0.1
 * Author: MuseSystems
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function(){
    add_submenu_page('musesys', 'Bulk Add Users', 'Bulk Add Users', 'create_users', 'musesys-bulk-users', 'musesys_bulk_users_page');
});

function musesys_bulk_users_page(){
    if (!current_user_can('create_users')) { wp_die('Insufficient permissions.'); }
    $nonce = wp_create_nonce('musesys_bulk_users');

    // Handle upload
    $log = [];
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_muse_nonce']) && wp_verify_nonce($_POST['_muse_nonce'],'musesys_bulk_users')) {
        if (!empty($_FILES['csv']['tmp_name'])) {
            $fh = fopen($_FILES['csv']['tmp_name'], 'r');
            if ($fh) {
                $headers = fgetcsv($fh, null, ',', '"', '\\');
                if (!is_array($headers)) $headers = [];
                // Expected: email, first_name, last_name, role
                $map = musesys_bulk_map_headers($headers);
                $count = 0;
                while (($row = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
                    $count++;
                    $email = sanitize_email($row[$map['email']] ?? '');
                    $first = sanitize_text_field($row[$map['first_name']] ?? '');
                    $last  = sanitize_text_field($row[$map['last_name']] ?? '');
                    $role  = sanitize_text_field($row[$map['role']] ?? 'customer');
                    if (!$email || !is_email($email)) { $log[] = "Row $count: invalid email"; continue; }

                    $existing = get_user_by('email', $email);
                    if ($existing) {
                        if ($role && !in_array($role, (array)$existing->roles, true)) {
                            $existing->set_role($role);
                        }
                        $log[] = "Row $count: updated role for {$email}";
                        continue;
                    }

                    $password = wp_generate_password(20, true, false);
                    $user_id = wp_create_user($email, $password, $email);
                    if (is_wp_error($user_id)) { $log[] = "Row $count: error creating {$email} — ".$user_id->get_error_message(); continue; }

                    wp_update_user(['ID'=>$user_id, 'first_name'=>$first, 'last_name'=>$last, 'display_name'=>trim("$first $last") ?: $email]);
                    if ($role) { $u = get_user_by('id', $user_id); $u && $u->set_role($role); }

                    // Send set-password email
                    $reset_key = get_password_reset_key(get_user_by('id', $user_id));
                    $reset_url = wp_login_url() . '?action=rp&key=' . rawurlencode($reset_key) . '&login=' . rawurlencode($email);
                    wp_mail($email, 'Welcome — Set Your Password', "Hi $first,\n\nYour account has been created.\nSet your password here:\n$reset_url\n\nThanks.");

                    $log[] = "Row $count: created {$email} as {$role}";
                }
                fclose($fh);
            } else {
                $log[] = 'Could not open CSV.';
            }
        } else {
            $log[] = 'No CSV uploaded.';
        }
    }

    ?>
    <div class="wrap">
      <h1>Bulk Add Users</h1>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_muse_nonce" value="<?php echo esc_attr($nonce); ?>">
        <p>Upload a CSV with columns: <code>email, first_name, last_name, role</code>. Header names are case-insensitive.</p>
        <p><input type="file" name="csv" accept=".csv" required></p>
        <p><button class="button button-primary">Process CSV</button></p>
      </form>
      <?php if (!empty($log)): ?>
        <h2>Results</h2>
        <pre style="background:#111;color:#eee;padding:10px;white-space:pre-wrap;"><?php echo esc_html(implode("\n", $log)); ?></pre>
      <?php endif; ?>
      <h2>CSV Example</h2>
      <pre>email,first_name,last_name,role
jane@example.com,Jane,Doe,customer
ops@example.com,Ops,User,shop_manager
dev@example.com,Dev,User,administrator</pre>
    </div>
    <?php
}

function musesys_bulk_map_headers($headers){
    $norm = [];
    foreach ($headers as $i => $h) {
        $k = strtolower(trim((string)$h));
        $norm[$k] = $i;
    }
    return [
        'email'      => $norm['email']      ?? 0,
        'first_name' => $norm['first_name'] ?? 1,
        'last_name'  => $norm['last_name']  ?? 2,
        'role'       => $norm['role']       ?? 3,
    ];
}
