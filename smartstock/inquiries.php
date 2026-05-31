<?php
require_once __DIR__ . '/includes/helpers.php';
require_login();

$user = current_user();
ensure_branch_assigned($user);

$fetchAllRows = static function (PDO $db, string $sql, array $params = []) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
};

$allowedBranches = allowed_branches($db, $user);
$branchMap = [];
foreach ($allowedBranches as $branchRow) {
    $branchMap[(int)$branchRow['id']] = $branchRow;
}

$requestedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
if (is_super_admin($user)) {
    $selectedBranchId = $requestedBranchId > 0 && isset($branchMap[$requestedBranchId]) ? $requestedBranchId : 0;
} else {
    $selectedBranchId = current_branch_id($user) ?? 0;
}

$selectedBranchName = $selectedBranchId > 0 && isset($branchMap[$selectedBranchId])
    ? str_replace('RF Chein - ', '', (string)$branchMap[$selectedBranchId]['name'])
    : 'All branches';
$canReply = in_array($user['role'], ['Super Admin', 'Branch Admin', 'Staff'], true);

$scopeSql = $selectedBranchId > 0 ? ' WHERE i.branch_id = ?' : '';
$scopeParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];

$inquiries = $fetchAllRows(
    $db,
    'SELECT i.*, b.name AS branch_name, CONCAT(p.brand, " ", p.model) AS product_name,
            COUNT(im.id) AS message_count,
            MAX(im.created_at) AS last_message_at
     FROM inquiries i
     LEFT JOIN branches b ON b.id = i.branch_id
     LEFT JOIN phones p ON p.id = i.phone_id
     LEFT JOIN inquiry_messages im ON im.inquiry_id = i.id' . $scopeSql . '
     GROUP BY i.id
     ORDER BY FIELD(i.status, "New", "Contacted", "Resolved", "Closed"), COALESCE(i.updated_at, i.created_at) DESC, i.id DESC',
    $scopeParams
);

$messageRows = $fetchAllRows(
    $db,
    'SELECT im.*, i.branch_id
     FROM inquiry_messages im
     INNER JOIN inquiries i ON i.id = im.inquiry_id' . $scopeSql . '
     ORDER BY im.created_at DESC, im.id DESC',
    $scopeParams
);

$messagesByInquiry = [];
foreach ($messageRows as $messageRow) {
    $messagesByInquiry[(int)$messageRow['inquiry_id']][] = $messageRow;
}

$stats = ['New' => 0, 'Contacted' => 0, 'Resolved' => 0, 'Closed' => 0];
foreach ($inquiries as $inquiry) {
    $stats[$inquiry['status']] = ($stats[$inquiry['status']] ?? 0) + 1;
}

