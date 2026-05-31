<?php
// includes/helpers.php — shared helpers and session bootstrap

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function redirect($url) { header('Location: ' . $url); exit; }

function flash_set($key, $msg) { $_SESSION['flash'][$key] = $msg; }
function flash_get($key) {
    if (!empty($_SESSION['flash'][$key])) {
        $m = $_SESSION['flash'][$key]; unset($_SESSION['flash'][$key]); return $m;
    }
    return null;
}

function normalize_role_name($role) {
    $role = trim((string)$role);
    if ($role === 'Branch Admin') {
        return 'Supervisor';
    }
    return $role;
}

function role_label($role) {
    $normalized = normalize_role_name($role);
    if ($normalized === 'Viewer') {
        return 'Viewer';
    }
    return $normalized;
}

function current_user() {
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return null;
    }

    if (isset($_SESSION['user']['role'])) {
        $_SESSION['user']['role'] = normalize_role_name($_SESSION['user']['role']);
    }

    return $_SESSION['user'];
}

function user_role(?array $user = null) {
    $user = $user ?? current_user();
    return normalize_role_name($user['role'] ?? '');
}

function has_role(array $roles, ?array $user = null) {
    $normalizedRoles = array_map('normalize_role_name', $roles);
    return in_array(user_role($user), $normalizedRoles, true);
}

function require_login() {
    if (empty($_SESSION['user'])) { redirect('login.php'); }
}

function require_role(array $roles) {
    require_login();
    if (!has_role($roles)) {
        http_response_code(403);
        die('Forbidden — insufficient role.');
    }
}

function is_super_admin(?array $user = null) {
    return user_role($user) === 'Super Admin';
}

function is_admin_user(?array $user = null) {
    return user_role($user) === 'Admin';
}

function is_executive_user(?array $user = null) {
    return is_super_admin($user) || is_admin_user($user);
}

function can_manage_transfer_status(?array $user = null) {
    return is_executive_user($user);
}

function can_create_transfer_requests(?array $user = null) {
    return is_executive_user($user) || is_supervisor_user($user);
}

function is_supervisor_user(?array $user = null) {
    return user_role($user) === 'Supervisor';
}

function is_staff_user(?array $user = null) {
    return user_role($user) === 'Staff';
}

function current_branch_id(?array $user = null) {
    $user = $user ?? current_user();
    return isset($user['branch_id']) && $user['branch_id'] !== null ? (int)$user['branch_id'] : null;
}

function can_access_branch($branchId, ?array $user = null) {
    if ($branchId === null || $branchId === 0 || $branchId === '0') {
        return false;
    }
    if (is_super_admin($user) || is_admin_user($user)) {
        return true;
    }
    return current_branch_id($user) === (int)$branchId;
}

function require_branch_access($branchId) {
    if (!can_access_branch($branchId)) {
        http_response_code(403);
        die('Forbidden — branch access denied.');
    }
}

function branch_scope_sql($column = 'branch_id', ?array $user = null) {
    $user = $user ?? current_user();
    if (is_super_admin($user) || is_admin_user($user)) {
        return ['sql' => '1=1', 'params' => []];
    }

    $branchId = current_branch_id($user);
    if ($branchId === null) {
        return ['sql' => '1=0', 'params' => []];
    }

    return ['sql' => $column . ' = ?', 'params' => [$branchId]];
}

function allowed_branches(PDO $db, ?array $user = null) {
    $user = $user ?? current_user();
    if (is_super_admin($user) || is_admin_user($user)) {
        return $db->query("SELECT * FROM branches ORDER BY name")->fetchAll();
    }

    $branchId = current_branch_id($user);
    if ($branchId === null) {
        return [];
    }

    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? ORDER BY name");
    $stmt->execute([$branchId]);
    return $stmt->fetchAll();
}

function resolve_managed_branch_id(PDO $db, $requestedBranchId, ?array $user = null) {
    $user = $user ?? current_user();
    if (is_super_admin($user) || is_admin_user($user)) {
        $branchId = $requestedBranchId !== null && $requestedBranchId !== '' ? (int)$requestedBranchId : null;
        if ($branchId === null || $branchId <= 0) {
            return null;
        }
        return $branchId;
    }

    return current_branch_id($user);
}

function ensure_branch_assigned(?array $user = null) {
    $user = $user ?? current_user();
    if (is_super_admin($user) || is_admin_user($user)) {
        return;
    }
    if (current_branch_id($user) === null) {
        http_response_code(403);
        die('Forbidden — branch assignment required.');
    }
}

