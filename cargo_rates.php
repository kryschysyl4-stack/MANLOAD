<?php
/**
 * Cargo freight rates — one price per unit (sack, box, balikbayan box, …), set by the Admin in
 * Commercial › Cargo Rates. A waybill's freight = quantity × the unit's rate (+ the route's
 * hazardous surcharge for Hazardous cargo). Waybills carry no weight.
 * The table is created on first use (same as sql/migrate_cargo_rates.sql), with starting rates to edit.
 */
require_once __DIR__ . '/functions.php';

function cargo_rates_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();
    if ($db->query("SHOW TABLES LIKE 'cargo_rate'")->num_rows) return;
    $db->query("CREATE TABLE IF NOT EXISTS cargo_rate (
        rate_id    INT AUTO_INCREMENT PRIMARY KEY,
        unit_name  VARCHAR(50) NOT NULL,
        rate       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_active  TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unit_name (unit_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT IGNORE INTO cargo_rate (unit_name, rate, sort_order) VALUES
        ('Sack', 80, 1), ('Box (small)', 100, 2), ('Balikbayan box', 350, 3), ('Bundle', 150, 4),
        ('Piece / parcel', 50, 5), ('Appliance', 600, 6)");
}

/** [rate_id => row] ordered for the dropdown. */
function cargo_rates(bool $active_only = true): array {
    cargo_rates_ensure();
    $rows = db()->query('SELECT * FROM cargo_rate' . ($active_only ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, unit_name')->fetch_all(MYSQLI_ASSOC);
    return array_column($rows, null, 'rate_id');
}
