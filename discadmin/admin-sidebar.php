<?php
declare(strict_types=1);

/* Shared persistent navigation for standalone DISCADMIN screens. */
$adminSidebarPage = basename($_SERVER['PHP_SELF'] ?? '');
$adminSidebarLinks = [
    ['label' => 'DASHBOARD', 'href' => '/discadmin/', 'page' => 'index.php'],
    ['label' => 'CONTENT CORE', 'href' => '/discadmin/content-core.php', 'page' => 'content-core.php'],
    ['label' => 'SECURITY / 2FA', 'href' => '/discadmin/totp-status.php', 'page' => 'totp-status.php'],
];
?>
<style id="brvtal-shared-admin-sidebar">
body{padding-left:245px!important}
#brvtal-admin-sidebar{position:fixed;left:0;top:0;bottom:0;width:245px;z-index:9999;background:#070707;border-right:1px solid #292d31;color:#f4f5f6;padding:24px;display:flex;flex-direction:column;font-family:Arial,Helvetica,sans-serif}
#brvtal-admin-sidebar .brand{font:800 15px/1 monospace;letter-spacing:2px;margin-bottom:5px}
#brvtal-admin-sidebar .sub{font:8px/1.4 monospace;letter-spacing:1.6px;color:#737980;margin-bottom:24px}
#brvtal-admin-sidebar nav{display:flex;flex-direction:column;gap:4px}
#brvtal-admin-sidebar a{display:block;padding:11px 10px;color:#858b91;text-decoration:none;border:1px solid transparent;font:800 9px/1 monospace;letter-spacing:1.3px}
#brvtal-admin-sidebar a:hover,#brvtal-admin-sidebar a.active{color:#fff;border-color:#292d31;background:#0d0f10}
#brvtal-admin-sidebar a.active{border-left:3px solid #ff2038;padding-left:8px}
#brvtal-admin-sidebar .foot{margin-top:auto;border-top:1px solid #202326;padding-top:12px;font:8px/1.6 monospace;letter-spacing:.7px;color:#8d949a;text-transform:uppercase}
#brvtal-admin-sidebar .version{display:block;color:#fff;font-size:11px;font-weight:800;letter-spacing:1px;margin-top:3px}
@media(max-width:850px){body{padding-left:0!important;padding-top:68px!important}#brvtal-admin-sidebar{right:0;bottom:auto;width:100%;height:68px;padding:10px 12px;display:block;border-right:0;border-bottom:1px solid #292d31}#brvtal-admin-sidebar .brand{display:inline-block;margin:0 12px 0 0}#brvtal-admin-sidebar .sub{display:none}#brvtal-admin-sidebar nav{display:inline-flex;vertical-align:middle;flex-direction:row;gap:4px;overflow-x:auto;max-width:calc(100% - 110px)}#brvtal-admin-sidebar a{white-space:nowrap;padding:9px 10px}#brvtal-admin-sidebar .foot{display:none}}
</style>
<aside id="brvtal-admin-sidebar" aria-label="BRVTAL DISCADMIN navigation">
    <div class="brand">BRVTAL</div>
    <div class="sub">DISCADMIN / CONTROL</div>
    <nav>
        <?php foreach ($adminSidebarLinks as $link): ?>
            <a class="<?= $adminSidebarPage === $link['page'] ? 'active' : '' ?>" href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="foot">
        SYSTEM CONTROL
        <span class="version">v<?= htmlspecialchars((string)BRVTAL_APP_VERSION, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)BRVTAL_APP_ENV, ENT_QUOTES, 'UTF-8') ?></span>
        BUILD <?= htmlspecialchars((string)BRVTAL_APP_BUILD, ENT_QUOTES, 'UTF-8') ?>
    </div>
</aside>
