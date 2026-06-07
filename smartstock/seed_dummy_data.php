<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This seed script runs from the CLI only.\n");
}

require_once __DIR__ . '/includes/helpers.php';

$fetchScalar = static function (PDO $db, string $sql, array $params = []) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};

$fetchRow = static function (PDO $db, string $sql, array $params = []) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
};

$formatDate = static function (int $daysAgo): string {
    return (new DateTimeImmutable('today'))->modify(($daysAgo >= 0 ? '-' : '+') . abs($daysAgo) . ' days')->format('Y-m-d');
};

$formatDateTime = static function (int $daysAgo, string $time = '10:00:00'): string {
    return (new DateTimeImmutable('today ' . $time))->modify(($daysAgo >= 0 ? '-' : '+') . abs($daysAgo) . ' days')->format('Y-m-d H:i:s');
};

$summary = [
    'branches_inserted' => 0,
    'branches_updated' => 0,
    'users_inserted' => 0,
    'users_updated' => 0,
    'phones_inserted' => 0,
    'phones_updated' => 0,
    'sales_inserted' => 0,
    'sales_updated' => 0,
    'transfers_inserted' => 0,
    'transfers_updated' => 0,
    'transfer_items_inserted' => 0,
    'transfer_items_updated' => 0,
    'inventory_logs_inserted' => 0,
    'inventory_logs_updated' => 0,
    'flash_sales_inserted' => 0,
    'flash_sales_updated' => 0,
    'inquiries_inserted' => 0,
    'inquiries_updated' => 0,
    'inquiry_messages_inserted' => 0,
    'activity_logs_inserted' => 0,
];

$upsertBranch = static function (PDO $db, array $payload) use ($fetchRow, &$summary): int {
    $existing = $fetchRow($db, "SELECT id FROM branches WHERE name = ? LIMIT 1", [$payload['name']]);

    if ($existing) {
        $db->prepare("UPDATE branches SET address = ?, manager = ?, phone = ?, email = ?, status = ? WHERE id = ?")
            ->execute([
                $payload['address'],
                $payload['manager'],
                $payload['phone'],
                $payload['email'],
                $payload['status'],
                (int)$existing['id'],
            ]);
        $summary['branches_updated']++;
        return (int)$existing['id'];
    }

    $db->prepare("INSERT INTO branches (name, address, manager, phone, email, status) VALUES (?,?,?,?,?,?)")
        ->execute([
            $payload['name'],
            $payload['address'],
            $payload['manager'],
            $payload['phone'],
            $payload['email'],
            $payload['status'],
        ]);
    $summary['branches_inserted']++;
    return (int)$db->lastInsertId();
};

$upsertUser = static function (PDO $db, array $payload) use ($fetchRow, &$summary): int {
    $existing = $fetchRow($db, "SELECT id FROM users WHERE username = ? LIMIT 1", [$payload['username']]);

    if ($existing) {
        $db->prepare("UPDATE users SET name = ?, email = ?, phone = ?, password = ?, role = ?, branch_id = ? WHERE id = ?")
            ->execute([
                $payload['name'],
                $payload['email'],
                $payload['phone'],
                $payload['password'],
                $payload['role'],
                $payload['branch_id'],
                (int)$existing['id'],
            ]);
        $summary['users_updated']++;
        return (int)$existing['id'];
    }

    $db->prepare("INSERT INTO users (name, email, phone, username, password, role, branch_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([
            $payload['name'],
            $payload['email'],
            $payload['phone'],
            $payload['username'],
            $payload['password'],
            $payload['role'],
            $payload['branch_id'],
        ]);
    $summary['users_inserted']++;
    return (int)$db->lastInsertId();
};

