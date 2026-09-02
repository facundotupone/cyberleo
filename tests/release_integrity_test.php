<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Uso: php tests/release_integrity_test.php <directorio-extraído>\n");
    exit(2);
}
$root = realpath($root);
if ($root === false) {
    fwrite(STDERR, "No se pudo resolver el directorio extraído.\n");
    exit(2);
}

function release_path_has_symlink(string $path): bool {
    $current = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') {
            continue;
        }
        $current .= ($current === '' || $current === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR) . $segment;
        if (is_link($current)) {
            return true;
        }
    }
    return false;
}

function is_regular_release_path(string $candidate, string $root): bool {
    if (release_path_has_symlink($candidate) || !is_file($candidate) || is_link($candidate)) {
        return false;
    }
    $realCandidate = realpath($candidate);
    return $realCandidate !== false
        && ($realCandidate === $root || str_starts_with($realCandidate, $root . DIRECTORY_SEPARATOR))
        && is_file($realCandidate)
        && !is_link($realCandidate);
}

$broken = [];
$references = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink()
        || !in_array(strtolower($file->getExtension()), ['css', 'php', 'html'], true)) {
        continue;
    }
    $relativeSource = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
    $ext = strtolower($file->getExtension());
    foreach (file($file->getPathname()) ?: [] as $lineNumber => $line) {
        /** @var list<array{ref:string,fromDir:bool,cssRel:bool}> $items */
        $items = [];
        if ($ext === 'css') {
            preg_match_all('/url\(\s*([\'"]?)([^)\'"]+)\1\s*\)/i', $line, $matches);
            foreach ($matches[2] ?? [] as $ref) {
                $items[] = ['ref' => $ref, 'fromDir' => false, 'cssRel' => true];
            }
        }
        preg_match_all('/\b(?:src|href)\s*=\s*([\'"])([^\'"]+)\1/i', $line, $matches);
        foreach ($matches[2] ?? [] as $ref) {
            $items[] = ['ref' => $ref, 'fromDir' => false, 'cssRel' => false];
        }
        if ($ext === 'php') {
            if (preg_match_all(
                '/\b(?:require|include)(?:_once)?\b\s*(?:\()?\s*(?:(__DIR__)\s*\.\s*)?([\'"])([^\'"]+)\2/i',
                $line,
                $phpMatches,
                PREG_SET_ORDER
            )) {
                foreach ($phpMatches as $match) {
                    $items[] = [
                        'ref' => $match[3],
                        'fromDir' => ($match[1] ?? '') === '__DIR__',
                        'cssRel' => false,
                    ];
                }
            }
        }

        foreach ($items as $item) {
            $reference = trim(html_entity_decode($item['ref'], ENT_QUOTES | ENT_HTML5));
            if ($reference === '' || str_contains($reference, '<?') || str_contains($reference, '$')
                || preg_match('#^(?:https?:|data:|mailto:|tel:|//|\#)#i', $reference)) {
                continue;
            }
            $path = preg_split('/[?#]/', $reference, 2)[0];
            if ($path === '') {
                continue;
            }
            $references++;
            if ($item['fromDir'] || $item['cssRel']) {
                $candidate = dirname($file->getPathname()) . '/' . $path;
                if (!is_regular_release_path($candidate, $root)) {
                    $broken[] = "{$relativeSource}:" . ($lineNumber + 1) . " -> {$reference}";
                }
            } elseif (str_starts_with($path, '/')) {
                $candidate = $root . '/' . ltrim($path, '/');
                if (!is_regular_release_path($candidate, $root)) {
                    $broken[] = "{$relativeSource}:" . ($lineNumber + 1) . " -> {$reference}";
                }
            } else {
                // PHP: CWD/include_path first, then the including file's directory.
                $fromRoot = $root . '/' . $path;
                $fromFile = dirname($file->getPathname()) . '/' . $path;
                if (!is_regular_release_path($fromRoot, $root)
                    && !is_regular_release_path($fromFile, $root)) {
                    $broken[] = "{$relativeSource}:" . ($lineNumber + 1) . " -> {$reference}";
                }
            }
        }
    }
}
if ($broken) {
    fwrite(STDERR, "Referencias locales rotas:\n" . implode("\n", $broken) . "\n");
    exit(1);
}
echo "OK: {$references} referencias locales estáticas existen en el release.\n";
