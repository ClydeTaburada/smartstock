<?php
require_once __DIR__ . '/includes/helpers.php';
require_role(['Super Admin', 'Admin', 'Supervisor']);

$user = current_user();
ensure_branch_assigned($user);
$canSelectBranch = is_super_admin($user) || is_admin_user($user);

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
if ($canSelectBranch) {
    $selectedBranchId = $requestedBranchId > 0 && isset($branchMap[$requestedBranchId]) ? $requestedBranchId : 0;
} else {
    $selectedBranchId = current_branch_id($user) ?? 0;
}

$selectedBranchName = $selectedBranchId > 0 && isset($branchMap[$selectedBranchId])
    ? str_replace('RF Chein - ', '', (string)$branchMap[$selectedBranchId]['name'])
    : 'All branches';

$branchFilterSql = $selectedBranchId > 0 ? ' AND p.branch_id = ?' : '';
$branchFilterParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$flashScopeSql = $selectedBranchId > 0 ? ' WHERE fs.branch_id = ?' : '';
$flashScopeParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];

$flashSaleOptions = $fetchAllRows(
    $db,
    'SELECT p.id, p.brand, p.model, p.stock, p.selling_price, p.image_url, p.color, b.name AS branch_name
     FROM phones p
     LEFT JOIN branches b ON b.id = p.branch_id
     WHERE p.is_listed = 1 AND p.stock > 0' . $branchFilterSql . '
     ORDER BY b.name, p.brand, p.model',
    $branchFilterParams
);

$flashSales = $fetchAllRows(
    $db,
    'SELECT fs.*, p.brand, p.model, p.image_url, p.color, p.selling_price AS regular_price, p.stock, b.name AS branch_name,
            CASE
                WHEN fs.is_active = 0 THEN "Disabled"
                WHEN NOW() < fs.starts_at THEN "Scheduled"
                WHEN NOW() > fs.ends_at THEN "Expired"
                ELSE "Live"
            END AS lifecycle
     FROM flash_sales fs
     INNER JOIN phones p ON p.id = fs.phone_id
     LEFT JOIN branches b ON b.id = fs.branch_id' . $flashScopeSql . '
     ORDER BY fs.is_active DESC, fs.ends_at DESC, fs.id DESC',
    $flashScopeParams
);

$flashStats = ['Live' => 0, 'Scheduled' => 0, 'Expired' => 0, 'Disabled' => 0];
foreach ($flashSales as $flashSale) {
    $flashStats[$flashSale['lifecycle']] = ($flashStats[$flashSale['lifecycle']] ?? 0) + 1;
}