$flash = flash_get(is_super_admin($user) ? 'superadmin' : 'dashboard');
$backHref = is_super_admin($user) ? 'superadmin.php' : 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartStock · Inquiries</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
  :root{--bg:#f5f7fb;--card:#fff;--text:#111827;--muted:#6b7280;--border:#dbe3ef;--accent:#2563eb;--good:#0f9d58;--warn:#d97706;--bad:#dc2626}
  *{box-sizing:border-box} body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(180deg,#eff6ff 0,#f5f7fb 26%);color:var(--text)}
  .wrap{max-width:1240px;margin:0 auto;padding:28px 22px 48px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px}
  .eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:700}.title{font-size:34px;font-weight:800;margin:6px 0}.sub{color:var(--muted);max-width:760px;line-height:1.6}
  .back{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.8);color:var(--text);text-decoration:none;font-size:14px}
  .flash{margin:0 0 18px;padding:12px 14px;background:#e9fff3;border:1px solid #bde6cc;color:#0f6e56;border-radius:12px}
  .toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}.toolbar form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  select,input,textarea{width:100%;padding:11px 12px;border:1px solid var(--border);border-radius:12px;font:inherit;background:#fff;color:var(--text)} textarea{min-height:94px;resize:vertical}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.stat{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:16px}.stat .n{font-size:28px;font-weight:800}.stat .l{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
  .stack{display:grid;gap:16px}.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:18px}.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}.meta{font-size:13px;color:var(--muted);line-height:1.6}
  .pill{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}.new{background:#dbeafe;color:#1d4ed8}.contacted{background:#fff7ed;color:#9a3412}.resolved{background:#dcfce7;color:#166534}.closed{background:#f3f4f6;color:#4b5563}
  .thread{display:grid;gap:10px;margin-top:14px}.message{border:1px solid #edf1f6;border-radius:14px;padding:12px 14px;background:#fbfdff}.message.staff{border-color:#dbeafe;background:#eff6ff}.message-meta{font-size:12px;color:var(--muted);margin-bottom:4px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
  .update-form{margin-top:14px;padding-top:14px;border-top:1px solid #edf1f6}.grid2{display:grid;grid-template-columns:160px 1fr;gap:12px;align-items:start}.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;cursor:pointer;font:inherit;color:var(--text)} .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
  .tiny{font-size:12px;color:var(--muted)} .empty{padding:26px;color:var(--muted);text-align:center;background:#fff;border:1px dashed var(--border);border-radius:18px}
  @media (max-width:980px){.stats{grid-template-columns:repeat(2,1fr)}.grid2{grid-template-columns:1fr}}
  @media (max-width:640px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <div class="eyebrow">Customer support</div>
      <div class="title">Inquiry Inbox</div>
      <div class="sub">Track public catalog inquiries by branch, review conversation history, and reply from the assigned branch team without leaving SmartStock.</div>
    </div>
    <a class="back" href="<?= e($backHref) ?>"><i class="ti ti-arrow-left"></i> Back to workspace</a>
  </div>

  <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

  <div class="toolbar">
    <div class="tiny">Viewing: <strong><?= e($selectedBranchName) ?></strong></div>
    <?php if (is_super_admin($user)): ?>
      <form method="get">
        <select name="branch_id" onchange="this.form.submit()">
          <option value="0">All branches</option>
          <?php foreach ($allowedBranches as $branchRow): ?>
            <option value="<?= (int)$branchRow['id'] ?>" <?= $selectedBranchId === (int)$branchRow['id'] ? 'selected' : '' ?>><?= e(str_replace('RF Chein - ', '', $branchRow['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <div class="stats">
    <div class="stat"><div class="l">New</div><div class="n"><?= (int)$stats['New'] ?></div></div>
    <div class="stat"><div class="l">Contacted</div><div class="n"><?= (int)$stats['Contacted'] ?></div></div>
    <div class="stat"><div class="l">Resolved</div><div class="n"><?= (int)$stats['Resolved'] ?></div></div>
    <div class="stat"><div class="l">Closed</div><div class="n"><?= (int)$stats['Closed'] ?></div></div>
  </div>

  <div class="stack">
    <?php foreach ($inquiries as $inquiry): ?>
      <?php
        $thread = array_reverse(array_slice($messagesByInquiry[(int)$inquiry['id']] ?? [], 0, 3));
        $statusClass = strtolower($inquiry['status']);
      ?>
      <div class="card">
        <div class="head">
          <div>
            <div style="font-size:18px;font-weight:800"><?= e($inquiry['subject'] ?: 'General inquiry') ?></div>
            <div class="meta">
              Customer: <strong><?= e($inquiry['customer_name']) ?></strong> · <?= e($inquiry['contact_number']) ?> · <?= e($inquiry['preferred_channel']) ?><br>
              Branch: <?= e(str_replace('RF Chein - ', '', $inquiry['branch_name'] ?: 'General')) ?> · Product: <?= e($inquiry['product_name'] ?: 'General inquiry') ?> · Messages: <?= (int)$inquiry['message_count'] ?>
            </div>
          </div>
          <div style="text-align:right">
            <span class="pill <?= e($statusClass) ?>"><?= e($inquiry['status']) ?></span>
            <div class="tiny" style="margin-top:8px">Opened <?= e(date('M d, Y h:i A', strtotime($inquiry['created_at']))) ?></div>
          </div>
        </div>

        <div class="thread">
          <?php foreach ($thread as $message): ?>
            <div class="message <?= strtolower($message['sender_type']) === 'staff' ? 'staff' : '' ?>">
              <div class="message-meta">
                <span><?= e($message['sender_name']) ?> · <?= e($message['sender_type']) ?></span>
                <span><?= e(date('M d, h:i A', strtotime($message['created_at']))) ?></span>
              </div>
              <div><?= nl2br(e($message['message'])) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (!$thread): ?><div class="tiny">No conversation history stored yet.</div><?php endif; ?>
        </div>

        <?php if ($canReply): ?>
          <form class="update-form" method="post" action="actions/update_inquiry.php">
            <input type="hidden" name="inquiry_id" value="<?= (int)$inquiry['id'] ?>">
            <div class="grid2">
              <div>
                <label class="tiny">Status</label>
                <select name="status">
                  <?php foreach (['New', 'Contacted', 'Resolved', 'Closed'] as $statusOption): ?>
                    <option value="<?= e($statusOption) ?>" <?= $inquiry['status'] === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="tiny">Reply</label>
                <textarea name="reply" placeholder="Send a follow-up message or update the status only."></textarea>
              </div>
            </div>
            <div style="margin-top:12px;display:flex;justify-content:flex-end">
              <button class="btn btn-primary" type="submit"><i class="ti ti-send"></i> Update inquiry</button>
            </div>
          </form>
        <?php else: ?>
          <div class="update-form tiny">Your role can review inquiry history but cannot send replies.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$inquiries): ?><div class="empty">No inquiries found for the current branch scope.</div><?php endif; ?>
  </div>
</div>
</body>
</html>