<?php
declare(strict_types=1);

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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BRVTAL — Security</title>
<style>
:root{color-scheme:dark;--bg:#080808;--panel:#111;--line:#292929;--text:#f4f4f4;--muted:#8d8d8d;--red:#ff1717}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:42px 24px 80px}.eyebrow{font:11px/1 monospace;letter-spacing:.18em;color:var(--red);text-transform:uppercase}.top{display:flex;justify-content:space-between;gap:20px;align-items:end;border-bottom:1px solid var(--line);padding-bottom:22px}.top h1{font-size:42px;line-height:1;margin:10px 0 0;text-transform:uppercase}.back{color:#fff;text-decoration:none;border:1px solid var(--line);padding:10px 14px}.card{margin-top:24px;border:1px solid var(--line);background:var(--panel);padding:24px}.status{display:inline-flex;gap:8px;align-items:center;padding:7px 10px;border:1px solid var(--line);font:12px monospace}.dot{width:7px;height:7px;border-radius:50%;background:var(--muted)}.on .dot{background:var(--red)}h2{margin:0 0 8px;font-size:20px;text-transform:uppercase}.muted{color:var(--muted)}button{border:0;background:var(--red);color:#fff;padding:12px 16px;font-weight:700;cursor:pointer}button.secondary{background:transparent;border:1px solid var(--line)}input{background:#080808;color:#fff;border:1px solid #383838;padding:12px;width:180px;font:16px monospace;letter-spacing:.18em}.row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.setup{display:none;margin-top:24px;border-top:1px solid var(--line);padding-top:24px}.setup.show{display:block}.qr{width:230px;height:230px;background:#fff;padding:10px;margin:16px 0}.secret{font-family:monospace;word-break:break-all;border:1px solid var(--line);padding:12px;background:#080808}.codes{font-family:monospace;columns:2;max-width:420px;border:1px solid var(--line);padding:16px;white-space:pre-line}.notice{padding:12px;border-left:3px solid var(--red);background:#171010;margin:16px 0}.error{color:#ff7777}.success{color:#8cffb0}.hidden{display:none!important}
</style>
</head>
<body>
<div class="wrap">
<div class="top"><div><div class="eyebrow">DISCADMIN / SECURITY</div><h1>Two-Factor Authentication</h1></div><a class="back" href="/discadmin/">← DISCADMIN</a></div>
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
<script src="/discadmin/qrcode.min.js"></script>
<script>
const csrf=<?= json_encode($csrf) ?>;
const setup=document.getElementById('setup'),msg=document.getElementById('message');
const post=async(action,data={})=>{const r=await fetch('/discadmin/totp-api.php?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data),credentials:'same-origin'});const j=await r.json().catch(()=>({ok:false,error:'INVALID_RESPONSE'}));if(!r.ok||!j.ok)throw new Error(j.message||j.error||'Request failed');return j};
const say=(text,cls='success')=>{msg.className=cls;msg.textContent=text};
const start=document.getElementById('start');
if(start)start.onclick=async()=>{start.disabled=true;try{const j=await post('start');document.getElementById('secret').textContent=j.secret;setup.classList.add('show');if(window.QRCode){new QRCode(document.getElementById('qr'),{text:j.otpauth,width:210,height:210,correctLevel:QRCode.CorrectLevel.M})}else{document.getElementById('qr').textContent='QR library unavailable. Use the setup key above.'}}catch(e){start.disabled=false;say(e.message,'error')}};
const confirmBtn=document.getElementById('confirm');
if(confirmBtn)confirmBtn.onclick=async()=>{confirmBtn.disabled=true;try{const j=await post('confirm',{code:document.getElementById('code').value});say('2FA enabled. Save these recovery codes now; they will not be shown again.');setup.classList.add('show');document.getElementById('secret').textContent='';document.getElementById('qr').innerHTML='';const pre=document.createElement('pre');pre.className='codes';pre.textContent=j.recovery_codes.join('\n');document.getElementById('qr').after(pre)}catch(e){confirmBtn.disabled=false;say(e.message,'error')}};
const disable=document.getElementById('disable'),disableBox=document.getElementById('disableBox'),disableConfirm=document.getElementById('disableConfirm');
if(disable)disable.onclick=()=>{disableBox.classList.toggle('show');if(disableBox.classList.contains('show'))document.getElementById('disableCode').focus()};
if(disableConfirm)disableConfirm.onclick=async()=>{disableConfirm.disabled=true;try{await post('disable',{code:document.getElementById('disableCode').value});location.reload()}catch(e){disableConfirm.disabled=false;say(e.message,'error')}};
</script>
</body></html>
