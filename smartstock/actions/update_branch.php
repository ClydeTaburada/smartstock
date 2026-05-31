<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../superadmin.php#branches');
}

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$address = trim($_POST['address'] ?? '');
$status = $_POST['status'] ?? 'Active';

if ($id <= 0 || $name === '' || $address === '') {
    flash_set('superadmin', 'Branch name and address are required.');
    redirect('../superadmin.php#branches');
}

if (!in_array($status, ['Active', 'Inactive'], true)) {
    $status = 'Active';
}

$stmt = $db->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$id]);
$branch = $stmt->fetch();

if (!$branch) {
    flash_set('superadmin', 'Branch not found.');
    redirect('../superadmin.php#branches');
}

$update = $db->prepare('UPDATE branches SET name = ?, address = ?, status = ? WHERE id = ?');
$update->execute([
    $name,
    $address,
    $status,
    $id,
]);

$changes = [];
if ($branch['name'] !== $name) {
    $changes[] = 'renamed to ' . $name;
}
if (($branch['status'] ?? 'Active') !== $status) {
    $changes[] = 'status set to ' . $status;
}
if (($branch['address'] ?? '') !== $address) {
    $changes[] = 'address updated';
}

$me = current_user();
$action = 'Updated branch: ' . $name;
if ($changes) {
    $action .= ' (' . implode(', ', $changes) . ')';
}
log_activity($db, $me['name'], 'System', $action);

flash_set('superadmin', 'Branch "' . $name . '" updated.');
redirect('../superadmin.php#branches');