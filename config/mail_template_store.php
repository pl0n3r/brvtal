<?php
declare(strict_types=1);
require_once __DIR__ . '/mail_template_catalog.php';

/**
 * #1886 store: no recipient, token, mailer, external request or side effects
 * outside the strictly versioned MariaDB schema. A new revision is a CAS
 * generation; content rows never change after creation.
 */
function brvtal_mail_template_transaction(PDO $pdo, callable $operation): mixed
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('MAIL_TEMPLATE_NESTED_TRANSACTION');
    }
    $pdo->beginTransaction();
    try {
        $result = $operation();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function brvtal_mail_template_lock(PDO $pdo, string $key, string $scope): array
{
    brvtal_mail_template_definition($key, $scope);
    $query = $pdo->prepare('SELECT * FROM mail_template_registry
        WHERE template_key=? AND scope=? FOR UPDATE');
    $query->execute([$key,$scope]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new DomainException('MAIL_TEMPLATE_NOT_FOUND');
    }
    return $row;
}

function brvtal_mail_template_expect_revision(array $row, int $expected): void
{
    if ($expected < 0 || (int)$row['revision'] !== $expected) {
        throw new DomainException('MAIL_TEMPLATE_CONFLICT');
    }
}

function brvtal_mail_template_save_draft(
    PDO $pdo, string $key, string $scope, array $document,
    int $actorAdminId, int $expectedRevision, ?callable $mediaAuthorized = null
): array {
    if ($actorAdminId < 1 || $expectedRevision < 0) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_ACTOR_INVALID');
    }
    $document = brvtal_mail_template_validate($key,$scope,$document,$mediaAuthorized);
    $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return brvtal_mail_template_transaction($pdo, static function () use (
        $pdo,$key,$scope,$encoded,$actorAdminId,$expectedRevision
    ): array {
        // Atomic bootstrap of an empty key; no writer can read an uncommitted
        // document, and the unique key serializes concurrent first saves.
        $insert = $pdo->prepare('INSERT INTO mail_template_registry (template_key,scope)
            VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $insert->execute([$key,$scope]);
        $row = brvtal_mail_template_lock($pdo,$key,$scope);
        brvtal_mail_template_expect_revision($row,$expectedRevision);
        $revision = $expectedRevision + 1;
        $version = $pdo->prepare("INSERT INTO mail_template_versions
            (template_id,version_number,kind,document_json,author_admin_id)
            VALUES(?,?,'draft',?,?)");
        $version->execute([(int)$row['id'],$revision,$encoded,$actorAdminId]);
        $versionId = (int)$pdo->lastInsertId();
        $update = $pdo->prepare('UPDATE mail_template_registry
            SET revision=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND revision=?');
        $update->execute([$revision,(int)$row['id'],$expectedRevision]);
        if ($update->rowCount() !== 1) throw new DomainException('MAIL_TEMPLATE_CONFLICT');
        return ['version_id'=>$versionId,'revision'=>$revision,'state'=>'draft'];
    });
}

function brvtal_mail_template_publish(
    PDO $pdo, string $key, string $scope,
    int $versionId, int $actorAdminId, int $expectedRevision
): array {
    if ($actorAdminId < 1 || $versionId < 1) throw new InvalidArgumentException('MAIL_TEMPLATE_ACTOR_INVALID');
    return brvtal_mail_template_transaction($pdo, static function () use (
        $pdo,$key,$scope,$versionId,$actorAdminId,$expectedRevision
    ): array {
        $row = brvtal_mail_template_lock($pdo,$key,$scope);
        brvtal_mail_template_expect_revision($row,$expectedRevision);
        $find = $pdo->prepare('SELECT id,document_json FROM mail_template_versions WHERE id=? AND template_id=? FOR UPDATE');
        $find->execute([$versionId,(int)$row['id']]);
        if (!is_array($find->fetch(PDO::FETCH_ASSOC))) throw new DomainException('MAIL_TEMPLATE_VERSION_UNKNOWN');
        $pdo->prepare('UPDATE mail_template_versions SET first_published_at=COALESCE(first_published_at,UTC_TIMESTAMP(6))
            WHERE id=?')->execute([$versionId]);
        $revision = $expectedRevision + 1;
        $update = $pdo->prepare('UPDATE mail_template_registry
            SET published_version_id=?,published_by_admin_id=?,published_at=UTC_TIMESTAMP(6),revision=?
            WHERE id=? AND revision=?');
        $update->execute([$versionId,$actorAdminId,$revision,(int)$row['id'],$expectedRevision]);
        if ($update->rowCount() !== 1) throw new DomainException('MAIL_TEMPLATE_CONFLICT');
        return ['version_id'=>$versionId,'revision'=>$revision,'state'=>'published'];
    });
}

/** Roll back by creating a new immutable version from a previously published one. */
function brvtal_mail_template_rollback(
    PDO $pdo, string $key, string $scope,
    int $previousVersionId, int $actorAdminId, int $expectedRevision
): array {
    if ($actorAdminId < 1 || $previousVersionId < 1) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_ACTOR_INVALID');
    }
    return brvtal_mail_template_transaction($pdo, static function () use (
        $pdo,$key,$scope,$previousVersionId,$actorAdminId,$expectedRevision
    ): array {
        $row = brvtal_mail_template_lock($pdo,$key,$scope);
        brvtal_mail_template_expect_revision($row,$expectedRevision);
        $find = $pdo->prepare('SELECT document_json FROM mail_template_versions
            WHERE id=? AND template_id=? AND first_published_at IS NOT NULL FOR UPDATE');
        $find->execute([$previousVersionId,(int)$row['id']]);
        $copy = $find->fetchColumn();
        if (!is_string($copy)) throw new DomainException('MAIL_TEMPLATE_VERSION_UNKNOWN');
        $revision = $expectedRevision + 1;
        $insert = $pdo->prepare("INSERT INTO mail_template_versions
            (template_id,version_number,kind,document_json,author_admin_id,first_published_at)
            VALUES(?,?,'rollback',?,?,UTC_TIMESTAMP(6))");
        $insert->execute([(int)$row['id'],$revision,$copy,$actorAdminId]);
        $newVersion = (int)$pdo->lastInsertId();
        $update = $pdo->prepare('UPDATE mail_template_registry
            SET revision=?,published_version_id=?,published_by_admin_id=?,
                published_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
            WHERE id=? AND revision=?');
        $update->execute([$revision,$newVersion,$actorAdminId,(int)$row['id'],$expectedRevision]);
        if ($update->rowCount() !== 1) throw new DomainException('MAIL_TEMPLATE_CONFLICT');
        return ['version_id'=>$newVersion,'revision'=>$revision,'state'=>'published','rolled_back_from'=>$previousVersionId];
    });
}

/**
 * Runtime resolution is read-only. Catalog PHP remains authoritative until
 * a published snapshot exists; missing additive tables also fall back safely.
 */
function brvtal_mail_template_resolve(PDO $pdo, string $key, string $scope): array
{
    $default = brvtal_mail_template_definition($key,$scope)['defaults'];
    try {
        $sql = 'SELECT v.document_json FROM mail_template_registry r
            JOIN mail_template_versions v ON v.id=r.published_version_id
              AND v.template_id=r.id
            WHERE r.template_key=? AND r.scope=? LIMIT 1';
        $q = $pdo->prepare($sql);
        $q->execute([$key,$scope]);
        $document = $q->fetchColumn();
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') return ['source'=>'catalog','document'=>$default];
        throw $error;
    }
    if (!is_string($document)) return ['source'=>'catalog','document'=>$default];
    $decoded = json_decode($document,true);
    if (!is_array($decoded)) throw new RuntimeException('MAIL_TEMPLATE_DOCUMENT_CORRUPTED');
    // Do not override protected link/recipient details: those live elsewhere.
    return ['source'=>'published','document'=>$decoded];
}

/** Metadata only, never recipient or rendered tokens. */
function brvtal_mail_template_versions(PDO $pdo, string $key, string $scope): array
{
    brvtal_mail_template_definition($key,$scope);
    $q = $pdo->prepare('SELECT v.id,v.version_number,v.kind,v.author_admin_id,
            v.first_published_at,v.created_at
        FROM mail_template_registry r
        JOIN mail_template_versions v ON v.template_id=r.id
        WHERE r.template_key=? AND r.scope=?
        ORDER BY v.version_number DESC LIMIT 100');
    $q->execute([$key,$scope]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
