<?php
require_once __DIR__ . '/includes/helpers.php';
require_login();

$user = current_user();
if (is_admin_user($user)) {
  redirect('superadmin.php');
}
ensure_branch_assigned($user);

$roleName = role_label($user['role']);
$isSupervisor = is_supervisor_user($user);
$isStaff = is_staff_user($user);

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

$allowedBranches = allowed_branches($db, $user);
$allowedBranchMap = [];
foreach ($allowedBranches as $branchRow) {
  $allowedBranchMap[(int)$branchRow['id']] = $branchRow;
}

$requestedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
if (is_super_admin($user)) {
  $selectedBranchId = $requestedBranchId > 0 && isset($allowedBranchMap[$requestedBranchId]) ? $requestedBranchId : 0;
} else {
  $selectedBranchId = current_branch_id($user) ?? 0;
}

$selectedBranch = $selectedBranchId > 0 ? ($allowedBranchMap[$selectedBranchId] ?? null) : null;
$selectedBranchName = $selectedBranch ? $shortBranchName($selectedBranch['name']) : 'All branches';
$canManageStock = $isSupervisor;
$canViewTransfers = $isSupervisor;
$canViewInquiries = $isSupervisor || $isStaff;
$canViewFlashSales = $isSupervisor;
$canManageTransferStatus = can_manage_transfer_status($user);
$canRecordSales = $isSupervisor || $isStaff;

$branchPhoneSql = $selectedBranchId > 0 ? ' AND p.branch_id = ?' : '';
$branchPhoneParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$branchSalesSql = $selectedBranchId > 0 ? ' AND s.branch_id = ?' : '';
$branchSalesParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$branchDirectPhoneSql = $selectedBranchId > 0 ? ' AND branch_id = ?' : '';
$branchDirectPhoneParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$branchDirectSalesSql = $selectedBranchId > 0 ? ' AND branch_id = ?' : '';
$branchDirectSalesParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];

// --- Metrics -----------------------------------------------------------------
$monthStart = date('Y-m-01');
$todayRevenue = (float)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(price),0) FROM sales WHERE sale_date = CURDATE() AND status='Completed'" . $branchDirectSalesSql,
  $branchDirectSalesParams
);
$todaySales = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM sales WHERE sale_date = CURDATE() AND status='Completed'" . $branchDirectSalesSql,
  $branchDirectSalesParams
);
$weekRevenue = (float)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(price),0) FROM sales WHERE YEARWEEK(sale_date,1) = YEARWEEK(CURDATE(),1) AND status='Completed'" . $branchDirectSalesSql,
  $branchDirectSalesParams
);
$weekSales = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM sales WHERE YEARWEEK(sale_date,1) = YEARWEEK(CURDATE(),1) AND status='Completed'" . $branchDirectSalesSql,
  $branchDirectSalesParams
);
$revenueMonth = (float)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(price),0) FROM sales WHERE sale_date >= ? AND status='Completed'" . $branchDirectSalesSql,
  array_merge([$monthStart], $branchDirectSalesParams)
);

$salesMonth = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM sales WHERE sale_date >= ? AND status='Completed'" . $branchDirectSalesSql,
  array_merge([$monthStart], $branchDirectSalesParams)
);

$unitsInStock = (int)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(stock),0) FROM phones WHERE is_listed=1" . $branchDirectPhoneSql,
  $branchDirectPhoneParams
);
$productsListed = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM phones WHERE is_listed=1" . $branchDirectPhoneSql,
  $branchDirectPhoneParams
);
$lowStockCount = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM phones WHERE is_listed=1 AND stock BETWEEN 1 AND 3" . $branchDirectPhoneSql,
  $branchDirectPhoneParams
);
$newInquiryCount = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM inquiries WHERE status = 'New'" . $branchDirectPhoneSql,
  $branchDirectPhoneParams
);
$openInquiryCount = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM inquiries WHERE status IN ('New','Contacted')" . $branchDirectPhoneSql,
  $branchDirectPhoneParams
);
$inquiriesHref = 'inquiries.php' . ($selectedBranchId > 0 ? '?branch_id=' . $selectedBranchId : '');

