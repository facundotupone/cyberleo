<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Uso: php tests/release_integrity_test.php <directorio-extraído>\n");
    exit(2);
}
$root = realpath($root);
$broken = [];
$references = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['css','php','html'], true)) continue;
    $relativeSource = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
    foreach (file($file->getPathname()) ?: [] as $lineNumber => $line) {
        $candidates = [];
        if ($file->getExtension() === 'css') {
            preg_match_all('/url\(\s*([\'"]?)([^)\'"]+)\1\s*\)/i', $line, $matches);
            $candidates = array_merge($candidates, $matches[2] ?? []);
        }
        preg_match_all('/\b(?:src|href)\s*=\s*([\'"])([^\'"]+)\1/i', $line, $matches);
        $candidates = array_merge($candidates, $matches[2] ?? []);
        foreach ($candidates as $reference) {
            $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5));
            if ($reference === '' || str_contains($reference, '<?') || str_contains($reference, '$')
                || preg_match('#^(?:https?:|data:|mailto:|tel:|//|\#)#i', $reference)) continue;
            $path = preg_split('/[?#]/', $reference, 2)[0];
            if ($path === '') continue;
            $references++;
            $candidate = str_starts_with($path, '/')
                ? $root . '/' . ltrim($path, '/')
                : ($file->getExtension() === 'css'
                    ? dirname($file->getPathname()) . '/' . $path
                    : $root . '/' . $path);
            if (!file_exists($candidate)) $broken[] = "{$relativeSource}:" . ($lineNumber + 1) . " -> {$reference}";
        }
    }
}
if ($broken) {
    fwrite(STDERR, "Referencias locales rotas:\n" . implode("\n", $broken) . "\n");
    exit(1);
}
echo "OK: {$references} referencias locales estáticas existen en el release.\n";
