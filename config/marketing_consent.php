<?php
declare(strict_types=1);

/** #1671: only disposable integration storage until separate activation authorization. */
function brvtalMarketingConsentFixture(PDO $db): bool
{
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
        return false;
    }
    $name = $db->query('SELECT DATABASE()')->fetchColumn();
    return is_string($name) && preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $name) === 1;
}

function brvtalMarketingConsentEmail(string $email): string
{
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('MARKETING_EMAIL_INVALID');
    }
    return $email;
}

/**
 * Caller must establish verified address ownership and an explicit, independent
 * opt-in to this exact notice version. Return the raw unsubscribe token only
 * to the trusted caller; never log or persist it. Not a public endpoint.
 */
function brvtalMarketingConsentGrant(
    PDO $db, string $email, string $version, string $origin, bool $optIn
): string {
    if (!brvtalMarketingConsentFixture($db)) {
        throw new DomainException('MARKETING_CONSENT_DISABLED');
    }
    if (!$optIn || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $version)
        || !in_array($origin, ['checkout','registration','account'], true)) {
        throw new InvalidArgumentException('MARKETING_CONSENT_INVALID');
    }
    $email = brvtalMarketingConsentEmail($email);
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO marketing_consents (email,consent_version,origin,unsubscribe_hash) VALUES (?,?,?,?)');
    // Database uniqueness serializes parallel grants, including independent connections.
    // A duplicate active grant cannot silently replace the consent or its token.
    $stmt->execute([$email,$version,$origin,hash('sha256',$token)]);
    return $token;
}

/** Replay is harmless: the token is bound to its original grant, not the address. */
function brvtalMarketingConsentRevoke(PDO $db, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !brvtalMarketingConsentFixture($db)) {
        return false;
    }
    $hash = hash('sha256', $token);
    $stmt = $db->prepare('UPDATE marketing_consents SET revoked_at_utc=UTC_TIMESTAMP(6),active_slot=NULL
        WHERE unsubscribe_hash=? AND revoked_at_utc IS NULL');
    $stmt->execute([$hash]);
    if ($stmt->rowCount() === 1) {
        return true;
    }
    $stmt = $db->prepare('SELECT 1 FROM marketing_consents WHERE unsubscribe_hash=? AND revoked_at_utc IS NOT NULL');
    $stmt->execute([$hash]);
    return $stmt->fetchColumn() !== false;
}

/** Marketing-only predicate; transactional mail must never depend on this service. */
function brvtalMarketingConsentContactable(PDO $db, string $email): bool
{
    if (!brvtalMarketingConsentFixture($db)) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM marketing_consents WHERE email=? AND active_slot=1 AND revoked_at_utc IS NULL');
    $stmt->execute([brvtalMarketingConsentEmail($email)]);
    return $stmt->fetchColumn() !== false;
}
