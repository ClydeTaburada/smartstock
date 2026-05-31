<?php
require_once __DIR__ . '/../includes/helpers.php';
require_role(['Super Admin']);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { redirect('../superadmin.php#branches'); }

$stmt = $db->prepare("SELECT id, name, status FROM branches WHERE id = ?");
$stmt->execute([$id]);
$branch = $stmt->fetch();

if ($branch) {
    $counts = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM users WHERE branch_id = ?) AS users_count,
            (SELECT COUNT(*) FROM phones WHERE branch_id = ?) AS phones_count,
            (SELECT COUNT(*) FROM sales WHERE branch_id = ?) AS sales_count,
            (SELECT COUNT(*) FROM stock_transfers WHERE source_branch_id = ? OR destination_branch_id = ?) AS transfer_count"
    );
    $counts->execute([$id, $id, $id, $id, $id]);
    $usage = $counts->fetch();

    if (($branch['status'] ?? 'Active') === 'Active') {
        flash_set('superadmin', 'Set the branch to Inactive before deleting it.');
        redirect('../superadmin.php#branches');
    }

    if ((int)$usage['users_count'] > 0 || (int)$usage['phones_count'] > 0 || (int)$usage['sales_count'] > 0 || (int)$usage['transfer_count'] > 0) {
        flash_set('superadmin', 'Cannot delete a branch that still has users, inventory, sales, or transfer history.');
        redirect('../superadmin.php#branches');
    }

    $db->prepare("DELETE FROM branches WHERE id = ?")->execute([$id]);
    $u = current_user();
    log_activity($db, $u['name'], 'System', 'Removed branch: ' . $branch['name']);
    flash_set('superadmin', 'Branch "' . $branch['name'] . '" removed.');
}

redirect('../superadmin.php#branches');
