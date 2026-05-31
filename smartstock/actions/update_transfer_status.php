<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin']);

$user = current_user();
$back = (is_super_admin($user) ? '../superadmin.php' : '../dashboard.php') . '#transfers';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}

ensure_branch_assigned($user);

$transferId = (int)($_POST['transfer_id'] ?? 0);
$action = $_POST['action'] ?? '';
$rejectionReason = trim($_POST['rejection_reason'] ?? '');

if ($transferId <= 0 || $action === '') {
    flash_set('dashboard', 'Transfer action is incomplete.');
    redirect($back);
}

$clonePhoneToBranch = static function (PDO $db, array $sourcePhone, int $destinationBranchId, int $quantity): array {
    $insert = $db->prepare(
        'INSERT INTO phones (brand, model, storage, ram, color, `condition`, battery, selling_price, purchase_price, supplier, stock, branch_id, imei, serial_number, accessories, notes, emoji, image_url, is_listed, last_moved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $insert->execute([
        $sourcePhone['brand'],
        $sourcePhone['model'],
        $sourcePhone['storage'],
        $sourcePhone['ram'] ?: null,
        $sourcePhone['color'] ?: null,
        $sourcePhone['condition'],
        $sourcePhone['battery'],
        $sourcePhone['selling_price'],
        $sourcePhone['purchase_price'],
        $sourcePhone['supplier'] ?: null,
        $quantity,
        $destinationBranchId,
        $sourcePhone['imei'] ?: null,
        $sourcePhone['serial_number'] ?: null,
        $sourcePhone['accessories'] ?: null,
        $sourcePhone['notes'] ?: null,
        $sourcePhone['emoji'] ?: null,
        $sourcePhone['image_url'] ?: null,
        $sourcePhone['is_listed'],
    ]);

    return [
        'phone_id' => (int)$db->lastInsertId(),
        'stock_before' => 0,
        'stock_after' => $quantity,
    ];
};

$db->beginTransaction();

