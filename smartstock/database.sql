-- =====================================================
-- SmartStock — Database schema & minimal test seed
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
    role            ENUM('Super Admin','Admin','Supervisor','Staff','Viewer') NOT NULL DEFAULT 'Staff',
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
-- MINIMAL TEST SEED
-- =====================================================

-- Branches
INSERT INTO branches (name, address, manager, phone, status) VALUES
('RF Chein - Main Branch',   'Burgos St, Bacolod City',  'John Francis Busel', '0912-345-6789', 'Active'),
('RF Chein - Lacson Branch', 'Lacson St, Bacolod City',  NULL,                 '0923-456-7890', 'Active'),
('RF Chein - SM Branch',     'SM City Bacolod',          NULL,                 '0934-567-8901', 'Active');

-- Users (default password for every seeded user = "password123")
-- Plain-text here for portability; login.php rehashes to bcrypt on first successful sign-in.
-- Keep only the credentials needed to test each role.
INSERT INTO users (name, email, username, password, role, branch_id) VALUES
('Super Admin',        'admin@rfchein.com',      'admin',       'password123', 'Super Admin',  NULL),
('Chief Executive Office', 'ceo@rfchein.com',    'ceo',         'password123', 'Admin',        NULL),
('John Francis Busel', 'jfbusel@rfchein.com',    'jfbusel',     'password123', 'Supervisor', 1),
('Almarie Ex',         'adex@rfchein.com',       'adex',        'password123', 'Staff',        1);

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
