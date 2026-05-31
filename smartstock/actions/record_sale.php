<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin','Branch Admin','Staff']);

// Super Admins operate from superadmin.php; everyone else from dashboard.php.
$user = current_user();
$back = is_super_admin($user) ? '../superadmin.php' : '../dashboard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect($back); }

ensure_branch_assigned($user);

$phone_id       = (int)($_POST['phone_id'] ?? 0);
$customer       = trim($_POST['customer'] ?? '') ?: 'Walk-in customer';
$price          = (float)($_POST['price'] ?? 0);
$payment_method = $_POST['payment_method'] ?? 'Cash';

if ($phone_id <= 0 || $price <= 0) {
    flash_set('dashboard', 'Please pick a product and a valid sale price.');
    redirect($back);
}
if (!in_array($payment_method, ['Cash','GCash','Maya','Bank transfer'], true)) {
    $payment_method = 'Cash';
}

$db->beginTransaction();
try {
    // Lock row
    $stmt = $db->prepare("SELECT p.id, p.brand, p.model, p.stock, p.branch_id, b.name AS branch_name FROM phones p LEFT JOIN branches b ON b.id = p.branch_id WHERE p.id = ? FOR UPDATE");
    $stmt->execute([$phone_id]);
    $phone = $stmt->fetch();

    if (!$phone) {
        $db->rollBack();
        flash_set('dashboard', 'Phone no longer exists.');
        redirect($back);
    }
    if ((int)$phone['stock'] <= 0) {
        $db->rollBack();
        flash_set('dashboard', 'That phone is out of stock.');
        redirect($back);
    }
    if (!can_access_branch($phone['branch_id'] ?? null, $user)) {
        $db->rollBack();
        flash_set('dashboard', 'You cannot record a sale for another branch.');
        redirect($back);
    }

    // Generate unique transaction ID using advisory lock to prevent duplicates
    $db->query("SELECT GET_LOCK('txn_id_lock', 10)");
    
    // Find the next available transaction ID by checking for duplicates
    $row = $db->query("SELECT txn_id FROM sales ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['txn_id'], $m)) {
        $next = (int)$m[1] + 1;
    }
    
    // Keep incrementing until we find a unique ID (handles gaps from failed transactions)
    $maxAttempts = 100;
    $attempts = 0;
    do {
        $txnId = 'TXN-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM sales WHERE txn_id = ?");
        $checkStmt->execute([$txnId]);
        $exists = (int)$checkStmt->fetchColumn() > 0;
        
        if ($exists) {
            $next++;
            $attempts++;
        }
    } while ($exists && $attempts < $maxAttempts);
    
    if ($attempts >= $maxAttempts) {
        $db->query("SELECT RELEASE_LOCK('txn_id_lock')");
        $db->rollBack();
        flash_set('dashboard', 'Could not generate unique transaction ID. Please try again.');
        redirect($back);
    }
    
    $productName = $phone['brand'] . ' ' . $phone['model'];
    $branchName = str_replace('RF Chein - ', '', $phone['branch_name'] ?? 'System');
    $stockBefore = (int)$phone['stock'];
    $stockAfter = $stockBefore - 1;

    $ins = $db->prepare("
        INSERT INTO sales (txn_id, phone_id, product_name, customer, price, payment_method, sale_date, status, user_id, branch_id)
        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Completed', ?, ?)
    ");
    $ins->execute([$txnId, $phone_id, $productName, $customer, $price, $payment_method, $user['id'], $phone['branch_id']]);

    // Decrement stock
    $db->prepare("UPDATE phones SET stock = stock - 1 WHERE id = ?")->execute([$phone_id]);

    log_activity($db, $user['name'], $branchName, 'Recorded sale ' . $txnId . ' - ' . $productName);
    log_inventory_event($db, [
        'phone_id' => $phone_id,
        'branch_id' => $phone['branch_id'],
        'user_id' => $user['id'],
        'event_type' => 'sale',
        'quantity_change' => -1,
        'stock_before' => $stockBefore,
        'stock_after' => $stockAfter,
        'reference_code' => $txnId,
        'remarks' => 'Completed sale for ' . $customer,
    ]);

    // Release the lock
    $db->query("SELECT RELEASE_LOCK('txn_id_lock')");

    $db->commit();
    flash_set('dashboard', "✓ Sale $txnId recorded — inventory updated.");
} catch (Throwable $e) {
    $db->rollBack();
    // Release lock if it was acquired
    try { $db->query("SELECT RELEASE_LOCK('txn_id_lock')"); } catch (Throwable $unlockError) {}
    flash_set('dashboard', 'Could not record sale: ' . $e->getMessage());
}

redirect($back);