$flash = flash_get($canSelectBranch ? 'superadmin' : 'dashboard');
$backHref = $canSelectBranch ? 'superadmin.php' : 'dashboard.php';
$defaultStart = date('Y-m-d\TH:i');
$defaultEnd = date('Y-m-d\TH:i', strtotime('+2 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartStock · Flash Sales</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
  :root{--bg:#ebeae4;--card:rgba(255,255,255,.92);--text:#111827;--muted:#5b6057;--border:#e3e2da;--accent:#ea580c;--accent-2:#c2410c;--good:#0f9d58;--warn:#d97706;--bad:#dc2626;--display:'Sora',sans-serif;--body:'Plus Jakarta Sans',sans-serif}
  *{box-sizing:border-box} body{margin:0;font-family:var(--body);background:radial-gradient(circle at top left,#ffe7d1 0,#f7f4ec 30%,#ebeae4 100%);color:var(--text)}
  .wrap{max-width:1220px;margin:0 auto;padding:28px 22px 48px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px}
  .eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:800}.title{font-size:34px;font-weight:700;font-family:var(--display);margin:6px 0}.sub{color:var(--muted);max-width:720px;line-height:1.6}
  .back{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.78);color:var(--text);text-decoration:none;font-size:14px;backdrop-filter:blur(16px)}
  .flash{margin:0 0 18px;padding:12px 14px;background:#e9fff3;border:1px solid #bde6cc;color:#0f6e56;border-radius:12px}
  .filters{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px}.filters form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  select,input,textarea{width:100%;padding:11px 12px;border:1px solid var(--border);border-radius:12px;font:inherit;background:#fff;color:var(--text)} textarea{min-height:100px;resize:vertical}
  .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;cursor:pointer;font:inherit;color:var(--text)} .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
  .btn-primary:hover{background:var(--accent-2)} .btn-danger{background:#fff5f5;color:var(--bad);border-color:#fecaca}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.stat{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:0 24px 60px -46px rgba(15,23,42,.22)}.stat .n{font-size:28px;font-weight:800}.stat .l{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
  .grid{display:grid;grid-template-columns:minmax(0,380px) minmax(0,1fr);gap:18px;align-items:start}.grid>.card{min-width:0}.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:18px;box-shadow:0 24px 60px -46px rgba(15,23,42,.22)}.card h2{margin:0 0 14px;font-size:18px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.grid2>div{min-width:0}.grid2-datetime{grid-template-columns:1fr}input[type="datetime-local"]{min-width:0}
  table{width:100%;border-collapse:collapse} th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #edf1f6;font-size:14px;vertical-align:top} th{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
  .pill{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}.live{background:#dcfce7;color:#166534}.scheduled{background:#fff7ed;color:#9a3412}.expired{background:#eef2ff;color:#4338ca}.disabled{background:#f3f4f6;color:#4b5563}
  .money-old{color:var(--muted);text-decoration:line-through;font-size:12px}.money-new{font-weight:800;color:var(--accent)} .meta{font-size:12px;color:var(--muted)}
  .product-cell{display:flex;align-items:center;gap:12px}.product-thumb{width:52px;height:52px;border-radius:14px;object-fit:cover;border:1px solid var(--border);background:#fff7ed;flex-shrink:0}.product-thumb.placeholder{display:inline-flex;align-items:center;justify-content:center;color:var(--muted);font-size:18px}
  .table-wrap{width:100%;overflow-x:auto}.table-wrap table{min-width:680px}
  .empty{padding:26px 10px;color:var(--muted);text-align:center}.tiny{font-size:12px;color:var(--muted)}
  @media (max-width:1120px){.grid{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}}
  @media (max-width:640px){.stats{grid-template-columns:1fr}.grid2{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="system-polish.css?v=1">
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <div class="eyebrow">Promotions</div>
      <div class="title">Flash Sale Management</div>
      <div class="sub">Schedule branch-specific product promos, keep discount windows controlled, and expose live flash pricing on the public catalog.</div>
    </div>
    <a class="back" href="<?= e($backHref) ?>"><i class="ti ti-arrow-left"></i> Back to workspace</a>
  </div>

  <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

  <div class="filters">
    <div class="tiny">Viewing: <strong><?= e($selectedBranchName) ?></strong></div>
    <?php if ($canSelectBranch): ?>
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
    <div class="stat"><div class="l">Live now</div><div class="n"><?= (int)$flashStats['Live'] ?></div></div>
    <div class="stat"><div class="l">Scheduled</div><div class="n"><?= (int)$flashStats['Scheduled'] ?></div></div>
    <div class="stat"><div class="l">Expired</div><div class="n"><?= (int)$flashStats['Expired'] ?></div></div>
    <div class="stat"><div class="l">Disabled</div><div class="n"><?= (int)$flashStats['Disabled'] ?></div></div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Create flash sale</h2>
      <form method="post" action="actions/create_flash_sale.php">
        <div style="margin-bottom:12px">
          <label class="tiny">Title</label>
          <input type="text" name="title" required placeholder="e.g. Weekend branch push">
        </div>
        <div style="margin-bottom:12px">
          <label class="tiny">Product</label>
          <select name="phone_id" required>
            <option value="">Select product</option>
            <?php foreach ($flashSaleOptions as $option): ?>
              <option value="<?= (int)$option['id'] ?>"><?= e($option['brand'] . ' ' . $option['model']) ?> · <?= e(str_replace('RF Chein - ', '', $option['branch_name'] ?? '—')) ?> · <?= e(peso($option['selling_price'])) ?> · <?= (int)$option['stock'] ?> in stock</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid2">
          <div>
            <label class="tiny">Promo label</label>
            <input type="text" name="promo_label" value="Flash Sale" placeholder="Flash Sale">
          </div>
          <div>
            <label class="tiny">Sale price</label>
            <input type="number" name="sale_price" min="0" step="0.01" required placeholder="e.g. 6799">
          </div>
        </div>
        <div class="grid2 grid2-datetime" style="margin-top:12px">
          <div>
            <label class="tiny">Starts</label>
            <input type="datetime-local" name="starts_at" required value="<?= e($defaultStart) ?>">
          </div>
          <div>
            <label class="tiny">Ends</label>
            <input type="datetime-local" name="ends_at" required value="<?= e($defaultEnd) ?>">
          </div>
        </div>
        <div style="margin-top:12px">
          <label class="tiny">Description</label>
          <textarea name="description" placeholder="Why this item is on promo and what the branch is targeting."></textarea>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:flex-end">
          <button class="btn btn-primary" type="submit"><i class="ti ti-bolt"></i> Save flash sale</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Flash sale board</h2>
      <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Product</th><th>Window</th><th>Price</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($flashSales as $flashSale): ?>
            <tr>
              <td>
                <div class="product-cell">
                  <?php if (!empty($flashSale['image_url'])): ?>
                    <img src="<?= e($flashSale['image_url']) ?>" alt="<?= e($flashSale['brand'] . ' ' . $flashSale['model']) ?>" class="product-thumb">
                  <?php else: ?>
                    <span class="product-thumb placeholder"><i class="ti ti-bolt"></i></span>
                  <?php endif; ?>
                  <div>
                    <strong><?= e($flashSale['brand'] . ' ' . $flashSale['model']) ?></strong>
                    <div class="meta"><?= e(str_replace('RF Chein - ', '', $flashSale['branch_name'] ?? '—')) ?><?= !empty($flashSale['color']) ? ' · ' . e($flashSale['color']) : '' ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div><?= e(date('M d, Y h:i A', strtotime($flashSale['starts_at']))) ?></div>
                <div class="meta">until <?= e(date('M d, Y h:i A', strtotime($flashSale['ends_at']))) ?></div>
              </td>
              <td>
                <div class="money-new"><?= e(peso($flashSale['sale_price'])) ?></div>
                <div class="money-old"><?= e(peso($flashSale['regular_price'])) ?></div>
              </td>
              <td>
                <?php $class = strtolower($flashSale['lifecycle']); ?>
                <span class="pill <?= e($class) ?>"><?= e($flashSale['lifecycle']) ?></span>
              </td>
              <td>
                <?php if ((int)$flashSale['is_active'] === 1): ?>
                  <form method="post" action="actions/toggle_flash_sale.php">
                    <input type="hidden" name="id" value="<?= (int)$flashSale['id'] ?>">
                    <input type="hidden" name="action" value="disable">
                    <button class="btn btn-danger" type="submit"><i class="ti ti-player-stop"></i> Disable</button>
                  </form>
                <?php elseif (strtotime($flashSale['ends_at']) > time()): ?>
                  <form method="post" action="actions/toggle_flash_sale.php">
                    <input type="hidden" name="id" value="<?= (int)$flashSale['id'] ?>">
                    <input type="hidden" name="action" value="activate">
                    <button class="btn" type="submit"><i class="ti ti-player-play"></i> Reactivate</button>
                  </form>
                <?php else: ?>
                  <span class="tiny">No action</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$flashSales): ?><tr><td colspan="5" class="empty">No flash sales recorded for this scope yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>