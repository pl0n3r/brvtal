<?php
// Ejecutar una sola vez desde CLI si tienes acceso local, o adaptar temporalmente a un entorno protegido.
// Luego elimina este archivo del servidor.
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
if (!$email || !$password) { fwrite(STDERR,"Uso: php create_admin.php admin@dominio.com 'PasswordLargo'\n"); exit(1); }
$hash=password_hash($password,PASSWORD_DEFAULT);
$stmt=db()->prepare('INSERT INTO admins(email,password_hash,name) VALUES(?,?,?)');
$stmt->execute([strtolower($email),$hash,'BRVTAL Admin']);
echo "Administrador creado. Elimina este archivo del servidor.\n";