// --- Inventory table ---------------------------------------------------------
$inventory = $fetchAllRows($db, "
    SELECT p.*, b.name AS branch_name
    FROM phones p LEFT JOIN branches b ON b.id = p.branch_id
    WHERE p.is_listed = 1
  " . $branchPhoneSql . "
    ORDER BY p.id DESC
", $branchPhoneParams);

// --- Recent sales ------------------------------------------------------------
$sales = $fetchAllRows($db, "
  SELECT s.*, b.name AS branch_name, u.name AS seller_name
  FROM sales s
  LEFT JOIN branches b ON b.id = s.branch_id
  LEFT JOIN users u ON u.id = s.user_id
  WHERE 1=1
  " . $branchSalesSql . "
    ORDER BY sale_date DESC, id DESC
    LIMIT 20
", $branchSalesParams);

// --- Brand breakdown (units sold this month) --------------------------------
$stmt = $db->prepare("
    SELECT p.brand AS brand, COUNT(*) AS units, COALESCE(SUM(s.price),0) AS revenue
    FROM sales s LEFT JOIN phones p ON p.id = s.phone_id
    WHERE s.sale_date >= ? AND s.status='Completed'
  " . $branchSalesSql . "
    GROUP BY p.brand
    ORDER BY units DESC
");
$stmt->execute(array_merge([$monthStart], $branchSalesParams));
$brandStats = $stmt->fetchAll();
$brandTotal = max(1, array_sum(array_column($brandStats, 'units')));

// --- Condition breakdown -----------------------------------------------------
$condStats = $fetchAllRows($db, "
    SELECT `condition`, COUNT(*) AS cnt, COALESCE(SUM(stock*selling_price),0) AS value
  FROM phones p WHERE p.is_listed=1
  " . $branchPhoneSql . "
    GROUP BY `condition`
", $branchPhoneParams);
$condTotal = max(1, array_sum(array_column($condStats, 'cnt')));

// --- Product performance -----------------------------------------------------
$perf = $fetchAllRows($db, "
    SELECT s.product_name AS name,
           COUNT(*) AS sold,
           SUM(s.price) AS rev,
           AVG(s.price) AS avg_price
  FROM sales s WHERE s.status='Completed'
  " . $branchSalesSql . "
    GROUP BY s.product_name
    ORDER BY sold DESC
    LIMIT 8
", $branchSalesParams);

// --- Analytics: sell-through rate (all-time) --------------------------------
$stSold = (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM sales WHERE status='Completed'" . $branchDirectSalesSql,
  $branchDirectSalesParams
);
$stRemaining = (int)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(stock),0) FROM phones WHERE is_listed=1" . $branchDirectPhoneSql,
  $branchDirectPhoneParams
);
$stDen       = $stSold + $stRemaining;
$stPct       = $stDen > 0 ? (int)round(($stSold / $stDen) * 100) : 0;

// --- Analytics: monthly revenue trend (last 6 months) -----------------------
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i month"));
    $months[$ym] = 0.0;
}
$mr = $fetchAllRows($db, "
    SELECT DATE_FORMAT(sale_date,'%Y-%m') AS ym, SUM(price) AS rev
  FROM sales WHERE status='Completed'
  " . $branchDirectSalesSql . "
    GROUP BY ym
", $branchDirectSalesParams);
foreach ($mr as $row) {
    if (isset($months[$row['ym']])) $months[$row['ym']] = (float)$row['rev'];
}
$maxMonthRev = max(1.0, max($months));

// --- Analytics: sales by condition ------------------------------------------
$condSales = $fetchAllRows($db, "
    SELECT p.`condition` AS cond, COUNT(*) AS units, COALESCE(SUM(s.price),0) AS rev
    FROM sales s LEFT JOIN phones p ON p.id = s.phone_id
    WHERE s.status='Completed'
  " . $branchSalesSql . "
    GROUP BY p.`condition`
    ORDER BY FIELD(p.`condition`,'Excellent','Good','Fair','Poor')
", $branchSalesParams);
$maxCondRev = 1.0;
foreach ($condSales as $c) { if ((float)$c['rev'] > $maxCondRev) $maxCondRev = (float)$c['rev']; }

// --- Analytics: remaining stock per product name (for sell-through column) --
$stockMap = [];
foreach ($fetchAllRows($db, "SELECT CONCAT(brand,' ',model) AS n, SUM(stock) AS rem FROM phones WHERE is_listed=1" . $branchDirectPhoneSql . " GROUP BY CONCAT(brand,' ',model)", $branchDirectPhoneParams) as $r) {
    $stockMap[$r['n']] = (int)$r['rem'];
}
foreach ($perf as &$p) {
    $rem   = $stockMap[$p['name']] ?? 0;
    $den   = (int)$p['sold'] + $rem;
    $p['sell_through'] = $den > 0 ? (int)round(((int)$p['sold'] / $den) * 100) : 0;
}
unset($p);

// Products available for sale form (in-stock only)
$saleOptions = $fetchAllRows($db, "
  SELECT p.id, p.brand, p.model, p.`condition`, p.selling_price, p.branch_id, b.name AS branch_name
  FROM phones p
  LEFT JOIN branches b ON b.id = p.branch_id
  WHERE p.is_listed=1 AND p.stock > 0
  " . $branchPhoneSql . "
  ORDER BY b.name, p.brand, p.model
", $branchPhoneParams);

$activeBranches = $db->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY name")->fetchAll();

$branchProfile = null;
if ($selectedBranchId > 0) {
  $branchProfile = $fetchRow($db, "
    SELECT b.*,
         (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) AS users_count,
         (SELECT COUNT(*) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1) AS products_count,
         (SELECT COALESCE(SUM(stock),0) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1) AS units_count,
         (SELECT COALESCE(SUM(price),0) FROM sales s WHERE s.branch_id = b.id AND s.status='Completed') AS revenue_total,
         (SELECT COUNT(*) FROM stock_transfers t WHERE t.source_branch_id = b.id OR t.destination_branch_id = b.id) AS transfers_count
    FROM branches b
    WHERE b.id = ?
  ", [$selectedBranchId]);
}

$flash = flash_get('dashboard');

// --- Transfers ----------------------------------------------------------------
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
  $statusKey = $transferRow['status'];
  if (isset($transferStatusCounts[$statusKey])) {
    $transferStatusCounts[$statusKey]++;
  }
}
$pendingTransferCount = $transferStatusCounts['Pending'];
$transferRequestCount = count($transfers);

$transferInventoryOptions = $fetchAllRows($db, "
  SELECT p.id, p.branch_id, p.brand, p.model, p.stock, p.imei, b.name AS branch_name
  FROM phones p
  LEFT JOIN branches b ON b.id = p.branch_id
  WHERE p.is_listed = 1 AND p.stock > 0
  " . $branchPhoneSql . "
  ORDER BY b.name, p.brand, p.model
", $branchPhoneParams);

$movementLogs = $fetchAllRows($db, "
  SELECT il.*, CONCAT(p.brand, ' ', p.model) AS product_name, b.name AS branch_name
  FROM inventory_logs il
  LEFT JOIN phones p ON p.id = il.phone_id
  LEFT JOIN branches b ON b.id = il.branch_id
  WHERE 1=1
  " . ($selectedBranchId > 0 ? ' AND il.branch_id = ?' : '') . "
  ORDER BY il.created_at DESC, il.id DESC
  LIMIT 12
", $selectedBranchId > 0 ? [$selectedBranchId] : []);
$movementMonitor = $fetchAllRows($db, "
  SELECT p.brand, p.model, p.series, p.storage, p.ram, p.color, p.stock,
         COALESCE((SELECT COUNT(*) FROM sales s WHERE s.phone_id = p.id AND s.status = 'Completed' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)),0) AS sold_30d
  FROM phones p
  WHERE p.is_listed = 1
  " . $branchDirectPhoneSql . "
  ORDER BY sold_30d DESC, p.stock ASC, p.brand ASC, p.model ASC
  LIMIT 12
", $branchDirectPhoneParams);
$inventoryValue = (float)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(stock * selling_price),0) FROM phones WHERE is_listed=1" . $branchDirectPhoneSql,
  $branchDirectPhoneParams
);

// --- Decision Support System --------------------------------------------------
$velMap = [];
foreach ($fetchAllRows($db, "SELECT phone_id, COUNT(*) AS sold_n, MAX(sale_date) AS last_sale FROM sales WHERE status='Completed' AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $branchDirectSalesSql . " GROUP BY phone_id", $branchDirectSalesParams) as $v) {
    $velMap[(int)$v['phone_id']] = ['sold_n'=>(int)$v['sold_n'],'last'=>$v['last_sale']];
}
$lastSaleAll = [];
foreach ($fetchAllRows($db, "SELECT phone_id, MAX(sale_date) AS last_sale FROM sales WHERE status='Completed'" . $branchDirectSalesSql . " GROUP BY phone_id", $branchDirectSalesParams) as $v) {
    $lastSaleAll[(int)$v['phone_id']] = $v['last_sale'];
}
$reorderList = []; $deadStock = []; $fastMovers = []; $tiedCapital = 0.0;
$today = new DateTime('today');
foreach ($inventory as $it) {
    $pid  = (int)$it['id'];
    $name = $it['brand'].' '.$it['model'];
    $stk  = (int)$it['stock'];
    $v    = $velMap[$pid] ?? null;
    $last = $lastSaleAll[$pid] ?? null;
    $days = $last ? (int)$today->diff(new DateTime($last))->days : 9999;
    if ($stk <= 3 && $v && $v['sold_n'] > 0) {
        $suggest = max(5, (int)ceil(($v['sold_n']/4.0)*4));
        $reorderList[] = ['name'=>$name,'branch'=>$it['branch_name']??'—','stock'=>$stk,'vel'=>$v['sold_n'],'suggest'=>$suggest,'priority'=>$stk===0?'critical':($stk<=1?'high':'medium')];
    }
    if ($stk > 0 && $days >= 30) {
        $unitCost = (float)($it['purchase_price'] ?: (float)$it['selling_price']*0.7);
        $cap = $stk * $unitCost; $tiedCapital += $cap;
        $deadStock[] = ['name'=>$name,'branch'=>$it['branch_name']??'—','stock'=>$stk,'days'=>$days>=9999?null:$days,'tied'=>$cap,'action'=>$days>=90?'Discount 20% or return':($days>=60?'Discount 10% or bundle':'Promote / feature')];
    }
    if ($v && $v['sold_n'] >= 1) $fastMovers[] = ['name'=>$name,'sold'=>$v['sold_n'],'stock'=>$stk];
}
usort($reorderList, function($a,$b){ $o=['critical'=>0,'high'=>1,'medium'=>2]; return ($o[$a['priority']]<=>$o[$b['priority']]) ?: ($b['vel']<=>$a['vel']); });
usort($deadStock,  function($a,$b){ return ($b['days']??9999) <=> ($a['days']??9999); });
usort($fastMovers, function($a,$b){ return $b['sold'] <=> $a['sold']; });
$fastMovers = array_slice($fastMovers, 0, 5);

$abcRows = $fetchAllRows($db, "
  SELECT p.id,
       CONCAT(p.brand,' ',p.model) AS name,
       COUNT(s.id) AS sold,
       COALESCE(SUM(s.price),0) AS revenue
  FROM phones p
  LEFT JOIN sales s ON s.phone_id = p.id AND s.status='Completed'" . ($selectedBranchId > 0 ? ' AND s.branch_id = ?' : '') . "
  WHERE p.is_listed=1" . ($selectedBranchId > 0 ? ' AND p.branch_id = ?' : '') . "
  GROUP BY p.id
  ORDER BY revenue DESC
", $selectedBranchId > 0 ? [$selectedBranchId, $selectedBranchId] : []);
$totalRev = array_sum(array_column($abcRows, 'revenue'));
$cumRev = 0.0;
foreach ($abcRows as &$r) {
    $cumRev += (float)$r['revenue'];
    $pct = $totalRev > 0 ? ($cumRev / $totalRev) : 1;
    if ($r['revenue'] == 0) $r['class'] = 'C';
    elseif ($pct <= 0.7)    $r['class'] = 'A';
    elseif ($pct <= 0.9)    $r['class'] = 'B';
    else                    $r['class'] = 'C';
}
unset($r);
$abcCount = ['A'=>0,'B'=>0,'C'=>0];
foreach ($abcRows as $r) { $abcCount[$r['class']]++; }

$marginRows = [];
foreach ($inventory as $it) {
    if (!$it['purchase_price'] || (float)$it['selling_price'] <= 0) continue;
    $m = ((float)$it['selling_price'] - (float)$it['purchase_price']) / (float)$it['selling_price'] * 100;
    $marginRows[] = ['name'=>$it['brand'].' '.$it['model'],'sell'=>(float)$it['selling_price'],'cost'=>(float)$it['purchase_price'],'margin'=>$m,'stock'=>(int)$it['stock']];
}
$lowMargin  = array_values(array_filter($marginRows, function($m){ return $m['margin'] < 15; }));
$highMargin = array_values(array_filter($marginRows, function($m){ return $m['margin'] >= 40; }));
usort($lowMargin,  function($a,$b){ return $a['margin'] <=> $b['margin']; });
usort($highMargin, function($a,$b){ return $b['margin'] <=> $a['margin']; });
$lowMargin  = array_slice($lowMargin, 0, 5);
$highMargin = array_slice($highMargin, 0, 5);

$userSalesScopeSql = $selectedBranchId > 0 ? ' AND branch_id = ?' : '';
$userSalesScopeParams = $selectedBranchId > 0 ? [$selectedBranchId] : [];
$userSalesTodayCount = $canRecordSales ? (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM sales WHERE status='Completed' AND user_id = ? AND sale_date = CURDATE()" . $userSalesScopeSql,
  array_merge([(int)$user['id']], $userSalesScopeParams)
) : 0;
$userRevenueToday = $canRecordSales ? (float)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(price),0) FROM sales WHERE status='Completed' AND user_id = ? AND sale_date = CURDATE()" . $userSalesScopeSql,
  array_merge([(int)$user['id']], $userSalesScopeParams)
) : 0.0;
$userSalesMonthCount = $canRecordSales ? (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM sales WHERE status='Completed' AND user_id = ? AND sale_date >= ?" . $userSalesScopeSql,
  array_merge([(int)$user['id'], $monthStart], $userSalesScopeParams)
) : 0;
$userRevenueMonth = $canRecordSales ? (float)$fetchScalar(
  $db,
  "SELECT COALESCE(SUM(price),0) FROM sales WHERE status='Completed' AND user_id = ? AND sale_date >= ?" . $userSalesScopeSql,
  array_merge([(int)$user['id'], $monthStart], $userSalesScopeParams)
) : 0.0;
$userRevenueSharePct = $revenueMonth > 0 ? (int)round(($userRevenueMonth / $revenueMonth) * 100) : 0;
$personalInquiryQueue = $canViewInquiries ? (int)$fetchScalar(
  $db,
  "SELECT COUNT(*) FROM inquiries WHERE status IN ('New','Contacted') AND (assigned_user_id = ? OR assigned_user_id IS NULL)" . ($selectedBranchId > 0 ? ' AND branch_id = ?' : ''),
  array_merge([(int)$user['id']], $selectedBranchId > 0 ? [$selectedBranchId] : [])
) : 0;
$monthSeries = array_values($months);
$currentMonthRevenue = (float)($monthSeries ? $monthSeries[count($monthSeries) - 1] : 0);
$previousMonthRevenue = (float)(count($monthSeries) > 1 ? $monthSeries[count($monthSeries) - 2] : 0);
if ($previousMonthRevenue > 0) {
  $branchRevenueMomentumPercent = (int)round((($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100);
  if ($branchRevenueMomentumPercent > 0) {
    $branchRevenueMomentumText = 'Month-to-date revenue is up ' . abs($branchRevenueMomentumPercent) . '% versus last month.';
  } elseif ($branchRevenueMomentumPercent < 0) {
    $branchRevenueMomentumText = 'Month-to-date revenue is down ' . abs($branchRevenueMomentumPercent) . '% versus last month.';
  } else {
    $branchRevenueMomentumText = 'Month-to-date revenue is flat versus last month.';
  }
} elseif ($currentMonthRevenue > 0) {
  $branchRevenueMomentumText = 'Revenue is active this month, but there is no prior-month baseline in the six-month trend yet.';
} else {
  $branchRevenueMomentumText = 'No completed branch revenue is visible in the latest month of the trend yet.';
}

if ($isSupervisor) {
  $serviceBacklogCopy = ($openInquiryCount + $pendingTransferCount) > 0
    ? $openInquiryCount . ' open inquir' . ($openInquiryCount === 1 ? 'y' : 'ies') . ' and ' . $pendingTransferCount . ' pending transfer' . ($pendingTransferCount === 1 ? '' : 's') . ' are waiting in the branch workflow.'
    : 'Customer messages and transfer requests are both clear right now.';
  $roleOverviewLead = $selectedBranchName . ' generated ' . peso($weekRevenue) . ' from ' . $weekSales . ' completed sale' . ($weekSales === 1 ? '' : 's') . ' this week. ' . ($lowStockCount > 0
    ? $lowStockCount . ' listed item' . ($lowStockCount === 1 ? ' is' : 's are') . ' already low on stock, so replenishment belongs in todays decisions.'
    : 'Stock levels are stable enough to focus on growth, customer follow-up, and transfer cleanup.');
  $overviewInsightCards = [
    [
      'tone' => $currentMonthRevenue >= $previousMonthRevenue ? 'success' : 'warn',
      'icon' => 'chart-bar',
      'title' => 'Revenue momentum',
      'body' => $branchRevenueMomentumText . ' Today delivered ' . peso($todayRevenue) . ' from ' . $todaySales . ' sale' . ($todaySales === 1 ? '' : 's') . '.',
    ],
    [
      'tone' => $lowStockCount > 0 ? 'warn' : 'success',
      'icon' => 'package',
      'title' => 'Inventory pressure',
      'body' => $lowStockCount > 0
        ? $lowStockCount . ' SKU' . ($lowStockCount === 1 ? ' is' : 's are') . ' low on stock and ' . count($reorderList) . ' fast mover' . (count($reorderList) === 1 ? '' : 's') . ' already signal replenishment.'
        : 'No immediate stock shortage is visible in the branch today.',
    ],
    [
      'tone' => ($openInquiryCount + $pendingTransferCount) > 0 ? 'info' : 'success',
      'icon' => 'messages',
      'title' => 'Customer and transfer queue',
      'body' => $serviceBacklogCopy,
    ],
  ];
  $overviewQuickActions = [];
  if ($openInquiryCount > 0) {
    $overviewQuickActions[] = [
      'title' => 'Reply to branch inquiries',
      'note' => 'Keep open customer conversations moving before leads go cold.',
      'href' => $inquiriesHref,
      'cta' => 'Open inquiries',
      'primary' => true,
    ];
  }
  if ($pendingTransferCount > 0) {
    $overviewQuickActions[] = [
      'title' => 'Monitor transfers',
      'note' => 'Review requests that still block stock rebalancing between branches.',
      'page' => 'transfers',
      'cta' => 'Open transfers',
      'primary' => true,
    ];
  }
  $overviewQuickActions[] = [
    'title' => 'Print monthly branch report',
    'note' => 'Generate the ready-to-print revenue and inventory report with the SmartStock logo.',
    'js' => 'printBranchReport()',
    'cta' => 'Print report',
    'primary' => false,
  ];
  $overviewQuickActions[] = [
    'title' => 'Open analytics',
    'note' => 'Review trend, sell-through, and the printable monthly summary in one place.',
    'page' => 'analytics',
    'cta' => 'Analytics',
    'primary' => false,
  ];
  $overviewQuickActions[] = [
    'title' => 'Open decision support',
    'note' => 'Go straight to reorder guidance, movement monitoring, and slow-stock decisions.',
    'page' => 'decisions',
    'cta' => 'Decision support',
    'primary' => false,
  ];
  if ($canViewFlashSales) {
    $overviewQuickActions[] = [
      'title' => 'Manage flash sales',
      'note' => 'Create or review branch promotions and approval status from the promo board.',
      'href' => 'flash_sales.php',
      'cta' => 'Flash sales',
      'primary' => false,
    ];
  }
  $overviewPriorityItems = [];
  if ($openInquiryCount > 0) {
    $overviewPriorityItems[] = [
      'level' => 'critical',
      'icon' => 'messages',
      'title' => 'Reply to ' . $openInquiryCount . ' open inquir' . ($openInquiryCount === 1 ? 'y' : 'ies'),
      'note' => 'These are current customer demand signals and deserve same-day response.',
      'href' => $inquiriesHref,
      'cta' => 'Open inquiries',
    ];
  }
  if ($lowStockCount > 0) {
    $topReorder = $reorderList[0] ?? null;
    $overviewPriorityItems[] = [
      'level' => 'high',
      'icon' => 'package',
      'title' => 'Replenish ' . $lowStockCount . ' low-stock SKU' . ($lowStockCount === 1 ? '' : 's'),
      'note' => $topReorder
        ? $topReorder['name'] . ' is the strongest replenishment signal in the branch right now.'
        : 'Low-stock items are already reducing branch availability.',
      'page' => 'decisions',
      'cta' => 'Review stock',
    ];
  }
  if ($pendingTransferCount > 0) {
    $overviewPriorityItems[] = [
      'level' => 'high',
      'icon' => 'arrows-transfer-up-down',
      'title' => 'Track ' . $pendingTransferCount . ' pending transfer' . ($pendingTransferCount === 1 ? '' : 's'),
      'note' => 'Transfers are part of the branch stock plan and should not sit idle in the queue.',
      'page' => 'transfers',
      'cta' => 'Open transfers',
    ];
  }
  if (count($deadStock) > 0) {
    $oldestDeadStock = $deadStock[0];
    $overviewPriorityItems[] = [
      'level' => 'medium',
      'icon' => 'clock-hour-4',
      'title' => 'Move ' . count($deadStock) . ' slow-moving item' . (count($deadStock) === 1 ? '' : 's'),
      'note' => $oldestDeadStock['name'] . ' has been idle the longest and may need markdown, bundle, or feature placement.',
      'page' => 'decisions',
      'cta' => 'Open decisions',
    ];
  }
} else {
  $roleOverviewLead = $userSalesMonthCount > 0
    ? 'You have recorded ' . $userSalesMonthCount . ' completed sale' . ($userSalesMonthCount === 1 ? '' : 's') . ' worth ' . peso($userRevenueMonth) . ' this month, which is ' . $userRevenueSharePct . '% of branch revenue. Use the shortcuts below to keep sales and customer replies moving without leaving the dashboard.'
    : 'You have a clean personal sales slate this month. Use this dashboard to record the next completed sale quickly and see which customer conversations need attention right now.';
  $staffQueueCopy = $personalInquiryQueue > 0
    ? $personalInquiryQueue . ' inquiry thread' . ($personalInquiryQueue === 1 ? ' is' : 's are') . ' in the queue you can handle today.'
    : 'Your inquiry queue is clear right now, so you can focus on closing the next sale.';
  $overviewInsightCards = [
    [
      'tone' => $userSalesTodayCount > 0 ? 'success' : 'info',
      'icon' => 'receipt',
      'title' => 'Your sales today',
      'body' => $userSalesTodayCount > 0
        ? $userSalesTodayCount . ' completed sale' . ($userSalesTodayCount === 1 ? '' : 's') . ' worth ' . peso($userRevenueToday) . ' are already under your name today.'
        : 'No completed sale is under your name yet today.',
    ],
    [
      'tone' => $userSalesMonthCount > 0 ? 'success' : 'info',
      'icon' => 'chart-bar',
      'title' => 'Monthly contribution',
      'body' => $userSalesMonthCount > 0
        ? 'You account for ' . $userRevenueSharePct . '% of branch revenue this month.'
        : 'Branch month-to-date revenue is ' . peso($revenueMonth) . '; your next recorded sale will move that number immediately.',
    ],
    [
      'tone' => $personalInquiryQueue > 0 ? 'warn' : 'success',
      'icon' => 'messages',
      'title' => 'Customer queue',
      'body' => $staffQueueCopy,
    ],
  ];
  $overviewQuickActions = [
    [
      'title' => 'Record the next sale',
      'note' => 'Open the quick sales form without leaving the dashboard.',
      'js' => 'openSaleModal()',
      'cta' => 'New sale',
      'primary' => true,
    ],
    [
      'title' => 'Reply to inquiries',
      'note' => 'Check the branch inbox and answer customers before the lead goes cold.',
      'href' => $inquiriesHref,
      'cta' => 'Open inquiries',
      'primary' => $personalInquiryQueue > 0,
    ],
    [
      'title' => 'Review sales history',
      'note' => 'See recent receipts, customer names, and completed transactions.',
      'page' => 'sales',
      'cta' => 'Open sales',
      'primary' => false,
    ],
  ];
  $overviewPriorityItems = [];
  if ($personalInquiryQueue > 0) {
    $overviewPriorityItems[] = [
      'level' => 'high',
      'icon' => 'messages',
      'title' => 'Reply to ' . $personalInquiryQueue . ' inquiry thread' . ($personalInquiryQueue === 1 ? '' : 's'),
      'note' => 'Customers are waiting for branch answers on price, availability, or reservation.',
      'href' => $inquiriesHref,
      'cta' => 'Open inquiries',
    ];
  }
  if ($userSalesTodayCount === 0 && $canRecordSales) {
    $overviewPriorityItems[] = [
      'level' => 'medium',
      'icon' => 'receipt',
      'title' => 'Start with the next walk-in sale',
      'note' => 'No completed sale is under your name yet today, so open the quick sale form when the next device is closed.',
      'js' => 'openSaleModal()',
      'cta' => 'New sale',
    ];
  }
  if ($lowStockCount > 0) {
    $overviewPriorityItems[] = [
      'level' => 'medium',
      'icon' => 'package',
      'title' => 'Flag ' . $lowStockCount . ' low-stock item' . ($lowStockCount === 1 ? '' : 's') . ' to the supervisor',
      'note' => 'Customers may ask for devices that are almost gone, so set expectations early and escalate shortages fast.',
    ];
  }
}

$overviewQuickActions = array_slice($overviewQuickActions, 0, 4);
if (!$overviewPriorityItems) {
  $overviewPriorityItems[] = [
    'level' => 'medium',
    'icon' => 'circle-check',
    'title' => 'Your immediate queue is clear',
    'note' => $isSupervisor
      ? 'Use Analytics to monitor momentum and Decision Support to plan the next branch move.'
      : 'Use the Sales and Inquiries shortcuts to stay ready for the next customer interaction.',
    'page' => $isSupervisor ? 'analytics' : 'sales',
    'cta' => $isSupervisor ? 'Open analytics' : 'Open sales',
  ];
}
$overviewPriorityItems = array_slice($overviewPriorityItems, 0, 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartStock — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.3.0/dist/tabler-icons.min.css">
<style>
  :root {
    --font-sans: 'Plus Jakarta Sans', sans-serif;
    --font-display: 'Sora', sans-serif;
    --color-background-primary: rgba(255,255,255,0.92);
    --color-background-secondary: #f0f1ed;
    --color-background-tertiary: #ebeae4;
    --color-background-success: #E1F5EE;
    --color-background-danger:  #FCEBEB;
    --color-border-secondary:   #d6d7cf;
    --color-border-tertiary:    #e3e2da;
    --color-border-danger:      #F2B4B4;
    --color-text-primary:       #16181d;
    --color-text-secondary:     #5b6057;
    --color-text-tertiary:      #82877d;
    --color-text-success:       #0F6E56;
    --color-text-danger:        #A32D2D;
    --border-radius-md: 12px;
    --border-radius-lg: 20px;
  }
  html,body{margin:0;padding:0;background:var(--color-background-tertiary)}
  .sr-only{position:absolute;left:-9999px}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:var(--font-sans);font-size:14px;color:var(--color-text-primary);background:radial-gradient(circle at top left,#dff2e7 0,#f7f4ec 32%,#ebeae4 100%)}
  .app{display:flex;min-height:100vh}
  .sidebar{width:228px;flex-shrink:0;padding:22px 0;background:linear-gradient(180deg,#101318 0,#171d24 100%);color:#cfd3dc;box-shadow:24px 0 60px -44px rgba(15,23,42,.8)}
  .sidebar-logo{padding:0 18px 18px;font-size:22px;font-family:var(--font-display);font-weight:700;letter-spacing:-.04em;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:10px;color:#ffffff}
  .sidebar-logo span{color:#1D9E75}
  .sidebar-user{padding:10px 18px 14px;font-size:12px;color:#94a3b8}
  .sidebar-user strong{color:#ffffff;display:block;font-size:15px;font-weight:700}
  .role-chip{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:6px 10px;border-radius:999px;background:rgba(20,184,166,.16);color:#99f6e4;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
  .nav-item{display:flex;align-items:center;gap:8px;padding:11px 18px;cursor:pointer;color:#9ca3af;font-size:13px;transition:background .15s,color .15s;text-decoration:none;border-right:2px solid transparent;border-radius:14px 0 0 14px;margin-left:10px}
  .nav-item:hover{background:rgba(255,255,255,.04);color:#ffffff}
  .nav-item.active{background:rgba(29,158,117,.16);color:#c8fff1;font-weight:700;border-right-color:#34d399}
  .nav-item i{font-size:16px}
  /* Mini bar-chart for Analytics */
  .mini-chart{display:flex;align-items:flex-end;gap:10px;height:150px;padding:6px 0 0}
  .mini-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%}
  .mini-bar-wrap{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center}
  .mini-bar{width:100%;max-width:34px;background:linear-gradient(180deg,#35c490,#1D9E75);border-radius:6px 6px 2px 2px;min-height:4px;transition:height .6s ease}
  .mini-label{font-size:11px;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.04em}
  .st-cell{display:flex;align-items:center;gap:8px;min-width:110px}
  .st-cell .bar-track{flex:1;max-width:70px;height:6px}
  .main{flex:1;display:flex;flex-direction:column}
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid var(--color-border-tertiary);background:rgba(255,255,255,.78);backdrop-filter:blur(16px)}
  .topbar h1{font-size:28px;font-weight:700;font-family:var(--font-display);letter-spacing:-.04em}
  .topbar-actions{display:flex;gap:8px;align-items:center}
  .badge{font-size:11px;padding:5px 10px;border-radius:20px;font-weight:700;background:var(--color-background-success);color:var(--color-text-success);text-transform:uppercase;letter-spacing:.04em}
  .notif-link{position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:999px;border:0.5px solid var(--color-border-secondary);background:var(--color-background-primary);color:var(--color-text-primary);text-decoration:none}
  .notif-link.has-items{background:#EEF6F2;border-color:#B6E4D3;color:#0F6E56}
  .notif-count{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#E05151;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  .content{padding:24px;flex:1;overflow-y:auto}
  .page{display:none}.page.active{display:block}
  .metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
  .metric-card{background:var(--color-background-primary);border:1px solid var(--color-border-tertiary);border-radius:var(--border-radius-lg);padding:16px 18px;box-shadow:0 24px 60px -44px rgba(15,23,42,.28)}
  .metric-label{font-size:11px;color:var(--color-text-secondary);margin-bottom:8px;display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.08em;font-weight:800}
  .metric-value{font-size:28px;font-weight:700}
  .metric-change{font-size:11px;margin-top:4px}
  .metric-change.up{color:var(--color-text-success)}
  .metric-change.down{color:var(--color-text-danger)}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
  .card{background:var(--color-background-primary);border:1px solid var(--color-border-tertiary);border-radius:var(--border-radius-lg);padding:18px;box-shadow:0 24px 60px -46px rgba(15,23,42,.22)}
  .card-title{font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:6px}
  .card-title i{color:#1D9E75}
  .bar-group{margin-bottom:10px}
  .bar-label{display:flex;justify-content:space-between;font-size:12px;color:var(--color-text-secondary);margin-bottom:4px}
  .bar-track{height:8px;background:var(--color-background-secondary);border-radius:4px;overflow:hidden}
  .bar-fill{height:100%;border-radius:4px;transition:width .6s ease}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{text-align:left;padding:8px 10px;font-size:11px;font-weight:500;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:0.5px solid var(--color-border-tertiary)}
  td{padding:10px;border-bottom:0.5px solid var(--color-border-tertiary)}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:var(--color-background-secondary)}
  .status-pill{font-size:11px;padding:3px 8px;border-radius:20px;font-weight:500;display:inline-block}
  .status-pill.in-stock{background:#E1F5EE;color:#0F6E56}
  .status-pill.low{background:#FAEEDA;color:#854F0B}
  .status-pill.out{background:#FCEBEB;color:#A32D2D}
  .btn{padding:9px 14px;font-size:13px;border:1px solid var(--color-border-secondary);border-radius:var(--border-radius-md);cursor:pointer;background:var(--color-background-primary);color:var(--color-text-primary);display:inline-flex;align-items:center;gap:6px;transition:background .15s,text-decoration:none}
  .btn:hover{background:var(--color-background-secondary)}
  .btn-primary{background:#0F766E;color:white;border-color:#0F766E}
  .btn-primary:hover{background:#0b5d57}
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100;align-items:center;justify-content:center}
  .modal-overlay.open{display:flex}
  .modal{background:var(--color-background-primary);border-radius:var(--border-radius-lg);border:0.5px solid var(--color-border-tertiary);padding:24px;width:500px;max-width:92%;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04)}
  .modal-title{font-size:16px;font-weight:600;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:0.5px solid var(--color-border-tertiary)}
  .modal .form-row{display:grid !important;grid-template-columns:1fr 1fr !important;gap:16px !important;margin-bottom:16px !important}
  .modal .form-group{display:flex !important;flex-direction:column !important;gap:6px !important}
  .modal .form-group label{font-size:12px !important;font-weight:500 !important;color:var(--color-text-secondary) !important;text-transform:uppercase !important;letter-spacing:.03em !important;display:block !important;margin-bottom:4px !important}
  .modal .form-group input,.modal .form-group select{padding:10px 12px !important;border:0.5px solid var(--color-border-secondary) !important;border-radius:var(--border-radius-md) !important;font-size:13px !important;background:var(--color-background-primary) !important;color:var(--color-text-primary) !important;transition:border-color .15s,box-shadow .15s !important;width:100% !important;box-sizing:border-box !important}
  .modal .form-group input:focus,.modal .form-group select:focus{outline:none !important;border-color:#1D9E75 !important;box-shadow:0 0 0 3px rgba(29,158,117,.1) !important}
  .donut-wrap{display:flex;align-items:center;gap:20px}
  .legend-item{display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:6px}
  .legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
  .chart-canvas{width:100%;height:140px;display:block}
  .alert-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--border-radius-md);margin-bottom:8px;font-size:13px}
  .alert-row.warn{background:#FAEEDA;color:#854F0B}
  .alert-row.danger{background:#FCEBEB;color:#A32D2D}
  .flash{margin:0 20px 12px;padding:10px 14px;background:#E1F5EE;color:#0F6E56;border-radius:var(--border-radius-md);font-size:13px;border:0.5px solid #B6E4D3}
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
  .btn-sm{padding:5px 10px;font-size:12px}
  .btn-danger{background:#FCEBEB;color:#A32D2D;border-color:#F2B4B4}
  .branch-switcher{display:flex;align-items:center;gap:8px}
  .branch-switcher form{display:flex;align-items:center;gap:8px}
  .branch-switcher select{padding:8px 12px;border:0.5px solid var(--color-border-secondary);border-radius:var(--border-radius-md);background:var(--color-background-primary);color:var(--color-text-primary);font-size:13px}
  .branch-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:#EEF6F2;color:#0F6E56;font-size:12px;font-weight:500}
  .page-intro{font-size:13px;color:var(--color-text-secondary);line-height:1.6}
  .topbar-note{font-size:12px;color:var(--color-text-secondary)}
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
  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
  .form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px}
  .fg{display:flex;flex-direction:column;gap:4px}
  .fg label{font-size:12px;color:var(--color-text-secondary)}
  .fg input,.fg select,textarea{padding:10px 12px;border:0.5px solid var(--color-border-secondary);border-radius:var(--border-radius-md);font-size:13px;background:var(--color-background-primary);color:var(--color-text-primary);font-family:inherit}
  .fg input:focus,.fg select:focus,textarea:focus{outline:none;border-color:#1D9E75;box-shadow:0 0 0 3px rgba(29,158,117,.1)}
  .product-cell{display:flex;align-items:center;gap:12px}
  .product-thumb{width:48px;height:48px;border-radius:14px;object-fit:cover;border:1px solid var(--color-border-tertiary);background:#f7f7f2;flex-shrink:0}
  .product-thumb.placeholder{display:inline-flex;align-items:center;justify-content:center;color:var(--color-text-tertiary);font-size:18px}
  .product-cell strong{display:block;font-size:13px}
  textarea{min-height:92px;resize:vertical}
  @media (max-width: 1100px){.metrics,.dss-grid,.profile-grid,.abc-summary,.insight-grid{grid-template-columns:repeat(2,1fr)}.grid2,.form-grid,.form-grid-3,.command-grid{grid-template-columns:1fr}}
  @media (max-width: 760px){.app{display:block}.sidebar{width:auto}.topbar{flex-direction:column;align-items:flex-start;gap:10px}.topbar-actions{flex-wrap:wrap}.metrics,.dss-grid,.profile-grid,.abc-summary,.insight-grid{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="system-polish.css?v=1">
</head>
<body>

<div class="app">
  <div class="sidebar">
    <div class="sidebar-logo"><span>Smart</span>Stock</div>
    <div class="sidebar-user">
      Signed in as<br><strong><?= e($user['name']) ?></strong>
      <div class="role-chip"><i class="ti ti-user-star" style="font-size:12px"></i> <?= e($roleName) ?></div>
    </div>
    <div class="nav-item active" data-page="dashboard" onclick="nav('dashboard',this)"><i class="ti ti-layout-dashboard"></i> Dashboard</div>
    <?php if ($canManageStock): ?>
      <div class="nav-item" data-page="inventory" onclick="nav('inventory',this)"><i class="ti ti-package"></i> Inventory</div>
    <?php endif; ?>
    <?php if ($canRecordSales): ?>
      <div class="nav-item" data-page="sales" onclick="nav('sales',this)"><i class="ti ti-receipt"></i> Sales</div>
    <?php endif; ?>
    <?php if ($canViewTransfers): ?>
      <div class="nav-item" data-page="transfers" onclick="nav('transfers',this)"><i class="ti ti-arrows-transfer-up-down"></i> Transfers</div>
    <?php endif; ?>
    <?php if ($isSupervisor): ?>
      <div class="nav-item" data-page="analytics" onclick="nav('analytics',this)"><i class="ti ti-chart-bar"></i> Analytics</div>
      <div class="nav-item" data-page="decisions" onclick="nav('decisions',this)"><i class="ti ti-brain"></i> Decision support</div>
    <?php endif; ?>
    <?php if ($canViewFlashSales): ?>
      <a class="nav-item" href="flash_sales.php"><i class="ti ti-bolt"></i> Flash sales</a>
    <?php endif; ?>
    <?php if ($canViewInquiries): ?>
      <a class="nav-item" href="inquiries.php"><i class="ti ti-messages"></i> Inquiries</a>
    <?php endif; ?>
    <?php if ($user['role'] === 'Super Admin'): ?>
      <a class="nav-item" href="superadmin.php" style="color:#35c490"><i class="ti ti-shield-check"></i> Super Admin</a>
    <?php endif; ?>
    <a class="nav-item" href="logout.php" style="margin-top:20px;color:#ff8a8a"><i class="ti ti-logout"></i> Sign out</a>
  </div>

  <div class="main">
    <div class="topbar">
      <h1 id="page-title">Dashboard</h1>
      <div class="topbar-actions">
        <div class="branch-switcher">
          <?php if (is_super_admin($user)): ?>
            <form method="get">
              <label class="sr-only" for="branch-switch">Branch view</label>
              <select id="branch-switch" name="branch_id" onchange="this.form.submit()">
                <option value="0">All branches</option>
                <?php foreach ($allowedBranches as $branchOption): ?>
                  <option value="<?= (int)$branchOption['id'] ?>" <?= $selectedBranchId === (int)$branchOption['id'] ? 'selected' : '' ?>><?= e($shortBranchName($branchOption['name'])) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php else: ?>
            <span class="branch-chip"><i class="ti ti-building-store"></i> <?= e($selectedBranchName) ?></span>
          <?php endif; ?>
        </div>
        <a class="notif-link <?= $openInquiryCount > 0 ? 'has-items' : '' ?>" href="<?= e($inquiriesHref) ?>" title="<?= $openInquiryCount > 0 ? e($openInquiryCount . ' open inquiries') : 'No open inquiries' ?>">
          <i class="ti ti-bell" style="font-size:18px"></i>
          <?php if ($openInquiryCount > 0): ?><span class="notif-count"><?= (int)$openInquiryCount ?></span><?php endif; ?>
        </a>
        <span class="badge"><i class="ti ti-circle-dot" style="font-size:10px"></i> Live</span>
        <?php if ($isSupervisor): ?>
          <button class="btn" type="button" onclick="printBranchReport()"><i class="ti ti-printer"></i> Print monthly report</button>
        <?php endif; ?>
        <?php if ($canRecordSales): ?>
          <button class="btn btn-primary" onclick="openSaleModal()"><i class="ti ti-plus"></i> New Sale</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="flash"><?= e($flash) ?></div>
    <?php endif; ?>
    <?php if ($newInquiryCount > 0): ?>
      <div class="flash">You have <?= (int)$newInquiryCount ?> new branch <?= $newInquiryCount === 1 ? 'inquiry' : 'inquiries' ?> waiting for review.</div>
    <?php endif; ?>

    <div class="content" id="content-area">
      <!-- DASHBOARD -->
      <div class="page active" id="pg-dashboard">
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
            <div class="metric-change <?= $lowStockCount ? 'down' : 'up' ?>">
                <?= $lowStockCount ? '↓ ' . $lowStockCount . ' low-stock items' : 'Stock sufficient' ?>
            </div>
          </div>
          <div class="metric-card">
            <div class="metric-label"><i class="ti ti-device-mobile"></i> Products Listed</div>
            <div class="metric-value"><?= $productsListed ?></div>
            <div class="metric-change up">Active SKUs</div>
          </div>
        </div>

        <div class="command-grid">
          <div class="card">
            <div class="card-title"><i class="ti ti-layout-dashboard"></i> <?= $isSupervisor ? 'Branch command brief' : 'Your work brief' ?></div>
            <div class="summary-lead"><?= e($roleOverviewLead) ?></div>
            <div class="insight-grid">
              <?php foreach ($overviewInsightCards as $insight): ?>
                <div class="insight-card <?= e($insight['tone']) ?>">
                  <div class="insight-card-title"><i class="ti ti-<?= e($insight['icon']) ?>"></i> <?= e($insight['title']) ?></div>
                  <div class="insight-card-body"><?= e($insight['body']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card">
            <div class="card-title"><i class="ti ti-bolt"></i> <?= $isSupervisor ? 'Next actions' : 'What to press next' ?></div>
            <div class="shortcut-grid">
              <?php foreach ($overviewQuickActions as $action): ?>
                <div class="shortcut-card">
                  <div class="shortcut-copy">
                    <div class="shortcut-title"><?= e($action['title']) ?></div>
                    <div class="shortcut-note"><?= e($action['note']) ?></div>
                  </div>
                  <?php if (!empty($action['js'])): ?>
                    <button type="button" class="btn <?= !empty($action['primary']) ? 'btn-primary' : '' ?>" onclick="<?= e($action['js']) ?>"><?= e($action['cta']) ?></button>
                  <?php elseif (!empty($action['href'])): ?>
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
            <div class="card-title"><i class="ti ti-calendar-stats"></i> Revenue and activity pulse</div>
            <div class="profile-grid">
              <div class="profile-stat"><div class="profile-stat-label">Today revenue</div><div class="profile-stat-value"><?= e(peso($todayRevenue)) ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">Today sales</div><div class="profile-stat-value"><?= (int)$todaySales ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">This week revenue</div><div class="profile-stat-value"><?= e(peso($weekRevenue)) ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">This week sales</div><div class="profile-stat-value"><?= (int)$weekSales ?></div></div>
            </div>
            <div class="mini-chart" style="margin-top:16px">
              <?php foreach ($months as $ym => $rev): ?>
                <div class="mini-col">
                  <div class="mini-bar-wrap"><div class="mini-bar" style="height:<?= max(4, (int)round(($rev / $maxMonthRev) * 100)) ?>%"></div></div>
                  <div class="mini-label"><?= e(date('M', strtotime($ym . '-01'))) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="chart-summary"><?= e($branchRevenueMomentumText) ?> <?= e($isSupervisor ? 'Use Analytics for the full trend and Decision Support for the next stock action.' : 'Use this pace view to answer customers quickly and move to the next sale with confidence.') ?></div>
          </div>

          <div class="card">
            <div class="card-title"><i class="ti ti-target-arrow"></i> Needs attention now</div>
            <div class="priority-list">
              <?php foreach ($overviewPriorityItems as $item): ?>
                <div class="priority-item">
                  <div class="priority-copy">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                      <span class="prio-pill prio-<?= e($item['level']) ?>"><?= e(ucfirst($item['level'])) ?></span>
                      <span class="priority-title"><i class="ti ti-<?= e($item['icon']) ?>" style="margin-right:6px;color:#0F766E"></i><?= e($item['title']) ?></span>
                    </div>
                    <div class="priority-note"><?= e($item['note']) ?></div>
                  </div>
                  <?php if (!empty($item['js']) || !empty($item['href']) || !empty($item['page'])): ?>
                    <div class="priority-actions">
                      <?php if (!empty($item['js'])): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="<?= e($item['js']) ?>"><?= e($item['cta']) ?></button>
                      <?php elseif (!empty($item['href'])): ?>
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

        <div class="grid2">
          <div class="card">
            <div class="card-title"><i class="ti ti-building-store"></i> <?= $branchProfile ? 'Branch profile' : 'Enterprise scope' ?></div>
            <?php if ($branchProfile): ?>
              <div class="page-intro"><strong><?= e($shortBranchName($branchProfile['name'])) ?></strong> operates from <?= e($branchProfile['address']) ?>.</div>
              <div class="topbar-note" style="margin-top:8px">
                Manager: <?= e($branchProfile['manager'] ?: 'Unassigned') ?>
                <?php if (!empty($branchProfile['phone'])): ?> · Contact: <?= e($branchProfile['phone']) ?><?php endif; ?>
                <?php if (!empty($branchProfile['email'])): ?> · <?= e($branchProfile['email']) ?><?php endif; ?>
              </div>
              <div class="profile-grid">
                <div class="profile-stat"><div class="profile-stat-label">Users</div><div class="profile-stat-value"><?= (int)$branchProfile['users_count'] ?></div></div>
                <div class="profile-stat"><div class="profile-stat-label">Units</div><div class="profile-stat-value"><?= (int)$branchProfile['units_count'] ?></div></div>
                <div class="profile-stat"><div class="profile-stat-label">Revenue</div><div class="profile-stat-value"><?= e(peso($branchProfile['revenue_total'])) ?></div></div>
                <div class="profile-stat"><div class="profile-stat-label">Transfers</div><div class="profile-stat-value"><?= (int)$branchProfile['transfers_count'] ?></div></div>
              </div>
            <?php else: ?>
              <div class="page-intro">View the whole business or switch to a specific branch to drill into branch-only sales, inventory, and transfers.</div>
              <div class="profile-grid">
                <div class="profile-stat"><div class="profile-stat-label">Branches</div><div class="profile-stat-value"><?= count($allowedBranches) ?></div></div>
                <div class="profile-stat"><div class="profile-stat-label">Transfer requests</div><div class="profile-stat-value"><?= $transferRequestCount ?></div></div>
                <div class="profile-stat"><div class="profile-stat-label">Pending</div><div class="profile-stat-value"><?= $pendingTransferCount ?></div></div>
                <div class="profile-stat"><div class="profile-stat-label">In transit</div><div class="profile-stat-value"><?= $transferStatusCounts['In Transit'] ?></div></div>
              </div>
            <?php endif; ?>
          </div>
          <div class="card">
            <div class="card-title"><i class="ti ti-arrows-transfer-up-down"></i> Transfer pulse</div>
            <?php foreach ($transferStatusCounts as $transferStatus => $transferCount): ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($transferStatus) ?></span><span><?= (int)$transferCount ?></span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= max(6, $transferRequestCount > 0 ? (int)round(($transferCount / $transferRequestCount) * 100) : 6) ?>%;background:<?= $transferStatus === 'Rejected' ? '#E05151' : ($transferStatus === 'In Transit' ? '#4338CA' : ($transferStatus === 'Approved' ? '#378ADD' : '#1D9E75')) ?>"></div></div>
              </div>
            <?php endforeach; ?>
            <div class="topbar-note" style="margin-top:12px">Pending requests: <?= $pendingTransferCount ?> · Completed transfers: <?= $transferStatusCounts['Completed'] ?></div>
          </div>
        </div>

        <div class="grid2">
          <div class="card">
            <div class="card-title"><i class="ti ti-device-mobile"></i> Top-selling brands (this month)</div>
            <?php if (!$brandStats): ?>
              <div style="color:var(--color-text-secondary);font-size:12px">No sales yet this month.</div>
            <?php endif; ?>
            <?php $palette = ['#1D9E75','#378ADD','#D85A30','#BA7517','#639922','#7F77DD']; foreach ($brandStats as $i => $b): $pct = round(($b['units']/$brandTotal)*100); ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($b['brand'] ?? 'Unknown') ?></span><span><?= (int)$b['units'] ?> units</span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $palette[$i % count($palette)] ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="card">
            <div class="card-title"><i class="ti ti-pie-chart"></i> Inventory by condition</div>
            <?php $cPal = ['Excellent'=>'#1D9E75','Good'=>'#378ADD','Fair'=>'#EF9F27','Poor'=>'#D85A30']; foreach ($condStats as $c): $pct = round(($c['cnt']/$condTotal)*100); ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($c['condition']) ?></span><span><?= e(peso($c['value'])) ?> value</span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $cPal[$c['condition']] ?? '#888' ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-title"><i class="ti ti-alert-triangle"></i> Low stock alerts</div>
          <?php
            $alerts = array_filter($inventory, fn($r) => $r['stock'] <= 3);
            if (!$alerts): echo '<div style="color:var(--color-text-secondary);font-size:12px">Stock levels are sufficient.</div>';
            else: foreach ($alerts as $r):
              $danger = ((int)$r['stock'] === 0);
          ?>
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
          <?php if ($canManageStock): ?>
            <button class="btn btn-primary" type="button" onclick="openDeviceModal()"><i class="ti ti-plus"></i> Add product</button>
          <?php endif; ?>
        </div>
        <div class="card" style="padding:0">
          <table>
            <thead><tr><th>Brand</th><th>Product</th><th>Series</th><th>Variant</th><th>Color</th><th>Branch</th><th>Price</th><th>Stock</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($inventory as $r):
                $status = $r['stock'] == 0 ? 'out' : ($r['stock'] <= 3 ? 'low' : 'in-stock');
                $statusLabel = $status === 'in-stock' ? 'Sufficient' : ($status === 'low' ? 'Low stock' : 'Out of stock');
              ?>
                <tr>
                  <td><?= e($r['brand']) ?></td>
                  <td>
                    <div class="product-cell">
                      <?php if (!empty($r['image_url'])): ?>
                        <img src="<?= e($r['image_url']) ?>" alt="<?= e($r['brand'] . ' ' . $r['model']) ?>" class="product-thumb">
                      <?php else: ?>
                        <span class="product-thumb placeholder"><i class="ti ti-device-mobile"></i></span>
                      <?php endif; ?>
                      <div>
                        <strong><?= e($r['model']) ?></strong>
                        <div class="topbar-note"><?= e($r['condition']) ?></div>
                      </div>
                    </div>
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
          <?php if ($canRecordSales): ?>
            <button class="btn btn-primary" onclick="openSaleModal()"><i class="ti ti-plus"></i> Record sale</button>
          <?php endif; ?>
        </div>
        <div class="card" style="padding:0">
          <table>
            <thead><tr><th>Receipt #</th><th>Product</th><th>Seller</th><th>Customer</th><th>Date</th><th>Branch</th><th>Price</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($sales as $s): ?>
                <tr>
                  <td style="color:var(--color-text-secondary);font-size:12px"><?= e($s['txn_id']) ?></td>
                  <td><?= e($s['product_name']) ?></td>
                  <td><?= e($s['seller_name'] ?: 'System') ?></td>
                  <td><?= e($s['customer']) ?></td>
                  <td><?= e(date('M j, Y', strtotime($s['sale_date']))) ?></td>
                  <td style="font-size:12px;color:var(--color-text-secondary)"><?= e($shortBranchName($s['branch_name'] ?? '—')) ?></td>
                  <td style="font-weight:500"><?= e(peso($s['price'])) ?></td>
                  <td><span class="status-pill in-stock"><?= e($s['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$sales): ?><tr><td colspan="8" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No sales recorded yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TRANSFERS -->
      <div class="page" id="pg-transfers">
        <div class="metrics">
          <div class="metric-card"><div class="metric-label"><i class="ti ti-arrows-transfer-up-down"></i> Transfer requests</div><div class="metric-value"><?= $transferRequestCount ?></div><div class="metric-change up">Recent workflow volume</div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-loader"></i> Pending</div><div class="metric-value"><?= $pendingTransferCount ?></div><div class="metric-change <?= $pendingTransferCount ? 'down' : 'up' ?>"><?= $pendingTransferCount ? 'Needs review' : 'Queue is clear' ?></div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-truck-delivery"></i> In transit</div><div class="metric-value"><?= $transferStatusCounts['In Transit'] ?></div><div class="metric-change up">On the move</div></div>
          <div class="metric-card"><div class="metric-label"><i class="ti ti-checks"></i> Completed</div><div class="metric-value"><?= $transferStatusCounts['Completed'] ?></div><div class="metric-change up">Closed successfully</div></div>
        </div>

        <div class="grid2">
          <div class="card">
            <div class="card-title"><i class="ti ti-send"></i> Create transfer request</div>
            <?php if ($canManageStock): ?>
              <form method="post" action="actions/create_transfer.php">
                <?php if (is_super_admin($user) && $selectedBranchId === 0): ?>
                  <div class="form-grid">
                    <div class="fg">
                      <label>Source branch</label>
                      <select id="transfer-source" name="source_branch_id" required>
                        <option value="">Select source branch</option>
                        <?php foreach ($activeBranches as $branchOption): ?>
                          <option value="<?= (int)$branchOption['id'] ?>"><?= e($shortBranchName($branchOption['name'])) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="fg">
                      <label>Destination branch</label>
                      <select id="transfer-destination" name="destination_branch_id" required>
                        <option value="">Select destination branch</option>
                        <?php foreach ($activeBranches as $branchOption): ?>
                          <option value="<?= (int)$branchOption['id'] ?>"><?= e($shortBranchName($branchOption['name'])) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                <?php else: ?>
                  <?php $transferSourceId = $selectedBranchId > 0 ? $selectedBranchId : (current_branch_id($user) ?? 0); ?>
                  <input type="hidden" name="source_branch_id" value="<?= (int)$transferSourceId ?>">
                  <div class="form-grid">
                    <div class="fg">
                      <label>Source branch</label>
                      <div class="branch-chip"><i class="ti ti-building-store"></i> <?= e($selectedBranchName) ?></div>
                    </div>
                    <div class="fg">
                      <label>Destination branch</label>
                      <select id="transfer-destination" name="destination_branch_id" required>
                        <option value="">Select destination branch</option>
                        <?php foreach ($activeBranches as $branchOption): ?>
                          <?php if ((int)$branchOption['id'] === (int)$transferSourceId) continue; ?>
                          <option value="<?= (int)$branchOption['id'] ?>"><?= e($shortBranchName($branchOption['name'])) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                <?php endif; ?>

                <div class="form-grid">
                  <div class="fg">
                    <label>Product</label>
                    <select id="transfer-product" name="phone_id" required>
                      <option value="">Select inventory item</option>
                      <?php foreach ($transferInventoryOptions as $option): ?>
                        <option value="<?= (int)$option['id'] ?>" data-branch="<?= (int)$option['branch_id'] ?>" data-stock="<?= (int)$option['stock'] ?>">
                          <?= e($option['brand'] . ' ' . $option['model']) ?> · <?= e($shortBranchName($option['branch_name'] ?? '—')) ?> · <?= (int)$option['stock'] ?> in stock
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="fg">
                    <label>Quantity</label>
                    <input type="number" id="transfer-quantity" name="quantity" min="1" value="1" required>
                  </div>
                </div>

                <div class="fg" style="margin-bottom:12px">
                  <label>Notes</label>
                  <textarea name="notes" placeholder="Add request notes, IMEI handling details, or delivery instructions"></textarea>
                </div>

                <div style="display:flex;justify-content:flex-end">
                  <button class="btn btn-primary" type="submit"><i class="ti ti-send"></i> Submit request</button>
                </div>
              </form>
            <?php else: ?>
              <div class="page-intro">Your role can review transfer history but cannot create a request.</div>
            <?php endif; ?>
          </div>

          <div class="card">
            <div class="card-title"><i class="ti ti-history"></i> Inventory movement logs</div>
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
                <?php if (!$movementLogs): ?><tr><td colspan="5" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No inventory movements logged yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-title"><i class="ti ti-truck-delivery"></i> Transfer board</div>
          <?php if (!$canManageTransferStatus): ?><div class="page-intro" style="margin-bottom:12px">Transfer status changes are handled from the executive board by the Super Admin or CEO. Branches can track the request here.</div><?php endif; ?>
          <div class="transfer-stack">
            <?php foreach ($transfers as $transfer): ?>
              <?php
                $statusClass = strtolower(str_replace(' ', '-', $transfer['status']));
                $steps = ['Pending', 'Approved', 'In Transit', 'Completed'];
                $currentStep = array_search($transfer['status'], $steps, true);
              ?>
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
                    <?php
                      $timelineClass = '';
                      if ($transfer['status'] === 'Rejected') {
                          $timelineClass = $step === 'Pending' ? 'done' : '';
                      } elseif ($currentStep !== false) {
                          $timelineClass = $index < $currentStep ? 'done' : ($index === $currentStep ? 'active' : '');
                      }
                    ?>
                    <span class="timeline-step <?= $timelineClass ?>"><?= e($step) ?></span>
                  <?php endforeach; ?>
                  <?php if ($transfer['status'] === 'Rejected'): ?><span class="timeline-step rejected">Rejected</span><?php endif; ?>
                </div>

                <div class="transfer-meta" style="margin-top:12px">
                  <?= (int)$transfer['item_count'] ?> line item(s) · <?= (int)$transfer['total_units'] ?> unit(s)
                  <?php if (!empty($transfer['notes'])): ?> · <?= e($transfer['notes']) ?><?php endif; ?>
                </div>

                <?php if ($canManageTransferStatus && in_array($transfer['status'], ['Pending', 'Approved', 'In Transit'], true)): ?>
                  <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:12px">
                    <?php if ($transfer['status'] === 'Pending'): ?>
                      <form method="post" action="actions/update_transfer_status.php">
                        <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="ti ti-check"></i> Approve</button>
                      </form>
                      <form method="post" action="actions/update_transfer_status.php">
                        <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="rejection_reason" value="Rejected from transfer board">
                        <button class="btn btn-danger btn-sm" type="submit"><i class="ti ti-x"></i> Reject</button>
                      </form>
                    <?php elseif ($transfer['status'] === 'Approved'): ?>
                      <form method="post" action="actions/update_transfer_status.php">
                        <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                        <input type="hidden" name="action" value="dispatch">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="ti ti-truck-delivery"></i> Dispatch</button>
                      </form>
                      <form method="post" action="actions/update_transfer_status.php">
                        <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="rejection_reason" value="Rejected before dispatch">
                        <button class="btn btn-danger btn-sm" type="submit"><i class="ti ti-x"></i> Reject</button>
                      </form>
                    <?php elseif ($transfer['status'] === 'In Transit'): ?>
                      <form method="post" action="actions/update_transfer_status.php">
                        <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                        <input type="hidden" name="action" value="complete">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="ti ti-checks"></i> Complete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$transfers): ?><div class="page-intro">No transfer requests recorded for this branch scope yet.</div><?php endif; ?>
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
            <div class="card-title"><i class="ti ti-chart-bar"></i> Monthly revenue trend</div>
            <div class="mini-chart">
              <?php foreach ($months as $ym => $rev): $h = $maxMonthRev > 0 ? round(($rev / $maxMonthRev) * 100) : 0; ?>
                <div class="mini-col">
                  <div class="mini-bar-wrap">
                    <div class="mini-bar" style="height:<?= max(4, $h) ?>%" title="<?= e(peso($rev)) ?>"></div>
                  </div>
                  <div class="mini-label"><?= e(date('M', strtotime($ym.'-01'))) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="card">
            <div class="card-title"><i class="ti ti-device-mobile"></i> Sales by condition</div>
            <?php if (!$condSales): ?>
              <div style="color:var(--color-text-secondary);font-size:12px">No completed sales yet.</div>
            <?php endif; ?>
            <?php $cPal2 = ['Excellent'=>'#1D9E75','Good'=>'#378ADD','Fair'=>'#EF9F27','Poor'=>'#D85A30']; foreach ($condSales as $c): $pct = $maxCondRev > 0 ? round(((float)$c['rev']/$maxCondRev)*100) : 0; ?>
              <div class="bar-group">
                <div class="bar-label"><span><?= e($c['cond']) ?></span><span><?= e(peso($c['rev'])) ?></span></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= max(5,$pct) ?>%;background:<?= $cPal2[$c['cond']] ?? '#888' ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-title"><i class="ti ti-table"></i> Product performance summary</div>
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

        <?php if ($isSupervisor): ?>
          <div class="card">
            <div class="card-title"><i class="ti ti-printer"></i> Monthly printable branch report</div>
            <div class="profile-grid">
              <div class="profile-stat"><div class="profile-stat-label">Monthly revenue</div><div class="profile-stat-value"><?= e(peso($revenueMonth)) ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">Completed sales</div><div class="profile-stat-value"><?= (int)$salesMonth ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">Inventory value</div><div class="profile-stat-value"><?= e(peso($inventoryValue)) ?></div></div>
              <div class="profile-stat"><div class="profile-stat-label">Units in stock</div><div class="profile-stat-value"><?= (int)$unitsInStock ?></div></div>
            </div>
            <div class="page-intro" style="margin-top:12px">This report is formatted for branch printing and includes the SmartStock logo, monthly sales summary, inventory snapshot, and top-performing products.</div>
          </div>
        <?php endif; ?>
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
            <div class="card-title"><i class="ti ti-refresh"></i> Reorder recommendations <span style="margin-left:auto;font-size:12px;font-weight:400;color:var(--color-text-secondary)">Last 30 days velocity</span></div>
            <table>
              <thead><tr><th>Product</th><th>Stock</th><th>Sold 30d</th><th>Suggested</th><th>Priority</th></tr></thead>
              <tbody>
                <?php foreach ($reorderList as $r): ?>
                  <tr>
                    <td><?= e($r['name']) ?></td>
                    <td><strong><?= (int)$r['stock'] ?></strong></td>
                    <td><?= (int)$r['vel'] ?> u</td>
                    <td style="font-weight:500;color:#1D9E75">+<?= (int)$r['suggest'] ?> u</td>
                    <td><span class="prio-pill prio-<?= e($r['priority']) ?>"><?= e($r['priority']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$reorderList): ?><tr><td colspan="5" style="text-align:center;color:var(--color-text-tertiary);padding:24px">✓ All items are adequately stocked.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="card">
            <div class="card-title"><i class="ti ti-trending-up"></i> Fast movers (last 30 days)</div>
            <table>
              <thead><tr><th>Product</th><th>Sold</th><th>In stock</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($fastMovers as $f): ?>
                  <tr>
                    <td><?= e($f['name']) ?></td>
                    <td><strong><?= (int)$f['sold'] ?></strong></td>
                    <td><?= (int)$f['stock'] ?></td>
                    <td><?php if ($f['stock'] <= $f['sold']): ?><span class="prio-pill prio-high">At risk</span><?php else: ?><span class="status-pill in-stock">Sufficient</span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$fastMovers): ?><tr><td colspan="4" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No sales in the last 30 days.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="card-title"><i class="ti ti-activity-heartbeat"></i> Item movement monitor</div>
          <table>
            <thead><tr><th>Brand</th><th>Product</th><th>Series</th><th>Variant</th><th>Color</th><th>Stock</th><th>Sold 30d</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($movementMonitor as $item): ?>
                <?php
                  $movementStatus = (int)$item['sold_30d'] >= 3 ? 'Fast moving' : ((int)$item['sold_30d'] === 0 ? 'Slow moving' : 'Steady');
                  $movementClass = (int)$item['sold_30d'] >= 3 ? 'in-stock' : ((int)$item['sold_30d'] === 0 ? 'out' : 'approved');
                ?>
                <tr>
                  <td><?= e($item['brand']) ?></td>
                  <td><?= e($item['model']) ?></td>
                  <td><?= e($item['series'] ?: strtok((string)$item['model'], ' ')) ?></td>
                  <td><?= e(trim(implode(' / ', array_filter([$item['storage'], $item['ram'] ? $item['ram'] . ' RAM' : ''])))) ?></td>
                  <td><?= e($item['color'] ?: '—') ?></td>
                  <td><strong><?= (int)$item['stock'] ?></strong></td>
                  <td><?= (int)$item['sold_30d'] ?></td>
                  <td><span class="status-pill <?= e($movementClass) ?>"><?= e($movementStatus) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$movementMonitor): ?><tr><td colspan="8" style="text-align:center;color:var(--color-text-tertiary);padding:24px">No movement data available yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="card-title"><i class="ti ti-alert-triangle"></i> Dead stock &amp; slow movers <span style="margin-left:auto;font-size:12px;font-weight:400;color:var(--color-text-secondary)">No sales in 30+ days</span></div>
          <table>
            <thead><tr><th>Product</th><th>Stock</th><th>Days idle</th><th>Capital tied</th><th>Recommended action</th></tr></thead>
            <tbody>
              <?php foreach ($deadStock as $d): ?>
                <tr>
                  <td><?= e($d['name']) ?></td>
                  <td><?= (int)$d['stock'] ?></td>
                  <td><?= $d['days']===null?'<span style="color:var(--color-text-tertiary)">Never sold</span>':(int)$d['days'].' days' ?></td>
                  <td style="font-weight:500"><?= e(peso($d['tied'])) ?></td>
                  <td><?= e($d['action']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$deadStock): ?><tr><td colspan="5" style="text-align:center;color:var(--color-text-tertiary);padding:24px">✓ No slow-moving stock detected.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="card-title"><i class="ti ti-chart-pie"></i> ABC product classification <span style="margin-left:auto;font-size:12px;font-weight:400;color:var(--color-text-secondary)">Pareto revenue contribution</span></div>
          <div class="abc-summary">
            <div class="abc-card"><div class="abc-card-title"><span class="abc-badge abc-A">A</span> High value (top 70% revenue)</div><div class="abc-card-value"><?= (int)$abcCount['A'] ?> <span style="font-size:12px;color:var(--color-text-secondary);font-weight:400">products — protect &amp; maintain</span></div></div>
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
            <div class="card-title"><i class="ti ti-arrow-down-right"></i> Low-margin items (&lt; 15%)</div>
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
            <div style="margin-top:10px;font-size:12px;color:var(--color-text-secondary)">→ Renegotiate supplier cost or raise price.</div>
          </div>

          <div class="card">
            <div class="card-title"><i class="ti ti-arrow-up-right"></i> High-margin items (≥ 40%)</div>
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
      <button type="submit" class="btn btn-primary" <?= ($saleOptions && $canRecordSales) ? '' : 'disabled' ?>>
        <i class="ti ti-check"></i> Record sale
      </button>
    </div>
  </form>
</div>

<div class="modal-overlay" id="device-modal">
  <form class="modal" method="post" action="actions/add_device.php" enctype="multipart/form-data" style="width:720px;max-width:94%">
    <div class="modal-title">
      Add branch inventory
      <button type="button" class="btn" onclick="closeDeviceModal()" style="padding:4px 8px"><i class="ti ti-x"></i></button>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Brand</label>
        <select name="brand">
          <option>Samsung</option><option>Apple</option><option>Xiaomi</option><option>OPPO</option><option>Vivo</option><option>Realme</option><option>Huawei</option><option>Tecno</option><option>Other</option>
        </select>
      </div>
      <div class="form-group">
        <label>Model name</label>
        <input type="text" name="model" required placeholder="e.g. Galaxy A54">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Series</label>
        <input type="text" name="series" placeholder="e.g. Galaxy, iPhone">
      </div>
      <div class="form-group">
        <label>Color</label>
        <input type="text" name="color" placeholder="e.g. Graphite">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Storage</label>
        <select name="storage"><option>32GB</option><option>64GB</option><option>128GB</option><option>256GB</option><option>512GB</option></select>
      </div>
      <div class="form-group">
        <label>RAM</label>
        <select name="ram"><option>2GB</option><option>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option></select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Condition</label>
        <select name="condition"><option>Excellent</option><option>Good</option><option>Fair</option><option>Poor</option></select>
      </div>
      <div class="form-group">
        <label>Battery health (%)</label>
        <input type="number" name="battery" min="0" max="100" placeholder="e.g. 87">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Operating system</label>
        <select name="operating_system"><option>Android</option><option>iOS</option><option>HarmonyOS</option><option>Other</option></select>
      </div>
      <div class="form-group">
        <label>Upload note</label>
        <input type="text" value="Optional front and back JPG, PNG, GIF, or WEBP up to 5MB" disabled>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Selling price (PHP)</label>
        <input type="number" name="selling_price" step="0.01" min="0" required>
      </div>
      <div class="form-group">
        <label>Purchase price (PHP)</label>
        <input type="number" name="purchase_price" step="0.01" min="0">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Supplier</label>
        <input type="text" name="supplier" placeholder="e.g. Trade-in, Walk-in seller">
      </div>
      <div class="form-group">
        <label>Stock quantity</label>
        <input type="number" name="stock" value="1" min="1" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>IMEI</label>
        <input type="text" name="imei" placeholder="Device IMEI">
      </div>
      <div class="form-group">
        <label>Serial number</label>
        <input type="text" name="serial_number" placeholder="Device serial number">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Front photo</label>
        <input type="file" name="image_file" accept=".jpg,.jpeg,.png,.gif,.webp,image/*">
      </div>
      <div class="form-group">
        <label>Back photo</label>
        <input type="file" name="back_image_file" accept=".jpg,.jpeg,.png,.gif,.webp,image/*">
      </div>
    </div>

    <div class="form-row">
      <?php if (is_super_admin($user)): ?>
        <div class="form-group">
          <label>Branch</label>
          <select name="branch_id" required>
            <?php foreach ($allowedBranches as $branchOption): ?>
              <option value="<?= (int)$branchOption['id'] ?>" <?= $selectedBranchId === (int)$branchOption['id'] ? 'selected' : '' ?>><?= e($shortBranchName($branchOption['name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="branch_id" value="<?= (int)$selectedBranchId ?>">
        <div class="form-group">
          <label>Branch</label>
          <input type="text" value="<?= e($selectedBranchName) ?>" disabled>
        </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Accessories</label>
        <select name="accessories"><option>Charger only</option><option>Charger + earphones</option><option>Complete (box, charger, earphones)</option><option>Unit only</option></select>
      </div>
    </div>

    <div class="form-group" style="margin-bottom:12px">
      <label>Notes</label>
      <textarea name="notes" placeholder="Condition notes, seller details, and stock remarks"></textarea>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:0.5px solid var(--color-border-tertiary)">
      <button type="button" class="btn" onclick="closeDeviceModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="ti ti-device-mobile-plus"></i> Save device</button>
    </div>
  </form>
</div>

<?php if ($isSupervisor): ?>
<div id="branch-report-template" style="display:none">
  <div style="font-family:'Plus Jakarta Sans',sans-serif;padding:28px;color:#0f172a">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px;border-bottom:2px solid #e5e7eb;padding-bottom:16px">
      <div>
        <div style="font-size:26px;font-weight:800;letter-spacing:-0.04em"><span style="color:#1D9E75">Smart</span>Stock</div>
        <div style="font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#64748b;margin-top:6px">Monthly branch revenue report</div>
      </div>
      <div style="text-align:right;font-size:13px;color:#334155">
        <div><strong><?= e($selectedBranchName) ?></strong></div>
        <div><?= e(date('F Y')) ?></div>
        <div>Generated <?= e(date('M j, Y h:i A')) ?></div>
      </div>
    </div>

    <table style="width:100%;border-collapse:collapse;margin-bottom:18px">
      <tr>
        <td style="padding:10px 12px;border:1px solid #e5e7eb"><strong>Monthly revenue</strong><br><?= e(peso($revenueMonth)) ?></td>
        <td style="padding:10px 12px;border:1px solid #e5e7eb"><strong>Monthly sales</strong><br><?= (int)$salesMonth ?></td>
        <td style="padding:10px 12px;border:1px solid #e5e7eb"><strong>Inventory value</strong><br><?= e(peso($inventoryValue)) ?></td>
        <td style="padding:10px 12px;border:1px solid #e5e7eb"><strong>Units in stock</strong><br><?= (int)$unitsInStock ?></td>
      </tr>
    </table>

    <div style="font-size:16px;font-weight:700;margin-bottom:10px">Top products this month</div>
    <table style="width:100%;border-collapse:collapse;margin-bottom:18px">
      <thead>
        <tr>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Product</th>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Units sold</th>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Revenue</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($perf, 0, 5) as $reportRow): ?>
          <tr>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= e($reportRow['name']) ?></td>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= (int)$reportRow['sold'] ?></td>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= e(peso($reportRow['rev'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="font-size:16px;font-weight:700;margin-bottom:10px">Inventory snapshot</div>
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Brand</th>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Product</th>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Series</th>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Variant</th>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Color</th>
          <th style="text-align:left;padding:10px 12px;border:1px solid #e5e7eb;background:#f8fafc">Stock</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($inventory, 0, 10) as $reportItem): ?>
          <tr>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= e($reportItem['brand']) ?></td>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= e($reportItem['model']) ?></td>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= e($reportItem['series'] ?: strtok((string)$reportItem['model'], ' ')) ?></td>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= e(trim(implode(' / ', array_filter([$reportItem['storage'], $reportItem['ram'] ? $reportItem['ram'] . ' RAM' : ''])))) ?></td>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= e($reportItem['color'] ?: '—') ?></td>
            <td style="padding:10px 12px;border:1px solid #e5e7eb"><?= (int)$reportItem['stock'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function nav(page, el){
  document.querySelectorAll('.nav-item[data-page]').forEach(n=>n.classList.remove('active'));
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('pg-'+page).classList.add('active');
  const titles={dashboard:'Dashboard',inventory:'Inventory',sales:'Sales',transfers:'Transfers',analytics:'Analytics',decisions:'Decision support'};
  document.getElementById('page-title').textContent=titles[page];
  window.location.hash = page;
}
function openSaleModal(){
  document.getElementById('sale-modal').classList.add('open');
  const sel = document.getElementById('sale-product');
  if (sel && sel.selectedOptions[0]) {
    document.getElementById('sale-price').value = sel.selectedOptions[0].dataset.price || '';
  }
}
function closeModal(){document.getElementById('sale-modal').classList.remove('open')}
function openDeviceModal(){document.getElementById('device-modal').classList.add('open')}
function closeDeviceModal(){document.getElementById('device-modal').classList.remove('open')}
function printBranchReport(){
  const template = document.getElementById('branch-report-template');
  if (!template) return;
  const reportWindow = window.open('', '_blank', 'width=1024,height=720');
  if (!reportWindow) return;
  reportWindow.document.write(`<!doctype html><html><head><title>Branch Monthly Report</title></head><body>${template.innerHTML}</body></html>`);
  reportWindow.document.close();
  reportWindow.focus();
  reportWindow.print();
}
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
    if (visible && opt.value === currentValue) {
      currentSelectionVisible = true;
    }
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
    if (Number(qty.value) > Number(selected.dataset.stock)) {
      qty.value = selected.dataset.stock;
    }
  } else if (qty) {
    qty.max = '';
    qty.value = '1';
  }
}
document.getElementById('transfer-source')?.addEventListener('change', filterTransferProducts);
document.getElementById('transfer-product')?.addEventListener('change', e => {
  const selected = e.target.selectedOptions[0];
  const qty = document.getElementById('transfer-quantity');
  if (selected && qty && selected.dataset.stock) {
    qty.max = selected.dataset.stock;
  }
});
// Close modal when clicking outside
document.getElementById('sale-modal')?.addEventListener('click', e => {
  if (e.target.id === 'sale-modal') closeModal();
});
document.getElementById('device-modal')?.addEventListener('click', e => {
  if (e.target.id === 'device-modal') closeDeviceModal();
});
filterTransferProducts();

const initialPage = window.location.hash ? window.location.hash.slice(1) : 'dashboard';
const initialNav = document.querySelector('.nav-item[data-page="' + initialPage + '"]');
if (initialNav) {
  nav(initialPage, initialNav);
}
</script>
</body>
</html>
