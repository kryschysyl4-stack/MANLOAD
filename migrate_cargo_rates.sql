-- Cargo freight rates per unit (Admin › Commercial › Cargo Rates).
-- The app also creates this table by itself on first use (includes/cargo_rates.php),
-- so importing this file is optional. Safe to import twice.
CREATE TABLE IF NOT EXISTS cargo_rate (
    rate_id    INT AUTO_INCREMENT PRIMARY KEY,
    unit_name  VARCHAR(50) NOT NULL,
    rate       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unit_name (unit_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cargo_rate (unit_name, rate, sort_order) VALUES
    ('Sack', 80, 1), ('Box (small)', 100, 2), ('Balikbayan box', 350, 3), ('Bundle', 150, 4),
    ('Piece / parcel', 50, 5), ('Appliance', 600, 6);