function log_activity(PDO $db, $userName, $branchName, $action) {
    $stmt = $db->prepare('INSERT INTO activity_logs (user_name, branch_name, action) VALUES (?, ?, ?)');
    $stmt->execute([$userName, $branchName ?: 'System', $action]);
}

function log_inventory_event(PDO $db, array $event) {
    $stmt = $db->prepare(
        'INSERT INTO inventory_logs (phone_id, branch_id, user_id, transfer_id, event_type, quantity_change, stock_before, stock_after, reference_code, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $event['phone_id'] ?? null,
        $event['branch_id'] ?? null,
        $event['user_id'] ?? null,
        $event['transfer_id'] ?? null,
        $event['event_type'] ?? 'adjustment',
        (int)($event['quantity_change'] ?? 0),
        $event['stock_before'] ?? null,
        $event['stock_after'] ?? null,
        $event['reference_code'] ?? null,
        $event['remarks'] ?? null,
    ]);
}

function peso($n) { return '₱' . number_format((float)$n, 0); }

function next_txn_id(PDO $db) {
    $row = $db->query("SELECT txn_id FROM sales ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['txn_id'], $m)) {
        $next = (int)$m[1] + 1;
    }
    return 'TXN-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function next_transfer_code(PDO $db) {
    $row = $db->query("SELECT transfer_code FROM stock_transfers ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['transfer_code'], $m)) {
        $next = (int)$m[1] + 1;
    }
    return 'TRF-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function table_exists(PDO $db, $tableName) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([DB_NAME, $tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(PDO $db, $tableName, $columnName) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([DB_NAME, $tableName, $columnName]);
    return (int)$stmt->fetchColumn() > 0;
}

