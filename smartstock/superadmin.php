<?php
require_once __DIR__ . '/includes/helpers.php';
require_role(['Super Admin', 'Admin']);

$user = current_user();
$isSystemAdmin = is_super_admin($user);
$hasExecutiveControl = is_executive_user($user);
$roleName = role_label($user['role']);

$fetchScalar = static function (PDO $db, $sql, array $params = []) {
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchColumn();
};

$fetchAllRows = static function (PDO $db, $sql, array $params = []) {
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
};

$fetchRow = static function (PDO $db, $sql, array $params = []) {
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetch();
};

$shortBranchName = static function ($name) {
  return str_replace('RF Chein - ', '', (string)$name);
};

$requestedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

$branches = $db->query("
    SELECT b.*,
           (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) AS users_count,
           (SELECT COUNT(*) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1) AS devices_count
  FROM branches b ORDER BY b.name
")->fetchAll();

$branchMap = [];
foreach ($branches as $branchRow) {
  $branchMap[(int)$branchRow['id']] = $branchRow;
}

$selectedBranchId = $requestedBranchId > 0 && isset($branchMap[$requestedBranchId]) ? $requestedBranchId : 0;
$selectedBranch = $selectedBranchId > 0 ? $branchMap[$selectedBranchId] : null;
$selectedBranchName = $selectedBranch ? $shortBranchName($selectedBranch['name']) : 'All branches';
$activeBranches = $db->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY name")->fetchAll();
$canManageTransferStatus = can_manage_transfer_status($user);

$branchPhoneSql = $selectedBranchId > 0 ? ' AND p.branch_id = ?' : '';
$branchPhoneParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$branchSalesSql = $selectedBranchId > 0 ? ' AND s.branch_id = ?' : '';
$branchSalesParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$branchDirectPhoneSql = $selectedBranchId > 0 ? ' AND branch_id = ?' : '';
$branchDirectPhoneParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$branchDirectSalesSql = $selectedBranchId > 0 ? ' AND branch_id = ?' : '';
$branchDirectSalesParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];

$users   = $db->query("SELECT u.*, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id ORDER BY u.role, u.name")->fetchAll();
$devices = $fetchAllRows($db, "SELECT p.*, b.name AS branch_name FROM phones p LEFT JOIN branches b ON b.id = p.branch_id WHERE 1=1" . $branchPhoneSql . " ORDER BY p.id DESC LIMIT 50", $branchPhoneParams);
$logs    = $db->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 40")->fetchAll();

$ovBranches = count($branches);
$ovUsers    = count($users);
$ovDevices  = (int)$fetchScalar($db, "SELECT COUNT(*) FROM phones WHERE is_listed = 1" . $branchDirectPhoneSql, $branchDirectPhoneParams);
$ovSalesToday = (int)$fetchScalar($db, "SELECT COUNT(*) FROM sales WHERE sale_date = CURDATE()" . $branchDirectSalesSql, $branchDirectSalesParams);
$newInquiryCount = (int)$fetchScalar($db, "SELECT COUNT(*) FROM inquiries WHERE status = 'New'" . ($selectedBranchId > 0 ? ' AND branch_id = ?' : ''), $selectedBranchId > 0 ? [$selectedBranchId] : []);
$openInquiryCount = (int)$fetchScalar($db, "SELECT COUNT(*) FROM inquiries WHERE status IN ('New','Contacted')" . ($selectedBranchId > 0 ? ' AND branch_id = ?' : ''), $selectedBranchId > 0 ? [$selectedBranchId] : []);
$flashApprovalPendingCount = (int)$fetchScalar($db, "SELECT COUNT(*) FROM flash_sales WHERE approval_status = 'Pending'" . ($selectedBranchId > 0 ? ' AND branch_id = ?' : ''), $selectedBranchId > 0 ? [$selectedBranchId] : []);
$inquiriesHref = 'inquiries.php' . ($selectedBranchId > 0 ? '?branch_id=' . $selectedBranchId : '');

$totalStocksAcrossBranches = (int)$fetchScalar($db, "SELECT COALESCE(SUM(stock),0) FROM phones WHERE is_listed = 1");
$totalTransferRequests = (int)$fetchScalar($db, "SELECT COUNT(*) FROM stock_transfers");
$pendingTransferCount = (int)$fetchScalar($db, "SELECT COUNT(*) FROM stock_transfers WHERE status = 'Pending'");
$globalPendingTransferCount = $pendingTransferCount;

$perms = [
  ['perm'=>'Dashboard and enterprise KPIs', 'sa'=>1,'ad'=>1,'sv'=>1,'st'=>0],
  ['perm'=>'Manage branches',              'sa'=>1,'ad'=>1,'sv'=>0,'st'=>0],
  ['perm'=>'Users and role access',        'sa'=>1,'ad'=>1,'sv'=>0,'st'=>0],
  ['perm'=>'Activity logs / IT oversight', 'sa'=>1,'ad'=>1,'sv'=>0,'st'=>0],
  ['perm'=>'Enterprise branch ranking',    'sa'=>1,'ad'=>1,'sv'=>0,'st'=>0],
  ['perm'=>'Device input and inventory',   'sa'=>1,'ad'=>1,'sv'=>1,'st'=>0],
  ['perm'=>'Transfer requests',            'sa'=>1,'ad'=>1,'sv'=>1,'st'=>0],
  ['perm'=>'Record sales',                 'sa'=>1,'ad'=>1,'sv'=>1,'st'=>1],
  ['perm'=>'Reply to inquiries',           'sa'=>1,'ad'=>1,'sv'=>1,'st'=>1],
];

// --- Embedded dashboard data ------------------------------------------------
$monthStart = date('Y-m-01');
$stmt = $db->prepare("SELECT COALESCE(SUM(price),0) FROM sales WHERE sale_date >= ? AND status='Completed'" . $branchDirectSalesSql);
$stmt->execute(array_merge([$monthStart], $branchDirectSalesParams));
$revenueMonth = (float)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM sales WHERE sale_date >= ? AND status='Completed'" . $branchDirectSalesSql);
$stmt->execute(array_merge([$monthStart], $branchDirectSalesParams));
$salesMonth = (int)$stmt->fetchColumn();
$unitsInStock   = (int)$fetchScalar($db, "SELECT COALESCE(SUM(stock),0) FROM phones WHERE is_listed=1" . $branchDirectPhoneSql, $branchDirectPhoneParams);
$productsListed = (int)$fetchScalar($db, "SELECT COUNT(*) FROM phones WHERE is_listed=1" . $branchDirectPhoneSql, $branchDirectPhoneParams);
$lowStockCount  = (int)$fetchScalar($db, "SELECT COUNT(*) FROM phones WHERE is_listed=1 AND stock BETWEEN 1 AND 3" . $branchDirectPhoneSql, $branchDirectPhoneParams);
$inventory = $fetchAllRows($db, "SELECT p.*, b.name AS branch_name FROM phones p LEFT JOIN branches b ON b.id = p.branch_id WHERE p.is_listed = 1" . $branchPhoneSql . " ORDER BY p.id DESC", $branchPhoneParams);
$salesRows = $fetchAllRows($db, "SELECT s.*, b.name AS branch_name, u.name AS seller_name FROM sales s LEFT JOIN branches b ON b.id = s.branch_id LEFT JOIN users u ON u.id = s.user_id WHERE 1=1" . $branchSalesSql . " ORDER BY s.sale_date DESC, s.id DESC LIMIT 20", $branchSalesParams);
$stmt = $db->prepare("SELECT p.brand AS brand, COUNT(*) AS units, COALESCE(SUM(s.price),0) AS revenue FROM sales s LEFT JOIN phones p ON p.id = s.phone_id WHERE s.sale_date >= ? AND s.status='Completed'" . $branchSalesSql . " GROUP BY p.brand ORDER BY units DESC");
$stmt->execute(array_merge([$monthStart], $branchSalesParams));
$brandStats = $stmt->fetchAll();
$brandTotal = max(1, array_sum(array_column($brandStats, 'units')));
$condStats = $fetchAllRows($db, "SELECT `condition`, COUNT(*) AS cnt, COALESCE(SUM(stock*selling_price),0) AS value FROM phones p WHERE p.is_listed=1" . $branchPhoneSql . " GROUP BY `condition`", $branchPhoneParams);
$condTotal = max(1, array_sum(array_column($condStats, 'cnt')));
$perf = $fetchAllRows($db, "SELECT s.product_name AS name, COUNT(*) AS sold, SUM(s.price) AS rev, AVG(s.price) AS avg_price FROM sales s WHERE s.status='Completed'" . $branchSalesSql . " GROUP BY s.product_name ORDER BY sold DESC LIMIT 8", $branchSalesParams);
$stSold      = (int)$fetchScalar($db, "SELECT COUNT(*) FROM sales WHERE status='Completed'" . $branchDirectSalesSql, $branchDirectSalesParams);
$stRemaining = (int)$fetchScalar($db, "SELECT COALESCE(SUM(stock),0) FROM phones WHERE is_listed=1" . $branchDirectPhoneSql, $branchDirectPhoneParams);
$stDen       = $stSold + $stRemaining;
$stPct       = $stDen > 0 ? (int)round(($stSold / $stDen) * 100) : 0;
$months = [];
for ($i = 5; $i >= 0; $i--) { $months[date('Y-m', strtotime("-$i month"))] = 0.0; }
foreach ($fetchAllRows($db, "SELECT DATE_FORMAT(sale_date,'%Y-%m') AS ym, SUM(price) AS rev FROM sales WHERE status='Completed'" . $branchDirectSalesSql . " GROUP BY ym", $branchDirectSalesParams) as $row) {
    if (isset($months[$row['ym']])) $months[$row['ym']] = (float)$row['rev'];
}
$maxMonthRev = max(1.0, max($months));
$condSalesData = $fetchAllRows($db, "SELECT p.`condition` AS cond, COUNT(*) AS units, COALESCE(SUM(s.price),0) AS rev FROM sales s LEFT JOIN phones p ON p.id = s.phone_id WHERE s.status='Completed'" . $branchSalesSql . " GROUP BY p.`condition` ORDER BY FIELD(p.`condition`,'Excellent','Good','Fair','Poor')", $branchSalesParams);
$maxCondRev = 1.0;
foreach ($condSalesData as $c) { if ((float)$c['rev'] > $maxCondRev) $maxCondRev = (float)$c['rev']; }
$stockMap = [];
foreach ($fetchAllRows($db, "SELECT CONCAT(brand,' ',model) AS n, SUM(stock) AS rem FROM phones WHERE is_listed=1" . $branchDirectPhoneSql . " GROUP BY CONCAT(brand,' ',model)", $branchDirectPhoneParams) as $r) { $stockMap[$r['n']] = (int)$r['rem']; }
foreach ($perf as &$p) {
    $rem = $stockMap[$p['name']] ?? 0;
    $den = (int)$p['sold'] + $rem;
    $p['sell_through'] = $den > 0 ? (int)round(((int)$p['sold'] / $den) * 100) : 0;
}
unset($p);
$saleOptions = $fetchAllRows($db, "SELECT p.id, p.brand, p.model, p.`condition`, p.selling_price, p.branch_id, b.name AS branch_name FROM phones p LEFT JOIN branches b ON b.id = p.branch_id WHERE p.is_listed=1 AND p.stock > 0" . $branchPhoneSql . " ORDER BY b.name, p.brand, p.model", $branchPhoneParams);
$dashFlash = flash_get('dashboard');

$branchPerformance = $db->query("SELECT b.id, b.name, b.status, b.manager,
  COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = 'Completed'),0) AS revenue,
  (SELECT COUNT(*) FROM sales s WHERE s.branch_id = b.id AND s.status = 'Completed') AS sales_count,
  COALESCE((SELECT SUM(stock) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1),0) AS units_count,
  (SELECT COUNT(*) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1 AND p.stock BETWEEN 1 AND 3) AS low_stock_items,
  (SELECT COUNT(*) FROM stock_transfers t WHERE t.source_branch_id = b.id OR t.destination_branch_id = b.id) AS transfer_count
  FROM branches b
  ORDER BY revenue DESC, units_count DESC, b.name ASC")->fetchAll();
$topPerformingBranch = $branchPerformance[0] ?? null;
$branchRevenueSummary = array_sum(array_column($branchPerformance, 'revenue'));
$branchInventoryShareTotal = max(1, array_sum(array_column($branchPerformance, 'units_count')));
$branchRevenuePeak = 1.0;
foreach ($branchPerformance as $branchPerfRow) {
  if ((float)$branchPerfRow['revenue'] > $branchRevenuePeak) {
    $branchRevenuePeak = (float)$branchPerfRow['revenue'];
  }
}
$branchLowStockAlerts = array_values(array_filter($branchPerformance, function ($branchPerfRow) {
  return (int)$branchPerfRow['low_stock_items'] > 0;
}));
$branchRankMap = [];
$branchRevenueMap = [];
foreach ($branchPerformance as $index => $branchPerformanceRow) {
  $branchRankMap[(int)$branchPerformanceRow['id']] = $index + 1;
  $branchRevenueMap[(int)$branchPerformanceRow['id']] = (float)$branchPerformanceRow['revenue'];
}
$topRankedBranches = array_slice($branchPerformance, 0, 5);
$branchRevenuePeriodSql = $selectedBranchId > 0 ? 'WHERE b.id = ?' : '';
$branchRevenuePeriods = $fetchAllRows($db, "
  SELECT b.id, b.name,
    COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = 'Completed' AND s.sale_date = CURDATE()),0) AS revenue_daily,
    COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = 'Completed' AND YEARWEEK(s.sale_date, 1) = YEARWEEK(CURDATE(), 1)),0) AS revenue_weekly,
    COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = 'Completed' AND DATE_FORMAT(s.sale_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')),0) AS revenue_monthly,
    COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = 'Completed' AND YEAR(s.sale_date) = YEAR(CURDATE()) AND QUARTER(s.sale_date) = QUARTER(CURDATE())),0) AS revenue_quarterly,
    COALESCE((SELECT SUM(price) FROM sales s WHERE s.branch_id = b.id AND s.status = 'Completed' AND YEAR(s.sale_date) = YEAR(CURDATE())),0) AS revenue_yearly
  FROM branches b
  " . $branchRevenuePeriodSql . "
  ORDER BY b.name ASC
