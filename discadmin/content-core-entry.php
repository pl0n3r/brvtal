<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();
header('Location: /discadmin/content-core.php', true, 302);
exit;
