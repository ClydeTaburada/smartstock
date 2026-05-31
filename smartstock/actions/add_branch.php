<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('../superadmin.php'); }

$name    = trim($_POST['name'] ?? '');
$address = trim($_POST['address'] ?? '');

if ($name === '' || $address === '') {
    flash_set('superadmin', 'Please provide branch name and address.');
    redirect('../superadmin.php#branches');
}

$stmt = $db->prepare("INSERT INTO branches (name, address, status) VALUES (?, ?, 'Active')");
$stmt->execute([$name, $address]);

$u = current_user();
log_activity($db, $u['name'], 'System', 'Added branch: ' . $name);

flash_set('superadmin', 'Branch "' . $name . '" added successfully.');
redirect('../superadmin.php#branches');
