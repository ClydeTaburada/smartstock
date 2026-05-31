<?php
// setup.php — one-click installer: creates the `smartstock` DB and runs database.sql.
// Visit http://localhost/smartstock/setup.php once, then delete this file.

require_once __DIR__ . '/config/credentials.php';
// We only need DB_* constants — NOT the PDO handle from config/db.php,
// because that one dies when the target database doesn't exist yet.

$sqlFile = __DIR__ . '/database.sql';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>SmartStock installer</title>';
echo '<style>body{font-family:system-ui;max-width:720px;margin:40px auto;padding:20px;line-height:1.6}
h1{font-size:22px;margin:0 0 8px}.ok{color:#0F6E56;background:#E1F5EE;padding:12px 16px;border-radius:8px;border:1px solid #B6E4D3}
.err{color:#A32D2D;background:#FCEBEB;padding:12px 16px;border-radius:8px;border:1px solid #F2B4B4}
.step{margin:8px 0;font-size:13px;color:#5b6170}code{background:#f4f4f4;padding:1px 6px;border-radius:4px}
a.btn{display:inline-block;margin-top:16px;background:#1D9E75;color:#fff;padding:10px 18px;border-radius:20px;text-decoration:none;font-weight:500}</style>';
echo '<h1>SmartStock — installer</h1>';

if (!is_readable($sqlFile)) {
    echo '<div class="err"><strong>Missing file:</strong> ' . htmlspecialchars($sqlFile) . '</div>';
    exit;
}

try {
    // Connect WITHOUT a database name so we can create it.
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo '<div class="step">✓ Connected to MySQL on <code>' . htmlspecialchars(DB_HOST) . '</code> as <code>' . htmlspecialchars(DB_USER) . '</code>.</div>';

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('Unable to read database.sql');
    }

    // MySQL can execute a multi-statement string via a single query() call
    // when using PDO's native driver (non-emulated). We keep it simple and
    // execute the whole file with exec().
    $pdo->exec($sql);

    echo '<div class="step">✓ Ran <code>database.sql</code> (created database, tables, and the minimal test seed).</div>';

    // Verify
    $pdo->exec('USE `' . DB_NAME . '`');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo '<div class="step">✓ Tables in <code>' . htmlspecialchars(DB_NAME) . '</code>: ' . htmlspecialchars(implode(', ', $tables)) . '</div>';

    echo '<div class="ok"><strong>Setup complete.</strong> You can now open the app.</div>';
    echo '<p style="font-size:13px;color:#5b6170;margin-top:14px">For security, delete <code>setup.php</code> from the folder now that the database exists.</p>';
    echo '<a class="btn" href="index.php">Open storefront →</a> &nbsp; ';
    echo '<a class="btn" href="login.php" style="background:#534AB7">Sign in →</a>';
} catch (Throwable $e) {
    echo '<div class="err"><strong>Setup failed:</strong><br>' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<p style="font-size:13px;color:#5b6170;margin-top:14px">Check that MySQL is running in XAMPP, and that the credentials in <code>config/db.php</code> match your local MySQL user (default XAMPP: <code>root</code>, no password).</p>';
}
