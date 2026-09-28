<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_staff_ops.php';

function fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}
function ok(bool $condition, string $message): void {
    if (!$condition) fail($message);
}

$mode = $argv[1] ?? '';
if ($mode === '') {
    foreach (['ac01', 'ac02', 'ac03'] as $contractMode) {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__FILE__)
            . ' ' . escapeshellarg($contractMode);
        passthru($command, $status);
        if ($status !== 0) {
            exit($status);
        }
    }
    echo "BRVTAL Admin Staff Ops contract tests passed.\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/brvtal-staff-ops-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);

try {
    if ($mode === 'ac01') {
        $target = brvtalStaffOpsCanonicalTarget('/ops/staff?role=admin&q=Ana%20Mar%C3%ADa');
        ok($target === '/ops/staff?q=Ana%20Mar%C3%ADa&role=admin', 'canonical query mismatch');
        $canonical = brvtalStaffOpsCanonicalRequest(
            'product-1','GET',$target,'1750000000',
            '0123456789abcdef0123456789abcdef',''
        );
        ok(
            hash_hmac('sha256', $canonical, 'test-secret-not-production')
                === '84cff31494ee89cc3961c33db0d93dfe7ddcfd1fc505841b0d3de9ddbf31b419',
            'known-answer HMAC mismatch'
        );
        foreach ([
            '/ops/staff?q=a+b',
            '/ops/staff?q',
            '/ops/staff?=x',
            '/ops/staff?q=a=b',
            '/ops/staff?q=%ZZ',
        ] as $bad) {
            try {
                brvtalStaffOpsCanonicalTarget($bad);
                fail('invalid query accepted: ' . $bad);
            } catch (BrvtalStaffOpsHttpError) {}
        }
        brvtalStaffOpsClaimNonce('product-1','0123456789abcdef0123456789abcdef',1000,$tmp);
        try {
            brvtalStaffOpsClaimNonce('product-1','0123456789abcdef0123456789abcdef',1001,$tmp);
            fail('replayed nonce accepted');
        } catch (BrvtalStaffOpsHttpError $error) {
            ok($error->status === 409, 'replayed nonce status mismatch');
        }
        putenv('BRVTAL_CONTROLBOT_STAFF_RATE_MAX=1');
        brvtalStaffOpsConsumeRate('product-1','127.0.0.1',1000,$tmp);
        try {
            brvtalStaffOpsConsumeRate('product-1','127.0.0.1',1001,$tmp);
            fail('rate limit not enforced');
        } catch (BrvtalStaffOpsHttpError $error) {
            ok($error->status === 429, 'rate-limit status mismatch');
        }
        putenv('BRVTAL_CONTROLBOT_STAFF_RATE_MAX');
        ok(brvtalStaffOpsProtectedRole('superadmin'), 'superadmin must be protected');
        ok(!brvtalStaffOpsAllowedManagedRole('viewer'), 'unenforced viewer role must be rejected');
        ok(brvtalStaffOpsAllowedManagedRole('admin'), 'admin must be the managed BRVTAL role');
        echo "AC-01 passed\n";
        exit(0);
    }

    if ($mode === 'ac02') {
        $calls = 0;
        $operation = static function() use (&$calls): array {
            $calls++;
            return ['status'=>200,'payload'=>['ok'=>true,'data'=>['id'=>7,'status'=>'suspended']]];
        };
        $first = brvtalStaffOpsIdempotent('brvtal|product-1|suspend|7','idem-key-123','abc',$operation,1000,$tmp);
        $second = brvtalStaffOpsIdempotent('brvtal|product-1|suspend|7','idem-key-123','abc',$operation,1001,$tmp);
        ok($calls === 1, 'equivalent retry repeated side effect');
        ok($first === $second, 'equivalent retry changed logical result');
        try {
            brvtalStaffOpsIdempotent('brvtal|product-1|suspend|7','idem-key-123','different',$operation,1002,$tmp);
            fail('idempotency fingerprint conflict accepted');
        } catch (BrvtalStaffOpsHttpError $error) {
            ok($error->status === 409, 'idempotency conflict status mismatch');
        }
        brvtalStaffOpsAssertSafePayload(['ok'=>true,'data'=>['id'=>7,'status'=>'active']]);
        try {
            brvtalStaffOpsAssertSafePayload(['ok'=>true,'data'=>['reset_token'=>'secret']]);
            fail('secret response key accepted');
        } catch (RuntimeException) {}
        echo "AC-02 passed\n";
        exit(0);
    }

    if ($mode === 'ac03') {
        foreach ([
            'BRVTAL_CONTROLBOT_STAFF_KEY_ID',
            'BRVTAL_CONTROLBOT_STAFF_KEY',
            'BRVTAL_CONTROLBOT_STAFF_ALLOWLIST',
        ] as $name) putenv($name);
        ok(brvtalStaffOpsConfig()['enabled'] === false, 'ops unexpectedly enabled without config');

        $index = file_get_contents(__DIR__ . '/../ops/index.php');
        $htaccess = file_get_contents(__DIR__ . '/../ops/.htaccess');
        ok(is_string($index) && str_contains($index, "json_response(['ok'=>false,'error'=>'NOT_FOUND'], 404)"), 'disabled 404 gate missing');
        ok(is_string($htaccess) && str_contains($htaccess, 'admin-password-mail-worker'), 'worker HTTP deny missing');
        ok(str_contains($index, 'brvtalStaffOpsAssertSafePayload'), 'safe response guard missing');
        ok(!str_contains($index, "'token'=>(string)"), 'endpoint exposes token');
        echo "AC-03 passed\n";
        exit(0);
    }

    fail('unknown mode');
} finally {
    if (is_dir($tmp)) {
        foreach (glob($tmp . '/*') ?: [] as $path) @unlink($path);
        @rmdir($tmp);
    }
}
