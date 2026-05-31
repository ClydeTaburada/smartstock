<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin', 'Supervisor']);

$user = current_user();
$flashKey = (is_super_admin($user) || is_admin_user($user)) ? 'superadmin' : 'dashboard';
$back = '../flash_sales.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}

ensure_branch_assigned($user);

$phoneId = (int)($_POST['phone_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$promoLabel = trim($_POST['promo_label'] ?? '');
$salePrice = (float)($_POST['sale_price'] ?? 0);
$startsAt = trim($_POST['starts_at'] ?? '');
$endsAt = trim($_POST['ends_at'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($phoneId <= 0 || $title === '' || $salePrice <= 0 || $startsAt === '' || $endsAt === '') {
    flash_set($flashKey, 'Phone, title, sale price, start, and end time are required.');
    redirect($back);
}

$startAtValue = strtotime($startsAt);
$endAtValue = strtotime($endsAt);
if ($startAtValue === false || $endAtValue === false || $endAtValue <= $startAtValue) {
    flash_set($flashKey, 'Flash sale schedule is invalid.');
    redirect($back);
}

$phoneStmt = $db->prepare(
    'SELECT p.id, p.brand, p.model, p.selling_price, p.branch_id, p.stock, p.is_listed, b.name AS branch_name, b.status AS branch_status
     FROM phones p
     LEFT JOIN branches b ON b.id = p.branch_id
     WHERE p.id = ?'
);
$phoneStmt->execute([$phoneId]);
$phone = $phoneStmt->fetch();

if (!$phone || (int)($phone['is_listed'] ?? 0) !== 1 || (int)($phone['stock'] ?? 0) <= 0) {
    flash_set($flashKey, 'Select an active listed product with available stock.');
    redirect($back);
}
if (!can_access_branch($phone['branch_id'] ?? null, $user)) {
    flash_set($flashKey, 'You cannot schedule a flash sale for another branch.');
    redirect($back);
}
if (($phone['branch_status'] ?? 'Inactive') !== 'Active') {
    flash_set($flashKey, 'Selected branch is inactive.');
    redirect($back);
}
if ($salePrice >= (float)$phone['selling_price']) {
    flash_set($flashKey, 'Flash sale price must be lower than the regular selling price.');
    redirect($back);
}

$scheduleStart = date('Y-m-d H:i:s', $startAtValue);
$scheduleEnd = date('Y-m-d H:i:s', $endAtValue);

$overlapStmt = $db->prepare(
    'SELECT COUNT(*)
     FROM flash_sales
     WHERE phone_id = ?
       AND is_active = 1
       AND NOT (ends_at < ? OR starts_at > ?)'
);
$overlapStmt->execute([$phoneId, $scheduleStart, $scheduleEnd]);
if ((int)$overlapStmt->fetchColumn() > 0) {
    flash_set($flashKey, 'There is already an overlapping flash sale for that product.');
    redirect($back);
}

$insert = $db->prepare(
    'INSERT INTO flash_sales (phone_id, branch_id, title, promo_label, sale_price, description, starts_at, ends_at, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insert->execute([
    $phoneId,
    (int)$phone['branch_id'],
    $title,
    $promoLabel !== '' ? $promoLabel : 'Flash Sale',
    $salePrice,
    $description !== '' ? $description : null,
    $scheduleStart,
    $scheduleEnd,
    $user['id'] ?? null,
]);

log_activity(
    $db,
    $user['name'],
    str_replace('RF Chein - ', '', $phone['branch_name'] ?? 'System'),
    'Scheduled flash sale for ' . $phone['brand'] . ' ' . $phone['model'] . ' at ' . peso($salePrice)
);

flash_set($flashKey, 'Flash sale scheduled for ' . $phone['brand'] . ' ' . $phone['model'] . '.');
redirect($back);