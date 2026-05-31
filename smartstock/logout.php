<?php
require_once __DIR__ . '/includes/helpers.php';
$u = current_user();
if ($u) log_activity($db, $u['name'], 'System', 'Signed out');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('login.php');
