<?php
require_once __DIR__ . '/includes/helpers.php';
require_role(['Super Admin', 'Admin']);

$branchRows = $db->query(
    'SELECT b.id, b.name, b.status, b.manager,
            COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = "Completed" AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)), 0) AS revenue_7d,
            (SELECT COUNT(*) FROM sales s WHERE s.branch_id = b.id AND s.status = "Completed" AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS sales_7d,
            COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = "Completed" AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS revenue_30d,
            (SELECT COUNT(*) FROM sales s WHERE s.branch_id = b.id AND s.status = "Completed" AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS sales_30d,
            COALESCE((SELECT SUM(stock) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1), 0) AS units_count,
            (SELECT COUNT(*) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1) AS products_count,
            (SELECT COUNT(*) FROM flash_sales fs WHERE fs.branch_id = b.id AND fs.is_active = 1 AND fs.starts_at <= NOW() AND fs.ends_at >= NOW()) AS live_flash_sales,
            (SELECT COUNT(*) FROM inquiries i WHERE i.branch_id = b.id AND i.status IN ("New", "Contacted")) AS open_inquiries
     FROM branches b
     ORDER BY b.name'
)->fetchAll();

$branchCount = max(1, count($branchRows));
$avgRevenue7d = array_sum(array_column($branchRows, 'revenue_7d')) / $branchCount;
$avgRevenue30d = array_sum(array_column($branchRows, 'revenue_30d')) / $branchCount;
$avgSales30d = array_sum(array_column($branchRows, 'sales_30d')) / $branchCount;

$watchlist = [];
foreach ($branchRows as &$branchRow) {
    $score = 100;
    $flags = [];

    if ((float)$branchRow['revenue_30d'] < $avgRevenue30d * 0.6) {
        $score -= 30;
        $flags[] = '30-day revenue is below 60% of the network average.';
    }
    if ((int)$branchRow['sales_7d'] === 0) {
        $score -= 30;
        $flags[] = 'No completed sales were recorded in the last 7 days.';
    } elseif ((float)$branchRow['revenue_7d'] < $avgRevenue7d * 0.5) {
        $score -= 15;
        $flags[] = '7-day revenue is below 50% of the network average.';
    }
    if ((int)$branchRow['units_count'] > 10 && (int)$branchRow['sales_30d'] <= 2) {
        $score -= 15;
        $flags[] = 'Inventory is high relative to 30-day sales volume.';
    }
    if ((int)$branchRow['live_flash_sales'] === 0 && (float)$branchRow['sales_30d'] < $avgSales30d * 0.7) {
        $score -= 10;
        $flags[] = 'No active flash sale is supporting a slow-sales branch.';
    }

    $score = max(0, $score);
    $branchRow['health_score'] = $score;
    $branchRow['flags'] = $flags;
    $branchRow['risk_level'] = $score <= 45 ? 'Critical' : ($score <= 70 ? 'Watch' : 'Sufficient');
    $branchRow['recommended_action'] = $score <= 45
        ? 'Launch a promo, rotate stock, and review branch demand immediately.'
        : ($score <= 70
            ? 'Add a flash sale or transfer hot items into this branch.'
            : 'Maintain current branch mix and monitor the next weekly cycle.');

    if ($branchRow['risk_level'] !== 'Sufficient') {
        $watchlist[] = $branchRow;
    }
}
unset($branchRow);

  usort($branchRows, static function (array $a, array $b): int {
    return [(float)$b['revenue_30d'], (int)$b['sales_30d'], (string)$a['name']] <=> [(float)$a['revenue_30d'], (int)$a['sales_30d'], (string)$b['name']];
  });
  foreach ($branchRows as $index => &$branchRow) {
    $branchRow['rank'] = $index + 1;
  }
  unset($branchRow);

usort($watchlist, static function (array $a, array $b): int {
    return $a['health_score'] <=> $b['health_score'];
});

$formulaRows = [
    ['metric' => 'Low-sales branch score', 'rule' => 'Starts at 100, then subtracts points for weak 30-day revenue, no 7-day sales, too much stock for too little turnover, and no live promo support.'],
    ['metric' => 'Reorder recommendation', 'rule' => 'Triggered when stock is 3 or below and the product recorded at least one sale in the last 30 days.'],
    ['metric' => 'Dead stock', 'rule' => 'Flagged when an item still has stock on hand but has no completed sale in the last 30 days.'],
    ['metric' => 'ABC classification', 'rule' => 'Products are ranked by revenue, then grouped into top 70%, next 20%, and bottom 10% contribution bands.'],
    ['metric' => 'Sell-through rate', 'rule' => 'Computed as sold units divided by sold plus remaining units, then multiplied by 100.'],
    ['metric' => 'Flash sale support flag', 'rule' => 'Slow branches with no live flash sale lose additional score because they have no active promotional push.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartStock · Branch Health Insights</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
  :root{--bg:#ebeae4;--card:rgba(255,255,255,.92);--text:#111827;--muted:#5b6057;--border:#e3e2da;--accent:#0f766e;--good:#0f9d58;--warn:#d97706;--bad:#dc2626;--display:'Sora',sans-serif;--body:'Plus Jakarta Sans',sans-serif}
  *{box-sizing:border-box} body{margin:0;font-family:var(--body);background:radial-gradient(circle at top left,#dff2e7 0,#f7f4ec 30%,#ebeae4 100%);color:var(--text)}
  .wrap{max-width:1240px;margin:0 auto;padding:28px 22px 48px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px}
  .eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:800}.title{font-size:34px;font-weight:700;font-family:var(--display);margin:6px 0}.sub{color:var(--muted);max-width:780px;line-height:1.6}
  .back{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.78);color:var(--text);text-decoration:none;font-size:14px;backdrop-filter:blur(16px)}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.stat{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:0 24px 60px -46px rgba(15,23,42,.22)}.stat .n{font-size:28px;font-weight:800}.stat .l{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
  .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:18px;box-shadow:0 24px 60px -46px rgba(15,23,42,.22)}.card h2{margin:0 0 14px;font-size:18px}
  table{width:100%;border-collapse:collapse} th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #edf1f6;font-size:14px;vertical-align:top} th{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
  .pill{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}.critical{background:#fee2e2;color:#991b1b}.watch{background:#fff7ed;color:#9a3412}.sufficient{background:#dcfce7;color:#166534}
  .flag{display:block;font-size:12px;color:var(--muted);margin-top:4px}.formula{display:grid;gap:12px}.formula-item{border:1px solid #edf1f6;border-radius:16px;padding:14px;background:#fcfcff}.formula-name{font-weight:800;margin-bottom:4px}.tiny{font-size:12px;color:var(--muted)}
  @media (max-width:980px){.grid{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}}
  @media (max-width:640px){.stats{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="system-polish.css?v=1">
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <div class="eyebrow">Executive layer</div>
      <div class="title">Low-Sales Branch Analytics</div>
      <div class="sub">This board highlights weak branch performance by week and month, then exposes the exact DSS rules behind the scores so the admin can defend the recommendations in plain language.</div>
    </div>
    <a class="back" href="superadmin.php"><i class="ti ti-arrow-left"></i> Back to super admin</a>
  </div>

  <div class="stats">
    <div class="stat"><div class="l">Branches under watch</div><div class="n"><?= count($watchlist) ?></div></div>
    <div class="stat"><div class="l">Network avg revenue 7d</div><div class="n"><?= e(peso($avgRevenue7d)) ?></div></div>
    <div class="stat"><div class="l">Network avg revenue 30d</div><div class="n"><?= e(peso($avgRevenue30d)) ?></div></div>
    <div class="stat"><div class="l">Network avg sales 30d</div><div class="n"><?= number_format($avgSales30d, 1) ?></div></div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Branch watchlist</h2>
      <table>
        <thead>
          <tr><th>Rank</th><th>Branch</th><th>7-day revenue</th><th>30-day revenue</th><th>30-day sales</th><th>Score</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($branchRows as $branchRow): ?>
            <?php $riskClass = strtolower($branchRow['risk_level']); ?>
            <tr>
              <td><strong>#<?= (int)$branchRow['rank'] ?></strong></td>
              <td>
                <strong><?= e(str_replace('RF Chein - ', '', $branchRow['name'])) ?></strong>
                <span class="flag">Manager: <?= e($branchRow['manager'] ?: 'Unassigned') ?></span>
                <span class="flag">Flash sales: <?= (int)$branchRow['live_flash_sales'] ?> · Open inquiries: <?= (int)$branchRow['open_inquiries'] ?></span>
              </td>
              <td><?= e(peso($branchRow['revenue_7d'])) ?></td>
              <td><?= e(peso($branchRow['revenue_30d'])) ?></td>
              <td><?= (int)$branchRow['sales_30d'] ?></td>
              <td><span class="pill <?= e($riskClass) ?>"><?= e($branchRow['risk_level']) ?> · <?= (int)$branchRow['health_score'] ?></span></td>
              <td>
                <div><?= e($branchRow['recommended_action']) ?></div>
                <?php foreach ($branchRow['flags'] as $flag): ?><span class="flag">• <?= e($flag) ?></span><?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>DSS explanation layer</h2>
      <div class="formula">
        <?php foreach ($formulaRows as $formulaRow): ?>
          <div class="formula-item">
            <div class="formula-name"><?= e($formulaRow['metric']) ?></div>
            <div class="tiny"><?= e($formulaRow['rule']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>