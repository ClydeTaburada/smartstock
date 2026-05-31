<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin']);

$id = (int)($_POST['id'] ?? 0);
$me = current_user();
if ($id <= 0 || $id == $me['id']) {
    flash_set('superadmin', 'Cannot delete that user.');
    redirect('../superadmin.php#users');
}

$stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$id]);
$name = $stmt->fetchColumn();

if ($name) {
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    log_activity($db, $me['name'], 'System', 'Deleted user: ' . $name);
    flash_set('superadmin', 'User "' . $name . '" removed.');
}

redirect('../superadmin.php#users');
