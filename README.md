# WooCommerce POS Stock Sync for Ultimate POS

**Contributors:** Nerghum  
**Tags:** WooCommerce, UltimatePOS, inventory sync, POS integration, WooCommerce stock  
**Requires at least:** 5.0  
**Tested up to:** 6.5  
**Stable tag:** 1.1  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

A simple but powerful plugin to **sync product stock and price** from your **UltimatePOS** database to **WooCommerce** using SKU. Supports manual syncing, scheduled cron jobs, and logs for tracking.

---

## 🔧 Features

- 🔁 **Automated Syncing:** Sync stock and pricing via cron job at your preferred interval.
- 🧮 **Variant Stock Summing:** Automatically updates parent product stock by summing variant stock.
- 🔍 **SKU Matching:** Matches products using WooCommerce `_sku` meta.
- 📝 **Logs:** View last sync log in admin panel.
- ⚙️ **Settings UI:** Set DB connection, enable/disable cron, set custom sync intervals.

---

## 📥 Installation

1. Upload the plugin files to `/wp-content/plugins/wc-ultimatepos-sync/` or install via WordPress Plugins.
2. Activate the plugin.
3. Navigate to **Ultimate POS Sync** in the WordPress admin menu.
4. Enter your **UltimatePOS DB credentials** and configure cron options.
5. Click **Save Settings** and optionally run a **Manual Sync**.

---

## ⚙️ Configuration

### Database Connection
- **DB Host:** Usually `localhost` or a remote host IP.
- **DB Name, DB User, DB Password:** Your UltimatePOS database credentials.

### Cron Sync
- **Enable Cron Job:** Enables automated syncing.
- **Interval:** Choose `hourly`, `twicedaily`, or `daily`.
- **Custom Interval (minutes):** Optional override. E.g., set `15` for every 15 minutes.

---

## 🛠️ How It Works

1. Connects to your UltimatePOS MySQL database.
2. Fetches all product SKUs, prices, and total available quantity across locations.
3. Updates WooCommerce products and variations using `_sku`.
4. Calculates total stock of variable products and updates parent product.
5. Saves logs for reference.

---

## 🧪 Example SQL Query Used

```sql
SELECT variations.sub_sku AS sku, 
       variations.default_sell_price AS price, 
       SUM(loc.qty_available) AS stock
FROM variations
JOIN variation_location_details loc ON variations.id = loc.variation_id
GROUP BY variations.sub_sku
