-- =====================================================
-- SmartStock — Database schema & seed data
-- RF Chein Gadgets (Bacolod City)
-- =====================================================
-- Import in phpMyAdmin (http://localhost/phpmyadmin) or:
--   mysql -u root < database.sql
-- =====================================================

DROP DATABASE IF EXISTS smartstock;
CREATE DATABASE smartstock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartstock;

-- -----------------------------------------------------
-- Table: branches
-- -----------------------------------------------------
CREATE TABLE branches (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    address         VARCHAR(255) NOT NULL,
    manager         VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(40)  DEFAULT NULL,
    status          ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: users
-- -----------------------------------------------------
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    phone           VARCHAR(40)  DEFAULT NULL,
    username        VARCHAR(80)  NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    role            ENUM('Super Admin','Branch Admin','Staff','Viewer') NOT NULL DEFAULT 'Staff',
    branch_id       INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: phones  (a.k.a. devices / inventory items)
-- -----------------------------------------------------
CREATE TABLE phones (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    brand           VARCHAR(60)  NOT NULL,
    model           VARCHAR(120) NOT NULL,
    storage         VARCHAR(20)  NOT NULL,
    ram             VARCHAR(20)  DEFAULT NULL,
    color           VARCHAR(60)  DEFAULT NULL,
    `condition`     ENUM('Excellent','Good','Fair','Poor') NOT NULL DEFAULT 'Good',
    battery         INT DEFAULT 100,
    selling_price   DECIMAL(10,2) NOT NULL,
    purchase_price  DECIMAL(10,2) DEFAULT NULL,
    stock           INT NOT NULL DEFAULT 1,
    branch_id       INT DEFAULT NULL,
    imei            VARCHAR(40)  DEFAULT NULL,
    serial_number   VARCHAR(80)  DEFAULT NULL,
    accessories     VARCHAR(120) DEFAULT 'Unit only',
    notes           TEXT,
    emoji           VARCHAR(10)  DEFAULT '📱',
    is_listed       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_phone_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: sales
-- -----------------------------------------------------
CREATE TABLE sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    txn_id          VARCHAR(20) NOT NULL UNIQUE,
    phone_id        INT DEFAULT NULL,
    product_name    VARCHAR(200) NOT NULL,
    customer        VARCHAR(150) DEFAULT 'Walk-in customer',
    price           DECIMAL(10,2) NOT NULL,
    payment_method  ENUM('Cash','GCash','Maya','Bank transfer') NOT NULL DEFAULT 'Cash',
    sale_date       DATE NOT NULL,
    status          ENUM('Completed','Pending','Refunded') NOT NULL DEFAULT 'Completed',
    user_id         INT DEFAULT NULL,
    branch_id       INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_phone  FOREIGN KEY (phone_id)  REFERENCES phones(id)   ON DELETE SET NULL,
    CONSTRAINT fk_sale_user   FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_sale_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: activity_logs
-- -----------------------------------------------------
CREATE TABLE activity_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_name       VARCHAR(150) NOT NULL,
    branch_name     VARCHAR(150) DEFAULT 'System',
    action          VARCHAR(255) NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================
-- SEED DATA
-- =====================================================

-- Branches
INSERT INTO branches (name, address, manager, phone, status) VALUES
('RF Chein - Main Branch',   'Burgos St, Bacolod City',  'John Francis Busel', '0912-345-6789', 'Active'),
('RF Chein - Lacson Branch', 'Lacson St, Bacolod City',  'James Mark Jariño',  '0923-456-7890', 'Active'),
('RF Chein - SM Branch',     'SM City Bacolod',          'Aljon Obediente',    '0934-567-8901', 'Active');

-- Users (default password for every seeded user = "password123")
-- Plain-text here for portability; login.php rehashes to bcrypt on first successful sign-in.
INSERT INTO users (name, email, username, password, role, branch_id) VALUES
('Super Admin',        'admin@rfchein.com',      'admin',       'password123', 'Super Admin',  NULL),
('John Francis Busel', 'jfbusel@rfchein.com',    'jfbusel',     'password123', 'Branch Admin', 1),
('James Mark Jariño',  'jmjarino@rfchein.com',   'jmjarino',    'password123', 'Branch Admin', 2),
('Aljon Obediente',    'amobediente@rfchein.com','amobediente', 'password123', 'Branch Admin', 3),
('Almarie Ex',         'adex@rfchein.com',       'adex',        'password123', 'Staff',        1),
('Maria Reyes',        'mreyes@rfchein.com',     'mreyes',      'password123', 'Staff',        2),
('Pedro Cruz',         'pcruz@rfchein.com',      'pcruz',       'password123', 'Staff',        1),
('Rosa Flores',        'rflores@rfchein.com',    'rflores',     'password123', 'Viewer',       3),
('Ramon Garcia',       'rgarcia@rfchein.com',    'rgarcia',     'password123', 'Viewer',       1);

-- Phones / inventory
INSERT INTO phones (brand, model, storage, ram, color, `condition`, battery, selling_price, purchase_price, stock, branch_id, imei, accessories, notes) VALUES
('Samsung','Galaxy A54',    '128GB','8GB', 'Midnight Black', 'Good',      85, 4200, 3000, 12, 1, '35xxxxxxxxxxxxxx', 'Charger + earphones',                 'Minor scratches on back, screen perfect.'),
('Apple',  'iPhone 12',     '64GB', '4GB', 'Black',          'Good',      79, 8500, 6800,  3, 2, '35xxxxxxxxxxxxxx', 'Charger only',                        'Face ID works perfectly. Small dent on corner.'),
('Xiaomi', 'Redmi Note 11', '128GB','6GB', 'Graphite Gray',  'Fair',      91, 2800, 1800,  8, 1, '86xxxxxxxxxxxxxx', 'Charger only',                        'Visible scratches on screen, no cracks.'),
('OPPO',   'A78',           '256GB','8GB', 'Glowing Black',  'Excellent', 96, 5500, 4000,  2, 3, '35xxxxxxxxxxxxxx', 'Complete (box, charger, earphones)',  'Like new, barely used for 2 months.'),
('Vivo',   'Y35',           '128GB','8GB', 'Dawn Gold',      'Good',      82, 3200, 2200,  0, 2, '86xxxxxxxxxxxxxx', 'Charger only',                        'Good overall condition.'),
('Samsung','Galaxy S21',    '256GB','8GB', 'Phantom Gray',   'Good',      77, 9800, 7500,  5, 1, '35xxxxxxxxxxxxxx', 'Charger only',                        'No cracks, camera works great.'),
('Realme', 'C35',           '64GB', '4GB', 'Glowing Green',  'Fair',      88, 1900, 1200, 14, 3, '86xxxxxxxxxxxxxx', 'Unit only',                           'Light scratches, fully functional.'),
('Apple',  'iPhone 11',     '64GB', '4GB', 'White',          'Fair',      72, 7200, 5500,  1, 2, '35xxxxxxxxxxxxxx', 'Charger only',                        'Cracked back cover, screen is perfect.'),
('Samsung','Galaxy A32',    '128GB','6GB', 'Awesome Black',  'Excellent', 93, 3800, 2500,  4, 1, '35xxxxxxxxxxxxxx', 'Charger + earphones',                 'Excellent condition, no scratches.'),
('Xiaomi', 'Redmi 10C',     '128GB','4GB', 'Mint Green',     'Good',      86, 2200, 1400,  6, 3, '86xxxxxxxxxxxxxx', 'Charger only',                        'Good condition, slight wear on corners.'),
('OPPO',   'Reno 6',        '128GB','8GB', 'Aurora',         'Good',      84, 6200, 4500,  3, 1, '35xxxxxxxxxxxxxx', 'Charger + earphones',                 'Very good condition, AI camera works great.'),
('Vivo',   'V23',           '256GB','12GB','Sunshine Gold',  'Excellent', 97, 7800, 6000,  2, 2, '86xxxxxxxxxxxxxx', 'Complete (box, charger, earphones)',  'Brand new condition, purchased 1 month ago.');

-- Sales
INSERT INTO sales (txn_id, phone_id, product_name, customer, price, payment_method, sale_date, status, branch_id) VALUES
('TXN-047', 1, 'Samsung Galaxy A54',    'Maria Santos',     4200, 'Cash',  '2026-05-11', 'Completed', 1),
('TXN-046', 2, 'iPhone 12',             'Jose Reyes',       8500, 'GCash', '2026-05-10', 'Completed', 2),
('TXN-045', 3, 'Xiaomi Redmi Note 11',  'Ana Garcia',       2800, 'Cash',  '2026-05-10', 'Completed', 1),
('TXN-044', 4, 'OPPO A78',              'Pedro Cruz',       5500, 'Maya',  '2026-05-09', 'Completed', 3),
('TXN-043', 7, 'Realme C35',            'Rosa Flores',      1900, 'Cash',  '2026-05-09', 'Completed', 3),
('TXN-042', 6, 'Samsung Galaxy S21',    'Ramon Dela Cruz',  9800, 'Bank transfer', '2026-05-08', 'Completed', 1);

-- Activity logs
INSERT INTO activity_logs (user_name, branch_name, action, created_at) VALUES
('Super Admin',        'System',         'Created user: Maria Reyes (Staff)',        '2026-05-11 09:14:00'),
('Super Admin',        'System',         'Added branch: RF Chein - SM Branch',       '2026-05-10 16:30:00'),
('John Francis Busel', 'Main Branch',    'Added device: Samsung Galaxy A54',         '2026-05-10 14:15:00'),
('James Mark Jariño',  'Lacson Branch',  'Recorded sale TXN-044',                    '2026-05-10 11:00:00'),
('Aljon Obediente',    'SM Branch',      'Updated stock: OPPO A78 → 2 units',        '2026-05-09 15:45:00'),
('Super Admin',        'System',         'Assigned Almarie Ex to Main Branch',       '2026-05-09 10:00:00');

-- =====================================================
-- MULTI-BRANCH UPGRADE STRUCTURE
-- =====================================================
ALTER TABLE branches
    ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER phone;

ALTER TABLE phones
    ADD COLUMN supplier VARCHAR(150) DEFAULT NULL AFTER purchase_price,
    ADD COLUMN image_url VARCHAR(255) DEFAULT NULL AFTER emoji,
    ADD COLUMN last_moved_at DATETIME DEFAULT NULL AFTER is_listed;

UPDATE branches
SET email = CASE id
    WHEN 1 THEN 'main@rfchein.com'
    WHEN 2 THEN 'lacson@rfchein.com'
    WHEN 3 THEN 'sm@rfchein.com'
    ELSE NULL
END;

UPDATE phones
SET supplier = CASE id
    WHEN 1 THEN 'Main trade-in desk'
    WHEN 2 THEN 'Lacson reseller network'
    WHEN 3 THEN 'Main walk-in seller'
    WHEN 4 THEN 'SM trade-in counter'
    WHEN 5 THEN 'Online marketplace'
    WHEN 6 THEN 'Main branch supplier'
    WHEN 7 THEN 'SM bulk acquisition'
    WHEN 8 THEN 'Lacson buyback desk'
    WHEN 9 THEN 'Main trade-in desk'
    WHEN 10 THEN 'SM reseller pool'
    WHEN 11 THEN 'Main branch supplier'
    WHEN 12 THEN 'Lacson premium source'
    ELSE 'Store acquisition'
END,
last_moved_at = created_at;

CREATE TABLE stock_transfers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    transfer_code       VARCHAR(30) NOT NULL UNIQUE,
    source_branch_id    INT NOT NULL,
    destination_branch_id INT NOT NULL,
    requested_by        INT DEFAULT NULL,
    approved_by         INT DEFAULT NULL,
    status              ENUM('Pending','Approved','In Transit','Completed','Rejected') NOT NULL DEFAULT 'Pending',
    notes               TEXT DEFAULT NULL,
    rejection_reason    VARCHAR(255) DEFAULT NULL,
    requested_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at         DATETIME DEFAULT NULL,
    in_transit_at       DATETIME DEFAULT NULL,
    completed_at        DATETIME DEFAULT NULL,
    rejected_at         DATETIME DEFAULT NULL,
    KEY idx_transfer_status (status),
    KEY idx_transfer_scope (source_branch_id, destination_branch_id),
    CONSTRAINT fk_transfer_source_branch FOREIGN KEY (source_branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_transfer_destination_branch FOREIGN KEY (destination_branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_transfer_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_transfer_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE transfer_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    transfer_id         INT NOT NULL,
    phone_id            INT NOT NULL,
    product_name        VARCHAR(200) NOT NULL,
    imei                VARCHAR(40) DEFAULT NULL,
    quantity            INT NOT NULL DEFAULT 1,
    unit_cost           DECIMAL(10,2) DEFAULT NULL,
    source_stock_before INT DEFAULT NULL,
    source_stock_after  INT DEFAULT NULL,
    destination_stock_after INT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transfer_item_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
    CONSTRAINT fk_transfer_item_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE inventory_logs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    phone_id            INT DEFAULT NULL,
    branch_id           INT DEFAULT NULL,
    user_id             INT DEFAULT NULL,
    transfer_id         INT DEFAULT NULL,
    event_type          ENUM('created','sale','transfer_out','transfer_in','adjustment','branch_update','status_change') NOT NULL DEFAULT 'adjustment',
    quantity_change     INT NOT NULL DEFAULT 0,
    stock_before        INT DEFAULT NULL,
    stock_after         INT DEFAULT NULL,
    reference_code      VARCHAR(50) DEFAULT NULL,
    remarks             VARCHAR(255) DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inventory_branch_created (branch_id, created_at),
    CONSTRAINT fk_inventory_log_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_log_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_log_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE flash_sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    phone_id        INT NOT NULL,
    branch_id       INT NOT NULL,
    title           VARCHAR(150) NOT NULL,
    promo_label     VARCHAR(60) NOT NULL DEFAULT 'Flash Sale',
    sale_price      DECIMAL(10,2) NOT NULL,
    description     TEXT DEFAULT NULL,
    starts_at       DATETIME NOT NULL,
    ends_at         DATETIME NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_flash_sale_branch_window (branch_id, is_active, starts_at, ends_at),
    CONSTRAINT fk_flash_sale_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE CASCADE,
    CONSTRAINT fk_flash_sale_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_flash_sale_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE inquiries (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    phone_id            INT DEFAULT NULL,
    branch_id           INT DEFAULT NULL,
    customer_name       VARCHAR(150) NOT NULL,
    contact_number      VARCHAR(40) NOT NULL,
    preferred_channel   ENUM('Phone','SMS','Call','Facebook','Email','Website') NOT NULL DEFAULT 'Website',
    subject             VARCHAR(150) DEFAULT NULL,
    latest_message      TEXT NOT NULL,
    status              ENUM('New','Contacted','Resolved','Closed') NOT NULL DEFAULT 'New',
    assigned_user_id    INT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT NULL,
    resolved_at         DATETIME DEFAULT NULL,
    KEY idx_inquiry_branch_status (branch_id, status, created_at),
    CONSTRAINT fk_inquiry_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE SET NULL,
    CONSTRAINT fk_inquiry_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_inquiry_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE inquiry_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inquiry_id      INT NOT NULL,
    user_id         INT DEFAULT NULL,
    sender_type     ENUM('Customer','Staff','System') NOT NULL DEFAULT 'Customer',
    sender_name     VARCHAR(150) NOT NULL,
    message         TEXT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inquiry_message_scope (inquiry_id, created_at),
    CONSTRAINT fk_inquiry_message_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiries(id) ON DELETE CASCADE,
    CONSTRAINT fk_inquiry_message_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO stock_transfers (transfer_code, source_branch_id, destination_branch_id, requested_by, approved_by, status, notes, requested_at, approved_at) VALUES
('TRF-0001', 1, 2, 2, 1, 'Approved', 'Move one Reno 6 unit to Lacson Branch for weekend demand.', '2026-05-20 08:30:00', '2026-05-20 09:00:00'),
('TRF-0002', 3, 1, 4, NULL, 'Pending', 'Restock Redmi inventory for Main Branch flash sale.', '2026-05-24 10:45:00', NULL);

INSERT INTO transfer_items (transfer_id, phone_id, product_name, imei, quantity, unit_cost) VALUES
(1, 11, 'OPPO Reno 6', '35xxxxxxxxxxxxxx', 1, 4500.00),
(2, 10, 'Xiaomi Redmi 10C', '86xxxxxxxxxxxxxx', 2, 1400.00);

INSERT INTO inventory_logs (phone_id, branch_id, user_id, transfer_id, event_type, quantity_change, stock_before, stock_after, reference_code, remarks, created_at) VALUES
(1, 1, 2, NULL, 'created', 12, 0, 12, NULL, 'Initial seeded inventory.', '2026-05-11 00:31:32'),
(6, 1, 1, NULL, 'sale', -1, 6, 5, 'TXN-042', 'Seeded sale transaction.', '2026-05-11 00:31:32'),
(11, 1, 1, 1, 'branch_update', 0, 3, 3, 'TRF-0001', 'Transfer request approved for Lacson Branch.', '2026-05-20 09:00:00');

INSERT INTO flash_sales (phone_id, branch_id, title, promo_label, sale_price, description, starts_at, ends_at, is_active, created_by) VALUES
(2, 2, 'Weekend iPhone Push', 'Flash Sale', 7999.00, 'Limited-time Lacson promo for weekend walk-ins.', '2026-05-28 09:00:00', '2026-06-05 19:00:00', 1, 1),
(11, 1, 'Main Branch Reno Boost', 'Weekend Drop', 5799.00, 'Promotional price to speed up Reno 6 turnover.', '2026-06-06 09:00:00', '2026-06-08 19:00:00', 1, 1);

INSERT INTO inquiries (phone_id, branch_id, customer_name, contact_number, preferred_channel, subject, latest_message, status, assigned_user_id, created_at, updated_at, resolved_at) VALUES
(2, 2, 'Karen Lopez', '0917-222-1100', 'Website', 'Inquiry for Apple iPhone 12', 'Hi, is this still available and can you hold it until Saturday?', 'New', NULL, '2026-05-29 10:15:00', '2026-05-29 10:15:00', NULL),
(11, 1, 'Michael Sy', '0918-555-2211', 'Call', 'Inquiry for OPPO Reno 6', 'Do you still have the Reno 6 and does it include the charger?', 'Contacted', 2, '2026-05-29 14:20:00', '2026-05-29 15:05:00', NULL);

INSERT INTO inquiry_messages (inquiry_id, user_id, sender_type, sender_name, message, created_at) VALUES
(1, NULL, 'Customer', 'Karen Lopez', 'Hi, is this still available and can you hold it until Saturday?', '2026-05-29 10:15:00'),
(2, NULL, 'Customer', 'Michael Sy', 'Do you still have the Reno 6 and does it include the charger?', '2026-05-29 14:20:00'),
(2, 2, 'Staff', 'John Francis Busel', 'Yes, one unit is still available and the charger is included.', '2026-05-29 15:05:00');

CREATE OR REPLACE VIEW branch_inventory AS
SELECT
    p.id AS inventory_item_id,
    p.id AS phone_id,
    p.branch_id,
    b.name AS branch_name,
    p.imei,
    p.brand,
    p.model,
    p.storage,
    p.ram,
    p.battery,
    p.`condition` AS device_condition,
    p.selling_price,
    p.purchase_price,
    p.supplier,
    p.image_url,
    p.stock,
    p.created_at AS date_added,
    p.last_moved_at AS last_transfer_at,
    p.is_listed
FROM phones p
LEFT JOIN branches b ON b.id = p.branch_id;

CREATE OR REPLACE VIEW branch_users AS
SELECT
    u.id AS user_id,
    u.name,
    u.email,
    u.phone,
    u.username,
    u.role,
    u.branch_id,
    b.name AS branch_name,
    b.status AS branch_status,
    u.created_at
FROM users u
LEFT JOIN branches b ON b.id = u.branch_id;
