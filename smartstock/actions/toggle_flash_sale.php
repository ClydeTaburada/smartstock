<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin']);

$user = current_user();
$flashKey = (is_super_admin($user) || is_admin_user($user)) ? 'superadmin' : 'dashboard';
$back = '../flash_sales.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}

ensure_branch_assigned($user);

$flashSaleId = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'disable';
$approvalNotes = trim($_POST['approval_notes'] ?? '');

if ($flashSaleId <= 0 || !in_array($action, ['disable', 'activate', 'approve', 'reject'], true)) {
    flash_set($flashKey, 'Flash sale action is invalid.');
    redirect($back);
}

$stmt = $db->prepare(
    'SELECT fs.*, p.brand, p.model, b.name AS branch_name
     FROM flash_sales fs
     INNER JOIN phones p ON p.id = fs.phone_id
     LEFT JOIN branches b ON b.id = fs.branch_id
     WHERE fs.id = ?'
);
$stmt->execute([$flashSaleId]);
$flashSale = $stmt->fetch();

if (!$flashSale) {
    flash_set($flashKey, 'Flash sale not found.');
    redirect($back);
}
if (!can_access_branch($flashSale['branch_id'] ?? null, $user)) {
    flash_set($flashKey, 'You cannot manage that flash sale.');
    redirect($back);
}

$now = date('Y-m-d H:i:s');

if ($action === 'approve') {
    $update = $db->prepare('UPDATE flash_sales SET is_active = 1, approval_status = "Approved", approval_notes = ?, approved_by = ?, approved_at = ? WHERE id = ?');
    $update->execute([$approvalNotes !== '' ? $approvalNotes : 'Approved from flash sale board.', $user['id'] ?? null, $now, $flashSaleId]);
    $logAction = 'Approved flash sale for ' . $flashSale['brand'] . ' ' . $flashSale['model'];
} elseif ($action === 'reject') {
    $update = $db->prepare('UPDATE flash_sales SET is_active = 0, approval_status = "Rejected", approval_notes = ?, approved_by = ?, approved_at = ? WHERE id = ?');
    $update->execute([$approvalNotes !== '' ? $approvalNotes : 'Rejected from flash sale board.', $user['id'] ?? null, $now, $flashSaleId]);
    $logAction = 'Rejected flash sale for ' . $flashSale['brand'] . ' ' . $flashSale['model'];
} else {
    if (($flashSale['approval_status'] ?? 'Approved') !== 'Approved') {
        flash_set($flashKey, 'Approve the flash sale before changing its live status.');
        redirect($back);
    }
    $isActive = $action === 'activate' ? 1 : 0;
    $update = $db->prepare('UPDATE flash_sales SET is_active = ?, approval_notes = ? WHERE id = ?');
    $update->execute([$isActive, $approvalNotes !== '' ? $approvalNotes : ($flashSale['approval_notes'] ?? null), $flashSaleId]);
    $logAction = ($isActive ? 'Re-activated' : 'Disabled') . ' flash sale for ' . $flashSale['brand'] . ' ' . $flashSale['model'];
}

log_activity(
    $db,
    $user['name'],
    str_replace('RF Chein - ', '', $flashSale['branch_name'] ?? 'System'),
    $logAction
);

flash_set($flashKey, 'Flash sale updated.');
redirect($back);