function index_exists(PDO $db, $tableName, $indexName) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([DB_NAME, $tableName, $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

function smartstock_bootstrap(PDO $db) {
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    if (!column_exists($db, 'branches', 'email')) {
        $db->exec("ALTER TABLE branches ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER phone");
    }
    if (!column_exists($db, 'users', 'phone')) {
        $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(40) DEFAULT NULL AFTER email");
    }
    $db->exec("ALTER TABLE users MODIFY role ENUM('Super Admin','Admin','Supervisor','Staff','Viewer','Branch Admin') NOT NULL DEFAULT 'Staff'");
    $db->exec("UPDATE users SET role = 'Supervisor' WHERE role = 'Branch Admin'");
    $db->exec("ALTER TABLE users MODIFY role ENUM('Super Admin','Admin','Supervisor','Staff','Viewer') NOT NULL DEFAULT 'Staff'");
    if (!column_exists($db, 'phones', 'supplier')) {
        $db->exec("ALTER TABLE phones ADD COLUMN supplier VARCHAR(150) DEFAULT NULL AFTER purchase_price");
    }
    if (!column_exists($db, 'phones', 'last_moved_at')) {
        $db->exec("ALTER TABLE phones ADD COLUMN last_moved_at DATETIME DEFAULT NULL AFTER is_listed");
    }
    if (!column_exists($db, 'phones', 'image_url')) {
        $db->exec("ALTER TABLE phones ADD COLUMN image_url VARCHAR(255) DEFAULT NULL AFTER emoji");
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS stock_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transfer_code VARCHAR(30) NOT NULL UNIQUE,
            source_branch_id INT NOT NULL,
            destination_branch_id INT NOT NULL,
            requested_by INT DEFAULT NULL,
            approved_by INT DEFAULT NULL,
            status ENUM('Pending','Approved','In Transit','Completed','Rejected') NOT NULL DEFAULT 'Pending',
            notes TEXT DEFAULT NULL,
            rejection_reason VARCHAR(255) DEFAULT NULL,
            requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME DEFAULT NULL,
            in_transit_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            CONSTRAINT fk_transfer_source_branch FOREIGN KEY (source_branch_id) REFERENCES branches(id) ON DELETE CASCADE,
            CONSTRAINT fk_transfer_destination_branch FOREIGN KEY (destination_branch_id) REFERENCES branches(id) ON DELETE CASCADE,
            CONSTRAINT fk_transfer_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_transfer_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS transfer_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transfer_id INT NOT NULL,
            phone_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            imei VARCHAR(40) DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_cost DECIMAL(10,2) DEFAULT NULL,
            source_stock_before INT DEFAULT NULL,
            source_stock_after INT DEFAULT NULL,
            destination_stock_after INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_transfer_item_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
            CONSTRAINT fk_transfer_item_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS inventory_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone_id INT DEFAULT NULL,
            branch_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            transfer_id INT DEFAULT NULL,
            event_type ENUM('created','sale','transfer_out','transfer_in','adjustment','branch_update','status_change') NOT NULL DEFAULT 'adjustment',
            quantity_change INT NOT NULL DEFAULT 0,
            stock_before INT DEFAULT NULL,
            stock_after INT DEFAULT NULL,
            reference_code VARCHAR(50) DEFAULT NULL,
            remarks VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_inventory_log_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE SET NULL,
            CONSTRAINT fk_inventory_log_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
            CONSTRAINT fk_inventory_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_inventory_log_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS flash_sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone_id INT NOT NULL,
            branch_id INT NOT NULL,
            title VARCHAR(150) NOT NULL,
            promo_label VARCHAR(60) NOT NULL DEFAULT 'Flash Sale',
            sale_price DECIMAL(10,2) NOT NULL,
            description TEXT DEFAULT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_flash_sale_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE CASCADE,
            CONSTRAINT fk_flash_sale_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
            CONSTRAINT fk_flash_sale_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone_id INT DEFAULT NULL,
            branch_id INT DEFAULT NULL,
            customer_name VARCHAR(150) NOT NULL,
            contact_number VARCHAR(40) NOT NULL,
            preferred_channel ENUM('Phone','SMS','Call','Facebook','Email','Website') NOT NULL DEFAULT 'Website',
            subject VARCHAR(150) DEFAULT NULL,
            latest_message TEXT NOT NULL,
            status ENUM('New','Contacted','Resolved','Closed') NOT NULL DEFAULT 'New',
            assigned_user_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            CONSTRAINT fk_inquiry_phone FOREIGN KEY (phone_id) REFERENCES phones(id) ON DELETE SET NULL,
            CONSTRAINT fk_inquiry_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
            CONSTRAINT fk_inquiry_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS inquiry_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inquiry_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            sender_type ENUM('Customer','Staff','System') NOT NULL DEFAULT 'Customer',
            sender_name VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_inquiry_message_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiries(id) ON DELETE CASCADE,
            CONSTRAINT fk_inquiry_message_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!index_exists($db, 'stock_transfers', 'idx_transfer_status')) {
        $db->exec('CREATE INDEX idx_transfer_status ON stock_transfers (status)');
    }
    if (!index_exists($db, 'stock_transfers', 'idx_transfer_source_destination')) {
        $db->exec('CREATE INDEX idx_transfer_source_destination ON stock_transfers (source_branch_id, destination_branch_id)');
    }
    if (!index_exists($db, 'inventory_logs', 'idx_inventory_branch_created')) {
        $db->exec('CREATE INDEX idx_inventory_branch_created ON inventory_logs (branch_id, created_at)');
    }
    if (!index_exists($db, 'flash_sales', 'idx_flash_sale_branch_window')) {
        $db->exec('CREATE INDEX idx_flash_sale_branch_window ON flash_sales (branch_id, is_active, starts_at, ends_at)');
    }
    if (!index_exists($db, 'inquiries', 'idx_inquiry_branch_status')) {
        $db->exec('CREATE INDEX idx_inquiry_branch_status ON inquiries (branch_id, status, created_at)');
    }
    if (!index_exists($db, 'inquiry_messages', 'idx_inquiry_message_scope')) {
        $db->exec('CREATE INDEX idx_inquiry_message_scope ON inquiry_messages (inquiry_id, created_at)');
    }

    $db->exec(
        "CREATE OR REPLACE VIEW branch_inventory AS
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
            p.stock,
            p.image_url,
            p.created_at AS date_added,
            p.last_moved_at AS last_transfer_at,
            p.is_listed
         FROM phones p
         LEFT JOIN branches b ON b.id = p.branch_id"
    );

    $db->exec(
        "CREATE OR REPLACE VIEW branch_users AS
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
         LEFT JOIN branches b ON b.id = u.branch_id"
    );

    if (!empty($_SESSION['user']['branch_id']) && empty($_SESSION['user']['branch_name'])) {
        $stmt = $db->prepare('SELECT name FROM branches WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user']['branch_id']]);
        $_SESSION['user']['branch_name'] = $stmt->fetchColumn() ?: null;
    }
}

smartstock_bootstrap($db);
