<?php
declare(strict_types=1);
$config = require __DIR__ . '/api/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Employee Monitor Setup</title><style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0}.box{max-width:520px;margin:50px auto;background:#fff;padding:28px;border-radius:14px;box-shadow:0 8px 30px #0001}label{display:block;margin-top:14px;font-weight:600}input,select{width:100%;box-sizing:border-box;padding:11px;margin-top:6px;border:1px solid #ccd3df;border-radius:8px}button{margin-top:20px;padding:12px 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}.warn{background:#fff7ed;border:1px solid #fed7aa;padding:12px;border-radius:8px}</style></head><body><div class="box"><h2>Employee Call Monitor – Manager Setup</h2><div class="warn">Use this page once to create or update the manager account. After successful setup, remove <b>setup.php</b> from the deployed backend.</div><form method="post"><label>Install Key<input name="install_key" type="password" required></label><label>Manager Email<input name="email" type="email" required></label><label>Full Name<input name="full_name" required></label><label>Role<select name="role"><option value="ADMIN">ADMIN</option><option value="MANAGER">MANAGER</option></select></label><label>Password<input name="password" type="password" minlength="8" required></label><button type="submit">Create / Update Manager</button></form></div></body></html>';
    exit;
}

$key = (string)($_POST['install_key'] ?? '');
if ($config['install_key'] === '' || !hash_equals((string)$config['install_key'], $key)) {
    http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo "Invalid install key."; exit;
}
$email = trim((string)($_POST['email'] ?? ''));
$name = trim((string)($_POST['full_name'] ?? ''));
$role = strtoupper(trim((string)($_POST['role'] ?? 'MANAGER')));
$password = (string)($_POST['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 8 || !in_array($role, ['ADMIN','MANAGER'], true)) {
    http_response_code(422); header('Content-Type: text/plain; charset=utf-8'); echo "Invalid setup data. Use a valid email, full name, ADMIN/MANAGER role, and a password of at least 8 characters."; exit;
}

try {
    $p = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $q = $p->prepare("INSERT INTO managers(email,password_hash,full_name,role,active) VALUES(:e,:h,:n,:r,1) ON CONFLICT (email) DO UPDATE SET password_hash=EXCLUDED.password_hash,full_name=EXCLUDED.full_name,role=EXCLUDED.role,active=1");
    $q->execute(['e'=>$email,'h'=>$hash,'n'=>$name,'r'=>$role]);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Setup Complete</title><body style="font-family:Arial,sans-serif;max-width:700px;margin:50px auto"><h2>Manager account created/updated.</h2><p>You can now log in using <b>'.htmlspecialchars($email, ENT_QUOTES, 'UTF-8').'</b>.</p><p><b>IMPORTANT:</b> remove <code>setup.php</code> from the deployed backend before continuing.</p></body>';
} catch (Throwable $e) {
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo "Setup failed: ".$e->getMessage();
}
