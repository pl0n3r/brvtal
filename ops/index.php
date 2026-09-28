<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/admin_staff_ops.php';
require_once __DIR__ . '/../config/admin_password_security.php';

$configOps = brvtalStaffOpsConfig();
if (!$configOps['enabled']) {
    json_response(['ok'=>false,'error'=>'NOT_FOUND'], 404);
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody)) $rawBody = '';
$pdo = null;

try {
    $auth = brvtalStaffOpsAuthenticate($_SERVER, $rawBody);
    $pdo = db();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $query = brvtalStaffOpsQueryMap((string)($_SERVER['REQUEST_URI'] ?? '/'));

    $decodePayload = static function() use ($rawBody): array {
        if ($rawBody === '') return [];
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) throw new BrvtalStaffOpsHttpError(422, 'INVALID_PAYLOAD');
        return $payload;
    };
    $exactFields = static function(array $payload, array $allowed): void {
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) throw new BrvtalStaffOpsHttpError(422, 'INVALID_PAYLOAD');
    };

    if ($method === 'GET' && $path === '/ops/staff') {
        $exactFields($query, ['q','cursor','limit']);
        $limit = isset($query['limit']) && ctype_digit($query['limit']) ? (int)$query['limit'] : 25;
        if ($limit < 1 || $limit > 100) throw new BrvtalStaffOpsHttpError(422, 'INVALID_LIMIT');
        $cursor = null;
        if (($query['cursor'] ?? '') !== '') {
            if (!ctype_digit((string)$query['cursor'])) throw new BrvtalStaffOpsHttpError(422, 'INVALID_CURSOR');
            $cursor = (int)$query['cursor'];
        }
        $data = brvtalStaffOpsSearch($pdo, (string)($query['q'] ?? ''), $cursor, $limit);
        brvtalStaffOpsAudit($pdo, 'ops_search', $auth['key_id'], null, 'success', $auth['request_id']);
        $response = ['ok'=>true,'data'=>$data];
        brvtalStaffOpsAssertSafePayload($response);
        json_response($response);
    }

    if ($method === 'GET' && $path === '/ops/summary') {
        if ($query !== []) throw new BrvtalStaffOpsHttpError(422, 'INVALID_QUERY');
        $data = brvtalStaffOpsSummary($pdo);
        brvtalStaffOpsAudit($pdo, 'ops_summary', $auth['key_id'], null, 'success', $auth['request_id']);
        $response = ['ok'=>true,'data'=>$data];
        brvtalStaffOpsAssertSafePayload($response);
        json_response($response);
    }

    $action = null;
    $staffId = null;
    if ($method === 'POST' && $path === '/ops/staff') {
        $action = 'invite';
    } elseif ($method === 'POST'
        && preg_match('#^/ops/staff/([1-9][0-9]*)/(suspend|reactivate|role|password-reset)$#D', $path, $match)) {
        $staffId = (int)$match[1];
        $action = $match[2];
    }
    if ($action === null) throw new BrvtalStaffOpsHttpError(404, 'NOT_FOUND');
    if ($query !== []) throw new BrvtalStaffOpsHttpError(422, 'INVALID_QUERY');

    $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $fingerprint = brvtalStaffOpsRequestFingerprint($method, $auth['target'], $rawBody);
    $scope = 'brvtal|' . $auth['key_id'] . '|' . $action . '|' . ($staffId ?? 'new');
    $result = brvtalStaffOpsIdempotent(
        $scope,
        $idempotencyKey,
        $fingerprint,
        static function() use (
            $action,$staffId,$pdo,$decodePayload,$exactFields,$auth
        ): array {
            $payload = $decodePayload();

            if ($action === 'invite') {
                $exactFields($payload, ['name','email','role']);
                $name = trim((string)($payload['name'] ?? ''));
                $email = strtolower(trim((string)($payload['email'] ?? '')));
                $role = strtolower(trim((string)($payload['role'] ?? '')));
                if ($name === '' || mb_strlen($name) > 120
                    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
                    || !brvtalStaffOpsAllowedManagedRole($role)) {
                    throw new BrvtalStaffOpsHttpError(422, 'INVALID_STAFF_INVITE');
                }

                $inviteState = brvtalStaffOpsStatePath('invite', $email);
                return brvtalStaffOpsWithExclusiveState(
                    $inviteState,
                    static function(string $_file) use ($pdo,$name,$email,$role,$auth): array {
                        $randomPassword = bin2hex(random_bytes(32));
                        $hash = password_hash($randomPassword, PASSWORD_DEFAULT);
                        if (!is_string($hash) || $hash === '') {
                            throw new RuntimeException('PASSWORD_HASH_FAILED');
                        }

                        $lookup = $pdo->prepare(
                            'SELECT id,staff_role,is_active,staff_invitation_state '
                            . 'FROM admins WHERE email=? LIMIT 1'
                        );
                        $lookup->execute([$email]);
                        $existing = $lookup->fetch(PDO::FETCH_ASSOC);

                        if (is_array($existing)) {
                            $retryable = (int)$existing['is_active'] === 0
                                && (string)$existing['staff_invitation_state'] === 'failed'
                                && hash_equals((string)$existing['staff_role'], $role);
                            if (!$retryable) {
                                throw new BrvtalStaffOpsHttpError(409, 'STAFF_ALREADY_EXISTS');
                            }
                            $id = (int)$existing['id'];
                            $retry = $pdo->prepare(
                                'UPDATE admins SET password_hash=?,name=?,credential_epoch=credential_epoch+1 '
                                . "WHERE id=? AND is_active=0 AND staff_invitation_state='failed'"
                            );
                            $retry->execute([$hash,$name,$id]);
                            if ($retry->rowCount() !== 1) {
                                throw new BrvtalStaffOpsHttpError(409, 'INVITATION_STATE_CONFLICT');
                            }
                        } else {
                            $insert = $pdo->prepare(
                                'INSERT INTO admins('
                                . 'email,password_hash,credential_epoch,name,staff_role,staff_invitation_state,is_active'
                                . ") VALUES(?,?,1,?,?,'failed',0)"
                            );
                            $insert->execute([$email,$hash,$name,$role]);
                            $id = (int)$pdo->lastInsertId();
                        }

                        $issued = null;
                        try {
                            $issued = brvtal_password_reset_issue($pdo, $id, null, true);
                            $baseUrl = (string)($GLOBALS['config']['app']['base_url'] ?? 'https://www.brvtal.com.co');
                            $link = rtrim($baseUrl, '/') . '/discadmin/reset-password.php#token='
                                . rawurlencode((string)$issued['token']);
                            $delivered = brvtalAdminMailSend(
                                $email,
                                'Tu invitación a BRVTAL',
                                "Fuiste invitado al staff de BRVTAL.\n\nDefine tu contraseña aquí:\n" . $link
                                    . "\n\nEl enlace vence en 60 minutos y solo puede usarse una vez.",
                                'staff_invitation'
                            );
                            if (!$delivered) {
                                throw new RuntimeException('DELIVERY_FAILED');
                            }

                            $activate = $pdo->prepare(
                                "UPDATE admins SET is_active=1,staff_invitation_state='sent',"
                                . "credential_epoch=credential_epoch+1 "
                                . "WHERE id=? AND is_active=0 AND staff_invitation_state='failed'"
                            );
                            $activate->execute([$id]);
                            if ($activate->rowCount() !== 1) {
                                throw new RuntimeException('INVITATION_STATE_CONFLICT');
                            }

                            brvtalStaffOpsAudit(
                                $pdo,'ops_invite',$auth['key_id'],$id,'success',$auth['request_id'],
                                ['role'=>$role]
                            );
                        } catch (Throwable $error) {
                            if (is_array($issued) && isset($issued['token'])) {
                                brvtal_password_reset_revoke_token($pdo, (string)$issued['token']);
                            }
                            $disable = $pdo->prepare(
                                "UPDATE admins SET is_active=0,staff_invitation_state='failed',"
                                . 'credential_epoch=credential_epoch+1 WHERE id=?'
                            );
                            $disable->execute([$id]);
                            try {
                                brvtalStaffOpsAudit(
                                    $pdo,'ops_invite',$auth['key_id'],$id,'failed',$auth['request_id'],
                                    ['role'=>$role]
                                );
                            } catch (Throwable) {
                            }
                            throw $error instanceof BrvtalStaffOpsHttpError
                                ? $error
                                : new BrvtalStaffOpsHttpError(503, 'INVITATION_DELIVERY_FAILED');
                        }

                        return ['status'=>201,'payload'=>[
                            'ok'=>true,'data'=>['id'=>$id,'status'=>'active','invitation_sent'=>true]
                        ]];
                    }
                );
            }

            if ($staffId === null) throw new BrvtalStaffOpsHttpError(422, 'INVALID_STAFF_ID');

            if ($action === 'suspend') {
                $exactFields($payload, ['reason_code']);
                $reason = trim((string)($payload['reason_code'] ?? ''));
                if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $reason)) {
                    throw new BrvtalStaffOpsHttpError(422, 'INVALID_REASON');
                }
                $data = brvtalStaffOpsTransaction($pdo, static function() use ($pdo,$staffId,$reason,$auth): array {
                    $row = brvtalStaffOpsFindForMutation($pdo,$staffId);
                    if ((int)$row['is_active'] === 1) {
                        $update = $pdo->prepare(
                            'UPDATE admins SET is_active=0,credential_epoch=credential_epoch+1 WHERE id=?'
                        );
                        $update->execute([$staffId]);
                    }
                    brvtalStaffOpsAudit(
                        $pdo,'ops_suspend',$auth['key_id'],$staffId,'success',$auth['request_id'],
                        ['reason_code'=>$reason]
                    );
                    return ['id'=>$staffId,'status'=>'suspended'];
                });
                return ['status'=>200,'payload'=>['ok'=>true,'data'=>$data]];
            }

            if ($action === 'reactivate') {
                $exactFields($payload, []);
                $data = brvtalStaffOpsTransaction($pdo, static function() use ($pdo,$staffId,$auth): array {
                    $row = brvtalStaffOpsFindForMutation($pdo,$staffId);
                    if ((int)$row['is_active'] !== 1) {
                        $update = $pdo->prepare('UPDATE admins SET is_active=1 WHERE id=?');
                        $update->execute([$staffId]);
                    }
                    brvtalStaffOpsAudit(
                        $pdo,'ops_reactivate',$auth['key_id'],$staffId,'success',$auth['request_id']
                    );
                    return ['id'=>$staffId,'status'=>'active'];
                });
                return ['status'=>200,'payload'=>['ok'=>true,'data'=>$data]];
            }

            if ($action === 'role') {
                $exactFields($payload, ['role']);
                $role = strtolower(trim((string)($payload['role'] ?? '')));
                if (!brvtalStaffOpsAllowedManagedRole($role)) {
                    throw new BrvtalStaffOpsHttpError(422, 'INVALID_STAFF_ROLE');
                }
                $data = brvtalStaffOpsTransaction($pdo, static function() use ($pdo,$staffId,$role,$auth): array {
                    $row = brvtalStaffOpsFindForMutation($pdo,$staffId);
                    if (!hash_equals((string)$row['staff_role'], $role)) {
                        $update = $pdo->prepare(
                            'UPDATE admins SET staff_role=?,credential_epoch=credential_epoch+1 WHERE id=?'
                        );
                        $update->execute([$role,$staffId]);
                    }
                    brvtalStaffOpsAudit(
                        $pdo,'ops_role',$auth['key_id'],$staffId,'success',$auth['request_id'],
                        ['role'=>$role]
                    );
                    return ['id'=>$staffId,'role'=>$role];
                });
                return ['status'=>200,'payload'=>['ok'=>true,'data'=>$data]];
            }

            if ($action === 'password-reset') {
                $exactFields($payload, []);
                $row = brvtalStaffOpsFindForMutation($pdo,$staffId,false);
                if ((int)$row['is_active'] !== 1) {
                    throw new BrvtalStaffOpsHttpError(409, 'STAFF_SUSPENDED');
                }
                $issued = brvtal_password_reset_issue($pdo,$staffId);
                try {
                    $baseUrl = (string)($GLOBALS['config']['app']['base_url'] ?? 'https://www.brvtal.com.co');
                    $link = rtrim($baseUrl, '/') . '/discadmin/reset-password.php#token='
                        . rawurlencode((string)$issued['token']);
                    if (!brvtalAdminMailSend(
                        (string)$row['email'],
                        'Recupera tu acceso a BRVTAL',
                        "Se solicitó restablecer tu acceso de staff.\n\n" . $link
                            . "\n\nEl enlace vence en 60 minutos y solo puede usarse una vez.",
                        'staff_password_reset'
                    )) {
                        throw new RuntimeException('DELIVERY_FAILED');
                    }
                    brvtalStaffOpsAudit(
                        $pdo,'ops_password_reset',$auth['key_id'],$staffId,'success',$auth['request_id']
                    );
                } catch (Throwable $error) {
                    brvtal_password_reset_revoke_token($pdo,(string)$issued['token']);
                    throw $error instanceof BrvtalStaffOpsHttpError
                        ? $error
                        : new BrvtalStaffOpsHttpError(503, 'RESET_DELIVERY_FAILED');
                }
                return ['status'=>200,'payload'=>[
                    'ok'=>true,'data'=>['id'=>$staffId,'reset_sent'=>true]
                ]];
            }

            throw new BrvtalStaffOpsHttpError(404, 'NOT_FOUND');
        }
    );
    brvtalStaffOpsAssertSafePayload($result['payload']);
    json_response($result['payload'], $result['status']);
} catch (BrvtalStaffOpsHttpError $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], $error->status);
} catch (Throwable $error) {
    if (function_exists('brvtal_log')) {
        brvtal_log('STAFF_OPS_ERROR', 'ControlBot staff operation failed', ['class'=>$error::class]);
    }
    json_response(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
