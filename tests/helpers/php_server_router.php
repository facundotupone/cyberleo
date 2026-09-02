<?php
declare(strict_types=1);

/**
 * Router del servidor de pruebas PHP built-in.
 * Replica las denegaciones públicas de .htaccess (403) y evita directory listing.
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($uri) ? rawurldecode($uri) : '/';
$path = ltrim($path, '/');

$denied = static function () : void {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    exit;
};

if ($path !== '' && (
    preg_match('#(^|/)\.#', $path)
    || preg_match('#^(tests|migrations|scripts|docs|cron|backups|dist)(/|$)#i', $path)
    || preg_match('#(^|/)(logs?)(/|$)#i', $path)
    || preg_match('#(^|/)(README([^/]*)?|schema(\.[^/]*)?)$#i', $path)
    || preg_match('#(^|/)(config(\.local)?|local\.config)(\.[^/]*)?$#i', $path)
    || preg_match('#(^|/)\.env(\..*)?$#i', $path)
    || preg_match('#\.(env|ini|log|sql|sqlite|bak|backup|dump|dist|swp|ya?ml)$#i', $path)
    || preg_match('#(^|/)[^/]+\.(bak|backup|dump|sql)(\.[^/]+)?$#i', $path)
    || preg_match('#(^|/)cyberleo-backup-[^/]+\.zip$#i', $path)
    || preg_match('#(^|/)backup\.zip$#i', $path)
    || preg_match('#\.(tar|tar\.gz|tgz|7z)$#i', $path)
)) {
    $denied();
}

$fileRoot = dirname(__DIR__, 2);
$file = $fileRoot . '/' . $path;
if ($path === '') {
    require $fileRoot . '/index.php';
    return true;
}

if (is_dir($file)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    return true;
}

if (is_file($file)) {
    return false; // let the built-in server serve the file from CWD (repo root)
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Not Found\n";
return true;
