<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin', 'Admin', 'Supervisor', 'Staff']);

$user = current_user();
$flashKey = is_executive_user($user) ? 'superadmin' : 'dashboard';
$back = '../inquiries.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}

ensure_branch_assigned($user);

$inquiryId = (int)($_POST['inquiry_id'] ?? 0);
$status = $_POST['status'] ?? 'Contacted';
$reply = trim($_POST['reply'] ?? '');

if ($inquiryId <= 0) {
    flash_set($flashKey, 'Inquiry update is incomplete.');
    redirect($back);
}
if (!in_array($status, ['New', 'Contacted', 'Resolved', 'Closed'], true)) {
    $status = 'Contacted';
}

$stmt = $db->prepare(
    'SELECT i.*, b.name AS branch_name, p.brand, p.model
     FROM inquiries i
     LEFT JOIN branches b ON b.id = i.branch_id
     LEFT JOIN phones p ON p.id = i.phone_id
     WHERE i.id = ?'
);
$stmt->execute([$inquiryId]);
$inquiry = $stmt->fetch();

if (!$inquiry) {
    flash_set($flashKey, 'Inquiry not found.');
    redirect($back);
}
if (!empty($inquiry['branch_id']) && !can_access_branch($inquiry['branch_id'], $user)) {
    flash_set($flashKey, 'You cannot update that inquiry.');
    redirect($back);
}
if ($reply === '' && $status === ($inquiry['status'] ?? 'New')) {
    flash_set($flashKey, 'No inquiry changes were submitted.');
    redirect($back);
}

$db->beginTransaction();

try {
    if ($reply !== '') {
        $messageStmt = $db->prepare(
            'INSERT INTO inquiry_messages (inquiry_id, user_id, sender_type, sender_name, message) VALUES (?, ?, ?, ?, ?)'
        );
        $messageStmt->execute([$inquiryId, $user['id'] ?? null, role_label(user_role($user)), $user['name'], $reply]);
    }

    $now = date('Y-m-d H:i:s');
    $latestMessage = $reply !== '' ? $reply : (string)$inquiry['latest_message'];
    $resolvedAt = in_array($status, ['Resolved', 'Closed'], true) ? $now : null;

    $updateStmt = $db->prepare(
        'UPDATE inquiries
         SET status = ?,
             assigned_user_id = ?,
             latest_message = ?,
             updated_at = ?,
             resolved_at = ?
         WHERE id = ?'
    );
    $updateStmt->execute([$status, $user['id'] ?? null, $latestMessage, $now, $resolvedAt, $inquiryId]);

    log_activity(
        $db,
        $user['name'],
        str_replace('RF Chein - ', '', $inquiry['branch_name'] ?? 'System'),
        'Updated inquiry #' . $inquiryId . ' to ' . $status
    );

    $db->commit();
    flash_set($flashKey, 'Inquiry updated.');
} catch (Throwable $e) {
    $db->rollBack();
    flash_set($flashKey, 'Could not update inquiry: ' . $e->getMessage());
}

redirect($back);