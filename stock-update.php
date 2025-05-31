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

class WC_POS_Stock_Sync {
    private $options;
    private $report = '';

    public function __construct() {
        $this->options = get_option('wc_pos_stock_sync_options');

        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wc_pos_stock_sync_cron', [$this, 'update_stocks']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Handle manual update button action
        add_action('admin_post_wc_pos_stock_sync_manual_update', [$this, 'handle_manual_update']);
    }

    public function create_admin_menu() {
        add_options_page(
            'POS Stock Sync',
            'POS Stock Sync',
            'manage_options',
            'wc-pos-stock-sync',
            [$this, 'admin_page']
        );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>POS Stock Sync Settings</h1>
            <?php if (!empty($_GET['updated_now'])): ?>
                <div class="notice notice-success">
                    <p><strong>Manual Update Report:</strong></p>
                    <pre><?php echo esc_html(get_transient('wc_pos_stock_sync_report')); ?></pre>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_pos_stock_sync_group');
                do_settings_sections('wc-pos-stock-sync');
                submit_button();
                ?>
            </form>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="wc_pos_stock_sync_manual_update">
                <?php wp_nonce_field('wc_pos_stock_sync_manual_update'); ?>
                <p>
                    <button type="submit" class="button button-primary">Update Stock Now</button>
                </p>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('wc_pos_stock_sync_group', 'wc_pos_stock_sync_options', [$this, 'sanitize_settings']);

        add_settings_section('wc_pos_stock_sync_section', 'Database Settings', null, 'wc-pos-stock-sync');

        add_settings_field('db_host', 'DB Host', [$this, 'text_field'], 'wc-pos-stock-sync', 'wc_pos_stock_sync_section', ['label_for' => 'db_host']);
        add_settings_field('db_name', 'DB Name', [$this, 'text_field'], 'wc-pos-stock-sync', 'wc_pos_stock_sync_section', ['label_for' => 'db_name']);
        add_settings_field('db_user', 'DB User', [$this, 'text_field'], 'wc-pos-stock-sync', 'wc_pos_stock_sync_section', ['label_for' => 'db_user']);
        add_settings_field('db_pass', 'DB Password', [$this, 'text_field'], 'wc-pos-stock-sync', 'wc_pos_stock_sync_section', ['label_for' => 'db_pass']);
        add_settings_field('auto_update', 'Auto Update', [$this, 'checkbox_field'], 'wc-pos-stock-sync', 'wc_pos_stock_sync_section', ['label_for' => 'auto_update']);
        add_settings_field('update_interval', 'Update Interval (minutes)', [$this, 'number_field'], 'wc-pos-stock-sync', 'wc_pos_stock_sync_section', ['label_for' => 'update_interval']);
    }

    public function text_field($args) {
        $id = $args['label_for'];
        $value = isset($this->options[$id]) ? esc_attr($this->options[$id]) : '';
        echo "<input type='text' id='$id' name='wc_pos_stock_sync_options[$id]' value='$value' class='regular-text'>";
    }

    public function number_field($args) {
        $id = $args['label_for'];
        $value = isset($this->options[$id]) ? esc_attr($this->options[$id]) : '';
        echo "<input type='number' id='$id' name='wc_pos_stock_sync_options[$id]' value='$value' min='1' class='small-text'>";
    }

    public function checkbox_field($args) {
        $id = $args['label_for'];
        $checked = isset($this->options[$id]) && $this->options[$id] == '1' ? 'checked' : '';
        echo "<input type='checkbox' id='$id' name='wc_pos_stock_sync_options[$id]' value='1' $checked>";
    }

    public function sanitize_settings($input) {
        $input['db_host'] = sanitize_text_field($input['db_host']);
        $input['db_name'] = sanitize_text_field($input['db_name']);
        $input['db_user'] = sanitize_text_field($input['db_user']);
        $input['db_pass'] = sanitize_text_field($input['db_pass']);
        $input['auto_update'] = isset($input['auto_update']) ? '1' : '';
        $input['update_interval'] = intval($input['update_interval']);

        if ($input['auto_update'] == '1' && $input['update_interval'] > 0) {
            if (!wp_next_scheduled('wc_pos_stock_sync_cron')) {
                wp_schedule_event(time(), 'wc_pos_stock_sync_interval', 'wc_pos_stock_sync_cron');
            }
        } else {
            wp_clear_scheduled_hook('wc_pos_stock_sync_cron');
        }

        return $input;
    }

    public function add_cron_schedules($schedules) {
        if (isset($this->options['update_interval']) && $this->options['update_interval'] > 0) {
            $interval = $this->options['update_interval'] * 60;
            $schedules['wc_pos_stock_sync_interval'] = [
                'interval' => $interval,
                'display'  => 'POS Stock Sync Interval'
            ];
        }
        return $schedules;
    }

    /**
     * Manual Update Handler
     */
    public function handle_manual_update() {
        check_admin_referer('wc_pos_stock_sync_manual_update');

        $this->update_stocks();

        wp_redirect(add_query_arg('updated_now', '1', wp_get_referer()));
        exit;
    }

    /**
     * Update Stocks and Report
     */
    public function update_stocks() {
        global $wpdb;

        $db_host = $this->options['db_host'];
        $db_name = $this->options['db_name'];
        $db_user = $this->options['db_user'];
        $db_pass = $this->options['db_pass'];

        $report = '';

        try {
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "
                SELECT
                    P.name AS product_name,
                    P.sku,
                    SUM(PL.qty_available) AS total_stock
                FROM
                    variations V
                JOIN
                    products P ON V.product_id = P.id
                JOIN
                    variation_location_details PL ON V.id = PL.variation_id
                GROUP BY
                    P.sku, P.name
                ORDER BY
                    P.name ASC;
            ";

            $stmt = $pdo->query($sql);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($results) {
                foreach ($results as $row) {
                    $sku = $row['sku'];
                    $stock = intval($row['total_stock']);
                    $product_id = wc_get_product_id_by_sku($sku);

                    if ($product_id) {
                        update_post_meta($product_id, '_stock', $stock);
                        $status = $stock > 0 ? 'instock' : 'outofstock';
                        update_post_meta($product_id, '_stock_status', $status);
                        update_post_meta($product_id, '_manage_stock', 'yes');

                        $report .= "✔️ Updated SKU: $sku | Stock: $stock\n";
                    } else {
                        $report .= "❌ SKU not found in WooCommerce: $sku\n";
                    }
                }
            } else {
                $report .= "No products found in POS database.\n";
            }

        } catch (PDOException $e) {
            $report .= "Database error: " . $e->getMessage() . "\n";
        }

        // Store report for next admin page load
        set_transient('wc_pos_stock_sync_report', $report, 60);
    }

    public static function activate() {
        // Nothing to do here, cron will be set on settings save
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('wc_pos_stock_sync_cron');
    }
}

$wc_pos_stock_sync = new WC_POS_Stock_Sync();
register_activation_hook(__FILE__, ['WC_POS_Stock_Sync', 'activate']);
register_deactivation_hook(__FILE__, ['WC_POS_Stock_Sync', 'deactivate']);