", $selectedBranchId > 0 ? [$selectedBranchId] : []);
$selectedBranchPerformance = null;
foreach ($branchPerformance as $branchPerformanceItem) {
  if ((int)$branchPerformanceItem['id'] === $selectedBranchId) {
    $selectedBranchPerformance = $branchPerformanceItem;
    break;
  }
}
$selectedBranchRevenuePeriods = $selectedBranchId > 0 ? ($branchRevenuePeriods[0] ?? null) : null;

$transferParams = [];
$transferFilterSql = '';
if ($selectedBranchId > 0) {
  $transferFilterSql = 'WHERE (t.source_branch_id = ? OR t.destination_branch_id = ?)';
  $transferParams = [$selectedBranchId, $selectedBranchId];
}
$transfers = $fetchAllRows($db, "
  SELECT t.*, sb.name AS source_branch_name, db2.name AS destination_branch_name,
       rq.name AS requested_by_name, ap.name AS approved_by_name,
       COUNT(ti.id) AS item_count, COALESCE(SUM(ti.quantity),0) AS total_units
  FROM stock_transfers t
  LEFT JOIN branches sb ON sb.id = t.source_branch_id
  LEFT JOIN branches db2 ON db2.id = t.destination_branch_id
  LEFT JOIN users rq ON rq.id = t.requested_by
  LEFT JOIN users ap ON ap.id = t.approved_by
  LEFT JOIN transfer_items ti ON ti.transfer_id = t.id
  " . $transferFilterSql . "
  GROUP BY t.id
  ORDER BY t.requested_at DESC, t.id DESC
  LIMIT 20
", $transferParams);
$transferStatusCounts = ['Pending' => 0, 'Approved' => 0, 'In Transit' => 0, 'Completed' => 0, 'Rejected' => 0];
foreach ($transfers as $transferRow) {
  if (isset($transferStatusCounts[$transferRow['status']])) {
    $transferStatusCounts[$transferRow['status']]++;
  }
}
$transferRequestCount = count($transfers);

$transferInventoryOptions = $fetchAllRows($db, "SELECT p.id, p.branch_id, p.brand, p.model, p.stock, p.imei, b.name AS branch_name FROM phones p LEFT JOIN branches b ON b.id = p.branch_id WHERE p.is_listed = 1 AND p.stock > 0" . $branchPhoneSql . " ORDER BY b.name, p.brand, p.model", $branchPhoneParams);
$movementLogs = $fetchAllRows($db, "SELECT il.*, CONCAT(p.brand, ' ', p.model) AS product_name, b.name AS branch_name FROM inventory_logs il LEFT JOIN phones p ON p.id = il.phone_id LEFT JOIN branches b ON b.id = il.branch_id WHERE 1=1" . ($selectedBranchId > 0 ? ' AND il.branch_id = ?' : '') . " ORDER BY il.created_at DESC, il.id DESC LIMIT 16", $selectedBranchId > 0 ? [$selectedBranchId] : []);
$movementMonitor = $fetchAllRows($db, "
  SELECT b.name AS branch_name, p.brand, p.model, p.series, p.storage, p.ram, p.color, p.stock,
         COALESCE((SELECT COUNT(*) FROM sales s WHERE s.phone_id = p.id AND s.status = 'Completed' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)),0) AS sold_30d
  FROM phones p
  LEFT JOIN branches b ON b.id = p.branch_id
  WHERE p.is_listed = 1" . $branchPhoneSql . "
  ORDER BY sold_30d DESC, p.stock ASC, p.brand ASC, p.model ASC
  LIMIT 12
", $branchPhoneParams);
$selectedBranchTopMovers = $selectedBranchId > 0 ? array_slice($movementMonitor, 0, 4) : [];
$selectedBranchPendingTransfers = $selectedBranchId > 0 ? ($transferStatusCounts['Pending'] ?? 0) : 0;

// --- Decision Support System --------------------------------------------------
$dssWindow = 30; // days
$velMap = [];
foreach ($fetchAllRows($db, "SELECT phone_id, COUNT(*) AS sold_n, MAX(sale_date) AS last_sale FROM sales WHERE status='Completed' AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $branchDirectSalesSql . " GROUP BY phone_id", $branchDirectSalesParams) as $v) {
    $velMap[(int)$v['phone_id']] = ['sold_n'=>(int)$v['sold_n'],'last'=>$v['last_sale']];
}
$lastSaleAll = [];
foreach ($fetchAllRows($db, "SELECT phone_id, MAX(sale_date) AS last_sale FROM sales WHERE status='Completed'" . $branchDirectSalesSql . " GROUP BY phone_id", $branchDirectSalesParams) as $v) {
    $lastSaleAll[(int)$v['phone_id']] = $v['last_sale'];
}

$reorderList = [];
$deadStock   = [];
$fastMovers  = [];
$tiedCapital = 0.0;
$today = new DateTime('today');
foreach ($inventory as $it) {
    $pid  = (int)$it['id'];
    $name = $it['brand'].' '.$it['model'];
    $stk  = (int)$it['stock'];
    $v    = $velMap[$pid] ?? null;
    $last = $lastSaleAll[$pid] ?? null;
    $days = $last ? (int)$today->diff(new DateTime($last))->days : 9999;

    if ($stk <= 3 && $v && $v['sold_n'] > 0) {
        $weekly  = $v['sold_n'] / 4.0;
        $suggest = max(5, (int)ceil($weekly * 4));
        $reorderList[] = [
            'name'=>$name,'branch'=>$it['branch_name']??'—','stock'=>$stk,
            'vel'=>$v['sold_n'],'suggest'=>$suggest,
            'priority'=>$stk===0?'critical':($stk<=1?'high':'medium'),
        ];
    }
    if ($stk > 0 && $days >= 30) {
        $unitCost = (float)($it['purchase_price'] ?: (float)$it['selling_price']*0.7);
        $cap = $stk * $unitCost;
        $tiedCapital += $cap;
        $deadStock[] = [
            'name'=>$name,'branch'=>$it['branch_name']??'—','stock'=>$stk,
            'days'=>$days>=9999?null:$days,'tied'=>$cap,
            'action'=>$days>=90?'Discount 20% or return':($days>=60?'Discount 10% or bundle':'Promote / feature'),
        ];
    }
    if ($v && $v['sold_n'] >= 1) {
        $fastMovers[] = ['name'=>$name,'sold'=>$v['sold_n'],'stock'=>$stk];
    }
}
usort($reorderList, function($a,$b){
    $order=['critical'=>0,'high'=>1,'medium'=>2];
    return ($order[$a['priority']]<=>$order[$b['priority']]) ?: ($b['vel']<=>$a['vel']);
});
usort($deadStock, function($a,$b){ return ($b['days']??9999) <=> ($a['days']??9999); });
usort($fastMovers, function($a,$b){ return $b['sold'] <=> $a['sold']; });
$fastMovers = array_slice($fastMovers, 0, 5);

$abcRows = $fetchAllRows($db, "SELECT p.id, CONCAT(p.brand,' ',p.model) AS name, COUNT(s.id) AS sold, COALESCE(SUM(s.price),0) AS revenue FROM phones p LEFT JOIN sales s ON s.phone_id = p.id AND s.status='Completed'" . ($selectedBranchId > 0 ? ' AND s.branch_id = ?' : '') . " WHERE p.is_listed=1" . ($selectedBranchId > 0 ? ' AND p.branch_id = ?' : '') . " GROUP BY p.id ORDER BY revenue DESC", $selectedBranchId > 0 ? [$selectedBranchId, $selectedBranchId] : []);
$totalRev = array_sum(array_column($abcRows, 'revenue'));
$cumRev = 0.0;
foreach ($abcRows as &$r) {
    $cumRev += (float)$r['revenue'];
    $pct = $totalRev > 0 ? ($cumRev / $totalRev) : 1;
    if ($r['revenue'] == 0)      $r['class'] = 'C';
    elseif ($pct <= 0.7)         $r['class'] = 'A';
    elseif ($pct <= 0.9)         $r['class'] = 'B';
    else                         $r['class'] = 'C';
}
unset($r);
$abcCount = ['A'=>0,'B'=>0,'C'=>0];
foreach ($abcRows as $r) { $abcCount[$r['class']]++; }

$marginRows = [];
foreach ($inventory as $it) {
    if (!$it['purchase_price'] || (float)$it['selling_price'] <= 0) continue;
    $m = ((float)$it['selling_price'] - (float)$it['purchase_price']) / (float)$it['selling_price'] * 100;
    $marginRows[] = [
        'name'=>$it['brand'].' '.$it['model'],
        'sell'=>(float)$it['selling_price'],'cost'=>(float)$it['purchase_price'],
        'margin'=>$m,'stock'=>(int)$it['stock'],
    ];
}
$lowMargin  = array_values(array_filter($marginRows, function($m){ return $m['margin'] < 15; }));
$highMargin = array_values(array_filter($marginRows, function($m){ return $m['margin'] >= 40; }));
usort($lowMargin,  function($a,$b){ return $a['margin'] <=> $b['margin']; });
usort($highMargin, function($a,$b){ return $b['margin'] <=> $a['margin']; });
$lowMargin  = array_slice($lowMargin, 0, 5);
$highMargin = array_slice($highMargin, 0, 5);

$lowestPerformingBranch = $branchPerformance ? $branchPerformance[count($branchPerformance) - 1] : null;
$monthSeries = array_values($months);
$currentMonthRevenue = (float)($monthSeries ? $monthSeries[count($monthSeries) - 1] : 0);
$previousMonthRevenue = (float)(count($monthSeries) > 1 ? $monthSeries[count($monthSeries) - 2] : 0);
if ($previousMonthRevenue > 0) {
  $revenueMomentumPercent = (int)round((($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100);
  if ($revenueMomentumPercent > 0) {
    $revenueMomentumText = 'Revenue is up ' . abs($revenueMomentumPercent) . '% versus last month in the current scope.';
  } elseif ($revenueMomentumPercent < 0) {
    $revenueMomentumText = 'Revenue is down ' . abs($revenueMomentumPercent) . '% versus last month in the current scope.';
  } else {
    $revenueMomentumText = 'Revenue is flat versus last month in the current scope.';
  }
} elseif ($currentMonthRevenue > 0) {
  $revenueMomentumText = 'Revenue is active this month, but there is no prior-month baseline in the six-month trend.';
} else {
  $revenueMomentumText = 'No completed revenue is visible in the latest month of the trend yet.';
}
$topBranchRevenueShare = ($branchRevenueSummary > 0 && $topPerformingBranch)
  ? (int)round((((float)$topPerformingBranch['revenue']) / $branchRevenueSummary) * 100)
  : 0;
$executiveBrief = $selectedBranchId > 0
  ? $selectedBranchName . ' is the active focus with ' . peso($revenueMonth) . ' in month-to-date revenue and ' . $salesMonth . ' completed sales. This dashboard keeps enterprise pressure, blocked approvals, and branch comparisons visible so the next decision is obvious.'
  : 'Month-to-date revenue stands at ' . peso($revenueMonth) . ' across ' . $ovBranches . ' branches. This command center surfaces growth direction, blocked workflows, and inventory pressure first so you can immediately decide what to approve, transfer, replenish, or coach.';
$executiveOperationalCopy = [];
if ($flashApprovalPendingCount > 0) {
  $executiveOperationalCopy[] = $flashApprovalPendingCount . ' flash sale request' . ($flashApprovalPendingCount === 1 ? ' is' : 's are') . ' waiting for approval';
}
if ($globalPendingTransferCount > 0) {
  $executiveOperationalCopy[] = $globalPendingTransferCount . ' transfer' . ($globalPendingTransferCount === 1 ? ' is' : 's are') . ' still pending';
}
if ($openInquiryCount > 0) {
  $executiveOperationalCopy[] = $openInquiryCount . ' customer inquir' . ($openInquiryCount === 1 ? 'y is' : 'ies are') . ' still open';
}
$executiveInsightCards = [
  [
    'tone' => $currentMonthRevenue >= $previousMonthRevenue ? 'success' : 'warn',
    'icon' => 'chart-bar',
    'title' => 'Revenue direction',
    'body' => $revenueMomentumText . ' ' . ($topPerformingBranch ? $shortBranchName($topPerformingBranch['name']) . ' leads the network with ' . peso($topPerformingBranch['revenue']) . ' and ' . $topBranchRevenueShare . '% of recorded branch revenue.' : 'No branch leader is available yet.'),
  ],
  [
    'tone' => $executiveOperationalCopy ? 'warn' : 'success',
    'icon' => 'route-2',
    'title' => 'Operational backlog',
    'body' => $executiveOperationalCopy
      ? implode('; ', $executiveOperationalCopy) . '.'
      : 'Flash-sale approvals, transfers, and customer inquiries are all clear right now.',
  ],
  [
    'tone' => (count($reorderList) || count($deadStock)) ? 'info' : 'success',
    'icon' => 'package',
    'title' => 'Inventory pressure',
    'body' => (count($reorderList)
      ? count($reorderList) . ' fast-moving item' . (count($reorderList) === 1 ? ' needs' : 's need') . ' replenishment'
      : 'No urgent replenishment signal is active')
      . ' and '
      . (count($deadStock)
        ? count($deadStock) . ' slow-moving SKU' . (count($deadStock) === 1 ? ' is' : 's are') . ' tying up ' . peso($tiedCapital)
        : 'no material slow-stock block is visible')
      . '.',
  ],
];
$executiveQuickActions = [];
if ($flashApprovalPendingCount > 0) {
  $executiveQuickActions[] = [
    'title' => 'Approve flash sale requests',
    'note' => 'Supervisor promotions cannot go live until an executive approves or rejects them.',
    'href' => 'flash_sales.php',
    'cta' => 'Open flash sales',
    'primary' => true,
  ];
}
if ($globalPendingTransferCount > 0) {
  $executiveQuickActions[] = [
    'title' => 'Resolve transfer queue',
    'note' => 'Stock movement remains blocked until pending transfer requests are reviewed.',
    'page' => 'transfers',
    'cta' => 'Open transfers',
    'primary' => true,
  ];
}
if ($openInquiryCount > 0) {
  $executiveQuickActions[] = [
    'title' => 'Reply to open inquiries',
    'note' => 'Open customer questions are live demand signals and should be routed today.',
    'href' => $inquiriesHref,
    'cta' => 'Open inquiries',
    'primary' => true,
  ];
}
$executiveQuickActions[] = [
  'title' => 'Review analytics',
  'note' => 'Open revenue periods, monthly trend, and branch-share comparisons.',
  'page' => 'analytics',
  'cta' => 'Analytics',
  'primary' => false,
];
$executiveQuickActions[] = [
  'title' => 'Open decision support',
  'note' => 'Go straight to reorder guidance, slow movers, and branch movement monitoring.',
  'page' => 'decisions',
  'cta' => 'Decision support',
  'primary' => false,
];
$executiveQuickActions[] = [
  'title' => 'Review branch structure',
  'note' => 'Check manager assignments, branch status, and network readiness from one page.',
  'page' => 'branches',
  'cta' => 'Branches',
  'primary' => false,
];
$executiveQuickActions = array_slice($executiveQuickActions, 0, 4);

$executivePriorityItems = [];
if ($flashApprovalPendingCount > 0) {
  $executivePriorityItems[] = [
    'level' => 'critical',
    'icon' => 'bolt',
    'title' => 'Approve ' . $flashApprovalPendingCount . ' flash sale request' . ($flashApprovalPendingCount === 1 ? '' : 's'),
    'note' => 'These promotions are blocked at the executive layer and cannot reach customers yet.',
    'href' => 'flash_sales.php',
    'cta' => 'Review now',
  ];
}
if ($globalPendingTransferCount > 0) {
  $executivePriorityItems[] = [
    'level' => 'high',
    'icon' => 'arrows-transfer-up-down',
    'title' => 'Move ' . $globalPendingTransferCount . ' pending transfer' . ($globalPendingTransferCount === 1 ? '' : 's') . ' forward',
    'note' => 'Pending transfers delay branch rebalancing and can leave fast movers unavailable.',
    'page' => 'transfers',
    'cta' => 'Open transfers',
  ];
}
if (count($reorderList) > 0) {
  $topReorder = $reorderList[0];
  $executivePriorityItems[] = [
    'level' => 'high',
    'icon' => 'package',
    'title' => 'Replenish ' . count($reorderList) . ' fast-moving low-stock item' . (count($reorderList) === 1 ? '' : 's'),
    'note' => $topReorder['name'] . ' at ' . $shortBranchName($topReorder['branch']) . ' is the strongest immediate replenishment signal.',
    'page' => 'decisions',
    'cta' => 'Review stock',
  ];
}
if (count($deadStock) > 0) {
  $oldestDeadStock = $deadStock[0];
  $executivePriorityItems[] = [
    'level' => 'medium',
    'icon' => 'clock-hour-4',
    'title' => 'Release capital from ' . count($deadStock) . ' slow-moving SKU' . (count($deadStock) === 1 ? '' : 's'),
    'note' => $oldestDeadStock['name'] . ' has been idle the longest and may need markdown, transfer, or feature placement.',
    'page' => 'decisions',
    'cta' => 'Open decisions',
  ];
}
if (!$executivePriorityItems) {
  $executivePriorityItems[] = [
    'level' => 'medium',
    'icon' => 'circle-check',
    'title' => 'Enterprise queues are currently clear',
    'note' => 'Use Analytics to track trend direction and Decision Support to look for the next growth or margin opportunity.',
    'page' => 'analytics',
    'cta' => 'Open analytics',
  ];
}
$executivePriorityItems = array_slice($executivePriorityItems, 0, 4);

$flash = flash_get('superadmin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartStock — <?= e($isSystemAdmin ? 'Super Admin' : 'Admin') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.3.0/dist/tabler-icons.min.css">
<style>
  :root {
    --font-sans:'Plus Jakarta Sans', sans-serif;--font-display:'Sora', sans-serif;
    --color-background-primary:rgba(255,255,255,0.92);--color-background-secondary:#f0f1ed;--color-background-tertiary:#ebeae4;
    --color-background-danger:#FCEBEB;
    --color-border-secondary:#d6d7cf;--color-border-tertiary:#e3e2da;--color-border-danger:#F2B4B4;
    --color-text-primary:#16181d;--color-text-secondary:#5b6057;--color-text-tertiary:#82877d;--color-text-danger:#A32D2D;
    --border-radius-md:12px;--border-radius-lg:20px;
  }
  html,body{margin:0;padding:0;background:var(--color-background-tertiary)}
  .sr-only{position:absolute;left:-9999px}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:radial-gradient(circle at top left,#dff2e7 0,#f7f4ec 30%,#ebeae4 100%)}
  .app{display:flex;min-height:100vh;font-family:var(--font-sans);font-size:14px;color:var(--color-text-primary)}
  .sidebar{width:228px;flex-shrink:0;padding:22px 0;background:linear-gradient(180deg,#101318 0,#171d24 100%);color:#cfd3dc;box-shadow:24px 0 60px -44px rgba(15,23,42,.8)}
  .logo{padding:0 18px 18px;font-size:22px;font-family:var(--font-display);font-weight:700;letter-spacing:-.04em;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:10px;color:#ffffff}
  .logo span{color:#1D9E75}
  .role-badge{margin:0 18px 14px;font-size:11px;padding:6px 10px;border-radius:999px;background:rgba(20,184,166,.16);color:#99f6e4;font-weight:700;display:inline-flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.04em}
  .nav-section{font-size:10px;letter-spacing:.08em;color:#6b7280;padding:10px 18px 4px;text-transform:uppercase}
  .nav-item{display:flex;align-items:center;gap:8px;padding:11px 18px;cursor:pointer;color:#9ca3af;font-size:13px;transition:background .15s,color .15s;text-decoration:none;border-right:2px solid transparent;border-radius:14px 0 0 14px;margin-left:10px}
  .nav-item:hover{background:rgba(255,255,255,.04);color:#ffffff}
  .nav-item.active{background:rgba(29,158,117,.16);color:#c8fff1;font-weight:700;border-right-color:#34d399}
  .nav-item i{font-size:16px}
  .main{flex:1;display:flex;flex-direction:column;overflow:hidden}
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid var(--color-border-tertiary);background:rgba(255,255,255,.78);backdrop-filter:blur(16px)}
  .topbar h1{font-size:28px;font-family:var(--font-display);font-weight:700;letter-spacing:-.04em}
  .content{padding:24px;flex:1;overflow-y:auto}
  .page{display:none}.page.active{display:block}
  .metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
  .metric-card{background:var(--color-background-primary);border:1px solid var(--color-border-tertiary);border-radius:var(--border-radius-lg);padding:16px 18px;box-shadow:0 24px 60px -44px rgba(15,23,42,.28)}
  .metric-label{font-size:11px;color:var(--color-text-secondary);margin-bottom:8px;display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.08em;font-weight:800}
  .metric-value{font-size:28px;font-weight:700}
  .metric-change{font-size:11px;margin-top:4px}
  .metric-change.up{color:#0F6E56}.metric-change.down{color:#A32D2D}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
  .bar-group{margin-bottom:10px}
  .bar-label{display:flex;justify-content:space-between;font-size:12px;color:var(--color-text-secondary);margin-bottom:4px}
  .bar-track{height:8px;background:var(--color-background-secondary);border-radius:4px;overflow:hidden}
  .bar-fill{height:100%;border-radius:4px;transition:width .6s ease}
  .alert-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--border-radius-md);margin-bottom:8px;font-size:13px}
  .alert-row.warn{background:#FAEEDA;color:#854F0B}.alert-row.danger{background:#FCEBEB;color:#A32D2D}
  .status-pill{font-size:11px;padding:3px 8px;border-radius:20px;font-weight:500;display:inline-block}
  .status-pill.in-stock{background:#E1F5EE;color:#0F6E56}.status-pill.low{background:#FAEEDA;color:#854F0B}.status-pill.out{background:#FCEBEB;color:#A32D2D}
  .mini-chart{display:flex;align-items:flex-end;gap:10px;height:150px;padding:6px 0 0}
  .mini-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%}
  .mini-bar-wrap{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center}
  .mini-bar{width:100%;max-width:34px;background:linear-gradient(180deg,#35c490,#1D9E75);border-radius:6px 6px 2px 2px;min-height:4px;transition:height .6s ease}
  .mini-label{font-size:11px;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.04em}
  .st-cell{display:flex;align-items:center;gap:8px;min-width:110px}
  .st-cell .bar-track{flex:1;max-width:70px;height:6px}
  .btn-primary{background:#1D9E75;color:#fff;border-color:#1D9E75}
  .btn-primary:hover{background:#0F6E56}
  .badge-live{font-size:11px;padding:5px 10px;border-radius:20px;font-weight:700;background:#E1F5EE;color:#0F6E56;display:inline-flex;align-items:center;gap:4px;text-transform:uppercase;letter-spacing:.04em}
  .notif-link{position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:999px;border:0.5px solid var(--color-border-secondary);background:var(--color-background-primary);color:var(--color-text-primary);text-decoration:none}
  .notif-link.has-items{background:#EEF6F2;border-color:#B6E4D3;color:#0F6E56}
  .notif-count{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#E05151;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100;align-items:center;justify-content:center}
  .modal-overlay.open{display:flex}
  .modal{background:var(--color-background-primary);border-radius:var(--border-radius-lg);border:0.5px solid var(--color-border-tertiary);padding:24px;width:500px;max-width:92%;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04)}
  .modal-title{font-size:16px;font-weight:600;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:0.5px solid var(--color-border-tertiary)}
  .modal .form-row{display:grid !important;grid-template-columns:1fr 1fr !important;gap:16px !important;margin-bottom:16px !important}
  .modal .form-group{display:flex !important;flex-direction:column !important;gap:6px !important}
  .modal .form-group label{font-size:12px !important;font-weight:500 !important;color:var(--color-text-secondary) !important;text-transform:uppercase !important;letter-spacing:.03em !important;display:block !important;margin-bottom:4px !important}
  .modal .form-group input,.modal .form-group select{padding:10px 12px !important;border:0.5px solid var(--color-border-secondary) !important;border-radius:var(--border-radius-md) !important;font-size:13px !important;background:var(--color-background-primary) !important;color:var(--color-text-primary) !important;transition:border-color .15s,box-shadow .15s !important;width:100% !important;box-sizing:border-box !important}
  .modal .form-group input:focus,.modal .form-group select:focus{outline:none !important;border-color:#1D9E75 !important;box-shadow:0 0 0 3px rgba(29,158,117,.1) !important}
  td table{table-layout:auto}
  .card{background:var(--color-background-primary);border:1px solid var(--color-border-tertiary);border-radius:var(--border-radius-lg);padding:18px;margin-bottom:16px;box-shadow:0 24px 60px -46px rgba(15,23,42,.22)}
  .card-title{font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
  .card-title-left{display:flex;align-items:center;gap:6px}
  .card-title-left i{color:#0F766E}
  table{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed}
  th{text-align:left;padding:8px 10px;font-size:11px;font-weight:500;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:0.5px solid var(--color-border-tertiary)}
  td{padding:10px;border-bottom:0.5px solid var(--color-border-tertiary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:var(--color-background-secondary)}
  .pill{font-size:11px;padding:3px 8px;border-radius:20px;font-weight:500;display:inline-block}
  .pill-purple{background:#EEEDFE;color:#3C3489}.pill-teal{background:#E1F5EE;color:#0F6E56}
  .pill-amber{background:#FAEEDA;color:#854F0B}.pill-red{background:#FCEBEB;color:#A32D2D}.pill-blue{background:#E6F1FB;color:#0C447C}
  .btn{padding:9px 14px;font-size:13px;border:1px solid var(--color-border-secondary);border-radius:var(--border-radius-md);cursor:pointer;background:var(--color-background-primary);color:var(--color-text-primary);display:inline-flex;align-items:center;gap:6px;transition:background .15s;text-decoration:none}
  .btn:hover{background:var(--color-background-secondary)}
  .btn-purple{background:#0F766E;color:white;border-color:#0F766E}
  .btn-purple:hover{background:#0b5d57}
  .btn-sm{padding:4px 10px;font-size:12px}
  .btn-danger{background:var(--color-background-danger);color:var(--color-text-danger);border-color:var(--color-border-danger)}
  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
  .form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px}
  .fg{display:flex;flex-direction:column;gap:4px}
  .fg label{font-size:12px;color:var(--color-text-secondary)}
  .fg input,.fg select,.fg textarea{padding:8px 10px;border:0.5px solid var(--color-border-secondary);border-radius:var(--border-radius-md);font-size:13px;background:var(--color-background-primary);color:var(--color-text-primary);width:100%;font-family:inherit}
  .fg textarea{resize:vertical;min-height:64px}
  .section-title{font-size:12px;font-weight:500;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.06em;margin:16px 0 8px;border-bottom:0.5px solid var(--color-border-tertiary);padding-bottom:6px}
  .avatar{width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:500;flex-shrink:0}
  .av-purple{background:#EEEDFE;color:#3C3489}.av-teal{background:#E1F5EE;color:#0F6E56}.av-blue{background:#E6F1FB;color:#0C447C}
  .user-cell{display:flex;align-items:center;gap:8px}
  .branch-card{background:var(--color-background-primary);border:0.5px solid var(--color-border-tertiary);border-radius:var(--border-radius-lg);padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between}
  .branch-card.is-focused{border-color:#B6E4D3;box-shadow:0 0 0 1px #B6E4D3 inset}
  .branch-card-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
  .branch-link{display:block;color:inherit;text-decoration:none;flex:1;min-width:0}
  .branch-link:hover .branch-name{text-decoration:underline}
  .branch-info{display:flex;align-items:center;gap:12px}
  .branch-icon{width:36px;height:36px;border-radius:var(--border-radius-md);background:#EEEDFE;display:flex;align-items:center;justify-content:center;color:#534AB7;font-size:18px}
  .branch-name{font-weight:500;font-size:14px}
  .branch-meta{font-size:12px;color:var(--color-text-secondary);margin-top:2px}
  .branch-actions{display:flex;gap:6px;align-items:center}
  .detail-stack{display:grid;gap:8px}
  .detail-line{font-size:13px;color:var(--color-text-secondary);line-height:1.6}
  .flash{margin:0 20px 12px;padding:10px 14px;background:#EEEDFE;color:#3C3489;border-radius:var(--border-radius-md);font-size:13px;border:0.5px solid #D6D1F9}
  /* Decision Support */
  .dss-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
  .dss-kpi{background:var(--color-background-primary);border:0.5px solid var(--color-border-tertiary);border-radius:var(--border-radius-lg);padding:14px 16px;position:relative;overflow:hidden}
  .dss-kpi::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:#1D9E75}
  .dss-kpi.warn::before{background:#F2A93B}
  .dss-kpi.danger::before{background:#E05151}
  .dss-kpi.info::before{background:#3B82F6}
  .dss-kpi-label{font-size:12px;color:var(--color-text-secondary);margin-bottom:6px;display:flex;align-items:center;gap:6px}
  .dss-kpi-value{font-size:22px;font-weight:500}
  .dss-kpi-sub{font-size:11px;color:var(--color-text-tertiary);margin-top:4px}
  .prio-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:.04em}
  .prio-critical{background:#FCEBEB;color:#A32D2D}
  .prio-high{background:#FEF0E1;color:#8A5312}
  .prio-medium{background:#FFF8E1;color:#8B6A12}
  .abc-badge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;font-size:11px;font-weight:600;color:#fff}
  .abc-A{background:#1D9E75}
  .abc-B{background:#3B82F6}
  .abc-C{background:#868c99}
  .abc-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}
  .abc-card{border:0.5px solid var(--color-border-tertiary);border-radius:var(--border-radius-md);padding:12px 14px;background:var(--color-background-primary)}
  .abc-card-title{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--color-text-secondary);margin-bottom:4px}
  .abc-card-value{font-size:20px;font-weight:500}
  .margin-cell{font-weight:500}
  .margin-low{color:#A32D2D}
  .margin-high{color:#1D9E75}
  .branch-switcher{display:flex;align-items:center;gap:8px}
  .branch-switcher form{display:flex;align-items:center;gap:8px}
  .branch-switcher select{padding:8px 12px;border:0.5px solid var(--color-border-secondary);border-radius:var(--border-radius-md);background:var(--color-background-primary);color:var(--color-text-primary);font-size:13px}
  .scope-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:#EEF6F2;color:#0F6E56;font-size:12px;font-weight:500}
  .profile-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px}
  .profile-stat{border:0.5px solid var(--color-border-tertiary);border-radius:var(--border-radius-md);padding:12px;background:var(--color-background-tertiary)}
  .profile-stat-label{font-size:11px;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
  .profile-stat-value{font-size:20px;font-weight:500}
  .command-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:16px;margin-bottom:16px}
  .summary-lead{font-size:15px;line-height:1.75;color:var(--color-text-primary);margin-bottom:16px}
  .insight-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .insight-card{border:1px solid var(--color-border-tertiary);border-radius:var(--border-radius-md);padding:14px 16px;background:linear-gradient(180deg,#ffffff 0%,#fafcfd 100%)}
  .insight-card.success{background:#EEF9F4;border-color:#B6E4D3}
  .insight-card.warn{background:#FFF7E8;border-color:#F3D68A}
  .insight-card.info{background:#EEF6FF;border-color:#C9DCF4}
  .insight-card.danger{background:#FFF0F0;border-color:#F2B4B4}
  .insight-card-title{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-secondary);margin-bottom:8px}
  .insight-card-body{font-size:13px;line-height:1.6;color:var(--color-text-primary)}
  .shortcut-grid,.priority-list{display:grid;gap:10px}
  .shortcut-card,.priority-item{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:14px 16px;border:1px solid var(--color-border-tertiary);border-radius:var(--border-radius-md);background:linear-gradient(180deg,#ffffff 0%,#fafcfd 100%)}
  .shortcut-copy,.priority-copy{display:grid;gap:4px}
  .shortcut-title,.priority-title{font-size:13px;font-weight:700;color:var(--color-text-primary)}
  .shortcut-note,.priority-note,.chart-summary{font-size:12px;color:var(--color-text-secondary);line-height:1.6}
  .priority-actions{display:flex;gap:8px;flex-wrap:wrap}
  .rank-stack{display:grid;gap:10px}
  .rank-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid var(--color-border-tertiary);border-radius:16px;background:#f8f7f1}
  .rank-badge{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:999px;background:#dff2e7;color:#0F766E;font-weight:800;flex-shrink:0}
  .rank-label{display:flex;align-items:center;gap:12px}
  .product-cell{display:flex;align-items:center;gap:12px}
  .product-thumb{width:48px;height:48px;border-radius:14px;object-fit:cover;border:1px solid var(--color-border-tertiary);background:#f7f7f2;flex-shrink:0}
  .product-thumb.placeholder{display:inline-flex;align-items:center;justify-content:center;color:var(--color-text-tertiary);font-size:18px}
  .transfer-stack{display:grid;gap:12px}
  .transfer-card{border:0.5px solid var(--color-border-tertiary);border-radius:var(--border-radius-lg);padding:16px;background:linear-gradient(180deg,#ffffff 0%,#fafcfd 100%)}
  .transfer-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
  .transfer-code{font-size:14px;font-weight:600}
  .transfer-route{font-size:12px;color:var(--color-text-secondary);margin-top:4px}
  .transfer-meta{font-size:12px;color:var(--color-text-tertiary);margin-top:4px}
  .timeline{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}
  .timeline-step{padding:6px 10px;border-radius:999px;font-size:11px;font-weight:500;background:#F3F4F6;color:#6B7280}
  .timeline-step.done{background:#E1F5EE;color:#0F6E56}
  .timeline-step.active{background:#E6F1FB;color:#0C447C}
  .timeline-step.rejected{background:#FCEBEB;color:#A32D2D}
  .status-pill.pending{background:#FFF4D6;color:#8A5A12}
  .status-pill.approved{background:#E6F1FB;color:#0C447C}
  .status-pill.in-transit{background:#EEF2FF;color:#4338CA}
  .status-pill.completed{background:#E1F5EE;color:#0F6E56}
  .status-pill.rejected{background:#FCEBEB;color:#A32D2D}
  .movement-neg{color:#A32D2D;font-weight:500}
  .movement-pos{color:#0F6E56;font-weight:500}
  .page-intro{font-size:13px;color:var(--color-text-secondary);line-height:1.6}
  .metric-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  @media (max-width: 1100px){.metrics,.dss-grid,.abc-summary,.profile-grid,.metric-grid-3,.insight-grid{grid-template-columns:repeat(2,1fr)}.grid2,.form-grid,.form-grid-3,.command-grid{grid-template-columns:1fr}}
  @media (max-width: 760px){.app{display:block}.sidebar{width:auto}.topbar{flex-direction:column;align-items:flex-start;gap:10px}.profile-grid,.metrics,.dss-grid,.abc-summary,.metric-grid-3,.insight-grid{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="system-polish.css?v=1">
</head>
<body>

<div class="app">
  <div class="sidebar">
    <div class="logo"><span>Smart</span>Stock</div>
    <div class="role-badge"><i class="ti <?= $isSystemAdmin ? 'ti-shield-check' : 'ti-briefcase' ?>" style="font-size:11px"></i> <?= e($roleName) ?></div>

    <div class="nav-section">Workspace</div>
    <?php if ($hasExecutiveControl): ?>
      <div class="nav-item active" data-page="branches" onclick="nav('branches',this)"><i class="ti ti-building-store"></i> Branches</div>
      <div class="nav-item" data-page="users" onclick="nav('users',this)"><i class="ti ti-users"></i> Users</div>
    <?php endif; ?>

    <?php if ($hasExecutiveControl): ?>
      <div class="nav-section">System</div>
      <div class="nav-item" data-page="roles" onclick="nav('roles',this)"><i class="ti ti-key"></i> Roles &amp; access</div>
      <div class="nav-item" data-page="logs" onclick="nav('logs',this)"><i class="ti ti-clipboard-list"></i> Activity</div>
    <?php endif; ?>

    <a class="nav-item" href="logout.php" style="margin-top:20px;color:#ff8a8a"><i class="ti ti-logout"></i> Sign out</a>
  </div>

  <div class="main">
    <div class="topbar">
      <h1 id="page-title">Branches</h1>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="branch-switcher">
          <form method="get">
            <label class="sr-only" for="branch-switch">Branch scope</label>
            <select id="branch-switch" name="branch_id" onchange="this.form.submit()">
              <option value="0">All branches</option>
              <?php foreach ($branches as $branchOption): ?>
                <option value="<?= (int)$branchOption['id'] ?>" <?= $selectedBranchId === (int)$branchOption['id'] ? 'selected' : '' ?>><?= e($shortBranchName($branchOption['name'])) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <span class="badge-live"><i class="ti ti-circle-dot" style="font-size:10px"></i> Live</span>
        <span class="scope-pill"><i class="ti ti-building-store"></i> <?= e($selectedBranchName) ?></span>
        <div style="font-size:13px;color:var(--color-text-secondary)"><strong><?= e($user['name']) ?></strong></div>
        <div class="avatar av-purple"><?= e(strtoupper(substr($user['name'], 0, 2))) ?></div>
      </div>
    </div>

    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($dashFlash): ?><div class="flash" style="background:#E1F5EE;color:#0F6E56;border-color:#B6E4D3"><?= e($dashFlash) ?></div><?php endif; ?>

    <div class="content">
      <!-- DASHBOARD -->
      <div class="page" id="pg-dashboard">
        <div class="metrics">
          <div class="metric-card">
            <div class="metric-label"><i class="ti ti-currency-peso"></i> Revenue (Month)</div>
            <div class="metric-value"><?= e(peso($revenueMonth)) ?></div>
            <div class="metric-change up">Live from database</div>
          </div>
          <div class="metric-card">
            <div class="metric-label"><i class="ti ti-shopping-cart"></i> Sales this month</div>
            <div class="metric-value"><?= $salesMonth ?></div>
            <div class="metric-change up">Completed transactions</div>
          </div>
          <div class="metric-card">
            <div class="metric-label"><i class="ti ti-package"></i> Units in Stock</div>
            <div class="metric-value"><?= $unitsInStock ?></div>
            <div class="metric-change <?= $lowStockCount ? 'down' : 'up' ?>"><?= $lowStockCount ? '↓ ' . $lowStockCount . ' low-stock items' : 'Stock sufficient' ?></div>
          </div>
          <div class="metric-card">
            <div class="metric-label"><i class="ti ti-device-mobile"></i> Products Listed</div>
            <div class="metric-value"><?= $productsListed ?></div>
            <div class="metric-change up"><?= $flashApprovalPendingCount > 0 ? $flashApprovalPendingCount . ' flash approvals queued' : 'Active SKUs' ?></div>
          </div>
        </div>
        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-device-mobile"></i> Top-selling brands (this month)</div></div>
            <?php if (!$brandStats): ?><div style="color:var(--color-text-secondary);font-size:12px">No sales yet this month.</div><?php endif; ?>
            <?php $palette = ['#1D9E75','#378ADD','#D85A30','#BA7517','#639922','#7F77DD']; foreach ($brandStats as $i => $b): $pct = round(($b['units']/$brandTotal)*100); ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($b['brand'] ?? 'Unknown') ?></span><span><?= (int)$b['units'] ?> units</span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $palette[$i % count($palette)] ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-pie-chart"></i> Inventory by condition</div></div>
            <?php $cPal = ['Excellent'=>'#1D9E75','Good'=>'#378ADD','Fair'=>'#EF9F27','Poor'=>'#D85A30']; foreach ($condStats as $c): $pct = round(($c['cnt']/$condTotal)*100); ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($c['condition']) ?></span><span><?= e(peso($c['value'])) ?> value</span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $cPal[$c['condition']] ?? '#888' ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-alert-triangle"></i> Low stock alerts</div></div>
          <?php $alerts = array_filter($inventory, fn($r) => $r['stock'] <= 3);
            if (!$alerts): echo '<div style="color:var(--color-text-secondary);font-size:12px">Stock levels are sufficient.</div>';
            else: foreach ($alerts as $r): $danger = ((int)$r['stock'] === 0); ?>
            <div class="alert-row <?= $danger ? 'danger' : 'warn' ?>">
              <i class="ti <?= $danger ? 'ti-alert-circle' : 'ti-alert-triangle' ?>"></i>
              <span><strong><?= e($r['brand'].' '.$r['model']) ?></strong> — <?= $danger ? 'Out of stock' : 'Only '.$r['stock'].' left' ?> <span style="color:var(--color-text-tertiary)">(<?= e($r['branch_name'] ?? '—') ?>)</span></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- INVENTORY -->
      <div class="page" id="pg-inventory">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <div style="font-size:13px;color:var(--color-text-secondary)"><?= $unitsInStock ?> units across <?= $productsListed ?> products</div>
          <a class="btn btn-primary" href="javascript:void(0)" onclick="nav('devices', document.querySelector('.nav-item[onclick*=&quot;devices&quot;]'))"><i class="ti ti-plus"></i> Add product</a>
        </div>
        <div class="card" style="padding:0">
          <table>
            <thead><tr><th>Brand</th><th>Product</th><th>Series</th><th>Variant</th><th>Color</th><th>Branch</th><th>Price</th><th>Stock</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($inventory as $r):
                $status = $r['stock'] == 0 ? 'out' : ($r['stock'] <= 3 ? 'low' : 'in-stock');
                $statusLabel = $status === 'in-stock' ? 'In stock' : ($status === 'low' ? 'Low stock' : 'Out of stock'); ?>
                <tr>
                  <td><?= e($r['brand']) ?></td>
                  <td>
                    <strong><?= e($r['model']) ?></strong>
                    <div class="page-intro"><?= e($r['condition']) ?></div>
                  </td>
                  <td><?= e($r['series'] ?: strtok((string)$r['model'], ' ')) ?></td>
                  <td><?= e(trim(implode(' / ', array_filter([$r['storage'], $r['ram'] ? $r['ram'] . ' RAM' : ''])))) ?></td>
                  <td><?= e($r['color'] ?: '—') ?></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e(str_replace('RF Chein - ', '', $r['branch_name'] ?? '—')) ?></td>
                  <td><?= e(peso($r['selling_price'])) ?></td>
                  <td style="font-weight:500"><?= (int)$r['stock'] ?></td>
                  <td><span class="status-pill <?= $status ?>"><?= $statusLabel ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- SALES -->
      <div class="page" id="pg-sales">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <div style="font-size:13px;color:var(--color-text-secondary)"><?= $salesMonth ?> transactions this month</div>
          <button class="btn btn-primary" onclick="openSaleModal()"><i class="ti ti-plus"></i> Record sale</button>
        </div>
        <div class="card" style="padding:0">
          <table>
            <thead><tr><th>Receipt #</th><th>Product</th><th>Seller</th><th>Customer</th><th>Branch</th><th>Price</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($salesRows as $s): ?>
                <tr>
                  <td style="color:var(--color-text-secondary);font-size:12px"><?= e($s['txn_id']) ?></td>
                  <td><?= e($s['product_name']) ?></td>
                  <td><?= e($s['seller_name'] ?: 'System') ?></td>
                  <td><?= e($s['customer']) ?></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e($shortBranchName($s['branch_name'] ?? '—')) ?></td>
                  <td style="font-weight:500"><?= e(peso($s['price'])) ?></td>
                  <td><?= e(date('M j, Y', strtotime($s['sale_date']))) ?></td>
                  <td><span class="status-pill in-stock"><?= e($s['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$salesRows): ?><tr><td colspan="8" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No sales recorded yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TRANSFERS -->
      <div class="page" id="pg-transfers">
        <div class="metrics">
          <div class="metric-card"><div class="metric-label"><i class="ti ti-arrows-transfer-up-down"></i> Requests</div><div class="metric-value"><?= $transferRequestCount ?></div><div class="metric-change up">Within current scope</div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-loader"></i> Pending</div><div class="metric-value"><?= $pendingTransferCount ?></div><div class="metric-change <?= $pendingTransferCount ? 'down' : 'up' ?>"><?= $pendingTransferCount ? 'Needs action' : 'No backlog' ?></div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-truck-delivery"></i> In transit</div><div class="metric-value"><?= $transferStatusCounts['In Transit'] ?></div><div class="metric-change up">Currently moving</div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-checks"></i> Completed</div><div class="metric-value"><?= $transferStatusCounts['Completed'] ?></div><div class="metric-change up">Finished transfers</div></div>
        </div>

        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-send"></i> Create transfer request</div></div>
            <form method="post" action="actions/create_transfer.php">
              <?php if ($selectedBranchId === 0): ?>
                <div class="form-grid">
                  <div class="fg"><label>Source branch</label>
                    <select id="transfer-source" name="source_branch_id" required>
                      <option value="">Select source branch</option>
                      <?php foreach ($activeBranches as $branchOption): ?>
                        <option value="<?= (int)$branchOption['id'] ?>"><?= e($shortBranchName($branchOption['name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="fg"><label>Destination branch</label>
                    <select id="transfer-destination" name="destination_branch_id" required>
                      <option value="">Select destination branch</option>
                      <?php foreach ($activeBranches as $branchOption): ?>
                        <option value="<?= (int)$branchOption['id'] ?>"><?= e($shortBranchName($branchOption['name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              <?php else: ?>
                <input type="hidden" name="source_branch_id" value="<?= (int)$selectedBranchId ?>">
                <div class="form-grid">
                  <div class="fg"><label>Source branch</label><div class="scope-pill"><i class="ti ti-building-store"></i> <?= e($selectedBranchName) ?></div></div>
                  <div class="fg"><label>Destination branch</label>
                    <select id="transfer-destination" name="destination_branch_id" required>
                      <option value="">Select destination branch</option>
                      <?php foreach ($activeBranches as $branchOption): ?>
                        <?php if ((int)$branchOption['id'] === (int)$selectedBranchId) continue; ?>
                        <option value="<?= (int)$branchOption['id'] ?>"><?= e($shortBranchName($branchOption['name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              <?php endif; ?>

              <div class="form-grid">
                <div class="fg"><label>Product</label>
                  <select id="transfer-product" name="phone_id" required>
                    <option value="">Select inventory item</option>
                    <?php foreach ($transferInventoryOptions as $option): ?>
                      <option value="<?= (int)$option['id'] ?>" data-branch="<?= (int)$option['branch_id'] ?>" data-stock="<?= (int)$option['stock'] ?>">
                        <?= e($option['brand'] . ' ' . $option['model']) ?> · <?= e($shortBranchName($option['branch_name'] ?? '—')) ?> · <?= (int)$option['stock'] ?> in stock
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="fg"><label>Quantity</label><input type="number" id="transfer-quantity" name="quantity" value="1" min="1" required></div>
              </div>
              <div class="fg" style="margin-bottom:12px"><label>Notes</label><textarea name="notes" placeholder="Approval notes, courier instructions, or IMEI handling"></textarea></div>
              <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary" type="submit"><i class="ti ti-send"></i> Submit request</button></div>
            </form>
          </div>

          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-history"></i> Inventory movement logs</div></div>
            <table>
              <thead><tr><th>Event</th><th>Product</th><th>Qty</th><th>Branch</th><th>When</th></tr></thead>
              <tbody>
                <?php foreach ($movementLogs as $log): ?>
                  <tr>
                    <td><?= e(str_replace('_', ' ', ucfirst($log['event_type']))) ?></td>
                    <td><?= e($log['product_name'] ?: 'Inventory item') ?></td>
                    <td class="<?= (int)$log['quantity_change'] < 0 ? 'movement-neg' : 'movement-pos' ?>"><?= (int)$log['quantity_change'] > 0 ? '+' : '' ?><?= (int)$log['quantity_change'] ?></td>
                    <td style="font-size:12px;color:var(--color-text-secondary)"><?= e($shortBranchName($log['branch_name'] ?? '—')) ?></td>
                    <td style="font-size:12px;color:var(--color-text-secondary)"><?= e(date('M j, H:i', strtotime($log['created_at']))) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$movementLogs): ?><tr><td colspan="5" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No movement logs yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-truck-delivery"></i> Transfer board</div><span style="font-size:12px;color:var(--color-text-secondary)"><?= e($selectedBranchName) ?></span></div>
          <div class="transfer-stack">
            <?php foreach ($transfers as $transfer): ?>
              <?php $statusClass = strtolower(str_replace(' ', '-', $transfer['status'])); $steps = ['Pending', 'Approved', 'In Transit', 'Completed']; $currentStep = array_search($transfer['status'], $steps, true); ?>
              <div class="transfer-card">
                <div class="transfer-head">
                  <div>
                    <div class="transfer-code"><?= e($transfer['transfer_code']) ?></div>
                    <div class="transfer-route"><?= e($shortBranchName($transfer['source_branch_name'] ?? 'Source')) ?> to <?= e($shortBranchName($transfer['destination_branch_name'] ?? 'Destination')) ?></div>
                    <div class="transfer-meta">Requested by <?= e($transfer['requested_by_name'] ?: 'System') ?> · <?= e(date('M j, Y H:i', strtotime($transfer['requested_at']))) ?></div>
                  </div>
                  <span class="status-pill <?= e($statusClass) ?>"><?= e($transfer['status']) ?></span>
                </div>
                <div class="timeline">
                  <?php foreach ($steps as $index => $step): ?>
                    <?php $timelineClass = ''; if ($transfer['status'] === 'Rejected') { $timelineClass = $step === 'Pending' ? 'done' : ''; } elseif ($currentStep !== false) { $timelineClass = $index < $currentStep ? 'done' : ($index === $currentStep ? 'active' : ''); } ?>
                    <span class="timeline-step <?= $timelineClass ?>"><?= e($step) ?></span>
                  <?php endforeach; ?>
                  <?php if ($transfer['status'] === 'Rejected'): ?><span class="timeline-step rejected">Rejected</span><?php endif; ?>
                </div>
                <div class="transfer-meta" style="margin-top:12px"><?= (int)$transfer['item_count'] ?> line item(s) · <?= (int)$transfer['total_units'] ?> unit(s)<?php if (!empty($transfer['notes'])): ?> · <?= e($transfer['notes']) ?><?php endif; ?></div>
                <?php if ($canManageTransferStatus && in_array($transfer['status'], ['Pending', 'Approved', 'In Transit'], true)): ?>
                  <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:12px">
                    <?php if ($transfer['status'] === 'Pending'): ?>
                      <form method="post" action="actions/update_transfer_status.php"><input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-primary btn-sm" type="submit"><i class="ti ti-check"></i> Approve</button></form>
                      <form method="post" action="actions/update_transfer_status.php"><input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="rejection_reason" value="Rejected from super admin board"><button class="btn btn-danger btn-sm" type="submit"><i class="ti ti-x"></i> Reject</button></form>
                    <?php elseif ($transfer['status'] === 'Approved'): ?>
                      <form method="post" action="actions/update_transfer_status.php"><input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>"><input type="hidden" name="action" value="dispatch"><button class="btn btn-primary btn-sm" type="submit"><i class="ti ti-truck-delivery"></i> Dispatch</button></form>
                      <form method="post" action="actions/update_transfer_status.php"><input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="rejection_reason" value="Rejected before dispatch"><button class="btn btn-danger btn-sm" type="submit"><i class="ti ti-x"></i> Reject</button></form>
                    <?php elseif ($transfer['status'] === 'In Transit'): ?>
                      <form method="post" action="actions/update_transfer_status.php"><input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>"><input type="hidden" name="action" value="complete"><button class="btn btn-primary btn-sm" type="submit"><i class="ti ti-checks"></i> Complete</button></form>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$transfers): ?><div class="page-intro">No transfer requests recorded yet for the current scope.</div><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ANALYTICS -->
      <div class="page" id="pg-analytics">
        <div class="metrics" style="grid-template-columns:repeat(3,1fr)">
          <div class="metric-card">
            <div class="metric-label">Avg sale price</div>
            <div class="metric-value"><?= e(peso($salesMonth > 0 ? $revenueMonth / $salesMonth : 0)) ?></div>
            <div class="metric-change up">This month</div>
          </div>
          <div class="metric-card">
            <div class="metric-label">Best-selling brand</div>
            <div class="metric-value" style="font-size:20px"><?= e($brandStats[0]['brand'] ?? '—') ?></div>
            <div class="metric-change up"><?= (int)($brandStats[0]['units'] ?? 0) ?> units sold</div>
          </div>
          <div class="metric-card">
            <div class="metric-label">Sell-through rate</div>
            <div class="metric-value"><?= $stPct ?>%</div>
            <div class="metric-change up"><?= $stSold ?> of <?= $stDen ?> units moved</div>
          </div>
        </div>
        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-chart-bar"></i> Monthly revenue trend</div></div>
            <div class="mini-chart">
              <?php foreach ($months as $ym => $rev): $h = $maxMonthRev > 0 ? round(($rev / $maxMonthRev) * 100) : 0; ?>
                <div class="mini-col">
                  <div class="mini-bar-wrap"><div class="mini-bar" style="height:<?= max(4, $h) ?>%" title="<?= e(peso($rev)) ?>"></div></div>
                  <div class="mini-label"><?= e(date('M', strtotime($ym.'-01'))) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-device-mobile"></i> Sales by condition</div></div>
            <?php if (!$condSalesData): ?><div style="color:var(--color-text-secondary);font-size:12px">No completed sales yet.</div><?php endif; ?>
            <?php $cPal2 = ['Excellent'=>'#1D9E75','Good'=>'#378ADD','Fair'=>'#EF9F27','Poor'=>'#D85A30']; foreach ($condSalesData as $c): $pct = $maxCondRev > 0 ? round(((float)$c['rev']/$maxCondRev)*100) : 0; ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($c['cond']) ?></span><span><?= e(peso($c['rev'])) ?></span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= max(5,$pct) ?>%;background:<?= $cPal2[$c['cond']] ?? '#888' ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-table"></i> Product performance summary</div></div>
          <table>
            <thead><tr><th>Product</th><th>Units sold</th><th>Revenue</th><th>Avg price</th><th>Sell-through</th></tr></thead>
            <tbody>
              <?php foreach ($perf as $r): ?>
                <tr>
                  <td><?= e($r['name']) ?></td>
                  <td><?= (int)$r['sold'] ?></td>
                  <td style="font-weight:500"><?= e(peso($r['rev'])) ?></td>
                  <td><?= e(peso($r['avg_price'])) ?></td>
                  <td>
                    <div class="st-cell">
                      <div class="bar-track"><div class="bar-fill" style="width:<?= (int)$r['sell_through'] ?>%;background:#1D9E75"></div></div>
                      <span style="font-size:12px;color:var(--color-text-secondary);min-width:34px;text-align:right"><?= (int)$r['sell_through'] ?>%</span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$perf): ?><tr><td colspan="5" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No sales data yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-calendar-stats"></i> Revenue per branch by period</div></div>
            <table>
              <thead><tr><th>Branch</th><th>Daily</th><th>Weekly</th><th>Monthly</th><th>Quarterly</th><th>Yearly</th></tr></thead>
              <tbody>
                <?php foreach ($branchRevenuePeriods as $periodRow): ?>
                  <tr>
                    <td><?= e($shortBranchName($periodRow['name'])) ?></td>
                    <td><?= e(peso($periodRow['revenue_daily'])) ?></td>
                    <td><?= e(peso($periodRow['revenue_weekly'])) ?></td>
                    <td><?= e(peso($periodRow['revenue_monthly'])) ?></td>
                    <td><?= e(peso($periodRow['revenue_quarterly'])) ?></td>
                    <td style="font-weight:600"><?= e(peso($periodRow['revenue_yearly'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$branchRevenuePeriods): ?><tr><td colspan="6" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No branch revenue data yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-building-store"></i> Branch revenue comparison</div></div>
            <?php foreach ($branchPerformance as $branchRow): $pct = $branchRevenuePeak > 0 ? (int)round(((float)$branchRow['revenue'] / $branchRevenuePeak) * 100) : 0; ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($shortBranchName($branchRow['name'])) ?></span><span><?= e(peso($branchRow['revenue'])) ?></span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= max(4, $pct) ?>%;background:<?= $topPerformingBranch && (int)$topPerformingBranch['id'] === (int)$branchRow['id'] ? '#1D9E75' : '#378ADD' ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-chart-pie"></i> Inventory share by branch</div></div>
            <?php foreach ($branchPerformance as $branchRow): $pct = $branchInventoryShareTotal > 0 ? (int)round(((int)$branchRow['units_count'] / $branchInventoryShareTotal) * 100) : 0; ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($shortBranchName($branchRow['name'])) ?></span><span><?= (int)$branchRow['units_count'] ?> units</span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= max(4, $pct) ?>%;background:#7F77DD"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- DECISIONS -->
      <div class="page" id="pg-decisions">
        <div class="dss-grid">
          <div class="dss-kpi <?= count($reorderList) ? 'danger' : '' ?>">
            <div class="dss-kpi-label"><i class="ti ti-refresh" style="font-size:13px"></i> Needs restocking</div>
            <div class="dss-kpi-value"><?= count($reorderList) ?></div>
            <div class="dss-kpi-sub">products with stock ≤ 3 and active sales</div>
          </div>
          <div class="dss-kpi <?= count($deadStock) ? 'warn' : '' ?>">
            <div class="dss-kpi-label"><i class="ti ti-alert-triangle" style="font-size:13px"></i> Dead stock items</div>
            <div class="dss-kpi-value"><?= count($deadStock) ?></div>
            <div class="dss-kpi-sub">no sale in 30+ days</div>
          </div>
          <div class="dss-kpi warn">
            <div class="dss-kpi-label"><i class="ti ti-cash" style="font-size:13px"></i> Capital tied up</div>
            <div class="dss-kpi-value"><?= e(peso($tiedCapital)) ?></div>
            <div class="dss-kpi-sub">in dead / slow-moving stock</div>
          </div>
          <div class="dss-kpi info">
            <div class="dss-kpi-label"><i class="ti ti-trending-up" style="font-size:13px"></i> Fast movers</div>
            <div class="dss-kpi-value"><?= count($fastMovers) ?></div>
            <div class="dss-kpi-sub">top sellers in last 30 days</div>
          </div>
        </div>

        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-refresh"></i> Reorder recommendations</div><span style="font-size:12px;color:var(--color-text-secondary)">Last 30 days velocity</span></div>
            <table>
              <thead><tr><th>Product</th><th>Branch</th><th>Stock</th><th>Sold 30d</th><th>Suggested</th><th>Priority</th></tr></thead>
              <tbody>
                <?php foreach ($reorderList as $r): ?>
                  <tr>
                    <td><?= e($r['name']) ?></td>
                    <td style="color:var(--color-text-secondary);font-size:12px"><?= e($r['branch']) ?></td>
                    <td><strong><?= (int)$r['stock'] ?></strong></td>
                    <td><?= (int)$r['vel'] ?> u</td>
                    <td style="font-weight:500;color:#1D9E75">+<?= (int)$r['suggest'] ?> u</td>
                    <td><span class="prio-pill prio-<?= e($r['priority']) ?>"><?= e($r['priority']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$reorderList): ?><tr><td colspan="6" style="text-align:center;color:var(--color-text-tertiary);padding:24px">✓ All items are adequately stocked.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-trending-up"></i> Fast movers (last 30 days)</div></div>
            <table>
              <thead><tr><th>Product</th><th>Sold</th><th>In stock</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($fastMovers as $f): ?>
                  <tr>
                    <td><?= e($f['name']) ?></td>
                    <td><strong><?= (int)$f['sold'] ?></strong></td>
                    <td><?= (int)$f['stock'] ?></td>
                    <td><?php if ($f['stock'] <= $f['sold']): ?><span class="prio-pill prio-high">At risk</span><?php else: ?><span class="pill pill-teal">Sufficient</span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$fastMovers): ?><tr><td colspan="4" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No sales in the last 30 days.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

          <div class="card" style="margin-bottom:16px">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-activity-heartbeat"></i> Branch item movement monitor</div><span style="font-size:12px;color:var(--color-text-secondary)">Fast and slow moving items per branch</span></div>
            <table>
              <thead><tr><th>Branch</th><th>Brand</th><th>Product</th><th>Series</th><th>Variant</th><th>Color</th><th>Stock</th><th>Sold 30d</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($movementMonitor as $item): ?>
                  <?php
                    $movementStatus = (int)$item['sold_30d'] >= 3 ? 'Fast moving' : ((int)$item['sold_30d'] === 0 ? 'Slow moving' : 'Steady');
                    $movementClass = (int)$item['sold_30d'] >= 3 ? 'pill-teal' : ((int)$item['sold_30d'] === 0 ? 'pill-red' : 'pill-blue');
                  ?>
                  <tr>
                    <td style="font-size:12px;color:var(--color-text-secondary)"><?= e($shortBranchName($item['branch_name'] ?? '—')) ?></td>
                    <td><?= e($item['brand']) ?></td>
                    <td><?= e($item['model']) ?></td>
                    <td><?= e($item['series'] ?: strtok((string)$item['model'], ' ')) ?></td>
                    <td><?= e(trim(implode(' / ', array_filter([$item['storage'], $item['ram'] ? $item['ram'] . ' RAM' : ''])))) ?></td>
                    <td><?= e($item['color'] ?: '—') ?></td>
                    <td><strong><?= (int)$item['stock'] ?></strong></td>
                    <td><?= (int)$item['sold_30d'] ?></td>
                    <td><span class="pill <?= e($movementClass) ?>"><?= e($movementStatus) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$movementMonitor): ?><tr><td colspan="9" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No movement data available yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>

        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-alert-triangle"></i> Dead stock &amp; slow movers</div><span style="font-size:12px;color:var(--color-text-secondary)">No sales in 30+ days</span></div>
          <table>
            <thead><tr><th>Product</th><th>Branch</th><th>Stock</th><th>Days idle</th><th>Capital tied</th><th>Recommended action</th></tr></thead>
            <tbody>
              <?php foreach ($deadStock as $d): ?>
                <tr>
                  <td><?= e($d['name']) ?></td>
                  <td style="color:var(--color-text-secondary);font-size:12px"><?= e($d['branch']) ?></td>
                  <td><?= (int)$d['stock'] ?></td>
                  <td><?= $d['days']===null?'<span style="color:var(--color-text-tertiary)">Never sold</span>':(int)$d['days'].' days' ?></td>
                  <td style="font-weight:500"><?= e(peso($d['tied'])) ?></td>
                  <td><?= e($d['action']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$deadStock): ?><tr><td colspan="6" style="text-align:center;color:var(--color-text-tertiary);padding:24px">✓ No slow-moving stock detected.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-chart-pie"></i> ABC product classification</div><span style="font-size:12px;color:var(--color-text-secondary)">Pareto revenue contribution</span></div>
          <div class="abc-summary">
            <div class="abc-card"><div class="abc-card-title"><span class="abc-badge abc-A">A</span> High value (top 70% revenue)</div><div class="abc-card-value"><?= (int)$abcCount['A'] ?> <span style="font-size:12px;color:var(--color-text-secondary);font-weight:400">products — protect &amp; maintain stock</span></div></div>
            <div class="abc-card"><div class="abc-card-title"><span class="abc-badge abc-B">B</span> Medium value (next 20%)</div><div class="abc-card-value"><?= (int)$abcCount['B'] ?> <span style="font-size:12px;color:var(--color-text-secondary);font-weight:400">products — monitor trends</span></div></div>
            <div class="abc-card"><div class="abc-card-title"><span class="abc-badge abc-C">C</span> Low value (bottom 10%)</div><div class="abc-card-value"><?= (int)$abcCount['C'] ?> <span style="font-size:12px;color:var(--color-text-secondary);font-weight:400">products — review or phase out</span></div></div>
          </div>
          <table>
            <thead><tr><th style="width:48px">Class</th><th>Product</th><th>Units sold</th><th>Revenue</th><th>Contribution</th></tr></thead>
            <tbody>
              <?php foreach ($abcRows as $r): $share = $totalRev>0 ? ((float)$r['revenue']/$totalRev*100) : 0; ?>
                <tr>
                  <td><span class="abc-badge abc-<?= e($r['class']) ?>"><?= e($r['class']) ?></span></td>
                  <td><?= e($r['name']) ?></td>
                  <td><?= (int)$r['sold'] ?></td>
                  <td style="font-weight:500"><?= e(peso($r['revenue'])) ?></td>
                  <td>
                    <div class="st-cell">
                      <div class="bar-track"><div class="bar-fill" style="width:<?= max(1,(int)round($share)) ?>%;background:#1D9E75"></div></div>
                      <span style="font-size:12px;color:var(--color-text-secondary);min-width:44px;text-align:right"><?= number_format($share,1) ?>%</span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$abcRows): ?><tr><td colspan="5" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No products to analyze.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-arrow-down-right"></i> Low-margin items (&lt; 15%)</div></div>
            <table>
              <thead><tr><th>Product</th><th>Sell</th><th>Cost</th><th>Margin</th></tr></thead>
              <tbody>
                <?php foreach ($lowMargin as $m): ?>
                  <tr>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e(peso($m['sell'])) ?></td>
                    <td><?= e(peso($m['cost'])) ?></td>
                    <td class="margin-cell margin-low"><?= number_format($m['margin'],1) ?>%</td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$lowMargin): ?><tr><td colspan="4" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No low-margin items.</td></tr><?php endif; ?>
              </tbody>
            </table>
            <div style="margin-top:10px;font-size:12px;color:var(--color-text-secondary)">→ Consider renegotiating supplier cost or raising price.</div>
          </div>

          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-arrow-up-right"></i> High-margin items (≥ 40%)</div></div>
            <table>
              <thead><tr><th>Product</th><th>Sell</th><th>Cost</th><th>Margin</th></tr></thead>
              <tbody>
                <?php foreach ($highMargin as $m): ?>
                  <tr>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e(peso($m['sell'])) ?></td>
                    <td><?= e(peso($m['cost'])) ?></td>
                    <td class="margin-cell margin-high"><?= number_format($m['margin'],1) ?>%</td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$highMargin): ?><tr><td colspan="4" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No high-margin items yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
            <div style="margin-top:10px;font-size:12px;color:var(--color-text-secondary)">→ Promote these — highest profit per unit sold.</div>
          </div>
        </div>
      </div>

      <!-- OVERVIEW -->
      <div class="page" id="pg-overview">
        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-trophy"></i> Enterprise highlights</div></div>
            <div class="profile-grid">
              <div class="profile-stat"><div class="profile-stat-label">Revenue summary</div><div class="profile-stat-value"><?= e(peso($branchRevenueSummary)) ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">Top branch</div><div class="profile-stat-value" style="font-size:16px"><?= e($topPerformingBranch ? $shortBranchName($topPerformingBranch['name']) : '—') ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">Top branch revenue</div><div class="profile-stat-value"><?= e(peso($topPerformingBranch['revenue'] ?? 0)) ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">Low-stock alerts</div><div class="profile-stat-value"><?= count($branchLowStockAlerts) ?></div></div>
            </div>
          </div>
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-alert-triangle"></i> Low-stock branch alerts</div></div>
            <?php if (!$branchLowStockAlerts): ?><div class="page-intro">All branches are currently sufficient.</div><?php endif; ?>
            <?php foreach ($branchLowStockAlerts as $branchAlert): ?>
              <div class="alert-row warn"><i class="ti ti-alert-triangle"></i><span><strong><?= e($shortBranchName($branchAlert['name'])) ?></strong> has <?= (int)$branchAlert['low_stock_items'] ?> low-stock item(s) across <?= (int)$branchAlert['units_count'] ?> total units.</span></div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-trophy"></i> Branch ranking snapshot</div></div>
          <div class="rank-stack">
            <?php foreach ($topRankedBranches as $rankedBranch): ?>
              <div class="rank-row">
                <div class="rank-label">
                  <span class="rank-badge">#<?= (int)$branchRankMap[(int)$rankedBranch['id']] ?></span>
                  <div>
                    <div style="font-weight:700"><?= e($shortBranchName($rankedBranch['name'])) ?></div>
                    <div class="page-intro">Sales: <?= (int)$rankedBranch['sales_count'] ?> · Units: <?= (int)$rankedBranch['units_count'] ?></div>
                  </div>
                </div>
                <div style="font-weight:700"><?= e(peso($rankedBranch['revenue'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="metrics">
          <div class="metric-card"><div class="metric-label"><i class="ti ti-building-store" style="font-size:13px"></i> Total branches</div><div class="metric-value"><?= $ovBranches ?></div><div class="metric-change up">Network footprint</div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-packages" style="font-size:13px"></i> Total stocks across branches</div><div class="metric-value"><?= $totalStocksAcrossBranches ?></div><div class="metric-change up">Enterprise inventory</div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-arrows-transfer-up-down" style="font-size:13px"></i> Transfer requests</div><div class="metric-value"><?= $totalTransferRequests ?></div><div class="metric-change up">Workflow volume</div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-loader" style="font-size:13px"></i> Pending transfers</div><div class="metric-value"><?= $globalPendingTransferCount ?></div><div class="metric-change <?= $globalPendingTransferCount ? 'down' : 'up' ?>"><?= $globalPendingTransferCount ? 'Needs review' : 'All clear' ?></div></div>
        </div>

        <div class="command-grid">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-briefcase-2"></i> Executive brief</div></div>
            <div class="summary-lead"><?= e($executiveBrief) ?></div>
            <div class="insight-grid">
              <?php foreach ($executiveInsightCards as $insight): ?>
                <div class="insight-card <?= e($insight['tone']) ?>">
                  <div class="insight-card-title"><i class="ti ti-<?= e($insight['icon']) ?>"></i> <?= e($insight['title']) ?></div>
                  <div class="insight-card-body"><?= e($insight['body']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-bolt"></i> Recommended next actions</div></div>
            <div class="shortcut-grid">
              <?php foreach ($executiveQuickActions as $action): ?>
                <div class="shortcut-card">
                  <div class="shortcut-copy">
                    <div class="shortcut-title"><?= e($action['title']) ?></div>
                    <div class="shortcut-note"><?= e($action['note']) ?></div>
                  </div>
                  <?php if (!empty($action['href'])): ?>
                    <a class="btn <?= !empty($action['primary']) ? 'btn-primary' : '' ?>" href="<?= e($action['href']) ?>"><?= e($action['cta']) ?></a>
                  <?php elseif (!empty($action['page'])): ?>
                    <button type="button" class="btn <?= !empty($action['primary']) ? 'btn-primary' : '' ?>" onclick="nav('<?= e($action['page']) ?>', document.querySelector('.nav-item[data-page=&quot;<?= e($action['page']) ?>&quot;]'))"><?= e($action['cta']) ?></button>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="grid2">
          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-chart-bar"></i> Revenue direction</div></div>
            <div class="mini-chart">
              <?php foreach ($months as $ym => $rev): ?>
                <div class="mini-col">
                  <div class="mini-bar-wrap"><div class="mini-bar" style="height:<?= max(4, (int)round(($rev / $maxMonthRev) * 100)) ?>%"></div></div>
                  <div class="mini-label"><?= e(date('M', strtotime($ym . '-01'))) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="chart-summary"><?= e($revenueMomentumText) ?> <?= e($lowestPerformingBranch ? $shortBranchName($lowestPerformingBranch['name']) . ' is the weakest branch in the current ranking and should be reviewed against inventory, staffing, and transfer support.' : 'Branch comparison will appear here once sales data exists.') ?></div>
          </div>

          <div class="card">
            <div class="card-title"><div class="card-title-left"><i class="ti ti-target-arrow"></i> Needs attention now</div></div>
            <div class="priority-list">
              <?php foreach ($executivePriorityItems as $item): ?>
                <div class="priority-item">
                  <div class="priority-copy">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                      <span class="prio-pill prio-<?= e($item['level']) ?>"><?= e(ucfirst($item['level'])) ?></span>
                      <span class="priority-title"><i class="ti ti-<?= e($item['icon']) ?>" style="margin-right:6px;color:#0F766E"></i><?= e($item['title']) ?></span>
                    </div>
                    <div class="priority-note"><?= e($item['note']) ?></div>
                  </div>
                  <?php if (!empty($item['href']) || !empty($item['page'])): ?>
                    <div class="priority-actions">
                      <?php if (!empty($item['href'])): ?>
                        <a class="btn btn-primary btn-sm" href="<?= e($item['href']) ?>"><?= e($item['cta']) ?></a>
                      <?php elseif (!empty($item['page'])): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="nav('<?= e($item['page']) ?>', document.querySelector('.nav-item[data-page=&quot;<?= e($item['page']) ?>&quot;]'))"><?= e($item['cta']) ?></button>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-building-store"></i> Branch summary</div></div>
          <table>
            <thead><tr><th style="width:10%">Rank</th><th style="width:24%">Branch</th><th style="width:18%">Manager</th><th style="width:16%">Revenue</th><th style="width:12%">Users</th><th style="width:10%">Devices</th><th style="width:10%">Status</th></tr></thead>
            <tbody>
              <?php foreach ($branches as $b): ?>
                <tr>
                  <td><strong>#<?= (int)($branchRankMap[(int)$b['id']] ?? 0) ?></strong></td>
                  <td><?= e($b['name']) ?></td>
                  <td><?= e($b['manager'] ?: '—') ?></td>
                  <td><?= e(peso($branchRevenueMap[(int)$b['id']] ?? 0)) ?></td>
                  <td><?= (int)$b['users_count'] ?></td>
                  <td><?= (int)$b['devices_count'] ?></td>
                  <td><span class="pill <?= $b['status']==='Active'?'pill-teal':'pill-amber' ?>"><?= e($b['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-clipboard-list"></i> Recent activity</div></div>
          <table>
            <thead><tr><th style="width:25%">User</th><th style="width:20%">Branch</th><th style="width:35%">Action</th><th style="width:20%">When</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($logs, 0, 6) as $i => $l): ?>
                <tr>
                  <td><div class="user-cell"><div class="avatar <?= ['av-purple','av-teal','av-blue'][$i%3] ?>" style="width:24px;height:24px;font-size:10px"><?= e(strtoupper(substr($l['user_name'],0,2))) ?></div><?= e($l['user_name']) ?></div></td>
                  <td><?= e($l['branch_name']) ?></td>
                  <td><?= e($l['action']) ?></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e(date('M j, H:i', strtotime($l['created_at']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- BRANCHES -->
      <div class="page active" id="pg-branches">
        <?php if ($selectedBranch && $selectedBranchPerformance): ?>
          <div class="card">
            <div class="card-title">
              <div class="card-title-left"><i class="ti ti-building-store"></i> <?= e($selectedBranchName) ?> details</div>
              <a class="btn btn-sm" href="superadmin.php#branches"><i class="ti ti-layout-grid"></i> Show all branches</a>
            </div>
            <div class="summary-lead" style="margin-bottom:12px"><?= e($selectedBranchName) ?> is currently ranked #<?= (int)($branchRankMap[$selectedBranchId] ?? 0) ?> with <?= e(peso($selectedBranchPerformance['revenue'])) ?> in completed revenue, <?= (int)$selectedBranchPerformance['sales_count'] ?> completed sales, and <?= (int)$selectedBranchPerformance['units_count'] ?> active units.</div>
            <div class="grid2">
              <div>
                <div class="card-title" style="margin-bottom:10px"><div class="card-title-left"><i class="ti ti-id-badge-2"></i> Branch overview</div></div>
                <div class="detail-stack">
                  <div class="detail-line"><strong>Address:</strong> <?= e($selectedBranch['address']) ?></div>
                  <div class="detail-line"><strong>Manager:</strong> <?= e($selectedBranch['manager'] ?: '—') ?></div>
                  <div class="detail-line"><strong>Phone:</strong> <?= e($selectedBranch['phone'] ?: '—') ?></div>
                  <div class="detail-line"><strong>Email:</strong> <?= e($selectedBranch['email'] ?? '—') ?></div>
                  <div class="detail-line"><strong>Status:</strong> <span class="pill <?= $selectedBranch['status'] === 'Active' ? 'pill-teal' : 'pill-amber' ?>"><?= e($selectedBranch['status']) ?></span></div>
                </div>
                <div class="profile-grid" style="margin-top:14px">
                  <div class="profile-stat"><div class="profile-stat-label">Users</div><div class="profile-stat-value"><?= (int)$selectedBranch['users_count'] ?></div></div>
                  <div class="profile-stat"><div class="profile-stat-label">Listed products</div><div class="profile-stat-value"><?= (int)$selectedBranch['devices_count'] ?></div></div>
                  <div class="profile-stat"><div class="profile-stat-label">Units in stock</div><div class="profile-stat-value"><?= (int)$selectedBranchPerformance['units_count'] ?></div></div>
                  <div class="profile-stat"><div class="profile-stat-label">Low-stock items</div><div class="profile-stat-value"><?= (int)$selectedBranchPerformance['low_stock_items'] ?></div></div>
                </div>
              </div>
              <div>
                <div class="card-title" style="margin-bottom:10px"><div class="card-title-left"><i class="ti ti-chart-bar"></i> Performance snapshot</div></div>
                <table>
                  <tbody>
                    <tr><td>Revenue today</td><td><?= e(peso($selectedBranchRevenuePeriods['revenue_daily'] ?? 0)) ?></td></tr>
                    <tr><td>Revenue this week</td><td><?= e(peso($selectedBranchRevenuePeriods['revenue_weekly'] ?? 0)) ?></td></tr>
                    <tr><td>Revenue this month</td><td><?= e(peso($selectedBranchRevenuePeriods['revenue_monthly'] ?? 0)) ?></td></tr>
                    <tr><td>Revenue this quarter</td><td><?= e(peso($selectedBranchRevenuePeriods['revenue_quarterly'] ?? 0)) ?></td></tr>
                    <tr><td>Revenue this year</td><td><?= e(peso($selectedBranchRevenuePeriods['revenue_yearly'] ?? 0)) ?></td></tr>
                    <tr><td>Completed sales</td><td><?= (int)$selectedBranchPerformance['sales_count'] ?></td></tr>
                    <tr><td>Transfer requests</td><td><?= (int)$selectedBranchPerformance['transfer_count'] ?></td></tr>
                    <tr><td>Pending transfers</td><td><?= (int)$selectedBranchPendingTransfers ?></td></tr>
                    <tr><td>Open inquiries</td><td><?= (int)$openInquiryCount ?></td></tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div style="margin-top:16px">
              <div class="card-title" style="margin-bottom:10px"><div class="card-title-left"><i class="ti ti-activity-heartbeat"></i> Top moving items</div></div>
              <table>
                <thead><tr><th style="width:48%">Product</th><th style="width:18%">Sold 30d</th><th style="width:14%">Stock</th><th style="width:20%">Movement</th></tr></thead>
                <tbody>
                  <?php foreach ($selectedBranchTopMovers as $movingItem): ?>
                    <?php
                      $movementLabel = (int)$movingItem['sold_30d'] >= 4 ? 'Fast' : ((int)$movingItem['sold_30d'] >= 2 ? 'Active' : 'Slow');
                      $movementClass = $movementLabel === 'Fast' ? 'pill-teal' : ($movementLabel === 'Active' ? 'pill-blue' : 'pill-amber');
                    ?>
                    <tr>
                      <td><?= e(trim(implode(' · ', array_filter([$movingItem['brand'] . ' ' . $movingItem['model'], $movingItem['series'] ?? '', $movingItem['storage'] ?? '', $movingItem['color'] ?? ''])))) ?></td>
                      <td><?= (int)$movingItem['sold_30d'] ?></td>
                      <td><?= (int)$movingItem['stock'] ?></td>
                      <td><span class="pill <?= $movementClass ?>"><?= e($movementLabel) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$selectedBranchTopMovers): ?><tr><td colspan="4" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No movement data is available for this branch yet.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="page-intro">Click any branch card below to load that branch's details, overview, and performance in this page.</div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-building-store"></i> Add new branch</div></div>
          <form method="post" action="actions/add_branch.php">
            <div class="form-grid">
              <div class="fg"><label>Branch name</label><input type="text" name="name" required placeholder="e.g. RF Chein - Lacson Branch"></div>
              <div class="fg"><label>Location / address</label><input type="text" name="address" required placeholder="e.g. Lacson St, Bacolod City"></div>
            </div>
            <div style="display:flex;justify-content:flex-end">
              <button type="submit" class="btn btn-purple"><i class="ti ti-plus"></i> Add branch</button>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-list"></i> All branches</div><span style="font-size:12px;color:var(--color-text-secondary)"><?= count($branches) ?> branches</span></div>
          <?php foreach ($branches as $b): ?>
            <?php
              $branchFocusHref = 'superadmin.php?branch_id=' . (int)$b['id'] . '#branches';
              $isFocusedBranch = $selectedBranchId === (int)$b['id'];
            ?>
            <div class="branch-card<?= $isFocusedBranch ? ' is-focused' : '' ?>" style="display:block">
              <div class="branch-card-header">
                <a class="branch-link" href="<?= e($branchFocusHref) ?>">
                  <div class="branch-info">
                    <div class="branch-icon"><i class="ti ti-building-store"></i></div>
                    <div>
                      <div class="branch-name"><?= e($b['name']) ?></div>
                      <div class="branch-meta"><i class="ti ti-map-pin" style="font-size:12px"></i> <?= e($b['address']) ?> &nbsp;|&nbsp; <i class="ti ti-user" style="font-size:12px"></i> <?= e($b['manager'] ?: '—') ?> &nbsp;|&nbsp; <?= (int)$b['users_count'] ?> users · <?= (int)$b['devices_count'] ?> devices</div>
                    </div>
                  </div>
                </a>
                <div class="branch-actions">
                  <span class="pill <?= $b['status']==='Active'?'pill-teal':'pill-amber' ?>"><?= e($b['status']) ?></span>
                  <a class="btn btn-sm<?= $isFocusedBranch ? ' btn-primary' : '' ?>" href="<?= e($branchFocusHref) ?>"><i class="ti ti-chart-bar"></i> <?= $isFocusedBranch ? 'Viewing' : 'View details' ?></a>
                  <form method="post" action="actions/delete_branch.php" onsubmit="return confirm('Remove this branch?')" style="display:inline">
                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit"><i class="ti ti-trash"></i></button>
                  </form>
                </div>
              </div>
              <form method="post" action="actions/update_branch.php" style="margin-top:14px">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <div class="form-grid">
                  <div class="fg"><label>Branch name</label><input type="text" name="name" value="<?= e($b['name']) ?>" required></div>
                  <div class="fg"><label>Address</label><input type="text" name="address" value="<?= e($b['address']) ?>" required></div>
                </div>
                <div class="form-grid">
                  <div class="fg"><label>Branch manager</label><input type="text" value="<?= e($b['manager'] ?: 'Assigned from Supervisor user') ?>" disabled></div>
                  <div class="fg"><label>Contact number</label><input type="text" value="<?= e($b['phone'] ?: 'Assigned from Supervisor user') ?>" disabled></div>
                </div>
                <div class="form-grid">
                  <div class="fg"><label>Branch email</label><input type="text" value="<?= e($b['email'] ?? 'Assigned from Supervisor user') ?>" disabled></div>
                  <div class="fg"><label>Status</label><select name="status"><option value="Active" <?= $b['status']==='Active' ? 'selected' : '' ?>>Active</option><option value="Inactive" <?= $b['status']==='Inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                </div>
                <div style="display:flex;justify-content:flex-end">
                  <button class="btn btn-purple btn-sm" type="submit"><i class="ti ti-device-floppy"></i> Save branch</button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- USERS -->
      <div class="page" id="pg-users">
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-user-plus"></i> Add new user</div></div>
          <form method="post" action="actions/add_user.php">
            <div class="form-grid">
              <div class="fg"><label>Full name</label><input type="text" name="name" required placeholder="e.g. Juan Dela Cruz"></div>
              <div class="fg"><label>Email address</label><input type="email" name="email" required placeholder="e.g. juan@rfchein.com"></div>
            </div>
            <div class="form-grid">
              <div class="fg"><label>Role</label>
                <select name="role"><option>Admin</option><option>Supervisor</option><option>Staff</option></select>
              </div>
              <div class="fg"><label>Assign to branch</label>
                <select name="branch_id">
                  <option value="">— No branch —</option>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-grid">
              <div class="fg"><label>Contact number</label><input type="text" name="phone" placeholder="e.g. 09XX-XXX-XXXX"></div>
              <div class="fg"><label>Username</label><input type="text" name="username" required placeholder="e.g. jdelacruz"></div>
            </div>
            <div class="form-grid">
              <div class="fg"><label>Temporary password</label><input type="password" name="password" required minlength="6" placeholder="Min. 6 characters"></div>
              <div class="fg"><label>Branch contact sync</label><input type="text" value="For Supervisors, the name, email, and contact number above become the branch manager details." disabled></div>
            </div>
            <div style="display:flex;justify-content:flex-end">
              <button type="submit" class="btn btn-purple"><i class="ti ti-user-plus"></i> Create user</button>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-users"></i> All users</div><span style="font-size:12px;color:var(--color-text-secondary)"><?= count($users) ?> users</span></div>
          <table>
            <thead><tr><th style="width:26%">Name</th><th style="width:26%">Branch</th><th style="width:16%">Role</th><th style="width:20%">Username</th><th style="width:12%">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($users as $i => $u): ?>
                <?php
                  $userRoleLabel = role_label($u['role']);
                  $userRoleClass = $userRoleLabel === 'Super Admin' ? 'pill-red' : ($userRoleLabel === 'Admin' ? 'pill-blue' : ($userRoleLabel === 'Supervisor' ? 'pill-purple' : ($userRoleLabel === 'Staff' ? 'pill-teal' : 'pill-amber')));
                ?>
                <tr>
                  <td><div class="user-cell"><div class="avatar <?= ['av-purple','av-teal','av-blue'][$i%3] ?>" style="width:28px;height:28px;font-size:11px"><?= e(strtoupper(substr($u['name'],0,2))) ?></div><?= e($u['name']) ?></div></td>
                  <td style="font-size:12px"><?= e(str_replace('RF Chein - ', '', $u['branch_name'] ?: '—')) ?></td>
                  <td><span class="pill <?= $userRoleClass ?>"><?= e($userRoleLabel) ?></span></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e($u['username']) ?></td>
                  <td>
                    <?php if ($u['id'] != $user['id']): ?>
                      <form method="post" action="actions/delete_user.php" onsubmit="return confirm('Delete user <?= e($u['name']) ?>?')" style="display:inline">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><i class="ti ti-trash"></i></button>
                      </form>
                    <?php else: ?>
                      <span style="font-size:11px;color:var(--color-text-tertiary)">you</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- DEVICES -->
      <div class="page" id="pg-devices">
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-device-mobile"></i> Add cellphone / device</div></div>
          <form method="post" action="actions/add_device.php" enctype="multipart/form-data">
            <div class="section-title">Basic information</div>
            <div class="form-grid-3">
              <div class="fg"><label>Brand</label>
                <select name="brand">
                  <option>Samsung</option><option>Apple</option><option>Xiaomi</option>
                  <option>OPPO</option><option>Vivo</option><option>Realme</option>
                  <option>Huawei</option><option>Tecno</option><option>Other</option>
                </select>
              </div>
              <div class="fg"><label>Model name</label><input type="text" name="model" required placeholder="e.g. Galaxy A54, iPhone 12"></div>
              <div class="fg"><label>Series</label><input type="text" name="series" placeholder="e.g. Galaxy, iPhone"></div>
            </div>
            <div class="form-grid-3">
              <div class="fg"><label>Storage</label>
                <select name="storage"><option>32GB</option><option>64GB</option><option>128GB</option><option>256GB</option><option>512GB</option></select>
              </div>
              <div class="fg"><label>RAM</label>
                <select name="ram"><option>2GB</option><option>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option></select>
              </div>
              <div class="fg"><label>Color</label><input type="text" name="color" placeholder="e.g. Midnight Black"></div>
            </div>

            <div class="section-title">Condition &amp; pricing</div>
            <div class="form-grid">
              <div class="fg"><label>Condition</label>
                <select name="condition"><option>Excellent</option><option>Good</option><option>Fair</option><option>Poor</option></select>
              </div>
              <div class="fg"><label>Battery health (%)</label><input type="number" name="battery" min="0" max="100" placeholder="e.g. 87"></div>
            </div>
            <div class="form-grid">
              <div class="fg"><label>Operating system</label>
                <select name="operating_system"><option>Android</option><option>iOS</option><option>HarmonyOS</option><option>Other</option></select>
              </div>
              <div class="fg"><label>Current branch scope</label><input type="text" value="<?= e($selectedBranchName) ?>" disabled></div>
            </div>
            <div class="form-grid-3">
              <div class="fg"><label>Selling price (₱)</label><input type="number" name="selling_price" step="0.01" required placeholder="e.g. 4200"></div>
              <div class="fg"><label>Purchase price (₱)</label><input type="number" name="purchase_price" step="0.01" placeholder="e.g. 3000"></div>
              <div class="fg"><label>Stock quantity</label><input type="number" name="stock" value="1" min="1"></div>
            </div>
            <div class="form-grid">
              <div class="fg"><label>Supplier</label><input type="text" name="supplier" placeholder="e.g. Walk-in seller, trade-in, marketplace"></div>
              <div class="fg"><label>Assign to branch</label>
                <select name="branch_id" required>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $selectedBranchId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="section-title">Device details</div>
            <div class="form-grid">
              <div class="fg"><label>IMEI number</label><input type="text" name="imei" placeholder="15-digit IMEI"></div>
              <div class="fg"><label>Serial number</label><input type="text" name="serial_number" placeholder="Device serial"></div>
            </div>
            <div class="form-grid">
              <div class="fg"><label>Front photo</label><input type="file" name="image_file" accept=".jpg,.jpeg,.png,.gif,.webp,image/*"></div>
              <div class="fg"><label>Back photo</label><input type="file" name="back_image_file" accept=".jpg,.jpeg,.png,.gif,.webp,image/*"></div>
            </div>
            <div class="form-grid">
              <div class="fg"><label>Accessories included</label>
                <select name="accessories">
                  <option>Charger only</option>
                  <option>Charger + earphones</option>
                  <option>Complete (box, charger, earphones)</option>
                  <option>Unit only</option>
                </select>
              </div>
              <div class="fg"><label>Upload note</label><input type="text" value="Optional JPG, PNG, GIF, or WEBP up to 5MB" disabled></div>
            </div>
            <div class="fg" style="margin-bottom:12px"><label>Notes</label><textarea name="notes" placeholder="e.g. Slight crack on back cover, touchscreen 100% functional..."></textarea></div>

            <div style="display:flex;justify-content:flex-end;gap:8px">
              <button class="btn" type="reset"><i class="ti ti-x"></i> Clear</button>
              <button class="btn btn-purple" type="submit"><i class="ti ti-device-mobile-plus"></i> Save device</button>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-list"></i> Recently added devices</div><span style="font-size:12px;color:var(--color-text-secondary)"><?= $ovDevices ?> devices</span></div>
          <table>
            <thead><tr><th style="width:28%">Product</th><th style="width:12%">Storage</th><th style="width:14%">Condition</th><th style="width:10%">Battery</th><th style="width:14%">Price</th><th style="width:12%">Supplier</th><th style="width:10%">Branch</th></tr></thead>
            <tbody>
              <?php foreach ($devices as $d): ?>
                <tr>
                  <td>
                    <div class="product-cell">
                      <?php if (!empty($d['image_url'])): ?>
                        <img src="<?= e($d['image_url']) ?>" alt="<?= e($d['brand'] . ' ' . $d['model']) ?>" class="product-thumb">
                      <?php else: ?>
                        <span class="product-thumb placeholder"><i class="ti ti-device-mobile"></i></span>
                      <?php endif; ?>
                      <div>
                        <div style="font-weight:700"><?= e($d['brand'].' '.$d['model']) ?></div>
                        <div class="page-intro"><?= e(trim(implode(' · ', array_filter([$d['series'] ?? '', $d['color'] ?: '', $d['operating_system'] ?? ''])) ) ?: 'Catalog item') ?></div>
                      </div>
                    </div>
                  </td>
                  <td><?= e($d['storage']) ?></td>
                  <td><span class="pill <?= $d['condition']==='Excellent'?'pill-teal':($d['condition']==='Good'?'pill-blue':($d['condition']==='Fair'?'pill-amber':'pill-red')) ?>"><?= e($d['condition']) ?></span></td>
                  <td><?= (int)$d['battery'] ?>%</td>
                  <td style="font-weight:500"><?= e(peso($d['selling_price'])) ?></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e($d['supplier'] ?: '—') ?></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e(str_replace('RF Chein - ', '', $d['branch_name'] ?? '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ROLES -->
      <div class="page" id="pg-roles">
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-key"></i> Role permissions matrix</div></div>
          <table>
            <thead><tr><th style="width:36%">Permission</th><th style="width:16%">Super Admin</th><th style="width:16%">Admin</th><th style="width:16%">Supervisor</th><th style="width:16%">Staff</th></tr></thead>
            <tbody>
              <?php foreach ($perms as $p):
                $chk = '<i class="ti ti-check" style="color:#1D9E75;font-size:16px"></i>';
                $x   = '<i class="ti ti-minus" style="color:var(--color-text-tertiary);font-size:16px"></i>';
              ?>
                <tr>
                  <td><?= e($p['perm']) ?></td>
                  <td style="text-align:center"><?= $p['sa'] ? $chk : $x ?></td>
                  <td style="text-align:center"><?= $p['ad'] ? $chk : $x ?></td>
                  <td style="text-align:center"><?= $p['sv'] ? $chk : $x ?></td>
                  <td style="text-align:center"><?= $p['st'] ? $chk : $x ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- LOGS -->
      <div class="page" id="pg-logs">
        <div class="card">
          <div class="card-title"><div class="card-title-left"><i class="ti ti-clipboard-list"></i> Activity logs</div></div>
          <table>
            <thead><tr><th style="width:22%">User</th><th style="width:20%">Branch</th><th style="width:38%">Action</th><th style="width:20%">Timestamp</th></tr></thead>
            <tbody>
              <?php foreach ($logs as $l): ?>
                <tr>
                  <td style="font-weight:500"><?= e($l['user_name']) ?></td>
                  <td style="color:var(--color-text-secondary)"><?= e($l['branch_name']) ?></td>
                  <td><?= e($l['action']) ?></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e(date('M j, Y H:i', strtotime($l['created_at']))) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$logs): ?><tr><td colspan="4" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No activity yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Sale Modal (POST form) -->
<div class="modal-overlay" id="sale-modal">
  <form class="modal" method="post" action="actions/record_sale.php">
    <div class="modal-title">
      Record new sale 
      <button type="button" class="btn" onclick="closeModal()" style="padding:4px 8px">
        <i class="ti ti-x"></i>
      </button>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label>Product</label>
        <select name="phone_id" id="sale-product" required>
          <?php foreach ($saleOptions as $o): ?>
            <option value="<?= (int)$o['id'] ?>" data-price="<?= (float)$o['selling_price'] ?>">
              <?= e($o['brand'].' '.$o['model'].' ('.$o['condition'].')') ?> - <?= e($shortBranchName($o['branch_name'] ?? '—')) ?> - <?= e(peso($o['selling_price'])) ?>
            </option>
          <?php endforeach; ?>
          <?php if (!$saleOptions): ?>
            <option value="">No phones in stock</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Customer name</label>
        <input type="text" name="customer" placeholder="e.g. Juan Dela Cruz">
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label>Sale price (₱)</label>
        <input type="number" name="price" id="sale-price" min="0" step="0.01" required>
      </div>
      <div class="form-group">
        <label>Payment method</label>
        <select name="payment_method">
          <option>Cash</option>
          <option>GCash</option>
          <option>Maya</option>
          <option>Bank transfer</option>
        </select>
      </div>
    </div>
    
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:0.5px solid var(--color-border-tertiary)">
      <button type="button" class="btn" onclick="closeModal()">Cancel</button>
      <button type="submit" class="btn btn-primary" <?= $saleOptions ? '' : 'disabled' ?>>
        <i class="ti ti-check"></i> Record sale
      </button>
    </div>
  </form>
</div>

<script>
function nav(page, el){
  document.querySelectorAll('.nav-item[data-page]').forEach(n=>n.classList.remove('active'));
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('pg-'+page).classList.add('active');
  const titles={branches:'Branches',users:'Users',roles:'Roles & access',logs:'Activity'};
  document.getElementById('page-title').textContent=titles[page];
  window.location.hash = page;
}
function openSaleModal(){
  document.getElementById('sale-modal').classList.add('open');
  const sel = document.getElementById('sale-product');
  if (sel && sel.selectedOptions[0]) document.getElementById('sale-price').value = sel.selectedOptions[0].dataset.price || '';
}
function closeModal(){document.getElementById('sale-modal').classList.remove('open')}
document.getElementById('sale-product')?.addEventListener('change', e => {
  const opt = e.target.selectedOptions[0];
  if (opt) document.getElementById('sale-price').value = opt.dataset.price || '';
});
function filterTransferProducts(){
  const source = document.getElementById('transfer-source');
  const product = document.getElementById('transfer-product');
  const destination = document.getElementById('transfer-destination');
  const qty = document.getElementById('transfer-quantity');
  if (!product) return;
  const branchId = source ? source.value : '<?= (int)$selectedBranchId ?>';
  const currentValue = product.value;
  let currentSelectionVisible = !currentValue;
  Array.from(product.options).forEach(opt => {
    if (!opt.value) return;
    const visible = !branchId || opt.dataset.branch === branchId;
    opt.hidden = !visible;
    opt.disabled = !visible;
    if (visible && opt.value === currentValue) currentSelectionVisible = true;
  });
  if (!currentSelectionVisible) {
    product.value = '';
  }

  if (destination) {
    if (destination.value === branchId) {
      destination.value = '';
    }
    Array.from(destination.options).forEach(opt => {
      if (!opt.value) return;
      const allowed = !branchId || opt.value !== branchId;
      opt.hidden = !allowed;
      opt.disabled = !allowed;
    });
  }

  const selected = product.selectedOptions[0];
  if (selected && qty && selected.dataset.stock) {
    qty.max = selected.dataset.stock;
    if (Number(qty.value) > Number(selected.dataset.stock)) qty.value = selected.dataset.stock;
  } else if (qty) {
    qty.max = '';
    qty.value = '1';
  }
}
document.getElementById('transfer-source')?.addEventListener('change', filterTransferProducts);
document.getElementById('transfer-product')?.addEventListener('change', e => {
  const selected = e.target.selectedOptions[0];
  const qty = document.getElementById('transfer-quantity');
  if (selected && qty && selected.dataset.stock) qty.max = selected.dataset.stock;
});
// Close modal when clicking outside
document.getElementById('sale-modal')?.addEventListener('click', e => {
  if (e.target.id === 'sale-modal') closeModal();
});
filterTransferProducts();

const initialPage = window.location.hash ? window.location.hash.slice(1) : 'branches';
const initialNav = document.querySelector('.nav-item[data-page="' + initialPage + '"]');
if (initialNav) nav(initialPage, initialNav);
</script>
</body>
</html>
