<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$installedFlag = __DIR__ . '/.installed';
if (is_file($installedFlag)) {
    http_response_code(403);
    exit('BRVTAL: instalador bloqueado. El administrador ya fue creado.');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $name = trim((string)($_POST['name'] ?? 'BRVTAL Admin'));

    try {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Introduce un email válido.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('La contraseña debe tener al menos 12 caracteres.');
        }
        if (strlen($name) < 2) {
            throw new RuntimeException('Introduce un nombre válido.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        $count = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($count > 0) {
            $pdo->rollBack();
            throw new RuntimeException('Ya existe un administrador. No se creó otro.');
        }

        $stmt = $pdo->prepare('INSERT INTO admins(email,password_hash,name) VALUES(?,?,?)');
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);

        $pdo->commit();

        // Lock the installer after successful setup.
        file_put_contents($installedFlag, date('c'));
        @chmod($installedFlag, 0600);

        $message = 'Administrador creado correctamente. El instalador quedó bloqueado. Ya puedes entrar a DISCADMIN.';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BRVTAL / INITIAL SETUP</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#050505;color:#f5f5f5;font-family:Arial,sans-serif}
.wrap{min-height:100vh;display:grid;place-items:center;padding:24px}.box{width:min(500px,100%);border:1px solid #252525;background:#0a0a0a;padding:32px}
h1{font-size:38px;margin:0 0 4px;font-weight:900;letter-spacing:-2px}.sub{font-size:10px;letter-spacing:3px;color:#777;margin-bottom:30px}
.field{margin:16px 0}.field label{display:block;color:#888;text-transform:uppercase;font-size:10px;letter-spacing:1px;margin-bottom:7px}
input{width:100%;padding:13px;background:#050505;color:#fff;border:1px solid #333}button{width:100%;padding:13px;background:#fff;color:#000;border:0;font-weight:800;cursor:pointer;margin-top:8px}
.note{font-size:11px;color:#777;line-height:1.6;margin-top:18px}.ok{border:1px solid #444;padding:14px;font-size:13px;line-height:1.5}.err{color:#ff5555;font-size:12px;margin:12px 0}
a{color:#fff}
</style>
</head>
<body><div class="wrap"><div class="box">
<h1>BRVTAL</h1><div class="sub">DISCADMIN / INITIAL SETUP</div>
<?php if ($message): ?>
<div class="ok"><?=htmlspecialchars($message, ENT_QUOTES, 'UTF-8')?><br><br><a href="./">→ Entrar a DISCADMIN</a></div>
<?php else: ?>
<?php if ($error): ?><div class="err"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<form method="post" autocomplete="off">
<div class="field"><label>Nombre</label><input name="name" value="BRVTAL Admin" required></div>
<div class="field"><label>Email de acceso</label><input name="email" type="email" required></div>
<div class="field"><label>Contraseña</label><input name="password" type="password" minlength="12" required></div>
<button>CREAR ADMINISTRADOR</button>
</form>
<div class="note">La contraseña debe tener mínimo 12 caracteres. Este instalador solo funciona mientras no exista un administrador y se bloquea después de crear el primero.</div>
<?php endif; ?>
</div></div></body></html>
