<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Branch Admin']);

$user = current_user();
$flashKey = is_super_admin($user) ? 'superadmin' : 'dashboard';
$back = '../flash_sales.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}

ensure_branch_assigned($user);

$flashSaleId = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'disable';

if ($flashSaleId <= 0 || !in_array($action, ['disable', 'activate'], true)) {
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

$isActive = $action === 'activate' ? 1 : 0;
$update = $db->prepare('UPDATE flash_sales SET is_active = ? WHERE id = ?');
$update->execute([$isActive, $flashSaleId]);

log_activity(
    $db,
    $user['name'],
    str_replace('RF Chein - ', '', $flashSale['branch_name'] ?? 'System'),
    ($isActive ? 'Re-activated' : 'Disabled') . ' flash sale for ' . $flashSale['brand'] . ' ' . $flashSale['model']
);

flash_set($flashKey, 'Flash sale updated.');
redirect($back);