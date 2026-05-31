<?php
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    flash_set('public', 'Please provide your name, contact number, and message.');
    redirect('../index.php#phones');
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
        flash_set('public', 'The selected product is no longer available for inquiry.');
        redirect('../index.php#phones');
    }

    if ($branchId <= 0) {
        $branchId = (int)($phone['branch_id'] ?? 0);
    }
    if ($subject === '') {
        $subject = 'Inquiry for ' . trim(($phone['brand'] ?? '') . ' ' . ($phone['model'] ?? ''));
    }
}

if ($branchId <= 0) {
    flash_set('public', 'Please choose the branch that should receive your inquiry.');
    redirect('../index.php#inquiries');
}

if ($branchId > 0) {
    $branchStmt = $db->prepare('SELECT id, status FROM branches WHERE id = ?');
    $branchStmt->execute([$branchId]);
    $branch = $branchStmt->fetch();

    if (!$branch || ($branch['status'] ?? 'Inactive') !== 'Active') {
        flash_set('public', 'Selected branch is not available right now.');
        redirect('../index.php#phones');
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

flash_set('public', 'Inquiry sent. A branch representative will get back to you soon.');
redirect('../index.php#inquiries');