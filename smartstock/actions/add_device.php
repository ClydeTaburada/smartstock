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
$series         = trim($_POST['series'] ?? '');
$storage        = $_POST['storage'] ?? '';
$ram            = $_POST['ram'] ?? null;
$color          = trim($_POST['color'] ?? '');
$operatingSystem = trim($_POST['operating_system'] ?? '');
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
$uploadedBackImage = $_FILES['back_image_file'] ?? null;

if ($brand === '' || $model === '' || $selling_price <= 0 || $branch_id <= 0) {
    flash_set($flashKey, 'Brand, model, price, and branch are required.');
    redirect($back);
}
if (!in_array($condition, ['Excellent','Good','Fair','Poor'], true)) {
    $condition = 'Good';
}
if ($battery < 0) $battery = 0;
if ($battery > 100) $battery = 100;
if ($series === '') {
    $series = trim((string)strtok($model, ' '));
}
if ($operatingSystem === '') {
    $operatingSystem = strcasecmp($brand, 'Apple') === 0 ? 'iOS' : 'Android';
}

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
$backImagePath = null;
$savedBackImageAbsolutePath = null;

$storeUploadedImage = static function (?array $uploadedFile, string $prefix): array {
    if (!$uploadedFile || (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ((int)$uploadedFile['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }
    if ((int)($uploadedFile['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Uploaded image must be 5MB or smaller.');
    }

    $mimeType = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($uploadedFile['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($uploadedFile['tmp_name']);
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])) {
        throw new RuntimeException('Images must be JPG, PNG, GIF, or WEBP files.');
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'device-images';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create the device image folder.');
    }

    try {
        $uniqueSuffix = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $uniqueSuffix = str_replace('.', '', uniqid('', true));
    }

    $fileName = $prefix . '-' . date('YmdHis') . '-' . $uniqueSuffix . '.' . $allowedMimeTypes[$mimeType];
    $absolutePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return ['uploads/device-images/' . $fileName, $absolutePath];
};

try {
    [$imagePath, $savedImageAbsolutePath] = $storeUploadedImage($uploadedImage, 'device-front');
    [$backImagePath, $savedBackImageAbsolutePath] = $storeUploadedImage($uploadedBackImage, 'device-back');
} catch (RuntimeException $e) {
    if ($savedImageAbsolutePath && is_file($savedImageAbsolutePath)) {
        @unlink($savedImageAbsolutePath);
    }
    if ($savedBackImageAbsolutePath && is_file($savedBackImageAbsolutePath)) {
        @unlink($savedBackImageAbsolutePath);
    }
    flash_set($flashKey, $e->getMessage());
    redirect($back);
}

try {
    $stmt = $db->prepare("
        INSERT INTO phones (brand, model, series, storage, ram, color, operating_system, `condition`, battery, selling_price, purchase_price, supplier, stock, branch_id, imei, serial_number, accessories, notes, image_url, back_image_url, is_listed, last_moved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([
        $brand, $model, $series ?: null, $storage, $ram, $color ?: null, $operatingSystem ?: null,
        $condition, $battery, $selling_price, $purchase_price, $supplier ?: null, $stock, $branch_id,
        $imei ?: null, $serial ?: null, $accessories, $notes ?: null, $imagePath, $backImagePath,
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
    if ($savedBackImageAbsolutePath && is_file($savedBackImageAbsolutePath)) {
        @unlink($savedBackImageAbsolutePath);
    }
    flash_set($flashKey, 'Could not add device: ' . $e->getMessage());
    redirect($back);
}

flash_set($flashKey, "$brand $model added to inventory.");
redirect($back);
