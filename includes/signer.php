<?php
/**
 * Who signs donation certificates.
 *
 * Chosen in Admin → Организация („Подписва сертификатите за дарение“), saved as
 * the user id `signing_admin_id` in content/organisation.json (constant
 * SIGNING_ADMIN_ID, like every other Организация setting). The signer draws
 * their signature under „Подпис“, signs certificates, and gets the email when a
 * certificate waits for signing.
 *
 * Resolution order (signing_admin_resolve()):
 *   1. the chosen user, if they still exist and are an admin;
 *   2. otherwise the admin whose email is SIGNING_ADMIN_EMAIL from
 *      site.config.php — what every site had before the setting existed, so an
 *      existing site keeps working without doing anything;
 *   3. otherwise nobody: the admin is told to choose a signer (signer_missing_notice_html()).
 *
 * This file and config.php are the only places that read SIGNING_ADMIN_EMAIL —
 * everything else asks signing_admin() / signing_admin_email()
 * (tests/Shop/SigningAdminTest.php checks it).
 *
 * Call sites require_once this file themselves, so they keep working on a site
 * whose config.php is an older, customised copy that does not load it.
 */

/** The anchor of the setting in Admin → Организация. */
const SIGNER_SETTING_URL = '/admin/organisation.php#f-signing_admin_id';

/**
 * Pure resolution: which of $users signs. $users are admin_users rows
 * (id, name, email, role); only role 'admin' can sign. Returns
 * ['id' => int, 'email' => string, 'name' => string] or null.
 */
function signing_admin_resolve(array $users, string $chosenId, ?string $fallbackEmail): ?array
{
    $admins = array_values(array_filter($users, static fn($u) => is_array($u) && ($u['role'] ?? '') === 'admin'));
    $pick = static fn(array $u): array => [
        'id'    => (int) $u['id'],
        'email' => (string) ($u['email'] ?? ''),
        'name'  => (string) ($u['name'] ?? ''),
    ];

    if (preg_match('/^[1-9]\d{0,9}$/', trim($chosenId))) {
        foreach ($admins as $u) {
            if ((int) $u['id'] === (int) $chosenId) return $pick($u);
        }
    }
    $fallbackEmail = strtolower(trim((string) $fallbackEmail));
    if ($fallbackEmail !== '') {
        foreach ($admins as $u) {
            if (strtolower(trim((string) ($u['email'] ?? ''))) === $fallbackEmail) return $pick($u);
        }
    }
    return null;
}

/** Users who may be chosen as the signer: every admin, by name. */
function signing_admin_candidates(?PDO $pdo = null): array
{
    try {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/admin/includes/db.php';
            $pdo = get_pdo();
        }
        return $pdo->query("SELECT id, name, email, role FROM admin_users WHERE role = 'admin' ORDER BY name, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('signing_admin_candidates: ' . $e->getMessage());
        return [];
    }
}

/**
 * The signer of donation certificates, or null when none can be found (also
 * when the database cannot be reached). Looked up once per request; pass
 * $refresh = true after changing users or the setting.
 */
function signing_admin(bool $refresh = false): ?array
{
    static $cache = false;
    if ($refresh) $cache = false;
    if ($cache !== false) return $cache;
    $cache = signing_admin_resolve(
        signing_admin_candidates(),
        defined('SIGNING_ADMIN_ID') ? (string) SIGNING_ADMIN_ID : '',
        defined('SIGNING_ADMIN_EMAIL') ? (string) SIGNING_ADMIN_EMAIL : null
    );
    return $cache;
}

/** Where „нужен подпис“ emails go, or null when nobody signs. */
function signing_admin_email(): ?string
{
    $email = signing_admin()['email'] ?? '';
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/** Pure check: is $user (a session user) the $signer? */
function admin_is_signer(array $user, ?array $signer): bool
{
    return $signer !== null && (int) ($user['id'] ?? 0) > 0 && (int) $user['id'] === (int) $signer['id']
        && ($user['role'] ?? '') === 'admin';
}

/**
 * Validate the signer chosen in Admin → Организация. $raw is the posted id
 * ('' = nobody chosen yet); $adminIds are the ids of the users who are admins.
 * Returns ['value' => string, 'error' => ?string].
 */
function signing_admin_validate(string $raw, array $adminIds): array
{
    $raw = trim($raw);
    if ($raw === '') return ['value' => '', 'error' => null];
    $ids = array_map('intval', $adminIds);
    if (!preg_match('/^[1-9]\d{0,9}$/', $raw) || !in_array((int) $raw, $ids, true)) {
        return ['value' => '', 'error' => 'Моля, изберете от списъка човек с роля „Администратор“.'];
    }
    return ['value' => (string) (int) $raw, 'error' => null];
}

/**
 * The notice shown when certificates are issued but nobody can sign them.
 * '' when there is a signer or certificates are off. $isAdmin: whether the
 * viewer can choose the signer (otherwise they are told to ask an admin).
 */
function signer_missing_notice_html(?array $signer, bool $certificatesOn, bool $isAdmin): string
{
    if (!$certificatesOn || $signer !== null) return '';
    $box = 'background:#fff8e6;border:1px solid #f0d58c;border-left:4px solid #b5870a;border-radius:8px;'
         . 'padding:.9rem 1.1rem;margin-bottom:.75rem;color:#5c4400;font-size:.9rem;line-height:1.5;';
    $text = '<strong style="display:block;margin-bottom:.15rem;">⚠ Никой не може да подписва сертификатите за дарение</strong>'
          . 'Сертификатите се издават, но остават без подпис, докато не изберете кой ги подписва. ';
    $text .= $isAdmin
        ? 'Изберете човека в <a href="' . SIGNER_SETTING_URL . '" style="color:inherit;font-weight:600;">Организация → „Подписва сертификатите за дарение“</a>.'
        : 'Помолете администратор да избере човека в Организация → „Подписва сертификатите за дарение“.';
    return '<div role="status" style="' . $box . '">' . $text . '</div>';
}