try {
    $transferStmt = $db->prepare(
        'SELECT t.*, sb.name AS source_branch_name, db2.name AS destination_branch_name FROM stock_transfers t LEFT JOIN branches sb ON sb.id = t.source_branch_id LEFT JOIN branches db2 ON db2.id = t.destination_branch_id WHERE t.id = ? FOR UPDATE'
    );
    $transferStmt->execute([$transferId]);
    $transfer = $transferStmt->fetch();

    if (!$transfer) {
        $db->rollBack();
        flash_set('dashboard', 'Transfer not found.');
        redirect($back);
    }
    $itemStmt = $db->prepare(
        'SELECT ti.*, p.brand, p.model, p.storage, p.ram, p.color, p.`condition`, p.battery, p.selling_price, p.purchase_price, p.supplier, p.serial_number, p.accessories, p.notes, p.emoji, p.image_url, p.is_listed, p.stock AS current_source_stock FROM transfer_items ti INNER JOIN phones p ON p.id = ti.phone_id WHERE ti.transfer_id = ? FOR UPDATE'
    );
    $itemStmt->execute([$transferId]);
    $items = $itemStmt->fetchAll();

    if (!$items) {
        $db->rollBack();
        flash_set('dashboard', 'Transfer has no items to process.');
        redirect($back);
    }

    switch ($action) {
        case 'approve':
            if ($transfer['status'] !== 'Pending') {
                throw new RuntimeException('Only pending transfers can be approved.');
            }

            $update = $db->prepare('UPDATE stock_transfers SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = NULL, rejected_at = NULL WHERE id = ?');
            $update->execute(['Approved', $user['id'], $transferId]);

            log_activity($db, $user['name'], 'System', 'Approved transfer ' . $transfer['transfer_code']);
            break;

        case 'reject':
            if (!in_array($transfer['status'], ['Pending', 'Approved'], true)) {
                throw new RuntimeException('Only pending or approved transfers can be rejected.');
            }

            $update = $db->prepare('UPDATE stock_transfers SET status = ?, approved_by = ?, rejection_reason = ?, rejected_at = NOW() WHERE id = ?');
            $update->execute(['Rejected', $user['id'], $rejectionReason ?: 'No reason provided', $transferId]);

            log_activity($db, $user['name'], 'System', 'Rejected transfer ' . $transfer['transfer_code']);
            break;

        case 'dispatch':
            if ($transfer['status'] !== 'Approved') {
                throw new RuntimeException('Only approved transfers can move to in transit.');
            }

            foreach ($items as $item) {
                $sourcePhoneStmt = $db->prepare('SELECT stock, branch_id FROM phones WHERE id = ? FOR UPDATE');
                $sourcePhoneStmt->execute([(int)$item['phone_id']]);
                $sourcePhone = $sourcePhoneStmt->fetch();

                if (!$sourcePhone || (int)$sourcePhone['branch_id'] !== (int)$transfer['source_branch_id']) {
                    throw new RuntimeException('Source inventory no longer belongs to the selected branch.');
                }
                if ((int)$sourcePhone['stock'] < (int)$item['quantity']) {
                    throw new RuntimeException('Insufficient stock to dispatch transfer ' . $transfer['transfer_code'] . '.');
                }

                $stockBefore = (int)$sourcePhone['stock'];
                $stockAfter = $stockBefore - (int)$item['quantity'];

                $updatePhone = $db->prepare('UPDATE phones SET stock = ?, last_moved_at = NOW() WHERE id = ?');
                $updatePhone->execute([$stockAfter, (int)$item['phone_id']]);

                $updateItem = $db->prepare('UPDATE transfer_items SET source_stock_before = ?, source_stock_after = ? WHERE id = ?');
                $updateItem->execute([$stockBefore, $stockAfter, (int)$item['id']]);

                log_inventory_event($db, [
                    'phone_id' => (int)$item['phone_id'],
                    'branch_id' => (int)$transfer['source_branch_id'],
                    'user_id' => $user['id'],
                    'transfer_id' => $transferId,
                    'event_type' => 'transfer_out',
                    'quantity_change' => -1 * (int)$item['quantity'],
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'reference_code' => $transfer['transfer_code'],
                    'remarks' => 'Dispatched to ' . str_replace('RF Chein - ', '', $transfer['destination_branch_name']),
                ]);
            }

            $update = $db->prepare('UPDATE stock_transfers SET status = ?, in_transit_at = NOW() WHERE id = ?');
            $update->execute(['In Transit', $transferId]);

            log_activity($db, $user['name'], 'System', 'Dispatched transfer ' . $transfer['transfer_code']);
            break;

        case 'complete':
            if ($transfer['status'] !== 'In Transit') {
                throw new RuntimeException('Only in-transit transfers can be completed.');
            }

            foreach ($items as $item) {
                $sourcePhoneStmt = $db->prepare('SELECT * FROM phones WHERE id = ? FOR UPDATE');
                $sourcePhoneStmt->execute([(int)$item['phone_id']]);
                $sourcePhone = $sourcePhoneStmt->fetch();

                if (!$sourcePhone) {
                    throw new RuntimeException('Source inventory record is missing for completion.');
                }

                $quantity = (int)$item['quantity'];
                if ((int)$sourcePhone['stock'] === 0) {
                    $moveStmt = $db->prepare('UPDATE phones SET branch_id = ?, stock = ?, last_moved_at = NOW() WHERE id = ?');
                    $moveStmt->execute([(int)$transfer['destination_branch_id'], $quantity, (int)$item['phone_id']]);
                    $destinationUpdate = [
                        'phone_id' => (int)$item['phone_id'],
                        'stock_before' => 0,
                        'stock_after' => $quantity,
                    ];
                } else {
                    $destinationUpdate = $clonePhoneToBranch($db, $sourcePhone, (int)$transfer['destination_branch_id'], $quantity);
                }

                $updateItem = $db->prepare('UPDATE transfer_items SET destination_stock_after = ? WHERE id = ?');
                $updateItem->execute([$destinationUpdate['stock_after'], (int)$item['id']]);

                log_inventory_event($db, [
                    'phone_id' => $destinationUpdate['phone_id'],
                    'branch_id' => (int)$transfer['destination_branch_id'],
                    'user_id' => $user['id'],
                    'transfer_id' => $transferId,
                    'event_type' => 'transfer_in',
                    'quantity_change' => $quantity,
                    'stock_before' => $destinationUpdate['stock_before'],
                    'stock_after' => $destinationUpdate['stock_after'],
                    'reference_code' => $transfer['transfer_code'],
                    'remarks' => 'Received from ' . str_replace('RF Chein - ', '', $transfer['source_branch_name']),
                ]);
            }

            $update = $db->prepare('UPDATE stock_transfers SET status = ?, completed_at = NOW() WHERE id = ?');
            $update->execute(['Completed', $transferId]);

            log_activity($db, $user['name'], 'System', 'Completed transfer ' . $transfer['transfer_code']);
            break;

        default:
            throw new RuntimeException('Unsupported transfer action.');
    }

    $db->commit();
    flash_set('dashboard', 'Transfer ' . $transfer['transfer_code'] . ' updated to ' . ($action === 'dispatch' ? 'In Transit' : ucfirst($action)) . '.');
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('dashboard', 'Could not update transfer: ' . $e->getMessage());
}

redirect($back);