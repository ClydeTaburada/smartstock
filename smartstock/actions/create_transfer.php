<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Branch Admin', 'Staff']);

$user = current_user();
$back = (is_super_admin($user) ? '../superadmin.php' : '../dashboard.php') . '#transfers';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}

ensure_branch_assigned($user);

$sourceBranchId = resolve_managed_branch_id($db, $_POST['source_branch_id'] ?? null, $user);
$destinationBranchId = (int)($_POST['destination_branch_id'] ?? 0);
$phoneId = (int)($_POST['phone_id'] ?? 0);
$quantity = max(1, (int)($_POST['quantity'] ?? 1));
$notes = trim($_POST['notes'] ?? '');

if ($sourceBranchId === null || $sourceBranchId <= 0 || $destinationBranchId <= 0 || $phoneId <= 0) {
    flash_set('dashboard', 'Source branch, destination branch, and product are required.');
    redirect($back);
}
if ($sourceBranchId === $destinationBranchId) {
    flash_set('dashboard', 'Transfer source and destination must be different branches.');
    redirect($back);
}
if (!can_access_branch($sourceBranchId, $user)) {
    flash_set('dashboard', 'You cannot transfer stock from another branch.');
    redirect($back);
}

$branchStmt = $db->prepare('SELECT id, name, status FROM branches WHERE id IN (?, ?) ORDER BY id');
$branchStmt->execute([$sourceBranchId, $destinationBranchId]);
$branchRows = $branchStmt->fetchAll();
$branches = [];
foreach ($branchRows as $branch) {
    $branches[(int)$branch['id']] = $branch;
}

if (count($branches) !== 2) {
    flash_set('dashboard', 'Selected branch was not found.');
    redirect($back);
}
if (($branches[$sourceBranchId]['status'] ?? 'Inactive') !== 'Active' || ($branches[$destinationBranchId]['status'] ?? 'Inactive') !== 'Active') {
    flash_set('dashboard', 'Transfers are only allowed between active branches.');
    redirect($back);
}

$db->beginTransaction();
try {
    $phoneStmt = $db->prepare('SELECT * FROM phones WHERE id = ? AND branch_id = ? FOR UPDATE');
    $phoneStmt->execute([$phoneId, $sourceBranchId]);
    $phone = $phoneStmt->fetch();

    if (!$phone) {
        $db->rollBack();
        flash_set('dashboard', 'Selected inventory item is not available in the chosen source branch.');
        redirect($back);
    }

    if ((int)$phone['stock'] < $quantity) {
        $db->rollBack();
        flash_set('dashboard', 'Requested transfer quantity exceeds available stock.');
        redirect($back);
    }

    $db->query("SELECT GET_LOCK('transfer_code_lock', 10)");

    $transferCode = next_transfer_code($db);
    $existsStmt = $db->prepare('SELECT COUNT(*) FROM stock_transfers WHERE transfer_code = ?');
    $attempts = 0;
    while ($attempts < 25) {
        $existsStmt->execute([$transferCode]);
        if ((int)$existsStmt->fetchColumn() === 0) {
            break;
        }
        $attempts++;
        $transferCode = 'TRF-' . str_pad((string)((int)substr($transferCode, 4) + 1), 4, '0', STR_PAD_LEFT);
    }

    if ($attempts >= 25) {
        $db->query("SELECT RELEASE_LOCK('transfer_code_lock')");
        $db->rollBack();
        flash_set('dashboard', 'Could not generate a transfer code. Please try again.');
        redirect($back);
    }

    $transferStmt = $db->prepare(
        'INSERT INTO stock_transfers (transfer_code, source_branch_id, destination_branch_id, requested_by, status, notes) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $transferStmt->execute([$transferCode, $sourceBranchId, $destinationBranchId, $user['id'], 'Pending', $notes ?: null]);
    $transferId = (int)$db->lastInsertId();

    $itemStmt = $db->prepare(
        'INSERT INTO transfer_items (transfer_id, phone_id, product_name, imei, quantity, unit_cost) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $itemStmt->execute([
        $transferId,
        $phoneId,
        trim($phone['brand'] . ' ' . $phone['model']),
        $phone['imei'] ?: null,
        $quantity,
        $phone['purchase_price'] !== null ? (float)$phone['purchase_price'] : null,
    ]);

    $db->query("SELECT RELEASE_LOCK('transfer_code_lock')");
    $db->commit();

    log_activity(
        $db,
        $user['name'],
        str_replace('RF Chein - ', '', $branches[$sourceBranchId]['name']),
        'Requested transfer ' . $transferCode . ' to ' . str_replace('RF Chein - ', '', $branches[$destinationBranchId]['name']) . ' - ' . $phone['brand'] . ' ' . $phone['model'] . ' x' . $quantity
    );

    flash_set('dashboard', 'Transfer request ' . $transferCode . ' submitted.');
} catch (Throwable $e) {
    $db->rollBack();
    try {
        $db->query("SELECT RELEASE_LOCK('transfer_code_lock')");
    } catch (Throwable $unlockError) {
    }
    flash_set('dashboard', 'Could not create transfer request: ' . $e->getMessage());
}

redirect($back);