<?php
declare(strict_types=1);
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=security', true, 302);
    exit;
}
header('Cache-Control: no-store');


require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/totp.php';

brvtal_admin_session_start();
if (!brvtal_admin_is_authenticated()) {
    header('Location: /discadmin/');
    exit;
}

$pdo = db();
$adminId = (int)$_SESSION['admin_id'];
$stmt = $pdo->prepare('SELECT id, email, is_active, totp_enabled, totp_confirmed_at FROM admins WHERE id = ? LIMIT 1');
$stmt->execute([$adminId]);
$admin = $stmt->fetch();
if (!$admin) {
    brvtal_admin_logout();
    header('Location: /discadmin/');
    exit;
}

$csrf = brvtal_admin_csrf_token();
$enabled = (bool)$admin['totp_enabled'];
?>
<section data-admin-module="security" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

<div class="wrap">
<h2>Two-Factor Authentication</h2>
<div class="card">
<div class="row"><span class="status <?= $enabled ? 'on' : '' ?>"><span class="dot"></span><?= $enabled ? '2FA ENABLED' : '2FA DISABLED' ?></span><span class="muted"><?= htmlspecialchars((string)$admin['email'], ENT_QUOTES, 'UTF-8') ?></span></div>
<div class="notice">Google Authenticator / RFC 6238 is available for this administrator. Enabling it here does not enable 2FA for other administrators.</div>
<?php if (!$enabled): ?>
<h2>Enable 2FA</h2><p class="muted">Start enrollment, scan the QR locally, then confirm with the current six-digit code. The secret is never sent to a third-party QR service.</p>
<button id="start">START ENROLLMENT</button>
<div id="setup" class="setup">
<h2>1. Add BRVTAL to your authenticator</h2>
<div id="qr" class="qr"></div>
<div class="muted">If you cannot scan the QR, enter this setup key manually:</div>
<div id="secret" class="secret"></div>
<h2 style="margin-top:24px">2. Confirm</h2>
<div class="row"><input id="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000"><button id="confirm">CONFIRM & ENABLE</button></div>
<div id="message"></div>
</div>
<?php else: ?>
<h2>2FA is active</h2><p class="muted">This administrator has confirmed a TOTP authenticator. Disabling it requires a current authenticator or unused recovery code.</p>
<button id="disable" class="secondary">DISABLE 2FA</button>
<div id="disableBox" class="setup">
<h2>Verify before disabling</h2>
<p class="muted">Enter a current six-digit authenticator code or one unused recovery code.</p>
<div class="row"><input id="disableCode" inputmode="numeric" maxlength="20" autocomplete="one-time-code" placeholder="000000"><button id="disableConfirm">CONFIRM DISABLE</button></div>
</div>
<div id="message"></div>
<?php endif; ?>
</div>
</div>



</section>
