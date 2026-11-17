<?php
/**
 * Plugin Name: MuseSys Table of Contents
 * Description: Dynamic dashboard listing all active MuseSys modules.
 * Version: 1.0.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class MuseSys_TOC_Dashboard {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'musesys',
            'Table of Contents',
            'Table of Contents',
            'manage_options',
            'musesys-toc',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $modules = self::scan_modules();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">MuseSys Modules</h1>';
        echo '<p class="description">Dynamic manifest of all standard modules loaded by the Mega Plugin.</p>';
        echo '<hr class="wp-header-end">';

        if (empty($modules)) {
            echo '<div class="notice notice-warning"><p>No modules found.</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th scope="col" class="manage-column column-primary" style="width:250px;">Module Name</th>';
        echo '<th scope="col" class="manage-column">Description</th>';
        echo '<th scope="col" class="manage-column" style="width:100px;">Version</th>';
        echo '<th scope="col" class="manage-column" style="width:100px;">Status</th>';
        echo '<th scope="col" class="manage-column" style="width:150px;">File</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($modules as $mod) {
            $name = esc_html($mod['Name'] ?: basename(dirname($mod['File'])));
            $desc = esc_html($mod['Description']);
            $ver = esc_html($mod['Version'] ?: '-');
            $status = '<span class="dashicons dashicons-yes" style="color:green;"></span> Active';
             // Simple relative path for display
            $rel_path = basename(dirname($mod['File'])) . '/' . basename($mod['File']);

            echo '<tr>';
            echo '<td class="column-primary"><strong>' . $name . '</strong></td>';
            echo '<td>' . $desc . '</td>';
            echo '<td>' . $ver . '</td>';
            echo '<td>' . $status . '</td>';
            echo '<td><code style="font-size:10px;">' . esc_html($rel_path) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '<p style="margin-top:20px; color:#666; font-size:12px;">Scanned ' . count($modules) . ' files in <code>' . esc_html(basename(dirname(__DIR__))) . '</code>.</p>';
        echo '</div>';
    }

    /**
     * Scan the modules directory for PHP files and extract headers.
     * 
     * @return array<int, array<string, string>>
     */
    private static function scan_modules(): array {
        $modules_dir = dirname(__DIR__); // c:/.../modules
        $module_files = [];

        if (!is_dir($modules_dir)) {
            return [];
        }

        // Logic must match musesys-mega.php discovery
        // 1. index.php files
        $indices = glob($modules_dir . '/*/index.php') ?: [];
        foreach ($indices as $file) {
            $module_files[] = $file;
        }

        // 2. Other PHP files
        $others = glob($modules_dir . '/*/*.php') ?: [];
        foreach ($others as $file) {
            if (basename($file) !== 'index.php') {
                $module_files[] = $file;
            }
        }

        $results = [];

        foreach ($module_files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            // Skip THIS file generally? Or include it? Include it.
            
            $data = self::get_file_data($file);
            // Only list if it looks like a module (has Name or Version, or just list everything found)
            // User asked for "Found modules".
            // If it doesn't have a header, we fallback to dirname.
            
            $results[] = [
                'Name' => $data['Name'],
                'Description' => $data['Description'],
                'Version' => $data['Version'],
                'File' => $file
            ];
        }

        // Sort by name
        usort($results, fn($a, $b) => strcasecmp($a['Name'] ?: basename(dirname($a['File'])), $b['Name'] ?: basename(dirname($b['File']))));

        return $results;
    }

    /**
     * Extract file headers.
     * Similar to wp_get_file_data but lightweight.
     * 
     * @param string $file
     * @return array<string, string>
     */
    private static function get_file_data(string $file): array {
        $fp = fopen($file, 'r');
        if (!$fp) {
            return ['Name' => '', 'Description' => '', 'Version' => ''];
        }

        // Pull only the first 8kiB of the file in.
        $file_data = fread($fp, 8192);
        fclose($fp);

        if ($file_data === false) {
             return ['Name' => '', 'Description' => '', 'Version' => ''];
        }

        $file_data = str_replace("\r", "\n", $file_data);

        return [
            'Name' => self::get_header('Plugin Name', $file_data),
            'Description' => self::get_header('Description', $file_data),
            'Version' => self::get_header('Version', $file_data),
        ];

    }

    private static function get_header(string $field, string $content): string {
        if (preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':(.*)$/mi', $content, $match) && $match[1]) {
            return trim($match[1]);
        }
        
        // Fallback for constants if defined in the first 8k? 
        // User suggested: "Check... if there is a standardized property or constant... you can read instead of regexing files."
        // But headers are safer to read statically without including/executing (which is already done, but re-including to get constants is messy). 
        // Since musesys-mega ALREADY includes them, we *could* check if classes exist, but we don't know the class names map.
        // Regex is the standard WP way.
        
        return '';
    }
}

MuseSys_TOC_Dashboard::init();
