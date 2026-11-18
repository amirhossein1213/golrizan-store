<?php
declare(strict_types=1);

const MOUSAVI_SCHEMA_VERSION = '2.10.50';
const MOUSAVI_SCHEMA_SIGNATURE = '2.10.50-snappay-production-compliance';

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function db_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}


function db_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$stmt->execute([$table,$index]);return (int)$stmt->fetchColumn()>0;
}
function db_constraint_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$stmt->execute([$table,$constraint]);return (int)$stmt->fetchColumn()>0;
}
function db_constraint_delete_rule(PDO $pdo,string $table,string $constraint): string
{
    $stmt=$pdo->prepare('SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? LIMIT 1');$stmt->execute([$table,$constraint]);return strtoupper((string)($stmt->fetchColumn()?:''));
}

function migration_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!db_table_exists($pdo, $table)) throw new RuntimeException("جدول پایه {$table} در دیتابیس وجود ندارد.");
    if (!db_column_exists($pdo, $table, $column)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function mousavi_schema_metadata(PDO $pdo): array
{
    // این متادیتا بعد از migration در همان request تغییر می‌کند؛ cache کردن آن باعث می‌شد نسخه قدیمی تا پایان request باقی بماند.
    $metadata = ['schema_version'=>'0.0.0','schema_signature'=>''];
    try {
        $stmt = $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('schema_version','schema_signature')");
        foreach ($stmt->fetchAll() as $row) {
            $settingKey = (string)($row['setting_key'] ?? '');
            if (array_key_exists($settingKey, $metadata)) $metadata[$settingKey] = trim((string)($row['setting_value'] ?? ''));
        }
    } catch (PDOException) {
        // نصب اولیه یا دیتابیس ناقص؛ مهاجرت مسیر ترمیم را اجرا می‌کند.
    }
    return $metadata;
}

function mousavi_schema_version(PDO $pdo): string
{
    $value = mousavi_schema_metadata($pdo)['schema_version'];
    return preg_match('/^\d+\.\d+\.\d+$/', $value) ? $value : '0.0.0';
}

function mousavi_schema_signature(PDO $pdo): string
{
    return mousavi_schema_metadata($pdo)['schema_signature'];
}

function mousavi_schema_missing(PDO $pdo): array
{
    $missing = [];
    foreach (['admin_roles','admin_role_permissions','admin_activity_logs','admin_recovery_codes','backup_runs','admin_notifications','admin_notification_recipients','admin_notification_preferences','price_adjustment_batches','price_adjustment_items','product_features','festivals','festival_products','accounting_accounts','accounting_entries','accounting_lines','accounting_expenses','customers','customer_addresses','customer_otp_codes','customer_wishlists','coupons','coupon_usages','abandoned_carts','product_reviews','product_questions','sms_campaigns','sms_campaign_recipients','sms_logs','order_status_history','product_attributes','product_attribute_values','product_attribute_selections','product_variants','product_variant_values','product_gallery','product_specifications','product_alerts','support_tickets','support_ticket_messages','payment_transactions','return_requests','return_items','refund_transactions','damaged_inventory','suppliers','purchase_orders','purchase_order_items','supplier_payments','inventory_movements','shipping_carriers','shipments','shipment_events','blog_categories','blog_posts','contact_messages','home_banners','trust_items','faqs','bale_product_bot_sessions','bale_product_bot_updates','bale_product_bot_history','bale_product_bot_deliveries'] as $table) {
        if (!db_table_exists($pdo, $table)) $missing[] = 'table:' . $table;
    }
    $columns = [
        'admins' => ['role_id','two_factor_secret_enc','two_factor_enabled','last_password_change_at'],
        'products' => ['cost_price','weight_grams','shipping_extra','product_type','authenticity_guarantee','safe_shipping_guarantee','festival_enabled','festival_price','festival_free_shipping'],
        'categories' => ['meta_title','meta_description'],
        'customers' => ['first_name','last_name','password_hash','google_sub','google_picture_url','marketing_sms_enabled','email_verified_at'],
        'orders' => ['customer_id','checkout_request_hash','postal_status','postal_verified_at','postal_lookup_json','discount_amount','coupon_id','coupon_code','shipping_rule_id','shipping_festival_id','shipping_details_json','payment_markup_percent','payment_markup_amount','payment_markup_accepted_at','tracking_code','paid_at','shipped_at','delivered_at','installed_at','cancelled_at','stock_restored','assigned_admin_id','admin_internal_note','priority'],
        'order_items' => ['variant_id','variant_title','variant_options_json','base_unit_price','unit_cost','festival_id','festival_title','festival_saving','line_cost'],
        'return_items' => ['inventory_processed_at'],
        'festivals' => ['festival_type','scope','discount_type','discount_value','target_id','starts_at','ends_at','priority','banner_text','show_on_home','is_active','is_torobpay_special','limited_discount_channel','torobpay_old_price','torobpay_new_price','torobpay_stock_snapshot','torobpay_popularity_reason'],
        'sms_logs' => ['delivery_state','delivery_status','delivery_reason','delivery_last_error','delivery_checked_at','delivered_at'],
        'contact_messages' => ['department'],
        'support_ticket_messages' => ['actor_label'],
    ];
    foreach ($columns as $table => $names) {
        if (!db_table_exists($pdo, $table)) { $missing[] = 'table:' . $table; continue; }
        foreach ($names as $column) if (!db_column_exists($pdo, $table, $column)) $missing[] = 'column:' . $table . '.' . $column;
    }
    return array_values(array_unique($missing));
}

function mousavi_schema_is_current(PDO $pdo, bool $deepCheck = false): bool
{
    if (version_compare(mousavi_schema_version($pdo), MOUSAVI_SCHEMA_VERSION, '<')) return false;
    if (!$deepCheck) return mousavi_schema_signature($pdo) === MOUSAVI_SCHEMA_SIGNATURE;
    return mousavi_schema_missing($pdo) === [];
}

function migration_schema_statements(string $schema): array
{
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];
    $wantedTables = ['admin_roles','admin_role_permissions','admin_activity_logs','admin_recovery_codes','backup_runs','admin_notifications','admin_notification_recipients','admin_notification_preferences','price_adjustment_batches','price_adjustment_items','product_features','festivals','festival_products','accounting_accounts','accounting_entries','accounting_lines','accounting_expenses','customers','customer_addresses','customer_otp_codes','customer_wishlists','coupons','coupon_usages','abandoned_carts','product_reviews','product_questions','sms_campaigns','sms_campaign_recipients','sms_logs','order_status_history','product_attributes','product_attribute_values','product_attribute_selections','product_variants','product_variant_values','product_gallery','product_specifications','product_alerts','support_tickets','support_ticket_messages','payment_transactions','return_requests','return_items','refund_transactions','damaged_inventory','suppliers','purchase_orders','purchase_order_items','supplier_payments','inventory_movements','shipping_carriers','shipments','shipment_events','blog_categories','blog_posts','contact_messages','home_banners','trust_items','faqs','bale_product_bot_sessions','bale_product_bot_updates','bale_product_bot_history','bale_product_bot_deliveries'];
    $result = [];
    foreach ($statements as $statement) {
        $sql = trim($statement);
        if ($sql === '') continue;
        if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([a-z0-9_]+)`?/i', $sql, $match)) {
            if (in_array(strtolower($match[1]), $wantedTables, true)) $result[] = $sql;
            continue;
        }
        if (preg_match('/^INSERT\s+IGNORE\s+INTO\s+`?accounting_accounts`?/i', $sql)) $result[] = $sql;
    }
    return $result;
}

function run_mousavi_migrations(PDO $pdo): array
{
    $sourceSchemaVersion = mousavi_schema_version($pdo);
    $resetLegacyMarketingConsent = version_compare($sourceSchemaVersion,'2.10.30','<');
    $hadStockRestoredColumn = db_column_exists($pdo, 'orders', 'stock_restored');
    $hadLimitedDiscountChannel = db_column_exists($pdo, 'festivals', 'limited_discount_channel');

    $pdo->exec("CREATE TABLE IF NOT EXISTS bale_product_bot_sessions (
      chat_id VARCHAR(64) PRIMARY KEY,
      user_id VARCHAR(64) NOT NULL,
      step VARCHAR(60) NOT NULL,
      payload_json MEDIUMTEXT NULL,
      expires_at DATETIME NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_bale_product_bot_sessions_expire (expires_at),
      INDEX idx_bale_product_bot_sessions_user (user_id,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bale_product_bot_updates (
      update_id BIGINT NOT NULL PRIMARY KEY,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_bale_product_bot_updates_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bale_product_bot_history (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id INT UNSIGNED NULL,
      chat_id VARCHAR(64) NOT NULL,
      user_id VARCHAR(64) NOT NULL,
      sku VARCHAR(100) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_bale_product_bot_history_product (product_id,created_at),
      INDEX idx_bale_product_bot_history_user (user_id,created_at),
      CONSTRAINT fk_bale_product_bot_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bale_product_bot_deliveries (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_key VARCHAR(190) NOT NULL,
      chat_id VARCHAR(64) NOT NULL,
      message_id BIGINT NULL,
      status ENUM('sending','sent','failed') NOT NULL DEFAULT 'sending',
      error_message VARCHAR(500) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_bale_product_bot_delivery (event_key,chat_id),
      INDEX idx_bale_product_bot_delivery_status (status,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS price_adjustment_batches (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      direction VARCHAR(10) NOT NULL,
      basis_points SMALLINT UNSIGNED NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'applied',
      source VARCHAR(20) NOT NULL DEFAULT 'admin',
      actor_label VARCHAR(190) NULL,
      product_count INT UNSIGNED NOT NULL DEFAULT 0,
      variant_count INT UNSIGNED NOT NULL DEFAULT 0,
      skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
      created_by INT UNSIGNED NULL,
      reverted_by INT UNSIGNED NULL,
      reverted_actor_label VARCHAR(190) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      reverted_at DATETIME NULL,
      INDEX idx_price_adjustment_status (status,id),
      INDEX idx_price_adjustment_created (created_at),
      CONSTRAINT fk_price_adjustment_creator FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
      CONSTRAINT fk_price_adjustment_reverter FOREIGN KEY (reverted_by) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_adjustment_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      batch_id BIGINT UNSIGNED NOT NULL,
      item_type VARCHAR(10) NOT NULL,
      product_id INT UNSIGNED NOT NULL,
      variant_id BIGINT UNSIGNED NULL,
      price_before BIGINT UNSIGNED NOT NULL,
      price_after BIGINT UNSIGNED NOT NULL,
      compare_price_before BIGINT UNSIGNED NULL,
      compare_price_after BIGINT UNSIGNED NULL,
      festival_price_before BIGINT UNSIGNED NULL,
      festival_price_after BIGINT UNSIGNED NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_price_adjustment_items_batch (batch_id,item_type,id),
      INDEX idx_price_adjustment_items_product (product_id,variant_id),
      CONSTRAINT fk_price_adjustment_item_batch FOREIGN KEY (batch_id) REFERENCES price_adjustment_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_key VARCHAR(80) NOT NULL,
      title VARCHAR(180) NOT NULL,
      message VARCHAR(500) NOT NULL,
      level ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
      reference_type VARCHAR(60) NULL,
      reference_id BIGINT UNSIGNED NULL,
      action_path VARCHAR(255) NULL,
      dedupe_key VARCHAR(190) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_admin_notification_dedupe (dedupe_key),
      INDEX idx_admin_notifications_event (event_key,created_at),
      INDEX idx_admin_notifications_reference (reference_type,reference_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notification_recipients (
      notification_id BIGINT UNSIGNED NOT NULL,
      admin_id INT UNSIGNED NOT NULL,
      seen_at DATETIME NULL,
      read_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (notification_id,admin_id),
      INDEX idx_admin_notification_unread (admin_id,read_at,notification_id),
      CONSTRAINT fk_admin_notification_recipient_notification FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE,
      CONSTRAINT fk_admin_notification_recipient_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notification_preferences (
      admin_id INT UNSIGNED PRIMARY KEY,
      in_panel TINYINT(1) NOT NULL DEFAULT 1,
      browser_enabled TINYINT(1) NOT NULL DEFAULT 1,
      sound_enabled TINYINT(1) NOT NULL DEFAULT 1,
      notify_new_order TINYINT(1) NOT NULL DEFAULT 1,
      notify_payment_success TINYINT(1) NOT NULL DEFAULT 1,
      notify_order_cancelled TINYINT(1) NOT NULL DEFAULT 1,
      notify_return_requested TINYINT(1) NOT NULL DEFAULT 1,
      notify_low_stock TINYINT(1) NOT NULL DEFAULT 1,
      notify_sms_failed TINYINT(1) NOT NULL DEFAULT 1,
      notify_gateway_failed TINYINT(1) NOT NULL DEFAULT 1,
      notify_order_assigned TINYINT(1) NOT NULL DEFAULT 1,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_admin_notification_preferences_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // این دو جدول در مسیر ساخت و نمایش محصول جزو وابستگی‌های مستقیم‌اند. آن‌ها را
    // پیش از هر ALTER بساز تا نصب‌های قدیمی یا پچ‌شده در نیمه مهاجرت متوقف نشوند.
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_features (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id INT UNSIGNED NOT NULL,
      feature_text VARCHAR(255) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      INDEX idx_product_features_product (product_id,sort_order,id),
      CONSTRAINT fk_feature_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS festivals (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(180) NOT NULL,
      slug VARCHAR(190) NOT NULL UNIQUE,
      festival_type ENUM('price','free_shipping') NOT NULL DEFAULT 'price',
      scope ENUM('all','category','brand','product','group') NOT NULL DEFAULT 'product',
      discount_type ENUM('percent','fixed') NULL,
      discount_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
      target_id INT UNSIGNED NULL,
      starts_at DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00',
      ends_at DATETIME NOT NULL DEFAULT '2099-12-31 23:59:59',
      priority INT NOT NULL DEFAULT 0,
      banner_text VARCHAR(255) NULL,
      show_on_home TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      is_torobpay_special TINYINT(1) NOT NULL DEFAULT 0,
      limited_discount_channel VARCHAR(20) NOT NULL DEFAULT 'site',
      torobpay_old_price BIGINT UNSIGNED NULL,
      torobpay_new_price BIGINT UNSIGNED NULL,
      torobpay_stock_snapshot INT UNSIGNED NULL,
      torobpay_popularity_reason VARCHAR(180) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_festivals_active_dates (is_active,starts_at,ends_at),
      INDEX idx_festivals_home (show_on_home,is_active,starts_at,ends_at),
      INDEX idx_festivals_scope (festival_type,scope,target_id),
      INDEX idx_festivals_torobpay (is_torobpay_special,is_active,starts_at,ends_at),
      INDEX idx_festivals_limited (limited_discount_channel,is_torobpay_special,is_active,ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    migration_add_column($pdo, 'products', 'cost_price', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER price');
    migration_add_column($pdo, 'products', 'weight_grams', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER stock');
    migration_add_column($pdo, 'products', 'shipping_extra', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER weight_grams');
    migration_add_column($pdo, 'products', 'product_type', "ENUM('simple','variable') NOT NULL DEFAULT 'simple' AFTER meta_description");

    // ترمیم کامل ساختار جشنواره برای نصب‌هایی که جدول قدیمی یا ناقص دارند.
    migration_add_column($pdo, 'festivals', 'festival_type', "ENUM('price','free_shipping') NOT NULL DEFAULT 'price' AFTER slug");
    migration_add_column($pdo, 'festivals', 'scope', "ENUM('all','category','brand','product','group') NOT NULL DEFAULT 'product' AFTER festival_type");
    migration_add_column($pdo, 'festivals', 'discount_type', "ENUM('percent','fixed') NULL AFTER scope");
    migration_add_column($pdo, 'festivals', 'discount_value', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_type');
    migration_add_column($pdo, 'festivals', 'target_id', 'INT UNSIGNED NULL AFTER discount_value');
    migration_add_column($pdo, 'festivals', 'starts_at', "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00' AFTER target_id");
    migration_add_column($pdo, 'festivals', 'ends_at', "DATETIME NOT NULL DEFAULT '2099-12-31 23:59:59' AFTER starts_at");
    migration_add_column($pdo, 'festivals', 'priority', 'INT NOT NULL DEFAULT 0 AFTER ends_at');
    migration_add_column($pdo, 'festivals', 'banner_text', 'VARCHAR(255) NULL AFTER priority');
    migration_add_column($pdo, 'festivals', 'show_on_home', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER banner_text');
    migration_add_column($pdo, 'festivals', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER show_on_home');
    migration_add_column($pdo, 'festivals', 'is_torobpay_special', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    migration_add_column($pdo, 'festivals', 'limited_discount_channel', "VARCHAR(20) NOT NULL DEFAULT 'site' AFTER is_torobpay_special");
    migration_add_column($pdo, 'festivals', 'torobpay_old_price', 'BIGINT UNSIGNED NULL AFTER limited_discount_channel');
    migration_add_column($pdo, 'festivals', 'torobpay_new_price', 'BIGINT UNSIGNED NULL AFTER torobpay_old_price');
    migration_add_column($pdo, 'festivals', 'torobpay_stock_snapshot', 'INT UNSIGNED NULL AFTER torobpay_new_price');
    migration_add_column($pdo, 'festivals', 'torobpay_popularity_reason', 'VARCHAR(180) NULL AFTER torobpay_stock_snapshot');
    if (!db_index_exists($pdo, 'festivals', 'idx_festivals_torobpay')) $pdo->exec('ALTER TABLE festivals ADD INDEX idx_festivals_torobpay (is_torobpay_special,is_active,starts_at,ends_at)');
    if (!db_index_exists($pdo, 'festivals', 'idx_festivals_limited')) $pdo->exec('ALTER TABLE festivals ADD INDEX idx_festivals_limited (limited_discount_channel,is_torobpay_special,is_active,ends_at)');
    if (!$hadLimitedDiscountChannel) $pdo->exec("UPDATE festivals SET limited_discount_channel='torobpay' WHERE is_torobpay_special=1");
    migration_add_column($pdo, 'categories', 'meta_title', 'VARCHAR(255) NULL AFTER image_path');
    migration_add_column($pdo, 'categories', 'meta_description', 'VARCHAR(320) NULL AFTER meta_title');

    migration_add_column($pdo, 'orders', 'customer_id', 'BIGINT UNSIGNED NULL AFTER id');
    migration_add_column($pdo, 'orders', 'checkout_request_hash', 'CHAR(64) NULL AFTER checkout_token');

    migration_add_column($pdo, 'orders', 'postal_status', "ENUM('local_valid','api_verified','api_failed','unchecked') NOT NULL DEFAULT 'unchecked' AFTER postal_code");
    migration_add_column($pdo, 'orders', 'postal_verified_at', 'DATETIME NULL AFTER postal_status');
    migration_add_column($pdo, 'orders', 'postal_lookup_json', 'MEDIUMTEXT NULL AFTER postal_verified_at');
    migration_add_column($pdo, 'orders', 'shipping_rule_id', 'INT UNSIGNED NULL AFTER shipping_amount');
    migration_add_column($pdo, 'orders', 'shipping_festival_id', 'INT UNSIGNED NULL AFTER shipping_rule_id');
    migration_add_column($pdo, 'orders', 'shipping_details_json', 'TEXT NULL AFTER shipping_festival_id');
    migration_add_column($pdo, 'orders', 'tracking_code', 'VARCHAR(120) NULL AFTER gateway_reference');
    migration_add_column($pdo, 'orders', 'paid_at', 'DATETIME NULL AFTER tracking_code');
    migration_add_column($pdo, 'orders', 'shipped_at', 'DATETIME NULL AFTER paid_at');
    migration_add_column($pdo, 'orders', 'delivered_at', 'DATETIME NULL AFTER shipped_at');
    migration_add_column($pdo, 'orders', 'installed_at', 'DATETIME NULL AFTER delivered_at');
    migration_add_column($pdo, 'orders', 'cancelled_at', 'DATETIME NULL AFTER installed_at');
    migration_add_column($pdo, 'orders', 'stock_restored', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER cancelled_at');
    migration_add_column($pdo, 'orders', 'payment_markup_percent', "DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0 AFTER shipping_details_json");
    migration_add_column($pdo, 'orders', 'payment_markup_amount', "BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_markup_percent");
    migration_add_column($pdo, 'orders', 'payment_markup_accepted_at', 'DATETIME NULL AFTER payment_markup_amount');
    migration_add_column($pdo, 'orders', 'assigned_admin_id', 'INT UNSIGNED NULL AFTER stock_restored');
    migration_add_column($pdo, 'orders', 'admin_internal_note', 'TEXT NULL AFTER assigned_admin_id');
    migration_add_column($pdo, 'orders', 'priority', "ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER admin_internal_note");
    if(!db_index_exists($pdo,'orders','idx_orders_assigned'))$pdo->exec('ALTER TABLE orders ADD INDEX idx_orders_assigned (assigned_admin_id,order_status,created_at)');
    if(!db_constraint_exists($pdo,'orders','fk_orders_assigned_admin'))$pdo->exec('ALTER TABLE orders ADD CONSTRAINT fk_orders_assigned_admin FOREIGN KEY (assigned_admin_id) REFERENCES admins(id) ON DELETE SET NULL');
    if (!$hadStockRestoredColumn) {
        // نسخه‌های قدیمی هنگام لغو موجودی را برمی‌گرداندند، اما پرچم جلوگیری از تکرار نداشتند.
        $pdo->exec("UPDATE orders SET stock_restored=1 WHERE order_status IN ('cancelled','refunded')");
    }

    migration_add_column($pdo, 'order_items', 'variant_id', 'BIGINT UNSIGNED NULL AFTER product_id');
    migration_add_column($pdo, 'order_items', 'variant_title', 'VARCHAR(255) NULL AFTER title');
    migration_add_column($pdo, 'order_items', 'variant_options_json', 'TEXT NULL AFTER variant_title');
    migration_add_column($pdo, 'order_items', 'base_unit_price', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_price');
    migration_add_column($pdo, 'order_items', 'unit_cost', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER base_unit_price');
    migration_add_column($pdo, 'order_items', 'festival_id', 'INT UNSIGNED NULL AFTER unit_cost');
    migration_add_column($pdo, 'order_items', 'festival_title', 'VARCHAR(180) NULL AFTER festival_id');
    migration_add_column($pdo, 'order_items', 'festival_saving', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER festival_title');
    migration_add_column($pdo, 'order_items', 'line_cost', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER line_total');

    // ترمیم مستقیم جدول مشخصات فنی؛ این بخش به فایل schema وابسته نیست و برای نصب‌های پچ‌شده ضروری است.
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_specifications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id INT UNSIGNED NOT NULL,
      spec_name VARCHAR(180) NOT NULL,
      spec_value VARCHAR(2000) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_product_specifications_product (product_id,sort_order,id),
      CONSTRAINT fk_product_specification_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $schema = file_get_contents(BASE_PATH . '/install/schema.sql');
    if (!is_string($schema)) throw new RuntimeException('فایل ساختار دیتابیس خوانده نشد.');
    foreach (migration_schema_statements($schema) as $sql) $pdo->exec($sql);
    migration_add_column($pdo, 'sms_logs', 'delivery_state', 'SMALLINT NULL AFTER message_id');
    migration_add_column($pdo, 'sms_logs', 'delivery_status', 'VARCHAR(32) NULL AFTER delivery_state');
    migration_add_column($pdo, 'sms_logs', 'delivery_reason', 'VARCHAR(255) NULL AFTER delivery_status');
    migration_add_column($pdo, 'sms_logs', 'delivery_last_error', 'VARCHAR(255) NULL AFTER delivery_reason');
    migration_add_column($pdo, 'sms_logs', 'delivery_checked_at', 'DATETIME NULL AFTER delivery_last_error');
    migration_add_column($pdo, 'sms_logs', 'delivered_at', 'DATETIME NULL AFTER delivery_checked_at');
    if (!db_index_exists($pdo, 'sms_logs', 'idx_sms_logs_delivery')) $pdo->exec('ALTER TABLE sms_logs ADD INDEX idx_sms_logs_delivery (delivery_status,delivery_checked_at)');

    if ((int)$pdo->query('SELECT COUNT(*) FROM home_banners')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO home_banners(title,subtitle,eyebrow,button_label,link_url,image_path,layout_size,sort_order,is_active) VALUES
            ('نظافت سریع‌تر خانه','جاروبرقی‌های کاربردی با مکش مناسب و مشخصات شفاف','نظافت خانه','مشاهده جاروبرقی‌ها','shop.php?q=جاروبرقی','assets/images/products/vacuum.svg','large',10,1),
            ('لباس‌های مرتب‌تر','اتوهای بخار و خشک برای استفاده روزمره','آماده‌سازی لباس','مشاهده اتوها','shop.php?q=اتو','assets/images/products/iron.svg','medium',20,1),
            ('خنکای ساده و اقتصادی','پنکه‌های مناسب اتاق و فضای خانه','روزهای گرم','مشاهده پنکه‌ها','shop.php?q=پنکه','assets/images/products/fan.svg','small',30,1),
            ('لوازم کوچک کاربردی','انتخاب‌های روزمره برای آشپزخانه و خانه','انتخاب بیشتر','مشاهده محصولات','shop.php?q=لوازم کوچک','assets/images/products/blender.svg','small',40,1)");
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM trust_items')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO trust_items(title,summary,details,icon_key,sort_order,is_active) VALUES
            ('ضمانت اصالت کالا','مشخصات و گارانتی هر مدل پیش از خرید شفاف است.','عنوان گارانتی و اطلاعات ثبت‌شده برای هر محصول در صفحه همان کالا نمایش داده می‌شود تا پیش از سفارش تصمیم دقیق‌تری بگیرید.','shield',10,1),
            ('ارسال عادی پس‌کرایه','هزینه حمل سفارش عادی هنگام تحویل پرداخت می‌شود.','روش ارسال و هر جشنواره ارسال رایگان پیش از ثبت نهایی سفارش نمایش داده می‌شود.','truck',20,1),
            ('قیمت و موجودی شفاف','اطلاعات فروش مستقیماً از پنل فروشگاه نمایش داده می‌شود.','قیمت، موجودی و ویژگی‌های ثبت‌شده هر محصول را در صفحه محصول مشاهده و مدل‌های هم‌گروه را مقایسه کنید.','receipt',30,1),
            ('مشاوره پیش از خرید','برای انتخاب مدل متناسب با نیازتان با فروشگاه تماس بگیرید.','راه ارتباطی فروشگاه در تمام صفحات در دسترس است و می‌توانید پیش از سفارش درباره مدل و گارانتی سؤال کنید.','support',40,1),
            ('پیگیری مرحله‌به‌مرحله','وضعیت سفارش و کد رهگیری در حساب مشتری قابل مشاهده است.','پس از ثبت سفارش، وضعیت‌های پردازش و ارسال از حساب کاربری یا صفحه رهگیری سفارش در دسترس شماست.','tracking',50,1),
            ('درخواست مرجوعی آنلاین','درخواست‌های مشمول شرایط از حساب مشتری ثبت می‌شوند.','شرایط مرجوعی پیش از خرید در سایت درج شده و درخواست واجد شرایط از پنل مشتری پیگیری می‌شود.','return',60,1)");
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM faqs')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO faqs(category_key,question,answer,sort_order,is_active) VALUES
            ('purchase','چگونه از موجودبودن کالا مطمئن شوم؟','موجودی نمایش‌داده‌شده در صفحه محصول از پنل فروشگاه دریافت می‌شود. برای کالای ناموجود می‌توانید هشدار موجودشدن ثبت کنید.',10,1),
            ('purchase','آیا برای خرید باید از قبل حساب داشته باشم؟','خیر. سفارش مهمان بدون اتصال به حساب ثبت می‌شود. پس از تأیید شماره همراه با کد یک‌بارمصرف، سفارش‌های همان شماره به‌صورت امن در حساب مشتری نمایش داده می‌شوند.',20,1),
            ('payment','چه روش‌هایی برای پرداخت وجود دارد؟','روش‌های فعال فروشگاه در مرحله پرداخت نمایش داده می‌شوند. پرداخت آنلاین زیبال یا هماهنگی پرداخت فقط در صورت فعال‌بودن در پنل قابل انتخاب است.',30,1),
            ('shipping','هزینه ارسال چگونه محاسبه می‌شود؟','ارسال عادی به‌صورت پس‌کرایه است و هزینه حمل هنگام تحویل دریافت می‌شود. در جشنواره ارسال رایگان، این موضوع پیش از ثبت سفارش نمایش داده می‌شود.',40,1),
            ('shipping','چگونه سفارش را پیگیری کنم؟','از حساب مشتری یا صفحه رهگیری سفارش می‌توانید آخرین وضعیت و کد رهگیری ثبت‌شده را مشاهده کنید.',50,1),
            ('warranty','گارانتی کالاها چگونه مشخص می‌شود؟','نام یا مدت گارانتی هر محصول در همان صفحه کالا نمایش داده می‌شود. پیش از خرید، اطلاعات گارانتی مدل انتخابی را بررسی کنید.',60,1),
            ('returns','درخواست مرجوعی چگونه ثبت می‌شود؟','اگر سفارش مشمول شرایط مرجوعی باشد، درخواست از حساب مشتری ثبت و نتیجه بررسی در همان بخش پیگیری می‌شود.',70,1),
            ('service','آیا فروشگاه خدمات نصب ارائه می‌دهد؟','خیر. فروشگاه لوازم خانگی گلریزان خدمات نصب یا نصاب ارائه نمی‌دهد و فقط اطلاعات گارانتی کالا را ثبت می‌کند.',80,1)");
    }
    migration_add_column($pdo, 'festivals', 'show_on_home', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER banner_text');
    if (!db_index_exists($pdo, 'festivals', 'idx_festivals_home')) $pdo->exec('ALTER TABLE festivals ADD INDEX idx_festivals_home (show_on_home,is_active,starts_at,ends_at)');
    $pdo->exec("INSERT IGNORE INTO blog_categories(name,slug,description,sort_order,is_active) VALUES ('راهنمای خرید','buying-guide','راهنماهای انتخاب، مقایسه و خرید لوازم خانگی',10,1)");
    $pdo->exec("INSERT IGNORE INTO product_gallery(product_id,image_path,alt_text,sort_order,is_primary) SELECT id,image_path,title,0,1 FROM products WHERE image_path IS NOT NULL AND image_path<>''");
    $pdo->exec("INSERT IGNORE INTO shipping_carriers(name,code,phone,tracking_url_template,is_active,sort_order) VALUES ('پست جمهوری اسلامی ایران','post','193','https://tracking.post.ir/?id={code}',1,10),('تیپاکس','tipax','0218457','https://tipaxco.com/tracking?id={code}',1,20),('باربری / حمل اختصاصی','freight',NULL,NULL,1,30),('پیک فروشگاه','store-courier',NULL,NULL,1,40)");
    $pdo->exec("INSERT IGNORE INTO accounting_accounts(code,name,account_type,normal_balance,system_key) VALUES ('4400','تخفیف فروش','revenue','debit','sales_discounts')");
    $pdo->exec("INSERT IGNORE INTO admin_role_permissions(role_id,permission_key) SELECT role_id,'manage_reviews' FROM admin_role_permissions WHERE permission_key='manage_content'");
    $pdo->exec("INSERT IGNORE INTO admin_role_permissions(role_id,permission_key) SELECT role_id,'manage_marketing' FROM admin_role_permissions WHERE permission_key IN ('manage_sms','manage_products')");
    $pdo->exec("INSERT IGNORE INTO admin_role_permissions(role_id,permission_key) SELECT role_id,'view_reports' FROM admin_role_permissions WHERE permission_key='manage_accounting'");
    $pdo->exec("INSERT IGNORE INTO admin_role_permissions(role_id,permission_key) SELECT role_id,'manage_tickets' FROM admin_role_permissions WHERE permission_key IN ('manage_content','manage_customers')");
    $pdo->exec("INSERT IGNORE INTO admin_role_permissions(role_id,permission_key) SELECT role_id,'manage_blog' FROM admin_role_permissions WHERE permission_key='manage_content'");
    migration_add_column($pdo, 'admins', 'role_id', 'INT UNSIGNED NULL AFTER id');
    migration_add_column($pdo, 'admins', 'two_factor_secret_enc', 'TEXT NULL AFTER is_active');
    migration_add_column($pdo, 'admins', 'two_factor_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_secret_enc');
    migration_add_column($pdo, 'admins', 'last_password_change_at', 'DATETIME NULL AFTER two_factor_enabled');
    migration_add_column($pdo, 'customers', 'marketing_sms_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER email');
    $pdo->exec('ALTER TABLE customers MODIFY COLUMN marketing_sms_enabled TINYINT(1) NOT NULL DEFAULT 0');
    // فقط ارتقا از نسخه‌های قبل از 2.10.30 نیازمند پاک‌سازی یک‌باره رضایت‌های قدیمی است.
    // اجرای دوباره این دستور در نسخه‌های بعدی، رضایت صریحی را که مشتری تازه ثبت کرده از بین می‌برد.
    if ($resetLegacyMarketingConsent) $pdo->exec('UPDATE customers SET marketing_sms_enabled=0 WHERE marketing_sms_enabled<>0');
    $pdo->exec('ALTER TABLE customers MODIFY COLUMN phone VARCHAR(20) NULL');
    migration_add_column($pdo, 'customers', 'first_name', 'VARCHAR(80) NULL AFTER phone');
    migration_add_column($pdo, 'customers', 'last_name', 'VARCHAR(100) NULL AFTER first_name');
    migration_add_column($pdo, 'customers', 'password_hash', 'VARCHAR(255) NULL AFTER email');
    migration_add_column($pdo, 'customers', 'google_sub', 'VARCHAR(255) NULL AFTER password_hash');
    migration_add_column($pdo, 'customers', 'google_picture_url', 'VARCHAR(500) NULL AFTER google_sub');
    migration_add_column($pdo, 'customers', 'email_verified_at', 'DATETIME NULL AFTER phone_verified_at');
    $pdo->exec("UPDATE customers SET email=NULL WHERE email=''");
    $pdo->exec("UPDATE customers c JOIN customers keeper ON c.email=keeper.email AND c.id>keeper.id SET c.email=NULL WHERE c.email IS NOT NULL");
    if (!db_index_exists($pdo,'customers','uq_customers_email')) $pdo->exec('ALTER TABLE customers ADD UNIQUE KEY uq_customers_email (email)');
    if (!db_index_exists($pdo,'customers','uq_customers_google_sub')) $pdo->exec('ALTER TABLE customers ADD UNIQUE KEY uq_customers_google_sub (google_sub)');
    migration_add_column($pdo, 'orders', 'discount_amount', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER subtotal');
    migration_add_column($pdo, 'orders', 'coupon_id', 'BIGINT UNSIGNED NULL AFTER discount_amount');
    migration_add_column($pdo, 'orders', 'coupon_code', 'VARCHAR(60) NULL AFTER coupon_id');
    if (!db_index_exists($pdo,'admins','idx_admins_role')) $pdo->exec('ALTER TABLE admins ADD INDEX idx_admins_role (role_id,is_active)');
    if (db_constraint_exists($pdo,'admins','fk_admin_role') && !in_array(db_constraint_delete_rule($pdo,'admins','fk_admin_role'),['RESTRICT','NO ACTION'],true)) $pdo->exec('ALTER TABLE admins DROP FOREIGN KEY fk_admin_role');
    if (!db_constraint_exists($pdo,'admins','fk_admin_role')) $pdo->exec('ALTER TABLE admins ADD CONSTRAINT fk_admin_role FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE RESTRICT');
    migration_add_column($pdo, 'return_items', 'inventory_processed_at', 'DATETIME NULL AFTER approved_amount');
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('unpaid','pending','paid','failed','partially_refunded','refunded') NOT NULL DEFAULT 'unpaid'");
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN order_status ENUM('new','pending_payment','reviewing','sourcing','confirmed','preparing','ready_to_ship','handed_to_carrier','shipped','delivered','installed','return_requested','return_approved','returned','cancelled','refunded') NOT NULL DEFAULT 'new'");
    if (!db_index_exists($pdo,'order_items','idx_order_items_variant')) $pdo->exec('ALTER TABLE order_items ADD INDEX idx_order_items_variant (variant_id)');
    if (!db_constraint_exists($pdo,'order_items','fk_item_variant')) $pdo->exec('ALTER TABLE order_items ADD CONSTRAINT fk_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL');
    if (!db_index_exists($pdo,'orders','idx_orders_customer')) $pdo->exec('ALTER TABLE orders ADD INDEX idx_orders_customer (customer_id)');
    if (!db_constraint_exists($pdo,'orders','fk_orders_customer')) $pdo->exec('ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL');
    if (!db_index_exists($pdo,'orders','idx_orders_coupon')) $pdo->exec('ALTER TABLE orders ADD INDEX idx_orders_coupon (coupon_id)');
    if (!db_constraint_exists($pdo,'orders','fk_orders_coupon')) $pdo->exec('ALTER TABLE orders ADD CONSTRAINT fk_orders_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL');
    if (!db_index_exists($pdo,'refund_transactions','uq_refund_method_reference')) $pdo->exec('ALTER TABLE refund_transactions ADD UNIQUE KEY uq_refund_method_reference (method,reference_id)');

    if (!db_table_exists($pdo, 'festivals') || !db_table_exists($pdo, 'festival_products') || !db_table_exists($pdo, 'accounting_accounts')) {
        throw new RuntimeException('ساخت جداول جشنواره یا حسابداری کامل نشد. دسترسی CREATE و ALTER کاربر دیتابیس را بررسی کنید.');
    }

    $pdo->exec('UPDATE order_items SET base_unit_price=unit_price WHERE base_unit_price=0');
    $defaults = [
        'shipping_strategy'=>'collect','customer_accounts_enabled'=>'1','customer_checkout_autofill'=>'1',
        'google_auth_enabled'=>'0','google_client_id'=>'','google_client_secret_enc'=>'',
        'sms_enabled'=>'0','sms_mode'=>'sandbox','sms_api_key_enc'=>'','sms_otp_enabled'=>'1','sms_otp_template_id'=>'123456',
        'sms_otp_param_code'=>'CODE','sms_otp_length'=>'5','sms_otp_expiry_minutes'=>'2','sms_otp_cooldown_seconds'=>'60','sms_otp_max_attempts'=>'5',
        'sms_order_created_enabled'=>'0','sms_order_created_template_id'=>'0','sms_order_param_order'=>'Order','sms_order_param_name'=>'Name','sms_order_param_amount'=>'Amount',
        'sms_order_status_enabled'=>'0','sms_order_status_template_id'=>'0','sms_status_param_order'=>'Order','sms_status_param_status'=>'Status',
        'sms_payment_status_enabled'=>'0','sms_payment_status_template_id'=>'0','sms_payment_param_order'=>'Order','sms_payment_param_status'=>'Status',
        'sms_admin_mobile'=>'','sms_admin_new_order_enabled'=>'0','sms_admin_new_order_template_id'=>'0','sms_admin_param_order'=>'Order','sms_admin_param_name'=>'Name','sms_admin_param_amount'=>'Amount','sms_log_retention_days'=>'90','sms_delivery_sync_batch'=>'20',
        'payment_gateway_enabled'=>'0','payment_gateway'=>'zibal','payment_gateway_mode'=>'sandbox','payment_merchant_id_enc'=>'','payment_manual_enabled'=>'1','payment_gateway_title'=>'پرداخت آنلاین زیبال','payment_reservation_minutes'=>'30','manual_payment_reservation_minutes'=>'1440','torobpay_enabled'=>'0','torobpay_title'=>'پرداخت اعتباری ترب‌پی','torobpay_api_url'=>'https://cpg.torobpay.com','torobpay_client_id_enc'=>'','torobpay_client_secret_enc'=>'','torobpay_username_enc'=>'','torobpay_password_enc'=>'','torobpay_settle_enabled'=>'1','torobpay_markup_percent'=>'0','snappay_enabled'=>'0','snappay_title'=>'پرداخت اقساطی اسنپ‌پی','snappay_api_url'=>'https://api.snapppay.ir','snappay_client_id_enc'=>'','snappay_client_secret_enc'=>'','snappay_username_enc'=>'','snappay_password_enc'=>'','snappay_settle_enabled'=>'1','snappay_payment_method_types'=>'INSTALLMENT','snappay_adapter_ready'=>'1',
        'return_enabled'=>'1','return_window_days'=>'7','return_require_delivered'=>'1','return_auto_restock'=>'0',
        'sms_return_status_enabled'=>'0','sms_return_status_template_id'=>'0','sms_return_param_number'=>'Return','sms_return_param_status'=>'Status',
        'sms_shipment_status_enabled'=>'0','sms_shipment_status_template_id'=>'0','sms_shipment_param_order'=>'Order','sms_shipment_param_status'=>'Status','sms_shipment_param_code'=>'Code','sms_shipment_param_carrier'=>'Carrier',
        'admin_session_timeout_minutes'=>'120','admin_login_max_attempts'=>'5','admin_login_lock_minutes'=>'15','admin_audit_retention_days'=>'365','admin_low_stock_threshold'=>'3','admin_notification_retention_days'=>'90',
        'backup_enabled'=>'1','backup_interval_hours'=>'24','backup_retention_count'=>'7','backup_include_uploads'=>'1','backup_cron_key'=>bin2hex(random_bytes(24)),'backup_last_success_at'=>'',
        'purchase_update_cost_price'=>'1',
        'reviews_enabled'=>'1','questions_enabled'=>'1','wishlist_enabled'=>'1','compare_enabled'=>'1','coupons_enabled'=>'1','abandoned_cart_enabled'=>'1','abandoned_cart_minutes'=>'30','blog_enabled'=>'1',
        'seo_indexing_enabled'=>'1','seo_default_title'=>'','seo_default_description'=>'','seo_default_image'=>'assets/images/ui/instagram-golrizan1368.webp','seo_logo_image'=>'',
        'home_hero_eyebrow'=>'خرید مستقیم با قیمت و موجودی به‌روز','home_hero_title'=>'خانه را هوشمندانه تجهیز کنید','home_hero_highlight'=>'مطمئن، سریع و با قیمت شفاف','home_hero_description'=>'مدل‌ها را مقایسه کنید، موجودی واقعی را ببینید و کالای مناسب خانه‌تان را با گارانتی مشخص سفارش دهید.',
        'home_hero_primary_label'=>'مشاهده محصولات','home_hero_primary_url'=>'shop.php','home_hero_secondary_label'=>'راهنمای خرید','home_hero_secondary_url'=>'blog.php','home_hero_note_title'=>'کالای اصل و دارای گارانتی','home_hero_note_text'=>'جزئیات گارانتی در صفحه هر محصول','home_hero_image'=>'',
        'home_categories_title'=>'دسته‌بندی‌های محبوب','home_featured_title'=>'پیشنهادهای منتخب برای خانه شما','home_newest_title'=>'تازه‌ترین کالاهای موجود','home_products_limit'=>'4','home_show_categories'=>'1','home_show_deals'=>'1','home_show_featured'=>'1','home_show_newest'=>'1','home_show_blog'=>'1','home_show_newsletter'=>'1',
        'home_show_banners'=>'1','home_show_category_bestsellers'=>'0','home_bestseller_title'=>'پرفروش‌های هر دسته','home_bestseller_category_limit'=>'4','home_bestseller_product_limit'=>'3','home_bestseller_category_ids'=>'',
        'home_show_instagram'=>'1','instagram_title'=>'ما را در اینستاگرام دنبال کنید','instagram_username'=>'golrizan1368','instagram_url'=>'https://www.instagram.com/golrizan1368/','instagram_profile_image'=>'assets/images/ui/instagram-golrizan1368.webp',
        'trust_page_title'=>'مرکز اعتماد گلریزان','trust_page_intro'=>'پیش از خرید، تعهدات فروشگاه و مسیرهای پشتیبانی را شفاف ببینید.','faq_page_title'=>'پرسش‌های متداول','faq_page_intro'=>'پاسخ پرسش‌های رایج درباره خرید، پرداخت، ارسال، گارانتی و حساب کاربری.',
        'support_phone'=>'','support_mobile'=>'','support_email'=>'','support_hours'=>'','support_whatsapp_url'=>'','support_bale_url'=>'','support_description'=>'',
        'sales_phone'=>'','sales_mobile'=>'','sales_email'=>'','sales_hours'=>'','sales_whatsapp_url'=>'','sales_bale_url'=>'','sales_description'=>'',
        'sms_marketing_enabled'=>'1','sms_campaign_cron_key'=>bin2hex(random_bytes(24)),
        'gallery_enabled'=>'1','product_alerts_enabled'=>'1','tickets_enabled'=>'1','tickets_create_limit_hour'=>'5','tickets_reply_limit_10min'=>'10','tickets_open_limit'=>'10','invoice_pdf_enabled'=>'1',
        'product_alert_cron_key'=>bin2hex(random_bytes(24)),'product_alert_batch_size'=>'30','product_alert_max_attempts'=>'5',
        'sms_product_alerts_enabled'=>'0','sms_stock_alert_template_id'=>'0','sms_price_alert_template_id'=>'0','sms_alert_param_product'=>'Product','sms_alert_param_price'=>'Price',
        'sms_ticket_reply_enabled'=>'0','sms_ticket_reply_template_id'=>'0','sms_ticket_param_number'=>'Ticket','sms_ticket_param_subject'=>'Subject',
        'bale_product_bot_enabled'=>'0','bale_product_bot_token_enc'=>'','bale_product_bot_webhook_key'=>bin2hex(random_bytes(24)),'bale_product_bot_allowed_ids'=>'',
        'bale_product_bot_default_category_id'=>'0','bale_product_bot_default_brand_id'=>'0','bale_product_bot_allow_direct_publish'=>'0','bale_product_bot_max_images'=>'12',
        'bale_product_bot_default_warranty_months'=>'0','bale_product_bot_default_authenticity'=>'0','bale_product_bot_default_safe_shipping'=>'0','bale_product_bot_name'=>'','bale_product_bot_username'=>'','bale_product_bot_installed_at'=>'',
        'bale_product_bot_pair_hash'=>'','bale_product_bot_pair_expires_at'=>'','bale_product_bot_permissions_json'=>'','bale_product_bot_session_minutes'=>'60',
        'bale_product_bot_order_notifications'=>'1','bale_product_bot_payment_notifications'=>'1','bale_product_bot_order_management'=>'1','bale_product_bot_low_stock_notifications'=>'1',
        'torob_feed_key'=>bin2hex(random_bytes(24)),'app_secret'=>bin2hex(random_bytes(32)),
    ];
    $stmt = $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=setting_value');
    foreach ($defaults as $key => $value) $stmt->execute([$key, $value]);
    $pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('torobpay_settle_enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();

    $cronKey=$pdo->query("SELECT setting_value FROM settings WHERE setting_key='sms_campaign_cron_key' LIMIT 1")->fetchColumn();
    if(!is_string($cronKey)||trim($cronKey)==='')$pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='sms_campaign_cron_key'")->execute([bin2hex(random_bytes(24))]);
    $torobFeedKey=$pdo->query("SELECT setting_value FROM settings WHERE setting_key='torob_feed_key' LIMIT 1")->fetchColumn();
    if(!is_string($torobFeedKey)||trim($torobFeedKey)==='')$pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('torob_feed_key',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([bin2hex(random_bytes(24))]);

    $pdo->exec("UPDATE settings SET setting_value='' WHERE (setting_key='phone' AND setting_value='025-00000000') OR (setting_key='mobile' AND setting_value='09xx-xxx-xxxx') OR (setting_key='email' AND setting_value='info@example.com') OR (setting_key='address' AND setting_value='نشانی فروشگاه را در پنل مدیریت تکمیل کنید.')");
    $pdo->exec("UPDATE settings SET setting_value='لوازم خانگی گلریزان' WHERE setting_key='store_name' AND setting_value='لوازم خانگی موسوی'");
    $pdo->exec("UPDATE settings SET setting_value='مرکز اعتماد گلریزان' WHERE setting_key='trust_page_title' AND setting_value='مرکز اعتماد موسوی'");
    $pdo->exec("UPDATE settings SET setting_value='assets/images/ui/instagram-golrizan1368.webp' WHERE setting_key='seo_default_image' AND setting_value=''");
    $pdo->exec("UPDATE settings SET setting_value='4' WHERE setting_key='home_products_limit' AND setting_value='8'");
    $pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='home_show_category_bestsellers' AND setting_value='1'");
    migration_add_column($pdo, 'contact_messages', 'department', "VARCHAR(20) NOT NULL DEFAULT 'support' AFTER id");
    migration_add_column($pdo, 'support_ticket_messages', 'actor_label', 'VARCHAR(190) NULL AFTER admin_id');
    if(!db_index_exists($pdo,'contact_messages','idx_contact_department_read'))$pdo->exec("ALTER TABLE contact_messages ADD INDEX idx_contact_department_read (department,is_read,created_at)");
    migration_add_column($pdo, 'products', 'authenticity_guarantee', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER warranty');
    migration_add_column($pdo, 'products', 'safe_shipping_guarantee', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER authenticity_guarantee');
    migration_add_column($pdo, 'products', 'festival_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER safe_shipping_guarantee');
    migration_add_column($pdo, 'products', 'festival_price', 'BIGINT UNSIGNED NULL AFTER festival_enabled');
    migration_add_column($pdo, 'products', 'festival_free_shipping', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER festival_price');
    $pdo->prepare("INSERT INTO categories(name,slug,image_path,meta_title,meta_description,sort_order,is_active) VALUES('جشنواره','festival','assets/images/ui/category-all.svg','محصولات جشنواره','محصولات دارای قیمت جشنواره‌ای یا ارسال رایگان',-100,1) ON DUPLICATE KEY UPDATE name=VALUES(name),is_active=1")->execute();
    $pdo->exec("UPDATE products SET badge='پیشنهاد گلریزان' WHERE badge='پیشنهاد موسوی'");
    // فقط بنرهای نمونه قدیمی فروشگاه جایگزین می‌شوند؛ بنرهایی که مدیر شخصی‌سازی کرده دست‌نخورده می‌مانند.
    $legacyBanners = [
        ['old_titles'=>['لباس‌هایی همیشه تمیز','انتخابی آرام برای شست‌وشوی روزمره'],'old_images'=>['assets/images/products/washer.svg'],'title'=>'نظافت سریع‌تر خانه','subtitle'=>'جاروبرقی‌های کاربردی با مکش مناسب و مشخصات شفاف','eyebrow'=>'نظافت خانه','button'=>'مشاهده جاروبرقی‌ها','link'=>'shop.php?q=جاروبرقی','image'=>'assets/images/products/vacuum.svg','layout'=>'large','sort'=>10],
        ['old_titles'=>['آشپزخانه‌ای درخشان','ظرف‌های تمیز، زمان بیشتر برای خانه'],'old_images'=>['assets/images/products/dishwasher.svg'],'title'=>'لباس‌های مرتب‌تر','subtitle'=>'اتوهای بخار و خشک برای استفاده روزمره','eyebrow'=>'آماده‌سازی لباس','button'=>'مشاهده اتوها','link'=>'shop.php?q=اتو','image'=>'assets/images/products/iron.svg','layout'=>'medium','sort'=>20],
        ['old_titles'=>['هوای مطبوع خانه','هوای مطبوع برای روزهای گرم'],'old_images'=>['assets/images/products/ac.svg'],'title'=>'خنکای ساده و اقتصادی','subtitle'=>'پنکه‌های مناسب اتاق و فضای خانه','eyebrow'=>'روزهای گرم','button'=>'مشاهده پنکه‌ها','link'=>'shop.php?q=پنکه','image'=>'assets/images/products/fan.svg','layout'=>'small','sort'=>30],
        ['old_titles'=>['تصویر واقعی‌تر','تصویر بزرگ‌تر برای دورهمی‌ها'],'old_images'=>['assets/images/products/tv.svg'],'title'=>'لوازم کوچک کاربردی','subtitle'=>'انتخاب‌های روزمره برای آشپزخانه و خانه','eyebrow'=>'انتخاب بیشتر','button'=>'مشاهده محصولات','link'=>'shop.php?q=لوازم کوچک','image'=>'assets/images/products/blender.svg','layout'=>'small','sort'=>40],
    ];
    $updateLegacyBanner = $pdo->prepare('UPDATE home_banners SET title=?,subtitle=?,eyebrow=?,button_label=?,link_url=?,image_path=?,layout_size=?,sort_order=? WHERE title=? AND image_path=?');
    foreach ($legacyBanners as $legacyBanner) {
        foreach ($legacyBanner['old_titles'] as $oldTitle) {
            foreach ($legacyBanner['old_images'] as $oldImage) {
                $updateLegacyBanner->execute([$legacyBanner['title'],$legacyBanner['subtitle'],$legacyBanner['eyebrow'],$legacyBanner['button'],$legacyBanner['link'],$legacyBanner['image'],$legacyBanner['layout'],$legacyBanner['sort'],$oldTitle,$oldImage]);
            }
        }
    }
    $pdo->prepare("UPDATE faqs SET answer=? WHERE question='آیا برای خرید باید از قبل حساب داشته باشم؟'")->execute(['خیر. سفارش مهمان بدون اتصال به حساب ثبت می‌شود. پس از تأیید شماره همراه با کد یک‌بارمصرف، سفارش‌های همان شماره به‌صورت امن در حساب مشتری نمایش داده می‌شوند.']);

    // داده‌های ناسازگار جشنواره نباید مسیر نمایش محصول را خراب کنند.
    $pdo->exec("UPDATE products SET festival_price=NULL WHERE festival_price IS NOT NULL AND (festival_price<1 OR festival_price>=price)");
    $pdo->exec("UPDATE products SET festival_enabled=0,festival_free_shipping=0 WHERE festival_enabled=1 AND festival_price IS NULL AND festival_free_shipping=0");
    $pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('schema_signature',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([MOUSAVI_SCHEMA_SIGNATURE]);
    $pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('schema_version',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([MOUSAVI_SCHEMA_VERSION]);
    $missing = mousavi_schema_missing($pdo);
    if ($missing !== []) throw new RuntimeException('بخش‌هایی از ساختار دیتابیس ایجاد نشد: ' . implode(', ', $missing));
    return ['ساختار دیتابیس فروشگاه تا نسخه ' . MOUSAVI_SCHEMA_VERSION . ' به‌روزرسانی شد.'];
}

function ensure_mousavi_schema(PDO $pdo): array
{
    static $checked = false;
    if ($checked) return [];
    $checked = true;
    if (mousavi_schema_is_current($pdo, false)) return [];
    $lockPath = BASE_PATH . '/storage/schema-migration.lock';
    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) throw new RuntimeException('امکان ساخت قفل ارتقای دیتابیس وجود ندارد؛ دسترسی نوشتن پوشه storage را بررسی کنید.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('دریافت قفل ارتقای دیتابیس انجام نشد.');
        if (mousavi_schema_is_current($pdo, false)) return [];
        return run_mousavi_migrations($pdo);
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
