<?php
require_once __DIR__ . '/../includes/helpers.php';

$expectsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

$respond = static function (bool $ok, string $message, string $redirect, int $status = 200, array $payload = []) use ($expectsJson) {
    if ($expectsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    flash_set('public', $message);
    redirect($redirect);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($expectsJson) {
        $respond(false, 'Invalid inquiry request.', '../index.php', 405);
    }
    redirect('../index.php');
}

$phoneId = (int)($_POST['phone_id'] ?? 0);
$branchId = (int)($_POST['branch_id'] ?? 0);
$customerName = trim($_POST['customer_name'] ?? '');
$contactNumber = trim($_POST['contact_number'] ?? '');
$preferredChannel = $_POST['preferred_channel'] ?? 'Website';
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($customerName === '' || $contactNumber === '' || $message === '') {
    $respond(false, 'Please provide your name, contact number, and message.', '../index.php#phones', 422);
}
if (!in_array($preferredChannel, ['Phone', 'SMS', 'Call', 'Facebook', 'Email', 'Website'], true)) {
    $preferredChannel = 'Website';
}

$phone = null;
if ($phoneId > 0) {
    $phoneStmt = $db->prepare(
        'SELECT p.id, p.brand, p.model, p.branch_id, b.status AS branch_status
         FROM phones p
         LEFT JOIN branches b ON b.id = p.branch_id
         WHERE p.id = ?'
    );
    $phoneStmt->execute([$phoneId]);
    $phone = $phoneStmt->fetch();

    if (!$phone) {
        $respond(false, 'The selected product is no longer available for inquiry.', '../index.php#phones', 404);
    }

    if ($branchId <= 0) {
        $branchId = (int)($phone['branch_id'] ?? 0);
    }
    if ($subject === '') {
        $subject = 'Inquiry for ' . trim(($phone['brand'] ?? '') . ' ' . ($phone['model'] ?? ''));
    }
}

if ($branchId <= 0) {
    $respond(false, 'Please choose the branch that should receive your inquiry.', '../index.php#inquiries', 422);
}

if ($branchId > 0) {
    $branchStmt = $db->prepare('SELECT id, name, status FROM branches WHERE id = ?');
    $branchStmt->execute([$branchId]);
    $branch = $branchStmt->fetch();

    if (!$branch || ($branch['status'] ?? 'Inactive') !== 'Active') {
        $respond(false, 'Selected branch is not available right now.', '../index.php#phones', 422);
    }
}

$insert = $db->prepare(
    'INSERT INTO inquiries (phone_id, branch_id, customer_name, contact_number, preferred_channel, subject, latest_message, status, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, "New", NOW())'
);
$insert->execute([
    $phoneId > 0 ? $phoneId : null,
    $branchId > 0 ? $branchId : null,
    $customerName,
    $contactNumber,
    $preferredChannel,
    $subject !== '' ? $subject : 'General inquiry',
    $message,
]);

$inquiryId = (int)$db->lastInsertId();

$messageStmt = $db->prepare(
    'INSERT INTO inquiry_messages (inquiry_id, sender_type, sender_name, message) VALUES (?, "Customer", ?, ?)'
);
$messageStmt->execute([$inquiryId, $customerName, $message]);

$branchName = !empty($branch['name']) ? str_replace('RF Chein - ', '', (string)$branch['name']) : '';
$branchLabel = $branchName !== '' ? $branchName : 'the selected branch';
$respond(
    true,
    'Inquiry sent to ' . $branchLabel . '. The branch team will reply via ' . $preferredChannel . ' soon.',
    '../index.php#inquiries',
    200,
    [
        'inquiryId' => $inquiryId,
        'branchName' => $branchLabel,
        'preferredChannel' => $preferredChannel,
    ]
);