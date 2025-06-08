<?php
/**
 * Plugin Name: WooCommerce POS Stock Sync for Ultimate POS
 * Plugin URI: https://endwp.com
 * Description: Syncs product stock from the POS database to WooCommerce via SKU. Includes admin settings, instant update, and cronjob.
 * Version: 1.1
 * Author: Nerghum
 * Author URI: https://facebook.com/nerghum
 *
 * A product by EndWP.com
 */


if (!defined('ABSPATH')) exit;

class WC_UltimatePOS_Sync {
    private $option_name = 'wc_ultimatepos_settings';
    private $log_option = 'wc_ultimatepos_log';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wc_ultimatepos_cron_hook', [$this, 'sync_inventory']);

        $settings = get_option($this->option_name);
        if (!empty($settings['cron_enabled']) && !wp_next_scheduled('wc_ultimatepos_cron_hook')) {
            if (!empty($settings['cron_minutes']) && is_numeric($settings['cron_minutes'])) {
                add_filter('cron_schedules', function($schedules) use ($settings) {
                    $interval = intval($settings['cron_minutes']) * 60;
                    $schedules['every_custom_minutes'] = [
                        'interval' => $interval,
                        'display' => "Every {$settings['cron_minutes']} minutes"
                    ];
                    return $schedules;
                });
                wp_schedule_event(time(), 'every_custom_minutes', 'wc_ultimatepos_cron_hook');
            } else {
                wp_schedule_event(time(), $settings['cron_interval'] ?? 'hourly', 'wc_ultimatepos_cron_hook');
            }
        }
    }

    public function add_admin_menu() {
        add_menu_page('Ultimate POS Sync', 'Ultimate POS Sync', 'manage_options', 'wc_ultimatepos_sync', [$this, 'admin_page']);
    }

    public function register_settings() {
        register_setting('wc_ultimatepos_sync', $this->option_name);
    }

    public function admin_page() {
        $settings = get_option($this->option_name);
        $log = get_option($this->log_option);
        ?>
        <div class="wrap">
            <h1>Ultimate POS Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wc_ultimatepos_sync'); ?>
                <table class="form-table">
                    <tr><th>DB Host</th><td><input type="text" name="<?php echo $this->option_name; ?>[db_host]" value="<?php echo esc_attr($settings['db_host'] ?? ''); ?>" /></td></tr>
                    <tr><th>DB Name</th><td><input type="text" name="<?php echo $this->option_name; ?>[db_name]" value="<?php echo esc_attr($settings['db_name'] ?? ''); ?>" /></td></tr>
                    <tr><th>DB User</th><td><input type="text" name="<?php echo $this->option_name; ?>[db_user]" value="<?php echo esc_attr($settings['db_user'] ?? ''); ?>" /></td></tr>
                    <tr><th>DB Password</th><td><input type="password" name="<?php echo $this->option_name; ?>[db_pass]" value="<?php echo esc_attr($settings['db_pass'] ?? ''); ?>" /></td></tr>
                    <tr><th>Enable Cron Job</th><td><input type="checkbox" name="<?php echo $this->option_name; ?>[cron_enabled]" value="1" <?php checked($settings['cron_enabled'] ?? '', '1'); ?> /></td></tr>
                    <tr><th>Cron Interval</th><td>
                        <select name="<?php echo $this->option_name; ?>[cron_interval]">
                            <option value="hourly" <?php selected($settings['cron_interval'] ?? '', 'hourly'); ?>>Hourly</option>
                            <option value="twicedaily" <?php selected($settings['cron_interval'] ?? '', 'twicedaily'); ?>>Twice Daily</option>
                            <option value="daily" <?php selected($settings['cron_interval'] ?? '', 'daily'); ?>>Daily</option>
                        </select></td></tr>
                    <tr><th>Custom Interval (minutes)</th><td>
                        <input type="number" name="<?php echo $this->option_name; ?>[cron_minutes]" min="1" value="<?php echo esc_attr($settings['cron_minutes'] ?? ''); ?>" />
                        <p class="description">Optional: If set, overrides default intervals above.</p>
                    </td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <form method="post">
                <input type="hidden" name="manual_sync_trigger" value="1">
                <?php submit_button('Run Manual Sync'); ?>
            </form>
            <h2>Last Sync Log</h2>
            <textarea rows="10" cols="100" readonly><?php echo esc_textarea($log); ?></textarea>
        </div>
        <?php

        if (!empty($_POST['manual_sync_trigger'])) {
            $this->sync_inventory();
            echo '<div class="updated"><p>Manual sync completed.</p></div>';
        }
    }

    public function sync_inventory() {
        global $wpdb;
        $settings = get_option($this->option_name);
        $log = "";

        $mysqli = new mysqli($settings['db_host'], $settings['db_user'], $settings['db_pass'], $settings['db_name']);
        if ($mysqli->connect_errno) {
            $log = "Database connection failed: " . $mysqli->connect_error;
            update_option($this->log_option, $log);
            return;
        }

        $query = "SELECT variations.sub_sku AS sku, variations.default_sell_price AS price, 
                         SUM(loc.qty_available) AS stock
                  FROM variations
                  JOIN variation_location_details loc ON variations.id = loc.variation_id
                  GROUP BY variations.sub_sku";

        if ($result = $mysqli->query($query)) {
            while ($row = $result->fetch_assoc()) {
                $sku = $row['sku'];
                $price = $row['price'];
                $stock = $row['stock'];

                // Get product/variation post ID by SKU
                $post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key='_sku' AND meta_value=%s",
                    $sku
                ));

                if ($post_id) {
                    // Update price
                    $wpdb->update($wpdb->postmeta, ['meta_value' => $price], ['post_id' => $post_id, 'meta_key' => '_regular_price']);
                    $wpdb->update($wpdb->postmeta, ['meta_value' => $price], ['post_id' => $post_id, 'meta_key' => '_price']);

                    // Update stock
                    $wpdb->update($wpdb->postmeta, ['meta_value' => $stock], ['post_id' => $post_id, 'meta_key' => '_stock']);
                    $wpdb->update($wpdb->postmeta, ['meta_value' => 'yes'], ['post_id' => $post_id, 'meta_key' => '_manage_stock']);

                    $log .= "Updated SKU: $sku | Price: $price | Stock: $stock\n";
                } else {
                    $log .= "SKU not found: $sku\n";
                }
            }
            $result->free();
        } else {
            $log = "Query error: " . $mysqli->error;
        }

        // --- BEGIN: Update parent product stock by summing variant stocks ---

        // Get all parent IDs for variations
        $parent_stocks = [];

        // Get all variations with their parent product IDs and stock
        $variations = $wpdb->get_results("
            SELECT p.ID AS variation_id, 
                   pm_sku.meta_value AS sku, 
                   pm_parent.meta_value AS parent_id
            FROM {$wpdb->prefix}posts p
            LEFT JOIN {$wpdb->prefix}postmeta pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->prefix}postmeta pm_parent ON p.post_parent = pm_parent.post_id AND pm_parent.meta_key = '_stock' -- This line is not correct for parent id
            WHERE p.post_type = 'product_variation'
        ");

        // Instead, get parent IDs directly from post_parent column:
        $variations = $wpdb->get_results("
            SELECT p.ID AS variation_id,
                   pm_sku.meta_value AS sku,
                   p.post_parent AS parent_id
            FROM {$wpdb->prefix}posts p
            LEFT JOIN {$wpdb->prefix}postmeta pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            WHERE p.post_type = 'product_variation'
        ");

        foreach ($variations as $var) {
            $stock = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = %d AND meta_key = '_stock'",
                $var->variation_id
            ));

            if ($var->parent_id) {
                if (!isset($parent_stocks[$var->parent_id])) {
                    $parent_stocks[$var->parent_id] = 0;
                }
                $parent_stocks[$var->parent_id] += $stock;
            }
        }

        // Update the parent products with the summed stock
        foreach ($parent_stocks as $parent_id => $total_stock) {
            $wpdb->update($wpdb->postmeta, ['meta_value' => $total_stock], ['post_id' => $parent_id, 'meta_key' => '_stock']);
            $wpdb->update($wpdb->postmeta, ['meta_value' => 'yes'], ['post_id' => $parent_id, 'meta_key' => '_manage_stock']);
            $log .= "Updated parent product ID $parent_id with stock: $total_stock\n";
        }

        // --- END: Update parent product stock ---

        $mysqli->close();
        update_option($this->log_option, $log . "Last Sync: " . current_time('mysql'));
    }
}

new WC_UltimatePOS_Sync();
/* Developed by nerghum */
