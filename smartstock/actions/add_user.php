<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('../superadmin.php'); }

$name      = trim($_POST['name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$phone     = trim($_POST['phone'] ?? '');
$username  = trim($_POST['username'] ?? '');
$password  = (string)($_POST['password'] ?? '');
$role      = normalize_role_name($_POST['role'] ?? 'Staff');
$branch_id = $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : null;

if ($name === '' || $email === '' || $username === '' || strlen($password) < 6) {
    flash_set('superadmin', 'Please fill all fields. Password must be at least 6 characters.');
    redirect('../superadmin.php#users');
}
if (!in_array($role, ['Admin','Supervisor','Staff'], true)) {
    flash_set('superadmin', 'Invalid role.');
    redirect('../superadmin.php#users');
}
if (in_array($role, ['Supervisor', 'Staff'], true) && !$branch_id) {
    flash_set('superadmin', ucfirst(strtolower($role)) . ' must be assigned to a branch.');
    redirect('../superadmin.php#users');
}
if ($role === 'Supervisor' && $branch_id && $phone === '') {
    flash_set('superadmin', 'Contact number is required when creating a Supervisor.');
    redirect('../superadmin.php#users');
}

// duplicate checks
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
$stmt->execute([$email, $username]);
if ($stmt->fetchColumn() > 0) {
    flash_set('superadmin', 'A user with that email or username already exists.');
    redirect('../superadmin.php#users');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$ins = $db->prepare("INSERT INTO users (name, email, phone, username, password, role, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$ins->execute([$name, $email, $phone ?: null, $username, $hash, $role, $branch_id]);

if ($role === 'Supervisor' && $branch_id) {
    $branchStmt = $db->prepare("UPDATE branches SET manager = ?, phone = ?, email = ? WHERE id = ?");
    $branchStmt->execute([$name, $phone ?: null, $email, $branch_id]);
}

$u = current_user();
log_activity($db, $u['name'], 'System', 'Created user: ' . $name . ' (' . $role . ')');

flash_set('superadmin', 'User "' . $name . '" created as ' . $role . '.');
redirect('../superadmin.php#users');
