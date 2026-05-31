<?php
require_once __DIR__ . '/includes/helpers.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $u = $stmt->fetch();

        if ($u) {
            $stored = (string)$u['password'];
            $ok = false;

            // Accept either a bcrypt hash or the plain seed password,
            // rehashing to bcrypt the first time a seeded user signs in.
            if (password_verify($password, $stored)) {
                $ok = true;
                if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                    $new = password_hash($password, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$new, $u['id']]);
                }
            } elseif (hash_equals($stored, $password)) {
                $ok = true;
                $new = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$new, $u['id']]);
            }

            if ($ok) {
                $_SESSION['user'] = [
                    'id'        => (int)$u['id'],
                    'name'      => $u['name'],
                    'email'     => $u['email'],
                    'username'  => $u['username'],
                    'role'      => $u['role'],
                    'branch_id' => $u['branch_id'],
                ];
                log_activity($db, $u['name'], 'System', 'Signed in');
                redirect($u['role'] === 'Super Admin' ? 'superadmin.php' : 'dashboard.php');
            }
        }
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartStock — Sign in</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',sans-serif;background:#0a0a0a;color:#f5f2ed;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .card{width:100%;max-width:420px;background:#141414;border:1px solid #2a2a2a;border-radius:20px;padding:32px}
  .logo{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:6px}
  .logo span{color:#c8ff00}
  .sub{font-size:13px;color:#6b6860;margin-bottom:24px}
  label{font-size:12px;color:#9a978d;display:block;margin-bottom:6px;margin-top:14px}
  input{width:100%;background:#0f0f0f;border:1px solid #2a2a2a;color:#fff;padding:11px 14px;border-radius:10px;font-size:14px;font-family:inherit;outline:none;transition:border-color .15s}
  input:focus{border-color:#c8ff00}
  button{width:100%;margin-top:20px;background:#c8ff00;color:#0a0a0a;border:none;padding:12px;border-radius:30px;font-weight:500;font-size:14px;cursor:pointer;transition:background .15s;font-family:inherit}
  button:hover{background:#9bc400}
  .err{background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);color:#f87171;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:16px}
  .hint{margin-top:20px;padding:12px 14px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:10px;font-size:12px;color:#6b6860;line-height:1.7}
  .hint code{color:#c8ff00;background:rgba(200,255,0,0.08);padding:1px 6px;border-radius:4px;font-size:11px}
  .back{display:block;text-align:center;margin-top:16px;font-size:12px;color:#6b6860;text-decoration:none}
  .back:hover{color:#f5f2ed}
</style>
</head>
<body>
  <form class="card" method="post">
    <div class="logo"><span>Smart</span>Stock</div>
    <div class="sub">Sign in to continue to the admin panel</div>

    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>

    <label>Username or email</label>
    <input type="text" name="username" autofocus required value="<?= e($_POST['username'] ?? '') ?>">

    <label>Password</label>
    <input type="password" name="password" required>

    <button type="submit">Sign in</button>

    <div class="hint">
      <strong style="color:#c8ff00">Demo accounts</strong> (password: <code>password123</code>)<br>
      • <code>admin</code> — Super Admin<br>
      • <code>jfbusel</code> — Branch Admin<br>
      • <code>adex</code> — Staff
    </div>

    <a href="index.php" class="back">← Back to storefront</a>
  </form>
</body>
</html>
