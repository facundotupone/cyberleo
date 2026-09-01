<?php
class RateLimitException extends RuntimeException {}
function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}
function csrf_token() {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_input() { return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">'; }
function require_csrf($json = false) {
    start_secure_session();
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Solicitud no válida.']); }
        else echo 'Solicitud no válida.';
        exit;
    }
}
function enforce_auth_rate_limit(PDO $pdo, $context) {
    if (APP_SECRET === '') throw new RuntimeException('Missing application secret.');
    $hash = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $context, APP_SECRET);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)'); $lock->execute(['auth-rate-' . $hash]);
    if (!$lock->fetchColumn()) throw new RuntimeException('Rate limit lock unavailable.');
    try {
    $pdo->prepare('DELETE FROM auth_rate_limits WHERE requested_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)')->execute();
    $s = $pdo->prepare('SELECT COUNT(*) FROM auth_rate_limits WHERE context_hash=? AND requested_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $s->execute([$hash]);
    if ((int)$s->fetchColumn() >= 5) throw new RateLimitException('Too many attempts.');
    $pdo->prepare('INSERT INTO auth_rate_limits (context_hash, requested_at) VALUES (?, NOW())')->execute([$hash]);
    return $hash;
    } finally { $pdo->prepare('DO RELEASE_LOCK(?)')->execute(['auth-rate-' . $hash]); }
}
function clear_auth_rate_limit(PDO $pdo, $hash) { $pdo->prepare('DELETE FROM auth_rate_limits WHERE context_hash=?')->execute([$hash]); }
