<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin', 'Supervisor']);

$user = current_user();
$back = is_executive_user($user) ? '../superadmin.php#devices' : '../dashboard.php';
$flashKey = is_executive_user($user) ? 'superadmin' : 'dashboard';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect($back); }

ensure_branch_assigned($user);

$brand          = trim($_POST['brand'] ?? '');
$model          = trim($_POST['model'] ?? '');
$storage        = $_POST['storage'] ?? '';
$ram            = $_POST['ram'] ?? null;
$color          = trim($_POST['color'] ?? '');
$condition      = $_POST['condition'] ?? 'Good';
$battery        = (int)($_POST['battery'] ?? 100);
$selling_price  = (float)($_POST['selling_price'] ?? 0);
$purchase_price = $_POST['purchase_price'] !== '' ? (float)$_POST['purchase_price'] : null;
$supplier       = trim($_POST['supplier'] ?? '');
$stock          = max(1, (int)($_POST['stock'] ?? 1));
$branch_id      = resolve_managed_branch_id($db, $_POST['branch_id'] ?? null, $user);
$imei           = trim($_POST['imei'] ?? '');
$serial         = trim($_POST['serial_number'] ?? '');
$accessories    = $_POST['accessories'] ?? 'Unit only';
$notes          = trim($_POST['notes'] ?? '');
$uploadedImage  = $_FILES['image_file'] ?? null;

if ($brand === '' || $model === '' || $selling_price <= 0 || $branch_id <= 0) {
    flash_set($flashKey, 'Brand, model, price, and branch are required.');
    redirect($back);
}
if (!in_array($condition, ['Excellent','Good','Fair','Poor'], true)) {
    $condition = 'Good';
}
if ($battery < 0) $battery = 0;
if ($battery > 100) $battery = 100;

$bstmt = $db->prepare("SELECT id, name, status FROM branches WHERE id = ?");
$bstmt->execute([$branch_id]);
$branch = $bstmt->fetch();

if (!$branch || !can_access_branch($branch_id, $user)) {
    flash_set($flashKey, 'You cannot add inventory to that branch.');
    redirect($back);
}
if (($branch['status'] ?? 'Inactive') !== 'Active') {
    flash_set($flashKey, 'Selected branch is inactive. Reactivate it before adding stock.');
    redirect($back);
}

$imagePath = null;
$savedImageAbsolutePath = null;

if ($uploadedImage && (int)($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if ((int)$uploadedImage['error'] !== UPLOAD_ERR_OK) {
        flash_set($flashKey, 'Product photo upload failed. Please try again.');
        redirect($back);
    }

    if ((int)($uploadedImage['size'] ?? 0) > 5 * 1024 * 1024) {
        flash_set($flashKey, 'Product photo must be 5MB or smaller.');
        redirect($back);
    }

    $mimeType = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($uploadedImage['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($uploadedImage['tmp_name']);
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])) {
        flash_set($flashKey, 'Product photo must be a JPG, PNG, GIF, or WEBP image.');
        redirect($back);
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'device-images';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        flash_set($flashKey, 'Could not create the product photo folder.');
        redirect($back);
    }

    try {
        $uniqueSuffix = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $uniqueSuffix = str_replace('.', '', uniqid('', true));
    }

    $fileName = 'device-' . date('YmdHis') . '-' . $uniqueSuffix . '.' . $allowedMimeTypes[$mimeType];
    $savedImageAbsolutePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($uploadedImage['tmp_name'], $savedImageAbsolutePath)) {
        flash_set($flashKey, 'Could not save the uploaded product photo.');
        redirect($back);
    }

    $imagePath = 'uploads/device-images/' . $fileName;
}

try {
    $stmt = $db->prepare("
        INSERT INTO phones (brand, model, storage, ram, color, `condition`, battery, selling_price, purchase_price, supplier, stock, branch_id, imei, serial_number, accessories, notes, image_url, is_listed, last_moved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([
        $brand, $model, $storage, $ram, $color ?: null, $condition, $battery,
        $selling_price, $purchase_price, $supplier ?: null, $stock, $branch_id,
        $imei ?: null, $serial ?: null, $accessories, $notes ?: null, $imagePath,
    ]);

    $phoneId = (int)$db->lastInsertId();
    $branchName = $branch['name'] ?? 'Unknown';

    log_activity($db, $user['name'], str_replace('RF Chein - ', '', $branchName), "Added device: $brand $model");
    log_inventory_event($db, [
        'phone_id' => $phoneId,
        'branch_id' => $branch_id,
        'user_id' => $user['id'],
        'event_type' => 'created',
        'quantity_change' => $stock,
        'stock_before' => 0,
        'stock_after' => $stock,
        'remarks' => 'Initial branch inventory entry for ' . $brand . ' ' . $model,
    ]);
} catch (Throwable $e) {
    if ($savedImageAbsolutePath && is_file($savedImageAbsolutePath)) {
        @unlink($savedImageAbsolutePath);
    }
    flash_set($flashKey, 'Could not add device: ' . $e->getMessage());
    redirect($back);
}

flash_set($flashKey, "$brand $model added to inventory.");
redirect($back);