$upsertPhone = static function (PDO $db, array $payload) use ($fetchRow, &$summary): int {
    $existing = $fetchRow($db, "SELECT id FROM phones WHERE imei = ? LIMIT 1", [$payload['imei']]);

    $params = [
        $payload['brand'],
        $payload['model'],
        $payload['series'],
        $payload['storage'],
        $payload['ram'],
        $payload['color'],
        $payload['operating_system'],
        $payload['condition'],
        $payload['battery'],
        $payload['selling_price'],
        $payload['purchase_price'],
        $payload['supplier'],
        $payload['stock'],
        $payload['branch_id'],
        $payload['serial_number'],
        $payload['accessories'],
        $payload['notes'],
        $payload['emoji'],
        $payload['image_url'],
        $payload['back_image_url'],
        $payload['is_listed'],
        $payload['last_moved_at'],
        $payload['created_at'],
    ];

    if ($existing) {
        $db->prepare(
            "UPDATE phones
             SET brand = ?, model = ?, series = ?, storage = ?, ram = ?, color = ?, operating_system = ?, `condition` = ?, battery = ?,
                 selling_price = ?, purchase_price = ?, supplier = ?, stock = ?, branch_id = ?, serial_number = ?, accessories = ?,
                 notes = ?, emoji = ?, image_url = ?, back_image_url = ?, is_listed = ?, last_moved_at = ?, created_at = ?
             WHERE id = ?"
        )->execute(array_merge($params, [(int)$existing['id']]));
        $summary['phones_updated']++;
        return (int)$existing['id'];
    }

    $db->prepare(
        "INSERT INTO phones (
            brand, model, series, storage, ram, color, operating_system, `condition`, battery,
            selling_price, purchase_price, supplier, stock, branch_id, imei, serial_number,
            accessories, notes, emoji, image_url, back_image_url, is_listed, last_moved_at, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute(array_merge(array_slice($params, 0, 14), [$payload['imei']], array_slice($params, 14)));
    $summary['phones_inserted']++;
    return (int)$db->lastInsertId();
};

$upsertSale = static function (PDO $db, array $payload) use ($fetchRow, &$summary): void {
    $existing = $fetchRow($db, "SELECT id FROM sales WHERE txn_id = ? LIMIT 1", [$payload['txn_id']]);
    $params = [
        $payload['phone_id'],
        $payload['product_name'],
        $payload['customer'],
        $payload['price'],
        $payload['payment_method'],
        $payload['sale_date'],
        $payload['status'],
        $payload['user_id'],
        $payload['branch_id'],
        $payload['created_at'],
    ];

    if ($existing) {
        $db->prepare(
            "UPDATE sales
             SET phone_id = ?, product_name = ?, customer = ?, price = ?, payment_method = ?, sale_date = ?, status = ?, user_id = ?, branch_id = ?, created_at = ?
             WHERE id = ?"
        )->execute(array_merge($params, [(int)$existing['id']]));
        $summary['sales_updated']++;
        return;
    }

    $db->prepare(
        "INSERT INTO sales (txn_id, phone_id, product_name, customer, price, payment_method, sale_date, status, user_id, branch_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    )->execute(array_merge([$payload['txn_id']], $params));
    $summary['sales_inserted']++;
};

$upsertTransfer = static function (PDO $db, array $payload) use ($fetchRow, &$summary): int {
    $existing = $fetchRow($db, "SELECT id FROM stock_transfers WHERE transfer_code = ? LIMIT 1", [$payload['transfer_code']]);
    $params = [
        $payload['source_branch_id'],
        $payload['destination_branch_id'],
        $payload['requested_by'],
        $payload['approved_by'],
        $payload['status'],
        $payload['notes'],
        $payload['rejection_reason'],
        $payload['requested_at'],
        $payload['approved_at'],
        $payload['in_transit_at'],
        $payload['completed_at'],
        $payload['rejected_at'],
    ];

    if ($existing) {
        $db->prepare(
            "UPDATE stock_transfers
             SET source_branch_id = ?, destination_branch_id = ?, requested_by = ?, approved_by = ?, status = ?, notes = ?, rejection_reason = ?,
                 requested_at = ?, approved_at = ?, in_transit_at = ?, completed_at = ?, rejected_at = ?
             WHERE id = ?"
        )->execute(array_merge($params, [(int)$existing['id']]));
        $summary['transfers_updated']++;
        return (int)$existing['id'];
    }

    $db->prepare(
        "INSERT INTO stock_transfers (
            transfer_code, source_branch_id, destination_branch_id, requested_by, approved_by, status,
            notes, rejection_reason, requested_at, approved_at, in_transit_at, completed_at, rejected_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute(array_merge([$payload['transfer_code']], $params));
    $summary['transfers_inserted']++;
    return (int)$db->lastInsertId();
};

$upsertTransferItem = static function (PDO $db, array $payload) use ($fetchRow, &$summary): void {
    $existing = $fetchRow($db, "SELECT id FROM transfer_items WHERE transfer_id = ? AND phone_id = ? LIMIT 1", [$payload['transfer_id'], $payload['phone_id']]);
    $params = [
        $payload['product_name'],
        $payload['imei'],
        $payload['quantity'],
        $payload['unit_cost'],
        $payload['source_stock_before'],
        $payload['source_stock_after'],
        $payload['destination_stock_after'],
        $payload['created_at'],
    ];

    if ($existing) {
        $db->prepare(
            "UPDATE transfer_items
             SET product_name = ?, imei = ?, quantity = ?, unit_cost = ?, source_stock_before = ?, source_stock_after = ?, destination_stock_after = ?, created_at = ?
             WHERE id = ?"
        )->execute(array_merge($params, [(int)$existing['id']]));
        $summary['transfer_items_updated']++;
        return;
    }

    $db->prepare(
        "INSERT INTO transfer_items (
            transfer_id, phone_id, product_name, imei, quantity, unit_cost,
            source_stock_before, source_stock_after, destination_stock_after, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute(array_merge([$payload['transfer_id'], $payload['phone_id']], $params));
    $summary['transfer_items_inserted']++;
};

$upsertInventoryLog = static function (PDO $db, array $payload) use ($fetchRow, &$summary): void {
    $existing = $fetchRow($db, "SELECT id FROM inventory_logs WHERE reference_code = ? AND event_type = ? LIMIT 1", [$payload['reference_code'], $payload['event_type']]);
    $params = [
        $payload['phone_id'],
        $payload['branch_id'],
        $payload['user_id'],
        $payload['transfer_id'],
        $payload['quantity_change'],
        $payload['stock_before'],
        $payload['stock_after'],
        $payload['remarks'],
        $payload['created_at'],
    ];

    if ($existing) {
        $db->prepare(
            "UPDATE inventory_logs
             SET phone_id = ?, branch_id = ?, user_id = ?, transfer_id = ?, quantity_change = ?, stock_before = ?, stock_after = ?, remarks = ?, created_at = ?
             WHERE id = ?"
        )->execute(array_merge($params, [(int)$existing['id']]));
        $summary['inventory_logs_updated']++;
        return;
    }

    $db->prepare(
        "INSERT INTO inventory_logs (
            phone_id, branch_id, user_id, transfer_id, event_type, quantity_change,
            stock_before, stock_after, reference_code, remarks, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    )->execute(array_merge([
        $payload['phone_id'],
        $payload['branch_id'],
        $payload['user_id'],
        $payload['transfer_id'],
        $payload['event_type'],
    ], array_slice($params, 4, 3), [$payload['reference_code']], array_slice($params, 7)));
    $summary['inventory_logs_inserted']++;
};

$upsertFlashSale = static function (PDO $db, array $payload) use ($fetchRow, &$summary): void {
    $existing = $fetchRow($db, "SELECT id FROM flash_sales WHERE title = ? AND branch_id = ? LIMIT 1", [$payload['title'], $payload['branch_id']]);
    $updateParams = [
        $payload['phone_id'],
        $payload['sale_price'],
        $payload['promo_label'],
        $payload['description'],
        $payload['starts_at'],
        $payload['ends_at'],
        $payload['is_active'],
        $payload['approval_status'],
        $payload['approval_notes'],
        $payload['approved_by'],
        $payload['approved_at'],
        $payload['created_by'],
        $payload['created_at'],
    ];

    if ($existing) {
        $db->prepare(
            "UPDATE flash_sales
             SET phone_id = ?, sale_price = ?, promo_label = ?, description = ?, starts_at = ?, ends_at = ?, is_active = ?, approval_status = ?, approval_notes = ?, approved_by = ?, approved_at = ?, created_by = ?, created_at = ?
             WHERE id = ?"
        )->execute(array_merge($updateParams, [(int)$existing['id']]));
        $summary['flash_sales_updated']++;
        return;
    }

    $db->prepare(
        "INSERT INTO flash_sales (
            phone_id, branch_id, title, promo_label, sale_price, description, starts_at, ends_at,
            is_active, approval_status, approval_notes, approved_by, approved_at, created_by, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $payload['phone_id'],
        $payload['branch_id'],
        $payload['title'],
        $payload['promo_label'],
        $payload['sale_price'],
        $payload['description'],
        $payload['starts_at'],
        $payload['ends_at'],
        $payload['is_active'],
        $payload['approval_status'],
        $payload['approval_notes'],
        $payload['approved_by'],
        $payload['approved_at'],
        $payload['created_by'],
        $payload['created_at'],
    ]);
    $summary['flash_sales_inserted']++;
};

$upsertInquiry = static function (PDO $db, array $payload) use ($fetchRow, &$summary): int {
    $existing = $fetchRow($db, "SELECT id FROM inquiries WHERE subject = ? AND customer_name = ? LIMIT 1", [$payload['subject'], $payload['customer_name']]);
    $updateParams = [
        $payload['phone_id'],
        $payload['branch_id'],
        $payload['contact_number'],
        $payload['preferred_channel'],
        $payload['latest_message'],
        $payload['status'],
        $payload['assigned_user_id'],
        $payload['created_at'],
        $payload['updated_at'],
        $payload['resolved_at'],
    ];

    if ($existing) {
        $db->prepare(
            "UPDATE inquiries
             SET phone_id = ?, branch_id = ?, contact_number = ?, preferred_channel = ?, latest_message = ?, status = ?, assigned_user_id = ?, created_at = ?, updated_at = ?, resolved_at = ?
             WHERE id = ?"
        )->execute(array_merge($updateParams, [(int)$existing['id']]));
        $summary['inquiries_updated']++;
        return (int)$existing['id'];
    }

    $db->prepare(
        "INSERT INTO inquiries (
            phone_id, branch_id, customer_name, contact_number, preferred_channel, subject,
            latest_message, status, assigned_user_id, created_at, updated_at, resolved_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $payload['phone_id'],
        $payload['branch_id'],
        $payload['customer_name'],
        $payload['contact_number'],
        $payload['preferred_channel'],
        $payload['subject'],
        $payload['latest_message'],
        $payload['status'],
        $payload['assigned_user_id'],
        $payload['created_at'],
        $payload['updated_at'],
        $payload['resolved_at'],
    ]);
    $summary['inquiries_inserted']++;
    return (int)$db->lastInsertId();
};

$insertInquiryMessageIfMissing = static function (PDO $db, array $payload) use ($fetchRow, &$summary): void {
    $existing = $fetchRow(
        $db,
        "SELECT id FROM inquiry_messages WHERE inquiry_id = ? AND sender_name = ? AND message = ? LIMIT 1",
        [$payload['inquiry_id'], $payload['sender_name'], $payload['message']]
    );

    if ($existing) {
        return;
    }

    $db->prepare(
        "INSERT INTO inquiry_messages (inquiry_id, user_id, sender_type, sender_name, message, created_at) VALUES (?,?,?,?,?,?)"
    )->execute([
        $payload['inquiry_id'],
        $payload['user_id'],
        $payload['sender_type'],
        $payload['sender_name'],
        $payload['message'],
        $payload['created_at'],
    ]);
    $summary['inquiry_messages_inserted']++;
};

$insertActivityLogIfMissing = static function (PDO $db, array $payload) use ($fetchRow, &$summary): void {
    $existing = $fetchRow(
        $db,
        "SELECT id FROM activity_logs WHERE user_name = ? AND branch_name = ? AND action = ? LIMIT 1",
        [$payload['user_name'], $payload['branch_name'], $payload['action']]
    );

    if ($existing) {
        return;
    }

    $db->prepare("INSERT INTO activity_logs (user_name, branch_name, action, created_at) VALUES (?,?,?,?)")
        ->execute([
            $payload['user_name'],
            $payload['branch_name'],
            $payload['action'],
            $payload['created_at'],
        ]);
    $summary['activity_logs_inserted']++;
};

try {
    $db->beginTransaction();

    $branchIds = [
        'main' => $upsertBranch($db, [
            'name' => 'RF Chein - Main Branch',
            'address' => 'Burgos St, Bacolod City',
            'manager' => 'John Francis Busel',
            'phone' => '0912-345-6789',
            'email' => 'main@rfchein.com',
            'status' => 'Active',
        ]),
        'lacson' => $upsertBranch($db, [
            'name' => 'RF Chein - Lacson Branch',
            'address' => 'Lacson St, Bacolod City',
            'manager' => 'Karen Dela Cruz',
            'phone' => '0923-456-7890',
            'email' => 'lacson@rfchein.com',
            'status' => 'Active',
        ]),
        'sm' => $upsertBranch($db, [
            'name' => 'RF Chein - SM Branch',
            'address' => 'SM City Bacolod',
            'manager' => 'Leah Ong',
            'phone' => '0934-567-8901',
            'email' => 'sm@rfchein.com',
            'status' => 'Active',
        ]),
    ];

    $userIds = [
        'admin' => $upsertUser($db, [
            'name' => 'Super Admin',
            'email' => 'admin@rfchein.com',
            'phone' => '0917-100-1000',
            'username' => 'admin',
            'password' => 'password123',
            'role' => 'Super Admin',
            'branch_id' => null,
        ]),
        'ceo' => $upsertUser($db, [
            'name' => 'Chief Executive Office',
            'email' => 'ceo@rfchein.com',
            'phone' => '0917-200-2000',
            'username' => 'ceo',
            'password' => 'password123',
            'role' => 'Admin',
            'branch_id' => null,
        ]),
        'jfbusel' => $upsertUser($db, [
            'name' => 'John Francis Busel',
            'email' => 'jfbusel@rfchein.com',
            'phone' => '0917-300-3000',
            'username' => 'jfbusel',
            'password' => 'password123',
            'role' => 'Supervisor',
            'branch_id' => $branchIds['main'],
        ]),
        'adex' => $upsertUser($db, [
            'name' => 'Almarie Ex',
            'email' => 'adex@rfchein.com',
            'phone' => '0917-400-4000',
            'username' => 'adex',
            'password' => 'password123',
            'role' => 'Staff',
            'branch_id' => $branchIds['main'],
        ]),
        'lacsuper' => $upsertUser($db, [
            'name' => 'Karen Dela Cruz',
            'email' => 'lacsuper@rfchein.com',
            'phone' => '0917-500-5000',
            'username' => 'lacsuper',
            'password' => 'password123',
            'role' => 'Supervisor',
            'branch_id' => $branchIds['lacson'],
        ]),
        'lacstaff' => $upsertUser($db, [
            'name' => 'Marco Tan',
            'email' => 'lacstaff@rfchein.com',
            'phone' => '0917-600-6000',
            'username' => 'lacstaff',
            'password' => 'password123',
            'role' => 'Staff',
            'branch_id' => $branchIds['lacson'],
        ]),
        'smsuper' => $upsertUser($db, [
            'name' => 'Leah Ong',
            'email' => 'smsuper@rfchein.com',
            'phone' => '0917-700-7000',
            'username' => 'smsuper',
            'password' => 'password123',
            'role' => 'Supervisor',
            'branch_id' => $branchIds['sm'],
        ]),
        'smstaff' => $upsertUser($db, [
            'name' => 'Paolo Reyes',
            'email' => 'smstaff@rfchein.com',
            'phone' => '0917-800-8000',
            'username' => 'smstaff',
            'password' => 'password123',
            'role' => 'Staff',
            'branch_id' => $branchIds['sm'],
        ]),
    ];

    $phones = [
        'main-iphone13' => ['branch' => 'main', 'brand' => 'Apple', 'model' => 'iPhone 13', 'series' => 'iPhone', 'storage' => '128GB', 'ram' => '4GB', 'color' => 'Midnight', 'operating_system' => 'iOS', 'condition' => 'Excellent', 'battery' => 90, 'selling_price' => 30500.00, 'purchase_price' => 26000.00, 'supplier' => 'SmartHub Manila', 'stock' => 2, 'imei' => 'DSS-MAIN-IP13-001', 'serial_number' => 'SN-DSS-MAIN-IP13', 'accessories' => 'Box, cable', 'notes' => 'Demo seed fast mover with low stock.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(3, '16:30:00'), 'created_at' => $formatDateTime(150, '09:00:00')],
        'main-vivo-v29' => ['branch' => 'main', 'brand' => 'Vivo', 'model' => 'V29', 'series' => 'V Series', 'storage' => '256GB', 'ram' => '12GB', 'color' => 'Purple', 'operating_system' => 'Android', 'condition' => 'Good', 'battery' => 94, 'selling_price' => 21500.00, 'purchase_price' => 18200.00, 'supplier' => 'Vivo Bacolod', 'stock' => 1, 'imei' => 'DSS-MAIN-V29-001', 'serial_number' => 'SN-DSS-MAIN-V29', 'accessories' => 'Box, cable, charger', 'notes' => 'Demo seed item with strong last-30-day velocity.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(4, '15:10:00'), 'created_at' => $formatDateTime(132, '11:00:00')],
        'main-galaxy-s23' => ['branch' => 'main', 'brand' => 'Samsung', 'model' => 'Galaxy S23', 'series' => 'Galaxy S', 'storage' => '256GB', 'ram' => '8GB', 'color' => 'Phantom Black', 'operating_system' => 'Android', 'condition' => 'Excellent', 'battery' => 92, 'selling_price' => 35500.00, 'purchase_price' => 31000.00, 'supplier' => 'Samsung Visayas', 'stock' => 5, 'imei' => 'DSS-MAIN-S23-001', 'serial_number' => 'SN-DSS-MAIN-S23', 'accessories' => 'Box, cable', 'notes' => 'Demo flagship stock for transfer workflow.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(6, '13:15:00'), 'created_at' => $formatDateTime(118, '10:45:00')],
        'main-oppo-reno10' => ['branch' => 'main', 'brand' => 'Oppo', 'model' => 'Reno10', 'series' => 'Reno', 'storage' => '256GB', 'ram' => '8GB', 'color' => 'Silver Grey', 'operating_system' => 'Android', 'condition' => 'Good', 'battery' => 88, 'selling_price' => 16990.00, 'purchase_price' => 14500.00, 'supplier' => 'Oppo Negros', 'stock' => 4, 'imei' => 'DSS-MAIN-RENO10-001', 'serial_number' => 'SN-DSS-MAIN-RENO10', 'accessories' => 'Unit only', 'notes' => 'Demo dead-stock candidate for DSS markdown suggestions.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(64, '09:20:00'), 'created_at' => $formatDateTime(164, '09:20:00')],
        'lacson-iphone12' => ['branch' => 'lacson', 'brand' => 'Apple', 'model' => 'iPhone 12', 'series' => 'iPhone', 'storage' => '128GB', 'ram' => '4GB', 'color' => 'Blue', 'operating_system' => 'iOS', 'condition' => 'Excellent', 'battery' => 89, 'selling_price' => 24500.00, 'purchase_price' => 21000.00, 'supplier' => 'SmartHub Manila', 'stock' => 3, 'imei' => 'DSS-LAC-IP12-001', 'serial_number' => 'SN-DSS-LAC-IP12', 'accessories' => 'Box, cable', 'notes' => 'Demo branch bestseller with limited stock.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(2, '17:00:00'), 'created_at' => $formatDateTime(148, '10:10:00')],
        'lacson-realme12plus' => ['branch' => 'lacson', 'brand' => 'Realme', 'model' => '12+', 'series' => 'Number Series', 'storage' => '256GB', 'ram' => '8GB', 'color' => 'Pioneer Green', 'operating_system' => 'Android', 'condition' => 'Good', 'battery' => 95, 'selling_price' => 16500.00, 'purchase_price' => 13900.00, 'supplier' => 'Realme Western Visayas', 'stock' => 2, 'imei' => 'DSS-LAC-R12P-001', 'serial_number' => 'SN-DSS-LAC-R12P', 'accessories' => 'Box, cable, case', 'notes' => 'Demo low-stock budget favorite.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(1, '12:45:00'), 'created_at' => $formatDateTime(105, '10:30:00')],
        'lacson-redmi13pro' => ['branch' => 'lacson', 'brand' => 'Xiaomi', 'model' => 'Redmi Note 13 Pro', 'series' => 'Redmi Note', 'storage' => '256GB', 'ram' => '12GB', 'color' => 'Midnight Black', 'operating_system' => 'Android', 'condition' => 'Excellent', 'battery' => 96, 'selling_price' => 17990.00, 'purchase_price' => 14950.00, 'supplier' => 'Mi Depot', 'stock' => 7, 'imei' => 'DSS-LAC-RN13P-001', 'serial_number' => 'SN-DSS-LAC-RN13P', 'accessories' => 'Box, cable', 'notes' => 'Demo midrange volume seller.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(13, '14:10:00'), 'created_at' => $formatDateTime(135, '11:20:00')],
        'lacson-a55' => ['branch' => 'lacson', 'brand' => 'Samsung', 'model' => 'Galaxy A55', 'series' => 'Galaxy A', 'storage' => '256GB', 'ram' => '8GB', 'color' => 'Awesome Navy', 'operating_system' => 'Android', 'condition' => 'Good', 'battery' => 91, 'selling_price' => 20990.00, 'purchase_price' => 18100.00, 'supplier' => 'Samsung Visayas', 'stock' => 6, 'imei' => 'DSS-LAC-A55-001', 'serial_number' => 'SN-DSS-LAC-A55', 'accessories' => 'Unit only', 'notes' => 'Demo slower item for executive branch mix.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(52, '09:40:00'), 'created_at' => $formatDateTime(142, '09:40:00')],
        'sm-iphone11' => ['branch' => 'sm', 'brand' => 'Apple', 'model' => 'iPhone 11', 'series' => 'iPhone', 'storage' => '64GB', 'ram' => '4GB', 'color' => 'White', 'operating_system' => 'iOS', 'condition' => 'Good', 'battery' => 85, 'selling_price' => 17990.00, 'purchase_price' => 15000.00, 'supplier' => 'SmartHub Manila', 'stock' => 1, 'imei' => 'DSS-SM-IP11-001', 'serial_number' => 'SN-DSS-SM-IP11', 'accessories' => 'Cable only', 'notes' => 'Demo low-stock Apple entry point.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(7, '15:45:00'), 'created_at' => $formatDateTime(156, '08:55:00')],
        'sm-infinix-zero30' => ['branch' => 'sm', 'brand' => 'Infinix', 'model' => 'Zero 30', 'series' => 'Zero', 'storage' => '256GB', 'ram' => '12GB', 'color' => 'Golden Hour', 'operating_system' => 'Android', 'condition' => 'Excellent', 'battery' => 97, 'selling_price' => 14990.00, 'purchase_price' => 12100.00, 'supplier' => 'Transsion Bacolod', 'stock' => 8, 'imei' => 'DSS-SM-ZERO30-001', 'serial_number' => 'SN-DSS-SM-ZERO30', 'accessories' => 'Box, cable, case', 'notes' => 'Demo promo-ready device for flash sales.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(14, '11:50:00'), 'created_at' => $formatDateTime(121, '09:05:00')],
        'sm-tecno-camon30' => ['branch' => 'sm', 'brand' => 'Tecno', 'model' => 'Camon 30', 'series' => 'Camon', 'storage' => '256GB', 'ram' => '8GB', 'color' => 'Basaltic Dark', 'operating_system' => 'Android', 'condition' => 'Good', 'battery' => 93, 'selling_price' => 13990.00, 'purchase_price' => 11350.00, 'supplier' => 'Transsion Bacolod', 'stock' => 5, 'imei' => 'DSS-SM-CAMON30-001', 'serial_number' => 'SN-DSS-SM-CAMON30', 'accessories' => 'Box, cable', 'notes' => 'Demo item used in transfer and momentum reports.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(10, '10:35:00'), 'created_at' => $formatDateTime(114, '10:35:00')],
        'sm-oppo-a98' => ['branch' => 'sm', 'brand' => 'Oppo', 'model' => 'A98', 'series' => 'A Series', 'storage' => '256GB', 'ram' => '8GB', 'color' => 'Cool Black', 'operating_system' => 'Android', 'condition' => 'Good', 'battery' => 87, 'selling_price' => 12990.00, 'purchase_price' => 10400.00, 'supplier' => 'Oppo Negros', 'stock' => 4, 'imei' => 'DSS-SM-A98-001', 'serial_number' => 'SN-DSS-SM-A98', 'accessories' => 'Unit only', 'notes' => 'Demo slow mover for branch decision support.', 'emoji' => 'PH', 'image_url' => null, 'back_image_url' => null, 'is_listed' => 1, 'last_moved_at' => $formatDateTime(83, '09:15:00'), 'created_at' => $formatDateTime(158, '09:15:00')],
    ];

    $phoneIds = [];
    foreach ($phones as $key => $phone) {
        $phone['branch_id'] = $branchIds[$phone['branch']];
        $phoneIds[$key] = $upsertPhone($db, $phone);
    }

    $customerNames = ['Mika Santos', 'Renz Villanueva', 'Aira Lim', 'Noel Garcia', 'Jessa Co', 'Paolo Dimaano', 'Bryan Co', 'Kaye Torres', 'Lara Serafica', 'Anton Mejia'];
    $paymentMethods = ['Cash', 'GCash', 'Maya', 'Bank transfer'];

    $salesPlans = [
        ['phone' => 'main-iphone13', 'user' => 'adex', 'days' => [0, 2, 8, 15, 37, 70, 111, 145], 'prices' => [30500, 30290, 30490, 29990, 30150, 30500, 30750, 30990]],
        ['phone' => 'main-vivo-v29', 'user' => 'adex', 'days' => [4, 11, 22, 58], 'prices' => [21500, 21250, 21490, 21500]],
        ['phone' => 'main-galaxy-s23', 'user' => 'jfbusel', 'days' => [6, 29, 63, 122], 'prices' => [35500, 35150, 34990, 35500]],
        ['phone' => 'lacson-iphone12', 'user' => 'lacstaff', 'days' => [1, 5, 19, 47, 89, 150], 'prices' => [24500, 24350, 24200, 24500, 24650, 24700]],
        ['phone' => 'lacson-realme12plus', 'user' => 'lacsuper', 'days' => [9, 18, 31, 74], 'prices' => [16500, 16290, 16490, 16500]],
        ['phone' => 'lacson-redmi13pro', 'user' => 'lacstaff', 'days' => [13, 39, 67, 119], 'prices' => [17990, 17690, 17850, 17990]],
        ['phone' => 'sm-iphone11', 'user' => 'smstaff', 'days' => [7, 16, 45, 84, 130], 'prices' => [17990, 17750, 17690, 17590, 17890]],
        ['phone' => 'sm-infinix-zero30', 'user' => 'smsuper', 'days' => [21, 42, 94, 156], 'prices' => [14990, 14790, 14650, 14990]],
        ['phone' => 'sm-tecno-camon30', 'user' => 'smstaff', 'days' => [10, 27, 60, 102], 'prices' => [13990, 13790, 13690, 13990]],
    ];

    $saleSequence = 1;
    $completedSales = [];
    foreach ($salesPlans as $plan) {
        foreach ($plan['days'] as $index => $daysAgo) {
            $phone = $phones[$plan['phone']];
            $salePayload = [
                'txn_id' => sprintf('DSSSALE-%03d', $saleSequence),
                'phone_id' => $phoneIds[$plan['phone']],
                'product_name' => $phone['brand'] . ' ' . $phone['model'],
                'customer' => $customerNames[($saleSequence - 1) % count($customerNames)],
                'price' => $plan['prices'][$index],
                'payment_method' => $paymentMethods[($saleSequence - 1) % count($paymentMethods)],
                'sale_date' => $formatDate($daysAgo),
                'status' => 'Completed',
                'user_id' => $userIds[$plan['user']],
                'branch_id' => $branchIds[$phone['branch']],
                'created_at' => $formatDateTime($daysAgo, '14:00:00'),
            ];

            $completedSales[] = $salePayload;
            $upsertSale($db, $salePayload);
            $saleSequence++;
        }
    }

    foreach ([
        ['txn_id' => 'DSSSALE-900', 'phone' => 'main-oppo-reno10', 'customer' => 'Demo Pending Buyer', 'price' => 16990, 'method' => 'GCash', 'days' => 1, 'status' => 'Pending', 'user' => 'adex'],
        ['txn_id' => 'DSSSALE-901', 'phone' => 'lacson-redmi13pro', 'customer' => 'Demo Refund Case', 'price' => 17990, 'method' => 'Cash', 'days' => 14, 'status' => 'Refunded', 'user' => 'lacstaff'],
    ] as $extraSale) {
        $phone = $phones[$extraSale['phone']];
        $upsertSale($db, [
            'txn_id' => $extraSale['txn_id'],
            'phone_id' => $phoneIds[$extraSale['phone']],
            'product_name' => $phone['brand'] . ' ' . $phone['model'],
            'customer' => $extraSale['customer'],
            'price' => $extraSale['price'],
            'payment_method' => $extraSale['method'],
            'sale_date' => $formatDate($extraSale['days']),
            'status' => $extraSale['status'],
            'user_id' => $userIds[$extraSale['user']],
            'branch_id' => $branchIds[$phone['branch']],
            'created_at' => $formatDateTime($extraSale['days'], '13:30:00'),
        ]);
    }

    $transferIds = [];
    foreach ([
        ['code' => 'DSS-TRF-001', 'source' => 'lacson', 'destination' => 'main', 'requested_by' => 'lacsuper', 'approved_by' => null, 'status' => 'Pending', 'notes' => 'Demo pending rebalancing for Main branch walk-ins.', 'rejection_reason' => null, 'requested_days' => 1, 'approved_days' => null, 'transit_days' => null, 'completed_days' => null, 'rejected_days' => null, 'item_phone' => 'lacson-realme12plus', 'qty' => 1, 'source_before' => 3, 'source_after' => 2, 'destination_after' => 3],
        ['code' => 'DSS-TRF-002', 'source' => 'main', 'destination' => 'sm', 'requested_by' => 'jfbusel', 'approved_by' => 'admin', 'status' => 'Approved', 'notes' => 'Demo approved branch transfer for flagship demand.', 'rejection_reason' => null, 'requested_days' => 4, 'approved_days' => 3, 'transit_days' => null, 'completed_days' => null, 'rejected_days' => null, 'item_phone' => 'main-galaxy-s23', 'qty' => 1, 'source_before' => 6, 'source_after' => 5, 'destination_after' => 6],
        ['code' => 'DSS-TRF-003', 'source' => 'sm', 'destination' => 'lacson', 'requested_by' => 'smsuper', 'approved_by' => 'ceo', 'status' => 'In Transit', 'notes' => 'Demo transfer already dispatched and still in transit.', 'rejection_reason' => null, 'requested_days' => 3, 'approved_days' => 2, 'transit_days' => 1, 'completed_days' => null, 'rejected_days' => null, 'item_phone' => 'sm-tecno-camon30', 'qty' => 2, 'source_before' => 7, 'source_after' => 5, 'destination_after' => 7],
        ['code' => 'DSS-TRF-004', 'source' => 'lacson', 'destination' => 'sm', 'requested_by' => 'lacsuper', 'approved_by' => 'admin', 'status' => 'Completed', 'notes' => 'Demo completed transfer for accessory bundle demand.', 'rejection_reason' => null, 'requested_days' => 12, 'approved_days' => 11, 'transit_days' => 10, 'completed_days' => 9, 'rejected_days' => null, 'item_phone' => 'lacson-redmi13pro', 'qty' => 2, 'source_before' => 9, 'source_after' => 7, 'destination_after' => 9],
        ['code' => 'DSS-TRF-005', 'source' => 'main', 'destination' => 'lacson', 'requested_by' => 'jfbusel', 'approved_by' => 'ceo', 'status' => 'Rejected', 'notes' => 'Demo rejected transfer request before promo weekend.', 'rejection_reason' => 'Keep demo stock in Main for the weekend flash sale.', 'requested_days' => 18, 'approved_days' => null, 'transit_days' => null, 'completed_days' => null, 'rejected_days' => 17, 'item_phone' => 'main-oppo-reno10', 'qty' => 1, 'source_before' => 5, 'source_after' => 4, 'destination_after' => 5],
    ] as $transferPlan) {
        $transferIds[$transferPlan['code']] = $upsertTransfer($db, [
            'transfer_code' => $transferPlan['code'],
            'source_branch_id' => $branchIds[$transferPlan['source']],
            'destination_branch_id' => $branchIds[$transferPlan['destination']],
            'requested_by' => $userIds[$transferPlan['requested_by']],
            'approved_by' => $transferPlan['approved_by'] ? $userIds[$transferPlan['approved_by']] : null,
            'status' => $transferPlan['status'],
            'notes' => $transferPlan['notes'],
            'rejection_reason' => $transferPlan['rejection_reason'],
            'requested_at' => $formatDateTime($transferPlan['requested_days'], '10:00:00'),
            'approved_at' => $transferPlan['approved_days'] !== null ? $formatDateTime($transferPlan['approved_days'], '12:00:00') : null,
            'in_transit_at' => $transferPlan['transit_days'] !== null ? $formatDateTime($transferPlan['transit_days'], '15:00:00') : null,
            'completed_at' => $transferPlan['completed_days'] !== null ? $formatDateTime($transferPlan['completed_days'], '17:30:00') : null,
            'rejected_at' => $transferPlan['rejected_days'] !== null ? $formatDateTime($transferPlan['rejected_days'], '16:20:00') : null,
        ]);

        $phone = $phones[$transferPlan['item_phone']];
        $upsertTransferItem($db, [
            'transfer_id' => $transferIds[$transferPlan['code']],
            'phone_id' => $phoneIds[$transferPlan['item_phone']],
            'product_name' => $phone['brand'] . ' ' . $phone['model'],
            'imei' => $phone['imei'],
            'quantity' => $transferPlan['qty'],
            'unit_cost' => $phone['purchase_price'],
            'source_stock_before' => $transferPlan['source_before'],
            'source_stock_after' => $transferPlan['source_after'],
            'destination_stock_after' => $transferPlan['destination_after'],
            'created_at' => $formatDateTime($transferPlan['requested_days'], '10:15:00'),
        ]);
    }

    foreach ([
        ['phone' => 'main-iphone13', 'branch' => 'main', 'title' => 'Demo: iPhone 13 Weekend Flash', 'promo_label' => 'Weekend Flash', 'sale_price' => 28990.00, 'description' => 'Live demo promo for the executive and branch overview cards.', 'starts' => 1, 'ends' => -2, 'is_active' => 1, 'approval_status' => 'Approved', 'approval_notes' => 'Approved for weekend traffic push.', 'approved_by' => 'ceo', 'approved_days' => 1, 'created_by' => 'jfbusel', 'created_days' => 2],
        ['phone' => 'lacson-realme12plus', 'branch' => 'lacson', 'title' => 'Demo: Realme 12+ Payday Flash', 'promo_label' => 'Payday Flash', 'sale_price' => 14990.00, 'description' => 'Pending approval sample for the flash-sale workflow.', 'starts' => -0, 'ends' => -4, 'is_active' => 1, 'approval_status' => 'Pending', 'approval_notes' => null, 'approved_by' => null, 'approved_days' => null, 'created_by' => 'lacsuper', 'created_days' => 0],
        ['phone' => 'lacson-a55', 'branch' => 'lacson', 'title' => 'Demo: Galaxy A55 Clearance Request', 'promo_label' => 'Clearance', 'sale_price' => 18990.00, 'description' => 'Rejected sample so executives can confirm the approval queue.', 'starts' => 3, 'ends' => 6, 'is_active' => 0, 'approval_status' => 'Rejected', 'approval_notes' => 'Margin is too thin for this markdown.', 'approved_by' => 'admin', 'approved_days' => 2, 'created_by' => 'lacsuper', 'created_days' => 4],
        ['phone' => 'sm-infinix-zero30', 'branch' => 'sm', 'title' => 'Demo: Infinix Back-to-School', 'promo_label' => 'Back to School', 'sale_price' => 13490.00, 'description' => 'Upcoming approved campaign to keep future promos visible in the system.', 'starts' => -5, 'ends' => -8, 'is_active' => 1, 'approval_status' => 'Approved', 'approval_notes' => 'Approved for the next promo cycle.', 'approved_by' => 'ceo', 'approved_days' => 5, 'created_by' => 'smsuper', 'created_days' => 6],
    ] as $flashPlan) {
        $phone = $phones[$flashPlan['phone']];
        $upsertFlashSale($db, [
            'phone_id' => $phoneIds[$flashPlan['phone']],
            'branch_id' => $branchIds[$flashPlan['branch']],
            'title' => $flashPlan['title'],
            'promo_label' => $flashPlan['promo_label'],
            'sale_price' => $flashPlan['sale_price'],
            'description' => $flashPlan['description'],
            'starts_at' => $formatDateTime($flashPlan['starts'], '09:00:00'),
            'ends_at' => $formatDateTime($flashPlan['ends'], '22:00:00'),
            'is_active' => $flashPlan['is_active'],
            'approval_status' => $flashPlan['approval_status'],
            'approval_notes' => $flashPlan['approval_notes'],
            'approved_by' => $flashPlan['approved_by'] ? $userIds[$flashPlan['approved_by']] : null,
            'approved_at' => $flashPlan['approved_days'] !== null ? $formatDateTime($flashPlan['approved_days'], '11:00:00') : null,
            'created_by' => $userIds[$flashPlan['created_by']],
            'created_at' => $formatDateTime($flashPlan['created_days'], '08:45:00'),
        ]);
    }

    $inquiryDefinitions = [
        [
            'phone' => 'main-iphone13', 'branch' => 'main', 'customer_name' => 'Mika Santos', 'contact_number' => '09181234567', 'preferred_channel' => 'Website',
            'subject' => 'Demo: iPhone 13 reservation', 'latest_message' => 'Available pa ang iPhone 13 128GB midnight for reservation?', 'status' => 'New', 'assigned_user_id' => $userIds['adex'],
            'created_days' => 1, 'updated_days' => null, 'resolved_days' => null,
            'messages' => [
                ['user_id' => null, 'sender_type' => 'Customer', 'sender_name' => 'Mika Santos', 'message' => 'Available pa ang iPhone 13 128GB midnight for reservation?', 'days' => 1, 'time' => '09:10:00'],
            ],
        ],
        [
            'phone' => 'main-vivo-v29', 'branch' => 'main', 'customer_name' => 'Renz Villanueva', 'contact_number' => '09182345678', 'preferred_channel' => 'Facebook',
            'subject' => 'Demo: Vivo V29 installment', 'latest_message' => 'Pwede ba ipareserve until Saturday after down payment?', 'status' => 'Contacted', 'assigned_user_id' => $userIds['adex'],
            'created_days' => 2, 'updated_days' => 1, 'resolved_days' => null,
            'messages' => [
                ['user_id' => null, 'sender_type' => 'Customer', 'sender_name' => 'Renz Villanueva', 'message' => 'Pwede ba ipareserve until Saturday after down payment?', 'days' => 2, 'time' => '11:25:00'],
                ['user_id' => $userIds['adex'], 'sender_type' => 'Staff', 'sender_name' => 'Almarie Ex', 'message' => 'Yes, we can hold it until Saturday afternoon once you confirm the branch pickup.', 'days' => 1, 'time' => '14:10:00'],
            ],
        ],
        [
            'phone' => 'lacson-iphone12', 'branch' => 'lacson', 'customer_name' => 'Aira Lim', 'contact_number' => '09183456789', 'preferred_channel' => 'SMS',
            'subject' => 'Demo: iPhone 12 trade-in', 'latest_message' => 'How much add cash if trade-in ang iPhone 11 128GB?', 'status' => 'New', 'assigned_user_id' => $userIds['lacstaff'],
            'created_days' => 3, 'updated_days' => null, 'resolved_days' => null,
            'messages' => [
                ['user_id' => null, 'sender_type' => 'Customer', 'sender_name' => 'Aira Lim', 'message' => 'How much add cash if trade-in ang iPhone 11 128GB?', 'days' => 3, 'time' => '16:00:00'],
            ],
        ],
        [
            'phone' => 'sm-infinix-zero30', 'branch' => 'sm', 'customer_name' => 'Joan Gamboa', 'contact_number' => '09184567890', 'preferred_channel' => 'Website',
            'subject' => 'Demo: Infinix Zero 30 availability', 'latest_message' => 'Confirmed for pickup this afternoon.', 'status' => 'Resolved', 'assigned_user_id' => $userIds['smstaff'],
            'created_days' => 5, 'updated_days' => 4, 'resolved_days' => 3,
            'messages' => [
                ['user_id' => null, 'sender_type' => 'Customer', 'sender_name' => 'Joan Gamboa', 'message' => 'May gold color pa ba sa SM branch?', 'days' => 5, 'time' => '10:50:00'],
                ['user_id' => $userIds['smstaff'], 'sender_type' => 'Staff', 'sender_name' => 'Paolo Reyes', 'message' => 'Yes, one unit is available and can be held until 4 PM today.', 'days' => 4, 'time' => '13:35:00'],
            ],
        ],
        [
            'phone' => null, 'branch' => 'main', 'customer_name' => 'Bryan Co', 'contact_number' => '09185678901', 'preferred_channel' => 'Call',
            'subject' => 'Demo: branch transfer follow-up', 'latest_message' => 'Customer already redirected to the Main branch for pickup.', 'status' => 'Closed', 'assigned_user_id' => $userIds['jfbusel'],
            'created_days' => 9, 'updated_days' => 8, 'resolved_days' => 8,
            'messages' => [
                ['user_id' => null, 'sender_type' => 'Customer', 'sender_name' => 'Bryan Co', 'message' => 'Pwede ko ba kwaon sa Main branch ang unit from Lacson?', 'days' => 9, 'time' => '09:00:00'],
                ['user_id' => $userIds['jfbusel'], 'sender_type' => 'Staff', 'sender_name' => 'John Francis Busel', 'message' => 'Yes, we coordinated the branch handoff and confirmed the pickup window.', 'days' => 8, 'time' => '12:20:00'],
            ],
        ],
    ];

    foreach ($inquiryDefinitions as $inquiryDefinition) {
        $inquiryId = $upsertInquiry($db, [
            'phone_id' => $inquiryDefinition['phone'] ? $phoneIds[$inquiryDefinition['phone']] : null,
            'branch_id' => $branchIds[$inquiryDefinition['branch']],
            'customer_name' => $inquiryDefinition['customer_name'],
            'contact_number' => $inquiryDefinition['contact_number'],
            'preferred_channel' => $inquiryDefinition['preferred_channel'],
            'subject' => $inquiryDefinition['subject'],
            'latest_message' => $inquiryDefinition['latest_message'],
            'status' => $inquiryDefinition['status'],
            'assigned_user_id' => $inquiryDefinition['assigned_user_id'],
            'created_at' => $formatDateTime($inquiryDefinition['created_days'], '09:00:00'),
            'updated_at' => $inquiryDefinition['updated_days'] !== null ? $formatDateTime($inquiryDefinition['updated_days'], '14:30:00') : null,
            'resolved_at' => $inquiryDefinition['resolved_days'] !== null ? $formatDateTime($inquiryDefinition['resolved_days'], '16:15:00') : null,
        ]);

        foreach ($inquiryDefinition['messages'] as $message) {
            $insertInquiryMessageIfMissing($db, [
                'inquiry_id' => $inquiryId,
                'user_id' => $message['user_id'],
                'sender_type' => $message['sender_type'],
                'sender_name' => $message['sender_name'],
                'message' => $message['message'],
                'created_at' => $formatDateTime($message['days'], $message['time']),
            ]);
        }
    }

    foreach ($phones as $key => $phone) {
        $upsertInventoryLog($db, [
            'phone_id' => $phoneIds[$key],
            'branch_id' => $branchIds[$phone['branch']],
            'user_id' => $userIds[$phone['branch'] === 'main' ? 'jfbusel' : ($phone['branch'] === 'lacson' ? 'lacsuper' : 'smsuper')],
            'transfer_id' => null,
            'event_type' => 'created',
            'quantity_change' => $phone['stock'],
            'stock_before' => 0,
            'stock_after' => $phone['stock'],
            'reference_code' => 'SEED-PHONE-' . strtoupper(str_replace('-', '', $key)),
            'remarks' => 'Demo inventory seed for analytics and DSS testing.',
            'created_at' => $phone['created_at'],
        ]);
    }

    foreach ($completedSales as $salePayload) {
        $upsertInventoryLog($db, [
            'phone_id' => $salePayload['phone_id'],
            'branch_id' => $salePayload['branch_id'],
            'user_id' => $salePayload['user_id'],
            'transfer_id' => null,
            'event_type' => 'sale',
            'quantity_change' => -1,
            'stock_before' => null,
            'stock_after' => null,
            'reference_code' => $salePayload['txn_id'],
            'remarks' => 'Demo completed sale for overview and monthly analytics.',
            'created_at' => $salePayload['created_at'],
        ]);
    }

    foreach ([
        ['code' => 'DSS-TRF-002', 'event' => 'transfer_out', 'branch' => 'main', 'user' => 'jfbusel', 'qty' => -1, 'before' => 6, 'after' => 5, 'time' => '12:15:00'],
        ['code' => 'DSS-TRF-003', 'event' => 'transfer_out', 'branch' => 'sm', 'user' => 'smsuper', 'qty' => -2, 'before' => 7, 'after' => 5, 'time' => '15:10:00'],
        ['code' => 'DSS-TRF-004', 'event' => 'transfer_out', 'branch' => 'lacson', 'user' => 'lacsuper', 'qty' => -2, 'before' => 9, 'after' => 7, 'time' => '10:10:00'],
        ['code' => 'DSS-TRF-004-IN', 'event' => 'transfer_in', 'branch' => 'sm', 'user' => 'smsuper', 'qty' => 2, 'before' => 7, 'after' => 9, 'time' => '17:30:00', 'transfer_key' => 'DSS-TRF-004', 'phone' => 'lacson-redmi13pro', 'days' => 9],
    ] as $movement) {
        $transferKey = $movement['transfer_key'] ?? $movement['code'];
        $phoneKey = $movement['phone'] ?? null;
        if ($phoneKey === null) {
            $phoneKey = $transferKey === 'DSS-TRF-002' ? 'main-galaxy-s23' : ($transferKey === 'DSS-TRF-003' ? 'sm-tecno-camon30' : 'lacson-redmi13pro');
        }
        $daysAgo = $movement['days'] ?? ($transferKey === 'DSS-TRF-002' ? 3 : ($transferKey === 'DSS-TRF-003' ? 1 : 9));
        $upsertInventoryLog($db, [
            'phone_id' => $phoneIds[$phoneKey],
            'branch_id' => $branchIds[$movement['branch']],
            'user_id' => $userIds[$movement['user']],
            'transfer_id' => $transferIds[$transferKey],
            'event_type' => $movement['event'],
            'quantity_change' => $movement['qty'],
            'stock_before' => $movement['before'],
            'stock_after' => $movement['after'],
            'reference_code' => $movement['code'],
            'remarks' => 'Demo transfer movement log for branch monitoring.',
            'created_at' => $formatDateTime($daysAgo, $movement['time']),
        ]);
    }

    foreach ([
        ['user_name' => 'Chief Executive Office', 'branch_name' => 'System', 'action' => 'Reviewed demo flash sale approval queue', 'days' => 0],
        ['user_name' => 'John Francis Busel', 'branch_name' => 'RF Chein - Main Branch', 'action' => 'Approved demo stock transfer request', 'days' => 1],
        ['user_name' => 'Almarie Ex', 'branch_name' => 'RF Chein - Main Branch', 'action' => 'Recorded demo completed sale DSSSALE-001', 'days' => 0],
        ['user_name' => 'Karen Dela Cruz', 'branch_name' => 'RF Chein - Lacson Branch', 'action' => 'Created demo payday flash sale request', 'days' => 0],
        ['user_name' => 'Leah Ong', 'branch_name' => 'RF Chein - SM Branch', 'action' => 'Monitored demo incoming transfer DSS-TRF-004', 'days' => 2],
    ] as $activity) {
        $insertActivityLogIfMissing($db, [
            'user_name' => $activity['user_name'],
            'branch_name' => $activity['branch_name'],
            'action' => $activity['action'],
            'created_at' => $formatDateTime($activity['days'], '18:00:00'),
        ]);
    }

    $db->commit();

    $counts = [];
    foreach (['branches', 'users', 'phones', 'sales', 'stock_transfers', 'transfer_items', 'inventory_logs', 'flash_sales', 'inquiries', 'inquiry_messages'] as $table) {
        $counts[$table] = (int)$fetchScalar($db, 'SELECT COUNT(*) FROM ' . $table);
    }

    $monthRevenue = (float)$fetchScalar($db, "SELECT COALESCE(SUM(price),0) FROM sales WHERE status = 'Completed' AND sale_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $todayRevenue = (float)$fetchScalar($db, "SELECT COALESCE(SUM(price),0) FROM sales WHERE status = 'Completed' AND sale_date = CURDATE()");
    $openInquiries = (int)$fetchScalar($db, "SELECT COUNT(*) FROM inquiries WHERE status IN ('New','Contacted')");
    $pendingTransfers = (int)$fetchScalar($db, "SELECT COUNT(*) FROM stock_transfers WHERE status = 'Pending'");
    $pendingFlash = (int)$fetchScalar($db, "SELECT COUNT(*) FROM flash_sales WHERE approval_status = 'Pending'");

    echo "Dummy analytics seed complete.\n\n";
    echo "Upsert summary:\n";
    foreach ($summary as $label => $value) {
        echo '  - ' . $label . ': ' . $value . "\n";
    }

    echo "\nCurrent table counts:\n";
    foreach ($counts as $table => $count) {
        echo '  - ' . $table . ': ' . $count . "\n";
    }

    echo "\nOverview sanity checks:\n";
    echo '  - Revenue today: ' . number_format($todayRevenue, 2) . "\n";
    echo '  - Revenue this month: ' . number_format($monthRevenue, 2) . "\n";
    echo '  - Open inquiries: ' . $openInquiries . "\n";
    echo '  - Pending transfers: ' . $pendingTransfers . "\n";
    echo '  - Pending flash approvals: ' . $pendingFlash . "\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'Dummy analytics seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}