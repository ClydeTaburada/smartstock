<?php
// config/db.php — PDO connection for SmartStock

require_once __DIR__ . '/credentials.php';

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    $setupUrl = 'setup.php';
    die(
        '<div style="font-family:system-ui;padding:32px;max-width:640px;margin:40px auto;background:#fff1f1;border:1px solid #f3c2c2;border-radius:12px;color:#6b1111">'
        . '<h2 style="margin:0 0 10px">Database connection failed</h2>'
        . '<p>' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p style="font-size:13px;color:#8a3b3b;margin-top:12px">Make sure MySQL is running in XAMPP. If this is your first run, click below to auto-create the database and seed data:</p>'
        . '<a href="' . $setupUrl . '" style="display:inline-block;margin-top:8px;background:#1D9E75;color:#fff;padding:10px 18px;border-radius:20px;text-decoration:none;font-weight:500">Run installer →</a>'
        . '</div>'
    );
}
