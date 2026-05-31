<?php
require_once __DIR__ . '/includes/helpers.php';

// Pull only listed, in-stock phones for the public catalog
$phones = $db->query("
    SELECT p.*, b.name AS branch_name
    FROM phones p
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE p.is_listed = 1 AND p.stock > 0
    ORDER BY p.created_at DESC, p.id DESC
")->fetchAll();

$branches = $db->query("
  SELECT b.id, b.name, b.address, b.phone, b.email, b.manager,
           (SELECT COALESCE(SUM(stock),0) FROM phones p WHERE p.branch_id = b.id AND p.is_listed = 1) AS units
    FROM branches b
    WHERE b.status = 'Active'
    ORDER BY b.id
")->fetchAll();

$liveFlashSales = $db->query("
  SELECT fs.*, p.brand, p.model, p.storage, p.ram, p.`condition`, p.battery, p.emoji, p.image_url,
       p.selling_price AS regular_price, p.branch_id, b.name AS branch_name
  FROM flash_sales fs
  INNER JOIN phones p ON p.id = fs.phone_id
  LEFT JOIN branches b ON b.id = fs.branch_id
  WHERE fs.is_active = 1
    AND fs.starts_at <= NOW()
    AND fs.ends_at >= NOW()
    AND p.is_listed = 1
    AND p.stock > 0
  ORDER BY fs.sale_price ASC, fs.ends_at ASC, fs.id DESC
")->fetchAll();

$flashSaleMap = [];
foreach ($liveFlashSales as $flashSale) {
  $phoneKey = (int)$flashSale['phone_id'];
  if (!isset($flashSaleMap[$phoneKey])) {
    $flashSaleMap[$phoneKey] = $flashSale;
  }
}

$brandCount = (int)$db->query("SELECT COUNT(DISTINCT brand) FROM phones WHERE is_listed = 1")->fetchColumn();
$totalUnits = array_sum(array_column($phones, 'stock'));
$brandList  = $db->query("SELECT DISTINCT brand FROM phones WHERE is_listed = 1 ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
$jsInquiryBranches = array_map(static function ($branch) {
  return [
    'id' => (int)$branch['id'],
    'name' => str_replace('RF Chein - ', '', (string)$branch['name']),
  ];
}, $branches);

// Find a featured "deal" — highest-margin listed unit (if purchase_price set)
$featuredFlash = $liveFlashSales[0] ?? null;
$featuredDeal = null;
if (!$featuredFlash) {
  foreach ($phones as $p) {
    if (!empty($p['purchase_price'])) {
      $disc = (float)$p['selling_price'] - (float)$p['purchase_price'];
      if (!$featuredDeal || $disc > $featuredDeal['_disc']) {
        $featuredDeal = $p;
        $featuredDeal['_disc'] = $disc;
      }
    }
  }
}

$publicFlash = flash_get('public');

$jsPhones = array_map(function ($p) use ($flashSaleMap) {
  $flashSale = $flashSaleMap[(int)$p['id']] ?? null;
    return [
        'id'=>(int)$p['id'],'brand'=>$p['brand'],'model'=>$p['model'],
        'storage'=>$p['storage'],'ram'=>$p['ram']??'','color'=>$p['color']??'',
        'cond'=>$p['condition'],'batt'=>(int)$p['battery'],
        'price'=>(float)$p['selling_price'],
    'branch_id'=>(int)($p['branch_id'] ?? 0),
        'branch'=>str_replace('RF Chein - ', '', $p['branch_name'] ?? 'Main Branch'),
        'accessories'=>$p['accessories']??'Unit only','imei'=>$p['imei']??'',
    'notes'=>$p['notes']??'','emoji'=>$p['emoji']?:'📱',
    'image'=>$p['image_url'] ?? '',
    'flash_price'=>$flashSale ? (float)$flashSale['sale_price'] : null,
    'flash_label'=>$flashSale['promo_label'] ?? '',
    'flash_title'=>$flashSale['title'] ?? '',
    'flash_until'=>$flashSale['ends_at'] ?? '',
    ];
}, $phones);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="RF Chein Gadgets — Premium pre-owned smartphones in Bacolod City. Quality checked. Honest prices. 3 branches, ready stock.">
<title>RF Chein Gadgets — Premium Pre-Owned Smartphones · Bacolod City</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --black:#1d1d1f; --black-2:#2c2c2e; --white:#ffffff; --cream:#f5f5f7;
    --accent:#1a1a1a; --accent-2:#000000; --lime:#c8ff00; --lime-2:#a8d800;
    --muted:#6e6e73; --muted-2:#86868b;
    --card-bg:#ffffff; --card-2:#fafafa; --border:#e5e5e7; --border-2:#d2d2d7; --pill:#f5f5f7;
    --tg:#e8f5e9; --tgt:#1b5e20; --ta:#fff4e0; --tat:#8a5a12;
    --tr:#fde8e8; --trt:#a32d2d; --tb:#e3f0ff; --tbt:#1d4ed8;
    --shadow-sm:0 1px 3px rgba(0,0,0,.04),0 1px 2px rgba(0,0,0,.06);
    --shadow-md:0 4px 16px rgba(0,0,0,.06),0 2px 4px rgba(0,0,0,.04);
    --shadow-lg:0 20px 50px -20px rgba(0,0,0,.12),0 10px 20px -10px rgba(0,0,0,.08);
    --radius-lg:24px; --radius-md:16px; --radius-sm:10px;
  }
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  html{scroll-behavior:smooth}
  body{background:var(--white);color:var(--black);font-family:'DM Sans',sans-serif;font-size:15px;line-height:1.6;min-height:100vh;overflow-x:hidden;-webkit-font-smoothing:antialiased}
  a{color:inherit;text-decoration:none}
  /* Promo strip */
  .promo-bar{background:var(--black);color:var(--white);text-align:center;padding:10px 20px;font-size:13px;font-weight:400;letter-spacing:.02em;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap}
  .promo-bar .dot{width:6px;height:6px;background:var(--lime);border-radius:50%;animation:pulse 1.6s infinite}
  .promo-bar a{font-weight:600;text-decoration:underline;text-underline-offset:3px;color:var(--lime)}
  .page-flash{max-width:1240px;margin:16px auto 0;padding:0 40px}.page-flash .flash-msg{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:18px;padding:14px 16px;font-size:14px;box-shadow:var(--shadow-sm)}
  @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.4)}}
  /* Nav */
  nav{display:flex;align-items:center;justify-content:space-between;padding:18px 40px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(255,255,255,.85);backdrop-filter:blur(14px);z-index:100}
  .nav-logo{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;letter-spacing:-.5px;display:flex;align-items:center;gap:8px;color:var(--black)}
  .nav-logo span{color:var(--black)}
  .nav-logo .nav-dot{width:8px;height:8px;background:var(--lime);border-radius:50%;box-shadow:0 0 8px rgba(200,255,0,.6)}
  .nav-links{display:flex;align-items:center;gap:32px;list-style:none}
  .nav-links a{color:var(--muted);font-size:14px;transition:color .2s;font-weight:500}
  .nav-links a:hover{color:var(--black)}
  .nav-cta{background:var(--black);color:var(--white)!important;font-weight:500!important;padding:9px 20px;border-radius:40px;transition:background .2s,transform .15s}
  .nav-cta:hover{background:var(--accent-2);transform:scale(1.03)}
  /* Hero */
  .hero{padding:80px 40px 70px;max-width:1240px;margin:0 auto;display:grid;grid-template-columns:1.1fr 1fr;gap:60px;align-items:center;position:relative;background:radial-gradient(ellipse at top left,rgba(200,255,0,.08) 0%,transparent 50%)}
  .hero-tag{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:600;color:var(--black);background:rgba(200,255,0,.25);border:1px solid rgba(200,255,0,.5);padding:6px 14px;border-radius:40px;margin-bottom:24px;letter-spacing:.06em;text-transform:uppercase}
  .hero-tag::before{content:'';width:6px;height:6px;background:var(--lime-2);border-radius:50%;animation:pulse 2s infinite}
  .hero h1{font-family:'Syne',sans-serif;font-size:clamp(42px,5.5vw,72px);font-weight:800;line-height:1.02;letter-spacing:-2.5px;margin-bottom:22px;color:var(--black)}
  .hero h1 em{font-style:normal;color:var(--black);position:relative;display:inline-block}
  .hero h1 em::after{content:'';position:absolute;bottom:4px;left:0;width:100%;height:14px;background:var(--lime);z-index:-1;border-radius:4px}
  .hero p.lead{color:var(--muted);font-size:17px;max-width:480px;margin-bottom:36px;line-height:1.7}
  .hero-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .btn-primary{background:var(--black);color:var(--white);font-weight:500;padding:14px 28px;border-radius:40px;border:none;cursor:pointer;font-size:14px;font-family:inherit;transition:background .2s,transform .15s,box-shadow .2s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary:hover{background:var(--accent-2);transform:translateY(-2px);box-shadow:var(--shadow-lg)}
  .btn-outline{background:transparent;color:var(--black);font-weight:500;padding:14px 28px;border-radius:40px;border:1px solid var(--border-2);cursor:pointer;font-size:14px;font-family:inherit;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
  .btn-outline:hover{border-color:var(--black);background:var(--cream)}
  .hero-stats{display:flex;gap:36px;margin-top:48px;padding-top:32px;border-top:1px solid var(--border)}
  .stat-num{font-family:'Syne',sans-serif;font-size:32px;font-weight:800;color:var(--black)}
  .stat-num span{color:var(--lime-2)}
  .stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:4px}
  /* Hero visual — featured deal card */
  .hero-visual{position:relative;display:flex;justify-content:center;align-items:center;min-height:440px}
  .deal-card{position:relative;width:330px;background:#ffffff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;animation:floatY 5s ease-in-out infinite;box-shadow:var(--shadow-lg)}
  .deal-tag{position:absolute;top:-12px;left:24px;background:var(--lime);color:var(--black);font-size:11px;font-weight:700;padding:5px 12px;border-radius:20px;letter-spacing:.08em;text-transform:uppercase;box-shadow:0 4px 12px -4px rgba(200,255,0,.5)}
  .deal-img{height:160px;background:linear-gradient(135deg,#fafaf8 0%,#f0eee8 100%);border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:88px;margin-bottom:18px;border:1px solid var(--border);overflow:hidden;position:relative}
  .deal-img img{width:100%;height:100%;object-fit:cover;display:block}
  .deal-name{font-family:'Syne',sans-serif;font-size:20px;font-weight:700;margin-bottom:6px;color:var(--black)}
  .deal-spec{font-size:13px;color:var(--muted);margin-bottom:14px}
  .deal-price-row{display:flex;align-items:baseline;gap:10px;margin-bottom:14px}
  .deal-price{font-family:'Syne',sans-serif;font-size:32px;font-weight:800;color:var(--black)}
  .deal-price-old{font-size:14px;color:var(--muted);text-decoration:line-through}
  .deal-meta{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);padding-top:14px;border-top:1px solid var(--border)}
  .deal-meta strong{color:var(--black);font-weight:600}
  .ghost-card{position:absolute;width:200px;background:#ffffff;border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;bottom:20px;right:-30px;animation:floatY 5s ease-in-out infinite 1.2s;box-shadow:var(--shadow-md)}
  .ghost-card .gc-emoji{font-size:32px;margin-bottom:8px}
  .ghost-card .gc-name{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--black)}
  .ghost-card .gc-price{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;color:var(--black);margin-top:6px}
  @keyframes floatY{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}
  /* Trust pillars */
  .trust{max-width:1240px;margin:0 auto;padding:0 40px 60px;display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
  .trust-item{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:24px;transition:all .25s}
  .trust-item:hover{border-color:var(--black);transform:translateY(-3px);box-shadow:var(--shadow-md)}
  .trust-icon{width:42px;height:42px;background:var(--lime);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:14px;color:var(--black)}
  .trust-icon svg{width:22px;height:22px}
  .trust-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:6px;color:var(--black)}
  .trust-sub{font-size:13px;color:var(--muted);line-height:1.55}
  /* Brand marquee */
  .marquee-wrap{border-top:1px solid var(--border);border-bottom:1px solid var(--border);background:var(--cream);padding:22px 0;overflow:hidden;margin-bottom:80px}
  .marquee-track{display:flex;gap:60px;animation:slide 28s linear infinite;white-space:nowrap}
  @keyframes slide{from{transform:translateX(0)}to{transform:translateX(-50%)}}
  .marquee-item{font-family:'Syne',sans-serif;font-size:22px;font-weight:700;color:var(--muted-2);letter-spacing:.04em;display:flex;align-items:center;gap:12px}
  .marquee-item::after{content:'';width:8px;height:8px;background:var(--lime-2);border-radius:50%}
  /* Section header */
  .section-header{max-width:1240px;margin:0 auto;padding:0 40px 28px;display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap}
  .section-label{font-size:12px;font-weight:700;color:var(--lime-2);text-transform:uppercase;letter-spacing:.12em;margin-bottom:10px}
  .section-title{font-family:'Syne',sans-serif;font-size:36px;font-weight:800;letter-spacing:-1.2px;line-height:1.1;color:var(--black)}
  .section-title em{font-style:normal;position:relative;display:inline-block}
  .section-title em::after{content:'';position:absolute;bottom:2px;left:0;width:100%;height:10px;background:var(--lime);z-index:-1;border-radius:4px}
  .section-sub{color:var(--muted);font-size:15px;max-width:420px;margin-top:6px}
  /* Filters */
  .filters-bar{max-width:1240px;margin:0 auto;padding:0 40px 28px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .search-wrap{flex:1;min-width:240px;position:relative}
  .search-wrap svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted);width:16px;height:16px}
  .search-input{width:100%;background:var(--card-bg);border:1px solid var(--border);color:var(--black);font-family:inherit;font-size:14px;padding:12px 16px 12px 42px;border-radius:40px;outline:none;transition:border-color .2s}
  .search-input::placeholder{color:var(--muted)}
  .search-input:focus{border-color:var(--black)}
  .filter-btn{background:var(--pill);border:1px solid var(--border);color:var(--black);font-family:inherit;font-size:13px;font-weight:500;padding:9px 18px;border-radius:40px;cursor:pointer;transition:all .2s;white-space:nowrap}
  .filter-btn:hover{border-color:var(--black)}
  .filter-btn.active{background:var(--black);border-color:var(--black);color:var(--white)}
  .filter-select{background:var(--card-bg);border:1px solid var(--border);color:var(--black);font-family:inherit;font-size:13px;padding:10px 14px;border-radius:40px;cursor:pointer;outline:none;transition:border-color .2s}
  .filter-select:focus{border-color:var(--black)}
  .filter-select option{background:#ffffff;color:var(--black)}
  /* Phones grid */
  .phones-grid{max-width:1240px;margin:0 auto;padding:0 40px 100px;display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:22px}
  .phone-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;cursor:pointer;transition:all .25s;animation:fadeUp .5s ease both}
  .phone-card:hover{border-color:var(--black);transform:translateY(-5px);box-shadow:var(--shadow-lg)}
  @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
  .card-img{background:linear-gradient(135deg,#fafaf8 0%,#f0eee8 100%);height:200px;display:flex;align-items:center;justify-content:center;font-size:80px;border-bottom:1px solid var(--border);position:relative}
  .card-photo{width:100%;height:100%;object-fit:cover;display:block}
  .card-branch-tag{position:absolute;top:14px;left:14px;font-size:10px;font-weight:600;padding:4px 10px;border-radius:20px;background:rgba(255,255,255,.92);color:var(--black);border:1px solid var(--border);letter-spacing:.04em;backdrop-filter:blur(8px)}
  .card-cond-tag{position:absolute;top:14px;right:14px;font-size:10px;font-weight:600;padding:4px 10px;border-radius:20px;letter-spacing:.04em;text-transform:uppercase}
  .card-flash-tag{position:absolute;left:14px;bottom:14px;font-size:10px;font-weight:700;padding:5px 10px;border-radius:20px;background:var(--lime);color:var(--black);letter-spacing:.06em;text-transform:uppercase;box-shadow:var(--shadow-sm)}
  .cond-excellent{background:var(--tg);color:var(--tgt)}
  .cond-good{background:var(--tb);color:var(--tbt)}
  .cond-fair{background:var(--ta);color:var(--tat)}
  .cond-poor{background:var(--tr);color:var(--trt)}
  .card-body{padding:20px}
  .card-brand{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px}
  .card-name{font-family:'Syne',sans-serif;font-size:18px;font-weight:700;line-height:1.2;margin-bottom:12px;color:var(--black)}
  .card-specs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}
  .spec-tag{font-size:11px;padding:4px 10px;border-radius:20px;background:var(--pill);border:1px solid var(--border);color:var(--muted)}
  .card-footer{display:flex;align-items:center;justify-content:space-between}
  .card-price{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--black)}
  .card-price-old{font-size:12px;color:var(--muted);text-decoration:line-through;margin-top:3px}
  .card-batt{font-size:12px;color:var(--muted);margin-top:2px}
  .inquire-btn{background:var(--black);border:1px solid var(--black);color:var(--white);font-family:inherit;font-size:12px;font-weight:500;padding:8px 14px;border-radius:40px;cursor:pointer;transition:all .2s}
  .inquire-btn:hover{background:var(--lime);color:var(--black);border-color:var(--lime);transform:scale(1.04)}
  .empty-state{grid-column:1/-1;text-align:center;padding:80px 0;color:var(--muted)}
  .empty-state .big-icon{font-size:56px;margin-bottom:16px}
  .result-info{max-width:1240px;margin:0 auto;padding:0 40px 16px;font-size:13px;color:var(--muted)}
  .result-info strong{color:var(--black)}
  .flash-deals-grid{max-width:1240px;margin:0 auto;padding:0 40px 46px;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:22px}
  .flash-deal-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-md)}
  .flash-deal-media{height:190px;background:linear-gradient(135deg,#fafaf8 0%,#f0eee8 100%);display:flex;align-items:center;justify-content:center;font-size:82px;position:relative;overflow:hidden}
  .flash-deal-media img{width:100%;height:100%;object-fit:cover;display:block}
  .flash-deal-body{padding:18px}.flash-deal-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:700;margin-bottom:6px}.flash-deal-sub{color:var(--muted);font-size:13px;margin-bottom:12px}
  .flash-deal-row{display:flex;justify-content:space-between;align-items:flex-end;gap:12px}.flash-deal-expiry{font-size:12px;color:var(--muted)}
  .flash-prices{display:flex;flex-direction:column;align-items:flex-start}.flash-sale-price{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--black)}.flash-regular-price{font-size:13px;color:var(--muted);text-decoration:line-through}
  /* How it works */
  .how-section{background:var(--cream);border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:80px 0}
  .how-grid{max-width:1240px;margin:0 auto;padding:0 40px;display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:40px}
  .how-step{position:relative;padding:28px 24px;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);transition:all .25s}
  .how-step:hover{transform:translateY(-3px);box-shadow:var(--shadow-md)}
  .how-num{position:absolute;top:-18px;left:24px;width:36px;height:36px;background:var(--black);color:var(--lime);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:16px;font-weight:800}
  .how-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:700;margin:14px 0 8px;color:var(--black)}
  .how-desc{font-size:14px;color:var(--muted);line-height:1.65}
  /* Testimonials */
  .testi-section{padding:90px 0;max-width:1240px;margin:0 auto}
  .testi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;padding:0 40px;margin-top:36px}
  .testi-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);padding:26px;transition:all .25s}
  .testi-card:hover{border-color:var(--black);box-shadow:var(--shadow-md)}
  .stars{color:#f0b429;font-size:16px;letter-spacing:2px;margin-bottom:14px}
  .testi-quote{font-size:15px;color:var(--black);line-height:1.65;margin-bottom:18px}
  .testi-meta{display:flex;align-items:center;gap:12px;padding-top:16px;border-top:1px solid var(--border)}
  .testi-avatar{width:38px;height:38px;border-radius:50%;background:var(--lime);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;font-size:13px;color:var(--black)}
  .testi-name{font-size:13px;font-weight:600;color:var(--black)}
  .testi-role{font-size:11px;color:var(--muted)}
  /* FAQ */
  .faq-section{max-width:900px;margin:0 auto;padding:80px 40px;background:var(--cream);border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
  .faq-section-wrap{background:var(--cream)}
  .faq-list{margin-top:36px;display:flex;flex-direction:column;gap:10px}
  .faq-item{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;transition:all .2s}
  .faq-item.open{border-color:var(--black);box-shadow:var(--shadow-sm)}
  .faq-q{width:100%;background:transparent;border:none;color:var(--black);font-family:inherit;font-size:15px;font-weight:500;padding:18px 22px;text-align:left;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:12px}
  .faq-q .faq-arrow{transition:transform .25s;color:var(--lime-2);font-size:24px;line-height:1;font-weight:300}
  .faq-item.open .faq-arrow{transform:rotate(45deg)}
  .faq-a{max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease;color:var(--muted);font-size:14px;line-height:1.7;padding:0 22px}
  .faq-item.open .faq-a{max-height:200px;padding:0 22px 18px}
  /* Branches */
  .branches-section{max-width:1240px;margin:0 auto;padding:80px 40px}
  .branches-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:36px}
  .branch-card-pub{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;transition:all .25s;position:relative;overflow:hidden}
  .branch-card-pub::before{content:'';position:absolute;top:0;left:0;width:80px;height:80px;background:radial-gradient(circle,rgba(200,255,0,.25) 0%,transparent 70%);border-radius:50%}
  .branch-card-pub:hover{border-color:var(--black);transform:translateY(-3px);box-shadow:var(--shadow-md)}
  .branch-card-pub .bicon{font-size:32px;margin-bottom:14px;position:relative}
  .branch-card-pub .bname{font-family:'Syne',sans-serif;font-size:17px;font-weight:700;margin-bottom:6px;color:var(--black)}
  .branch-card-pub .baddr{font-size:13px;color:var(--muted);margin-bottom:14px;line-height:1.55}
  .branch-card-pub .bcount{font-size:12px;color:var(--black);font-weight:600;display:inline-block;background:var(--lime);padding:5px 12px;border-radius:20px;letter-spacing:.04em}
  /* CTA banner */
  .cta-banner{max-width:1240px;margin:0 auto 80px;padding:0 40px}
  .cta-inner{background:linear-gradient(135deg,var(--lime) 0%,var(--lime-2) 100%);color:var(--black);border-radius:var(--radius-lg);padding:50px;display:flex;justify-content:space-between;align-items:center;gap:30px;flex-wrap:wrap;position:relative;overflow:hidden}
  .cta-inner::before{content:'';position:absolute;right:-80px;bottom:-80px;width:280px;height:280px;border:40px solid rgba(0,0,0,.06);border-radius:50%}
  .cta-headline{font-family:'Syne',sans-serif;font-size:34px;font-weight:800;line-height:1.1;letter-spacing:-1px;max-width:600px;position:relative}
  .cta-sub{font-size:15px;color:rgba(0,0,0,.7);margin-top:10px;font-weight:500;position:relative}
  .cta-btn{background:var(--black);color:var(--white);font-weight:500;padding:16px 32px;border-radius:40px;font-size:15px;display:inline-flex;align-items:center;gap:10px;transition:transform .15s;position:relative;border:none;cursor:pointer;font-family:inherit}
  .cta-actions{display:flex;gap:12px;flex-wrap:wrap;position:relative}
  .cta-btn:hover{transform:scale(1.04)}
  /* Modal */
  .modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);z-index:200;align-items:center;justify-content:center;padding:20px}
  .modal-backdrop.open{display:flex}
  .modal-box{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);width:100%;max-width:520px;overflow:hidden;animation:modalIn .25s ease;box-shadow:var(--shadow-lg)}
  @keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
  .modal-img{background:linear-gradient(135deg,#fafaf8 0%,#f0eee8 100%);height:220px;display:flex;align-items:center;justify-content:center;font-size:96px;border-bottom:1px solid var(--border)}
  .modal-img{position:relative;overflow:hidden}
  .modal-photo{width:100%;height:100%;object-fit:cover;display:none}
  .modal-emoji{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
  .modal-body{padding:26px}
  .modal-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px}
  .modal-name{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--black)}
  .modal-brand{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
  .modal-close{background:var(--pill);border:1px solid var(--border);color:var(--muted);width:34px;height:34px;border-radius:50%;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;line-height:1;transition:all .2s}
  .modal-close:hover{background:var(--black);color:var(--white);border-color:var(--black)}
  .modal-specs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:22px}
  .modal-spec-item{background:var(--pill);border:1px solid var(--border);border-radius:12px;padding:11px 14px}
  .modal-spec-label{font-size:11px;color:var(--muted);margin-bottom:2px;text-transform:uppercase;letter-spacing:.08em}
  .modal-spec-val{font-size:14px;font-weight:500;color:var(--black)}
  .modal-footer{display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--border);padding-top:22px}
  .modal-price{font-family:'Syne',sans-serif;font-size:32px;font-weight:800;color:var(--black)}
  .modal-price-old{font-size:13px;color:var(--muted);text-decoration:line-through;margin-top:4px}
  .modal-price-label{font-size:12px;color:var(--muted)}
  .inquiry-box .modal-body{padding:24px}.inquiry-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.inquiry-box textarea{min-height:110px}
  .inquiry-meta{background:var(--pill);border:1px solid var(--border);border-radius:14px;padding:12px 14px;font-size:13px;color:var(--muted);margin-bottom:14px;line-height:1.6}
  .chatbot-shell{position:fixed;right:24px;bottom:24px;z-index:260;display:flex;flex-direction:column;align-items:flex-end;gap:12px}
  .chatbot-pill{max-width:220px;background:rgba(255,255,255,.95);border:1px solid var(--border);border-radius:999px;padding:10px 14px;font-size:12px;font-weight:600;color:var(--black);box-shadow:var(--shadow-md);backdrop-filter:blur(12px);transition:opacity .2s ease,transform .2s ease}
  .chatbot-shell.open .chatbot-pill{opacity:0;transform:translateY(8px);pointer-events:none}
  .chatbot-launcher{position:relative;width:66px;height:66px;border:none;border-radius:24px;background:linear-gradient(135deg,var(--black) 0%,#3a3a3f 100%);color:var(--white);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 18px 35px -18px rgba(0,0,0,.6),0 8px 18px rgba(0,0,0,.14);transition:transform .2s ease,box-shadow .2s ease}
  .chatbot-launcher:hover{transform:translateY(-2px) scale(1.03);box-shadow:0 24px 40px -18px rgba(0,0,0,.65),0 10px 24px rgba(0,0,0,.18)}
  .chatbot-launcher::after{content:'';position:absolute;inset:8px;border-radius:18px;border:1px solid rgba(255,255,255,.12)}
  .chatbot-launcher svg{position:relative;z-index:1}
  .chatbot-launcher-dot{position:absolute;top:10px;right:10px;width:12px;height:12px;border-radius:50%;background:var(--lime);border:2px solid var(--black);box-shadow:0 0 0 6px rgba(200,255,0,.14)}
  .chatbot-panel{position:absolute;right:0;bottom:78px;width:min(390px,calc(100vw - 28px));max-height:min(78vh,700px);background:var(--card-bg);border:1px solid var(--border);border-radius:28px;overflow:hidden;box-shadow:var(--shadow-lg);display:flex;flex-direction:column;opacity:0;visibility:hidden;transform:translateY(18px) scale(.96);transform-origin:bottom right;pointer-events:none;transition:opacity .22s ease,transform .22s ease,visibility 0s linear .22s}
  .chatbot-shell.open .chatbot-panel{opacity:1;visibility:visible;transform:translateY(0) scale(1);pointer-events:auto;transition:opacity .22s ease,transform .22s ease}
  .chatbot-header{padding:20px 20px 18px;background:linear-gradient(135deg,var(--black) 0%,#36363b 100%);color:var(--white);display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
  .chatbot-eyebrow{font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.62);margin-bottom:6px}
  .chatbot-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;line-height:1.1}
  .chatbot-sub{font-size:13px;color:rgba(255,255,255,.74);margin-top:6px;line-height:1.55;max-width:260px}
  .chatbot-close{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.12);color:rgba(255,255,255,.8);flex:0 0 auto}
  .chatbot-close:hover{background:var(--white);color:var(--black);border-color:var(--white)}
  .chatbot-thread{padding:18px;background:linear-gradient(180deg,#f8f8f8 0%,#f1f3f5 100%);display:flex;flex-direction:column;gap:12px;border-bottom:1px solid var(--border)}
  .chat-bubble{max-width:86%;padding:12px 14px;border-radius:18px;font-size:13px;line-height:1.6;box-shadow:var(--shadow-sm)}
  .chat-bubble.bot{align-self:flex-start;background:var(--white);border:1px solid var(--border);color:var(--black);border-bottom-left-radius:8px}
  .chat-context{align-self:flex-end;background:var(--lime);color:var(--black);padding:10px 14px;border-radius:18px;border-bottom-right-radius:8px;font-size:12px;font-weight:700;letter-spacing:.02em;box-shadow:var(--shadow-sm)}
  .chat-quick-row{display:flex;flex-wrap:wrap;gap:8px}
  .chat-quick{border:1px solid var(--border);background:rgba(255,255,255,.94);color:var(--black);padding:8px 12px;border-radius:999px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:border-color .2s ease,transform .15s ease,background .2s ease}
  .chat-quick:hover{border-color:var(--black);background:var(--white);transform:translateY(-1px)}
  .chatbot-form{padding:18px;background:var(--white)}
  .chatbot-form label{display:block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
  .chatbot-form input,.chatbot-form select,.chatbot-form textarea{width:100%;background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:12px 14px;font:inherit;color:var(--black);outline:none;transition:border-color .2s ease,box-shadow .2s ease}
  .chatbot-form input:focus,.chatbot-form select:focus,.chatbot-form textarea:focus{border-color:var(--black);box-shadow:0 0 0 3px rgba(29,29,31,.08)}
  .chatbot-form textarea{min-height:110px;resize:vertical}
  .chatbot-form select{appearance:none;background-image:linear-gradient(45deg,transparent 50%,var(--muted) 50%),linear-gradient(135deg,var(--muted) 50%,transparent 50%);background-position:calc(100% - 18px) calc(50% - 2px),calc(100% - 12px) calc(50% - 2px);background-size:6px 6px,6px 6px;background-repeat:no-repeat;padding-right:34px}
  .chatbot-send{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px}
  .chatbot-note{font-size:12px;color:var(--muted);line-height:1.5;max-width:200px}
  /* Footer */
  footer{background:var(--black);color:var(--white);border-top:1px solid var(--border);padding:60px 40px 30px}
  .footer-inner{max-width:1240px;margin:0 auto;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:50px;margin-bottom:40px}
  .footer-brand{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:14px;color:var(--white)}
  .footer-brand span{color:var(--lime)}
  .footer-tag{color:rgba(255,255,255,.6);font-size:14px;line-height:1.7;max-width:340px;margin-bottom:18px}
  .footer-social{display:flex;gap:10px}
  .social-btn{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.7);transition:all .2s}
  .social-btn:hover{background:var(--lime);color:var(--black);border-color:var(--lime)}
  .footer-col h4{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;margin-bottom:16px;text-transform:uppercase;letter-spacing:.08em;color:var(--white)}
  .footer-col ul{list-style:none;display:flex;flex-direction:column;gap:10px}
  .footer-col a{font-size:13px;color:rgba(255,255,255,.6);transition:color .2s}
  .footer-col a:hover{color:var(--lime)}
  .footer-bottom{max-width:1240px;margin:0 auto;padding-top:24px;border-top:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px}
  .footer-copy{font-size:12px;color:rgba(255,255,255,.5)}
  .footer-pay{display:flex;align-items:center;gap:10px;font-size:12px;color:rgba(255,255,255,.5)}
  .pay-pill{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);padding:5px 11px;border-radius:20px;font-weight:600;color:var(--white);font-size:11px}
  /* Responsive */
  @media (max-width:980px){
    .hero{grid-template-columns:1fr;padding:50px 20px 40px;gap:50px}
    .hero-visual{display:none}
    .trust{grid-template-columns:repeat(2,1fr);padding:0 20px 40px}
    .how-grid,.testi-grid,.branches-grid{grid-template-columns:1fr;padding:0 20px}
    .how-section{padding:60px 0}
    .testi-section,.branches-section{padding:60px 0}
    .footer-inner{grid-template-columns:1fr 1fr;gap:30px}
    .cta-inner{padding:32px;flex-direction:column;align-items:flex-start;text-align:left}
    .cta-headline{font-size:26px}
  }
  @media (max-width:640px){
    nav{padding:14px 20px}
    .nav-links li:not(:last-child){display:none}
    .filters-bar{padding:0 20px 20px}
    .phones-grid{padding:0 20px 60px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
    .section-header{padding:0 20px 16px}
    .result-info{padding:0 20px 12px}
    .footer-inner{grid-template-columns:1fr}
    .hero-stats{flex-wrap:wrap;gap:18px}
    .branches-section,.faq-section,.cta-banner{padding-left:20px;padding-right:20px}
    .chatbot-shell{right:12px;left:12px;bottom:12px}
    .chatbot-panel{width:100%;max-height:min(76vh,680px)}
    .chatbot-pill{display:none}
    .chatbot-send{flex-direction:column;align-items:stretch}
    .chatbot-note{max-width:none}
    .inquiry-grid{grid-template-columns:1fr}
  }
</style>
</head>
<body>

<div class="promo-bar">
  <span class="dot"></span>
  <?php if ($liveFlashSales): ?>
    <strong><?= count($liveFlashSales) ?> live flash deal<?= count($liveFlashSales) === 1 ? '' : 's' ?></strong> · Branch promos are running right now · <a href="#flash-deals">See deals</a>
  <?php else: ?>
    <strong>Limited offer</strong> · Free battery health check on every phone purchased this month · <a href="#phones">Browse now</a>
  <?php endif; ?>
</div>

<nav>
  <a href="index.php" class="nav-logo"><span class="nav-dot"></span><span>RF</span> Chein Gadgets</a>
  <ul class="nav-links">
    <li><a href="#phones">Phones</a></li>
    <li><a href="#how">How it works</a></li>
    <li><a href="#branches">Branches</a></li>
    <li><a href="#faq">FAQ</a></li>
    <li><a href="login.php" class="nav-cta">Admin login</a></li>
  </ul>
</nav>

<?php if ($publicFlash): ?>
<div class="page-flash"><div class="flash-msg"><?= e($publicFlash) ?></div></div>
<?php endif; ?>

<section class="hero">
  <div>
    <div class="hero-tag">Now serving · Bacolod City</div>
    <h1>Quality pre-owned phones at <em>honest</em> prices.</h1>
    <p class="lead">Every device hand-checked, battery-tested, and priced fairly. Walk into any of our 3 Bacolod branches or browse our live stock — what you see is what's on the shelf.</p>
    <div class="hero-actions">
      <a href="#phones" class="btn-primary">Browse phones <span>→</span></a>
      <a href="#how" class="btn-outline">How it works</a>
    </div>
    <div class="hero-stats">
      <div><div class="stat-num"><?= (int)$totalUnits ?><span>+</span></div><div class="stat-label">Devices in stock</div></div>
      <div><div class="stat-num"><?= count($branches) ?></div><div class="stat-label">Branch locations</div></div>
      <div><div class="stat-num"><?= $brandCount ?><span>+</span></div><div class="stat-label">Trusted brands</div></div>
      <div><div class="stat-num">7<span>d</span></div><div class="stat-label">Warranty period</div></div>
    </div>
  </div>
  <div class="hero-visual">
    <?php if ($featuredFlash): ?>
    <div class="deal-card">
      <div class="deal-tag">⚡ <?= e($featuredFlash['promo_label']) ?></div>
      <div class="deal-img"><?php if (!empty($featuredFlash['image_url'])): ?><img src="<?= e($featuredFlash['image_url']) ?>" alt="<?= e($featuredFlash['brand'].' '.$featuredFlash['model']) ?>"><?php else: ?><?= e($featuredFlash['emoji'] ?: '📱') ?><?php endif; ?></div>
      <div class="deal-name"><?= e($featuredFlash['brand'].' '.$featuredFlash['model']) ?></div>
      <div class="deal-spec"><?= e($featuredFlash['storage']) ?> · <?= e($featuredFlash['ram'] ?: '—') ?> · <?= e($featuredFlash['condition']) ?></div>
      <div class="deal-price-row">
        <div class="deal-price"><?= e(peso($featuredFlash['sale_price'])) ?></div>
        <div class="deal-price-old"><?= e(peso($featuredFlash['regular_price'])) ?></div>
      </div>
      <div class="deal-meta"><span>Ends <strong><?= e(date('M d, h:i A', strtotime($featuredFlash['ends_at']))) ?></strong></span><span>Branch <strong><?= e(str_replace('RF Chein - ','',$featuredFlash['branch_name'] ?? 'Main')) ?></strong></span></div>
    </div>
    <?php elseif ($featuredDeal): ?>
    <div class="deal-card">
      <div class="deal-tag">⚡ Best deal</div>
      <div class="deal-img"><?php if (!empty($featuredDeal['image_url'])): ?><img src="<?= e($featuredDeal['image_url']) ?>" alt="<?= e($featuredDeal['brand'].' '.$featuredDeal['model']) ?>"><?php else: ?><?= e($featuredDeal['emoji'] ?: '📱') ?><?php endif; ?></div>
      <div class="deal-name"><?= e($featuredDeal['brand'].' '.$featuredDeal['model']) ?></div>
      <div class="deal-spec"><?= e($featuredDeal['storage']) ?> · <?= e($featuredDeal['ram'] ?: '—') ?> · <?= e($featuredDeal['condition']) ?></div>
      <div class="deal-price-row">
        <div class="deal-price"><?= e(peso($featuredDeal['selling_price'])) ?></div>
        <?php if (!empty($featuredDeal['purchase_price']) && (float)$featuredDeal['purchase_price'] > (float)$featuredDeal['selling_price']*0.7): ?>
        <div class="deal-price-old"><?= e(peso((float)$featuredDeal['selling_price']*1.25)) ?></div>
        <?php endif; ?>
      </div>
      <div class="deal-meta"><span>Battery <strong><?= (int)$featuredDeal['battery'] ?>%</strong></span><span>Branch <strong><?= e(str_replace('RF Chein - ','',$featuredDeal['branch_name'] ?? 'Main')) ?></strong></span></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($liveFlashSales[1])): $f2 = $liveFlashSales[1]; ?>
    <div class="ghost-card">
      <div class="gc-emoji"><?= e($f2['emoji'] ?: '📱') ?></div>
      <div class="gc-name"><?= e($f2['brand'].' '.$f2['model']) ?></div>
      <div class="gc-price"><?= e(peso($f2['sale_price'])) ?></div>
    </div>
    <?php elseif (!empty($phones[1])): $f2 = $phones[1]; ?>
    <div class="ghost-card">
      <div class="gc-emoji"><?= e($f2['emoji'] ?: '📱') ?></div>
      <div class="gc-name"><?= e($f2['brand'].' '.$f2['model']) ?></div>
      <div class="gc-price"><?= e(peso($f2['selling_price'])) ?></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($liveFlashSales): ?>
<section id="flash-deals">
  <div class="section-header">
    <div>
      <div class="section-label">Live promotions</div>
      <div class="section-title">Flash deals <em>running now</em></div>
      <div class="section-sub">These branch promos are active right now. Inquiry before the timer runs out.</div>
    </div>
    <button class="btn-outline" onclick="openInquiryModal()">Ask about a deal</button>
  </div>
  <div class="flash-deals-grid">
    <?php foreach (array_slice($liveFlashSales, 0, 6) as $flashSale): ?>
      <div class="flash-deal-card" onclick="openModal(<?= (int)$flashSale['phone_id'] ?>)">
        <div class="flash-deal-media">
          <?php if (!empty($flashSale['image_url'])): ?>
            <img src="<?= e($flashSale['image_url']) ?>" alt="<?= e($flashSale['brand'].' '.$flashSale['model']) ?>">
          <?php else: ?>
            <?= e($flashSale['emoji'] ?: '📱') ?>
          <?php endif; ?>
        </div>
        <div class="flash-deal-body">
          <div class="flash-deal-title"><?= e($flashSale['brand'].' '.$flashSale['model']) ?></div>
          <div class="flash-deal-sub"><?= e($flashSale['title']) ?> · <?= e(str_replace('RF Chein - ', '', $flashSale['branch_name'] ?? '—')) ?></div>
          <div class="flash-deal-row">
            <div class="flash-prices">
              <div class="flash-sale-price"><?= e(peso($flashSale['sale_price'])) ?></div>
              <div class="flash-regular-price"><?= e(peso($flashSale['regular_price'])) ?></div>
            </div>
            <div class="flash-deal-expiry">Ends <?= e(date('M d, h:i A', strtotime($flashSale['ends_at']))) ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Trust pillars -->
<section class="trust">
  <div class="trust-item">
    <div class="trust-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M12 2 4 5v6c0 5 3.5 9 8 11 4.5-2 8-6 8-11V5l-8-3z"/></svg></div>
    <div class="trust-title">Quality checked</div>
    <div class="trust-sub">12-point inspection on every unit — battery, screen, IMEI, sensors, and more.</div>
  </div>
  <div class="trust-item">
    <div class="trust-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
    <div class="trust-title">7-day warranty</div>
    <div class="trust-sub">Hassle-free replacement if anything's off — bring it back, we'll make it right.</div>
  </div>
  <div class="trust-item">
    <div class="trust-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 12h.01M2 10h20"/></svg></div>
    <div class="trust-title">Pay your way</div>
    <div class="trust-sub">Cash · GCash · Maya · Bank transfer. Pay how you like, instantly.</div>
  </div>
  <div class="trust-item">
    <div class="trust-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></div>
    <div class="trust-title">Honest pricing</div>
    <div class="trust-sub">No hidden fees. The price you see is the price you pay — guaranteed.</div>
  </div>
</section>

<!-- Brand marquee -->
<div class="marquee-wrap">
  <div class="marquee-track">
    <?php for ($k=0;$k<2;$k++): foreach ($brandList as $bn): ?>
      <div class="marquee-item"><?= e($bn) ?></div>
    <?php endforeach; endfor; ?>
  </div>
</div>

<section id="phones">
  <div class="section-header">
    <div>
      <div class="section-label">Live stock · updated today</div>
      <div class="section-title">Browse <em>all phones</em></div>
      <div class="section-sub">Showing real-time inventory across all 3 branches.</div>
    </div>
    <a href="#how" class="btn-outline">Need help choosing?</a>
  </div>

  <div class="filters-bar">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" class="search-input" id="search" placeholder="Search brand or model..." oninput="renderPhones()">
    </div>
    <button class="filter-btn active" onclick="setFilter('all',this)">All</button>
    <?php foreach (array_slice($brandList,0,5) as $bn): ?>
      <button class="filter-btn" onclick="setFilter('<?= e($bn) ?>',this)"><?= e($bn) ?></button>
    <?php endforeach; ?>
    <select class="filter-select" id="cond-filter" onchange="renderPhones()">
      <option value="">Any condition</option>
      <option value="Excellent">Excellent</option>
      <option value="Good">Good</option>
      <option value="Fair">Fair</option>
    </select>
    <select class="filter-select" id="branch-filter" onchange="renderPhones()">
      <option value="">All branches</option>
      <?php foreach ($branches as $b): ?>
        <option value="<?= e(str_replace('RF Chein - ','',$b['name'])) ?>"><?= e(str_replace('RF Chein - ','',$b['name'])) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="filter-select" id="sort-filter" onchange="renderPhones()">
      <option value="default">Sort: Default</option>
      <option value="price-asc">Price: Low → High</option>
      <option value="price-desc">Price: High → Low</option>
      <option value="batt-desc">Battery: Best first</option>
    </select>
  </div>

  <div class="result-info" id="result-info"></div>
  <div class="phones-grid" id="phones-grid"></div>
</section>

<!-- How it works -->
<section id="how" class="how-section">
  <div class="section-header">
    <div>
      <div class="section-label">Simple process</div>
      <div class="section-title">How it <em>works</em></div>
    </div>
  </div>
  <div class="how-grid">
    <div class="how-step">
      <div class="how-num">1</div>
      <div class="how-title">Browse the catalog</div>
      <div class="how-desc">Search by brand, condition, or branch. Every listing shows real specs, real photos, and live stock.</div>
    </div>
    <div class="how-step">
      <div class="how-num">2</div>
      <div class="how-title">Visit any branch</div>
      <div class="how-desc">Drop by Main, Lacson, or SM Bacolod. See the unit, test it, ask anything — no pressure to buy.</div>
    </div>
    <div class="how-step">
      <div class="how-num">3</div>
      <div class="how-title">Walk away happy</div>
      <div class="how-desc">Pay your way, get the paperwork, and walk out with a quality device backed by our 7-day warranty.</div>
    </div>
  </div>
</section>

<!-- Testimonials -->
<section class="testi-section">
  <div class="section-header">
    <div>
      <div class="section-label">Real customers</div>
      <div class="section-title">Why Bacolod <em>trusts us</em></div>
    </div>
  </div>
  <div class="testi-grid">
    <div class="testi-card">
      <div class="stars">★★★★★</div>
      <div class="testi-quote">"Got a Galaxy A54 here for half the new price. Battery still 85%, no scratches I could see, and they let me test everything before paying. Totally legit shop."</div>
      <div class="testi-meta"><div class="testi-avatar">MS</div><div><div class="testi-name">Maria Santos</div><div class="testi-role">Customer · Main Branch</div></div></div>
    </div>
    <div class="testi-card">
      <div class="stars">★★★★★</div>
      <div class="testi-quote">"Honest staff, no overpriced units. They told me the truth about each phone — even pointed out flaws. That's rare. Will buy again."</div>
      <div class="testi-meta"><div class="testi-avatar">JR</div><div><div class="testi-name">Jose Reyes</div><div class="testi-role">Customer · Lacson Branch</div></div></div>
    </div>
    <div class="testi-card">
      <div class="stars">★★★★★</div>
      <div class="testi-quote">"My iPhone died after 3 days, brought it back, they replaced it on the spot. No questions, no drama. That's how you build a loyal customer."</div>
      <div class="testi-meta"><div class="testi-avatar">AG</div><div><div class="testi-name">Ana Garcia</div><div class="testi-role">Customer · SM Branch</div></div></div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section id="faq" class="faq-section">
  <div class="section-header" style="padding:0 0 8px">
    <div>
      <div class="section-label">Common questions</div>
      <div class="section-title">Everything you <em>need to know</em></div>
    </div>
  </div>
  <div class="faq-list">
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Are these phones really tested? <span class="faq-arrow">+</span></button><div class="faq-a">Yes — every unit goes through a 12-point inspection covering battery health, screen, charging port, speakers, microphone, cameras, IMEI verification, and more before it's listed.</div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Do you offer warranty? <span class="faq-arrow">+</span></button><div class="faq-a">Every phone comes with a 7-day replacement warranty for hardware defects. If the phone develops a fault that wasn't disclosed, bring it back — we'll replace or refund.</div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Can I reserve a unit online? <span class="faq-arrow">+</span></button><div class="faq-a">Call or message us at 0912-345-6789 to reserve. We'll hold the unit at your chosen branch for up to 24 hours so you can come test it.</div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What payment methods do you accept? <span class="faq-arrow">+</span></button><div class="faq-a">Cash, GCash, Maya, and bank transfer. Full payment at pickup — no installments at this time.</div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Do you buy used phones? <span class="faq-arrow">+</span></button><div class="faq-a">Yes — bring your phone (with charger and box if available) to any branch. We'll inspect it and offer a fair trade-in or buy-back price on the spot.</div></div>
  </div>
</section>

<!-- Branches -->
<section id="branches" class="branches-section">
  <div class="section-header" style="padding:0 0 8px">
    <div>
      <div class="section-label">Visit us</div>
      <div class="section-title">Three convenient <em>locations</em></div>
      <div class="section-sub">Open Mon–Sat · 9AM to 7PM · Walk-ins always welcome</div>
    </div>
  </div>
  <div class="branches-grid">
    <?php $icons=['🏪','🏬','🛍️','🏢']; foreach ($branches as $i=>$b): ?>
      <div class="branch-card-pub">
        <div class="bicon"><?= $icons[$i % count($icons)] ?></div>
        <div class="bname"><?= e($b['name']) ?></div>
        <div class="baddr"><?= e($b['address']) ?><br>Mon–Sat, 9AM–7PM</div>
        <span class="bcount"><?= (int)$b['units'] ?> units in stock</span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- CTA banner -->
<section id="inquiries" class="cta-banner">
  <div class="cta-inner">
    <div>
      <div class="cta-headline">Ready to find your next phone?</div>
      <div class="cta-sub">Call us at <strong>0912-345-6789</strong> or drop by any branch — we're open until 7PM.</div>
    </div>
    <div class="cta-actions">
      <button class="cta-btn" onclick="window.location.href='#phones'">Browse the catalog →</button>
      <button class="cta-btn" onclick="openInquiryModal()">Start an inquiry →</button>
    </div>
  </div>
</section>

<!-- Modal -->
<div class="modal-backdrop" id="modal" onclick="closeModal(event)">
  <div class="modal-box">
    <div class="modal-img">
      <img id="modal-photo" class="modal-photo" alt="Device photo">
      <div class="modal-emoji" id="modal-emoji">📱</div>
    </div>
    <div class="modal-body">
      <div class="modal-top">
        <div>
          <div class="modal-brand" id="modal-brand"></div>
          <div class="modal-name" id="modal-name"></div>
        </div>
        <button class="modal-close" onclick="document.getElementById('modal').classList.remove('open')">×</button>
      </div>
      <div class="modal-specs" id="modal-specs"></div>
      <div class="modal-footer">
        <div>
          <div class="modal-price-label">Selling price</div>
          <div class="modal-price" id="modal-price"></div>
          <div class="modal-price-old" id="modal-price-old"></div>
        </div>
        <button class="btn-primary" onclick="openInquiryModal(activeModalPhoneId)">Inquire now</button>
      </div>
    </div>
  </div>
</div>

<div class="chatbot-shell" id="inquiry-modal">
  <div class="chatbot-pill">Need help? Chat with RF Chein.</div>
  <div class="chatbot-panel" id="chatbot-panel">
    <div class="chatbot-header">
      <div>
        <div class="chatbot-eyebrow">Store assistant</div>
        <div class="chatbot-title" id="inquiry-title">RF Chein Chat</div>
        <div class="chatbot-sub">Ask about stock, reservations, branch pickup, or live promos and we will route it to the right team.</div>
      </div>
      <button type="button" class="modal-close chatbot-close" onclick="closeInquiryModal()" aria-label="Collapse chat">×</button>
    </div>
    <div class="chatbot-thread">
      <div class="chat-bubble bot">Hi. Tell us what phone you are interested in and which branch should handle the inquiry.</div>
      <div class="chat-bubble bot inquiry-meta" id="inquiry-meta">Choose a branch, tell us what you need, and we will forward your message to the correct team.</div>
      <div class="chat-context" id="inquiry-context">General inquiry</div>
      <div class="chat-quick-row">
        <button type="button" class="chat-quick" onclick="applyInquiryPreset('availability')">Check availability</button>
        <button type="button" class="chat-quick" onclick="applyInquiryPreset('reservation')">Reserve a unit</button>
        <button type="button" class="chat-quick" onclick="applyInquiryPreset('visit')">Plan a branch visit</button>
      </div>
    </div>
    <form method="post" action="actions/create_inquiry.php" class="chatbot-form">
      <input type="hidden" name="phone_id" id="inquiry-phone-id">
      <input type="hidden" name="subject" id="inquiry-subject">
      <div class="inquiry-grid">
        <div><label>Full name</label><input type="text" name="customer_name" required placeholder="e.g. Juan Dela Cruz"></div>
        <div><label>Contact number</label><input type="text" name="contact_number" required placeholder="e.g. 0912-345-6789"></div>
      </div>
      <div class="inquiry-grid" style="margin-top:12px">
        <div><label>Preferred channel</label>
          <select name="preferred_channel"><option>Website</option><option>Call</option><option>SMS</option><option>Facebook</option><option>Email</option></select>
        </div>
        <div><label>Branch</label>
          <select name="branch_id" id="inquiry-branch-id" required>
            <option value="">Select branch</option>
            <?php foreach ($branches as $branchOption): ?>
              <option value="<?= (int)$branchOption['id'] ?>"><?= e(str_replace('RF Chein - ', '', $branchOption['name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="margin-top:12px"><label>Message</label><textarea name="message" id="inquiry-message" required placeholder="Ask about condition, availability, reservation, or flash sale details."></textarea></div>
      <div class="chatbot-send">
        <div class="chatbot-note">We will reply through your selected channel as soon as the branch team is available.</div>
        <button type="submit" class="btn-primary">Send inquiry</button>
      </div>
    </form>
  </div>
  <button type="button" class="chatbot-launcher" id="chatbot-launcher" onclick="toggleInquiryModal()" aria-controls="chatbot-panel" aria-expanded="false" aria-label="Open inquiry chat">
    <span class="chatbot-launcher-dot"></span>
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M7 10h10"></path>
      <path d="M7 14h6"></path>
      <path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 8.4 8.4 0 0 1-4.1-1.1L3 20l1.3-4.1A8.5 8.5 0 1 1 21 11.5Z"></path>
    </svg>
  </button>
</div>

<footer>
  <div class="footer-inner">
    <div>
      <div class="footer-brand"><span>RF</span> Chein Gadgets</div>
      <div class="footer-tag">Bacolod's trusted pre-owned smartphone shop. Honest prices, real warranties, and a 12-point inspection on every device since day one.</div>
      <div class="footer-social">
        <a href="#" class="social-btn" aria-label="Facebook"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.7l-.4 2.9h-2.3v7A10 10 0 0 0 22 12z"/></svg></a>
        <a href="#" class="social-btn" aria-label="Instagram"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.4A4 4 0 1 1 12.6 8 4 4 0 0 1 16 11.4zM17.5 6.5h.01"/></svg></a>
        <a href="#" class="social-btn" aria-label="Phone"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.8 12.8 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.8 12.8 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></a>
      </div>
    </div>
    <div class="footer-col">
      <h4>Shop</h4>
      <ul>
        <li><a href="#phones">All phones</a></li>
        <li><a href="#phones">New arrivals</a></li>
        <li><a href="#branches">Branches</a></li>
        <li><a href="#faq">FAQ</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Company</h4>
      <ul>
        <li><a href="#how">How it works</a></li>
        <li><a href="#">About us</a></li>
        <li><a href="#">Terms of service</a></li>
        <li><a href="#">Privacy policy</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Contact</h4>
      <ul>
        <li><a href="tel:0912-345-6789">📞 0912-345-6789</a></li>
        <li><a href="mailto:hello@rfchein.com">✉ hello@rfchein.com</a></li>
        <li><a href="#branches">📍 3 branches in Bacolod</a></li>
        <li><a href="login.php">🔐 Admin login</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-copy">© <?= date('Y') ?> RF Chein Gadgets · All rights reserved · Bacolod City, Philippines</div>
    <div class="footer-pay">Accept: <span class="pay-pill">Cash</span><span class="pay-pill">GCash</span><span class="pay-pill">Maya</span><span class="pay-pill">Bank</span></div>
  </div>
</footer>

<script>
const phones = <?= json_encode($jsPhones, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const inquiryBranches = <?= json_encode($jsInquiryBranches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let activeFilter = 'all';
let activeModalPhoneId = null;
let activeInquiryPhoneId = null;

function esc(value){
  return String(value ?? '').replace(/[&<>"']/g, function(ch){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
  });
}

function mediaMarkup(p){
  return p.image ? `<img class="card-photo" src="${esc(p.image)}" alt="${esc(p.brand + ' ' + p.model)}">` : `<span>${esc(p.emoji)}</span>`;
}

function effectivePrice(p){
  return p.flash_price || p.price;
}

function setFilter(val, el){
  activeFilter = val;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  renderPhones();
}
function getCondClass(c){return c==='Excellent'?'cond-excellent':c==='Good'?'cond-good':c==='Fair'?'cond-fair':'cond-poor';}
function renderPhones(){
  const search = document.getElementById('search').value.toLowerCase();
  const cond = document.getElementById('cond-filter').value;
  const branch = document.getElementById('branch-filter').value;
  const sort = document.getElementById('sort-filter').value;
  let filtered = phones.filter(p => {
    const mb = activeFilter==='all' || p.brand===activeFilter;
    const ms = !search || (p.brand+' '+p.model).toLowerCase().includes(search);
    const mc = !cond || p.cond===cond;
    const mbr = !branch || p.branch===branch;
    return mb && ms && mc && mbr;
  });
  if (sort==='price-asc') filtered.sort((a,b)=>effectivePrice(a)-effectivePrice(b));
  else if (sort==='price-desc') filtered.sort((a,b)=>effectivePrice(b)-effectivePrice(a));
  else if (sort==='batt-desc') filtered.sort((a,b)=>b.batt-a.batt);

  const grid = document.getElementById('phones-grid');
  document.getElementById('result-info').innerHTML = `Showing <strong>${filtered.length}</strong> of <strong>${phones.length}</strong> units`;
  if (!filtered.length){ grid.innerHTML = `<div class="empty-state"><div class="big-icon">🔍</div><p>No phones match your filters.</p></div>`; return; }
  grid.innerHTML = filtered.map((p,i) => `
    <div class="phone-card" onclick="openModal(${p.id})" style="animation-delay:${i*0.04}s">
      <div class="card-img">
        ${mediaMarkup(p)}
        <span class="card-branch-tag">${p.branch}</span>
        <span class="card-cond-tag ${getCondClass(p.cond)}">${p.cond}</span>
        ${p.flash_price ? `<span class="card-flash-tag">${esc(p.flash_label || 'Flash Sale')}</span>` : ''}
      </div>
      <div class="card-body">
        <div class="card-brand">${esc(p.brand)}</div>
        <div class="card-name">${esc(p.model)}</div>
        <div class="card-specs">
          <span class="spec-tag">${esc(p.storage)}</span>
          ${p.ram?`<span class="spec-tag">${esc(p.ram)} RAM</span>`:''}
          ${p.color?`<span class="spec-tag">${esc(p.color)}</span>`:''}
        </div>
        <div class="card-footer">
          <div>
            <div class="card-price">₱${effectivePrice(p).toLocaleString()}</div>
            ${p.flash_price ? `<div class="card-price-old">₱${p.price.toLocaleString()}</div>` : ''}
            <div class="card-batt">🔋 ${p.batt}% battery</div>
          </div>
          <button class="inquire-btn" onclick="event.stopPropagation();openModal(${p.id})">View details</button>
        </div>
      </div>
    </div>`).join('');
}
function openModal(id){
  const p = phones.find(ph => ph.id === id);
  if (!p) return;
  activeModalPhoneId = id;
  const modalPhoto = document.getElementById('modal-photo');
  const modalEmoji = document.getElementById('modal-emoji');
  if (p.image) {
    modalPhoto.src = p.image;
    modalPhoto.style.display = 'block';
    modalEmoji.style.display = 'none';
  } else {
    modalPhoto.removeAttribute('src');
    modalPhoto.style.display = 'none';
    modalEmoji.style.display = 'flex';
    modalEmoji.textContent = p.emoji;
  }
  document.getElementById('modal-brand').textContent = p.brand;
  document.getElementById('modal-name').textContent = p.model;
  document.getElementById('modal-price').textContent = '₱' + effectivePrice(p).toLocaleString();
  document.getElementById('modal-price-old').textContent = p.flash_price ? 'Regular price ₱' + p.price.toLocaleString() : '';
  document.getElementById('modal-specs').innerHTML = [
    {label:'Storage',val:p.storage},{label:'RAM',val:p.ram||'—'},
    {label:'Battery health',val:p.batt+'%'},{label:'Condition',val:p.cond},
    {label:'Color',val:p.color||'—'},{label:'Accessories',val:p.accessories},
    {label:'Branch',val:p.branch},{label:'Notes',val:p.notes||'—'},
    {label:'Promo',val:p.flash_price ? (p.flash_label || 'Flash Sale') : 'Standard listing'},
    {label:'Promo ends',val:p.flash_until ? new Date(p.flash_until).toLocaleString() : '—'},
  ].map(s => `<div class="modal-spec-item"><div class="modal-spec-label">${esc(s.label)}</div><div class="modal-spec-val">${esc(s.val)}</div></div>`).join('');
  document.getElementById('modal').classList.add('open');
}
function closeModal(e){if(e.target === document.getElementById('modal')) document.getElementById('modal').classList.remove('open');}
function setInquiryOpen(isOpen){
  const shell = document.getElementById('inquiry-modal');
  const launcher = document.getElementById('chatbot-launcher');
  shell.classList.toggle('open', isOpen);
  launcher.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}
function toggleInquiryModal(){
  const shell = document.getElementById('inquiry-modal');
  setInquiryOpen(!shell.classList.contains('open'));
}
function branchNameById(id){
  const branch = inquiryBranches.find(item => item.id === Number(id));
  return branch ? branch.name : 'selected branch';
}
function updateInquiryMeta(){
  const branchSelect = document.getElementById('inquiry-branch-id');
  const branchName = branchSelect && branchSelect.value ? branchNameById(branchSelect.value) : 'the selected branch';
  const p = activeInquiryPhoneId ? phones.find(ph => ph.id === activeInquiryPhoneId) : null;
  document.getElementById('inquiry-meta').textContent = p
    ? `${branchName} will receive this inquiry. Ask about availability, reservation, condition, or flash sale details for ${p.brand} ${p.model}.`
    : `Tell us what kind of phone or branch support you need, and ${branchName} will receive the inquiry.`;
  document.getElementById('inquiry-context').textContent = p ? `${p.brand} ${p.model}` : 'General inquiry';
}
function applyInquiryPreset(type){
  const p = activeInquiryPhoneId ? phones.find(ph => ph.id === activeInquiryPhoneId) : null;
  const presets = {
    availability: p
      ? `Hi, is the ${p.brand} ${p.model}${p.flash_price ? ' flash sale unit' : ''} still available?`
      : 'Hi, I would like to know which phones are currently available.',
    reservation: p
      ? `Hi, can I reserve the ${p.brand} ${p.model} for branch pickup today?`
      : 'Hi, I would like to reserve a phone. What is the process?',
    visit: p
      ? `Hi, when can I visit the branch to check the ${p.brand} ${p.model} in person?`
      : 'Hi, what time can I visit the branch to check the available phones?'
  };
  const messageBox = document.getElementById('inquiry-message');
  messageBox.value = presets[type] || messageBox.value;
  messageBox.focus();
  setInquiryOpen(true);
}
function openInquiryModal(id){
  const p = id ? phones.find(ph => ph.id === id) : null;
  activeInquiryPhoneId = p ? p.id : null;
  document.getElementById('modal').classList.remove('open');
  document.getElementById('inquiry-phone-id').value = p ? p.id : '';
  document.getElementById('inquiry-branch-id').value = p ? String(p.branch_id) : '';
  document.getElementById('inquiry-subject').value = p ? `Inquiry for ${p.brand} ${p.model}` : 'General inquiry';
  document.getElementById('inquiry-title').textContent = p ? `Ask about ${p.brand} ${p.model}` : 'RF Chein Chat';
  document.getElementById('inquiry-message').value = p
    ? `Hi, I would like to ask about the ${p.brand} ${p.model}${p.flash_price ? ' flash sale' : ''}. Is it still available?`
    : '';
  updateInquiryMeta();
  setInquiryOpen(true);
}
function closeInquiryModal(){setInquiryOpen(false);}
function toggleFaq(btn){btn.parentElement.classList.toggle('open');}
document.getElementById('inquiry-branch-id')?.addEventListener('change', updateInquiryMeta);
document.addEventListener('keydown', e => {
  if(e.key==='Escape') {
    document.getElementById('modal').classList.remove('open');
    setInquiryOpen(false);
  }
});
updateInquiryMeta();
renderPhones();
</script>
</body>
</html>
