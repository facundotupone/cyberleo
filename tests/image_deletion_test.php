<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/images.php';

$dsn = getenv('TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "TEST_DSN is required.\n");
    exit(2);
}
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$root = sys_get_temp_dir() . '/cyberleo-images-' . bin2hex(random_bytes(8));
mkdir($root . '/assets/images/products', 0700, true);

function check(bool $condition, string $id): void {
    if (!$condition) throw new RuntimeException("$id failed");
    printf("%s OK\n", $id);
}
function reset_database(PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE product_images; TRUNCATE products; TRUNCATE categories; SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO categories (id, name, icon) VALUES (1, 'Tests', 'bi-cpu')");
}
function path_for(string $hex): string {
    return "assets/images/products/$hex.jpg";
}
function file_for(string $root, string $path): string {
    return product_image_directory($root) . '/' . basename($path);
}
function write_image(string $root, string $path): void {
    file_put_contents(file_for($root, $path), 'test image');
}
function product(PDO $pdo, ?string $image = null): int {
    $statement = $pdo->prepare(
        "INSERT INTO products (name, description, price, stock, image, category_id) VALUES ('Test', 'Test', 1, 1, ?, 1)"
    );
    $statement->execute([$image]);
    return (int) $pdo->lastInsertId();
}
function image(PDO $pdo, int $productId, string $path, int $main): int {
    $statement = $pdo->prepare('INSERT INTO product_images (product_id, image_path, is_main) VALUES (?, ?, ?)');
    $statement->execute([$productId, $path, $main]);
    return (int) $pdo->lastInsertId();
}
function remove_tree(string $path): void {
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') remove_tree("$path/$entry");
    }
    rmdir($path);
}

try {
    $one = path_for('11111111111111111111111111111111');
    $two = path_for('22222222222222222222222222222222');
    $three = path_for('33333333333333333333333333333333');

    check(resolve_safe_product_image_path('../secret.jpg', $root)['status'] === 'unsafe_path', 'D-01');
    write_image($root, $one);
    $resolved = resolve_safe_product_image_path($one, $root);
    check($resolved['status'] === 'resolved' && $resolved['path'] === file_for($root, $one), 'D-02');
    check(resolve_safe_product_image_path($two, $root)['status'] === 'missing_file', 'D-03');

    symlink('/etc/passwd', file_for($root, $two));
    check(resolve_safe_product_image_path($two, $root)['status'] === 'symlink_path', 'D-04');
    unlink(file_for($root, $two));
    $linkRoot = "$root/link-root";
    mkdir("$linkRoot/assets/images", 0700, true);
    symlink(product_image_directory($root), "$linkRoot/assets/images/products");
    check(resolve_safe_product_image_path($one, $linkRoot)['status'] === 'symlink_path', 'D-05');
    remove_tree($linkRoot);

    reset_database($pdo);
    check(delete_unreferenced_product_image($pdo, $one, $root) === 'deleted' && !file_exists(file_for($root, $one)), 'D-06');
    write_image($root, $one);
    $id = product($pdo, $one);
    image($pdo, $id, $one, 1);
    check(delete_unreferenced_product_image($pdo, $one, $root) === 'still_referenced', 'D-07');
    $pdo->exec('DELETE FROM product_images');
    check(delete_unreferenced_product_image($pdo, $one, $root) === 'still_referenced', 'D-08');
    $pdo->beginTransaction();
    try {
        cleanup_product_images_after_commit($pdo, [$one], $root);
        $transactionGuarded = false;
    } catch (LogicException) {
        $transactionGuarded = true;
    }
    $pdo->rollBack();
    check($transactionGuarded, 'D-09');

    reset_database($pdo);
    write_image($root, $one);
    write_image($root, $two);
    $id = product($pdo, $one);
    $main = image($pdo, $id, $one, 1);
    $other = image($pdo, $id, $two, 0);
    $result = delete_product_image_record($pdo, $other);
    check($result['status'] === 'deleted' && (int) $pdo->query("SELECT is_main FROM product_images WHERE id=$main")->fetchColumn() === 1, 'D-10');

    reset_database($pdo);
    write_image($root, $one);
    write_image($root, $two);
    $id = product($pdo, $one);
    $main = image($pdo, $id, $one, 1);
    $replacement = image($pdo, $id, $two, 0);
    $result = delete_product_image_record($pdo, $main);
    check($result['status'] === 'deleted' && (int) $pdo->query("SELECT is_main FROM product_images WHERE id=$replacement")->fetchColumn() === 1
        && $pdo->query("SELECT image FROM products WHERE id=$id")->fetchColumn() === $two, 'D-11');

    reset_database($pdo);
    write_image($root, $one);
    $id = product($pdo, $one);
    $main = image($pdo, $id, $one, 1);
    delete_product_image_record($pdo, $main);
    check($pdo->query("SELECT image FROM products WHERE id=$id")->fetchColumn() === null, 'D-12');
    check(delete_product_image_record($pdo, 999999)['status'] === 'not_found' && !$pdo->inTransaction(), 'D-13');

    reset_database($pdo);
    write_image($root, $one);
    write_image($root, $two);
    $id = product($pdo, $one);
    image($pdo, $id, $one, 1);
    image($pdo, $id, $two, 0);
    $result = delete_product_record($pdo, $id);
    $states = cleanup_product_images_after_commit($pdo, $result['paths'], $root);
    check($result['status'] === 'deleted' && $states[$one] === 'deleted' && $states[$two] === 'deleted'
        && !file_exists(file_for($root, $one)) && !file_exists(file_for($root, $two)), 'D-14');

    reset_database($pdo);
    write_image($root, $three);
    $first = product($pdo, $three);
    $second = product($pdo, $three);
    image($pdo, $first, $three, 1);
    image($pdo, $second, $three, 1);
    $result = delete_product_record($pdo, $first);
    $states = cleanup_product_images_after_commit($pdo, $result['paths'], $root);
    check($states[$three] === 'still_referenced' && file_exists(file_for($root, $three)), 'D-15');

    check(cleanup_product_images_after_commit($pdo, ['not-an-image', $two], $root) === [
        'not-an-image' => 'unsafe_path', $two => 'missing_file'
    ], 'D-16');
} finally {
    remove_tree($root);
}
