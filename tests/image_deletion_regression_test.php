<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/images.php';

$pdo = new PDO((string)getenv('TEST_DSN'), getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root = sys_get_temp_dir() . '/cyberleo-regression-' . bin2hex(random_bytes(6));
mkdir($root . '/assets/images/products', 0700, true);
$passed = 0;

function ok(bool $condition, string $id, string $text): void {
    global $passed;
    if (!$condition) throw new RuntimeException("$id failed");
    $passed++; echo "$id PASS - $text\n";
}
function resetDb(PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE product_images; TRUNCATE products; TRUNCATE categories; TRUNCATE store_settings; SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO categories(id,name,icon) VALUES(1,'Test','bi-cpu')");
}
function pathN(string $char): string { return 'assets/images/products/' . str_repeat($char, 32) . '.jpg'; }
function disk(string $root, string $path): string { return "$root/assets/images/products/" . basename($path); }
function touchImage(string $root, string $path): void { file_put_contents(disk($root, $path), 'image'); }
function product(PDO $pdo, ?string $image): int {
    $s=$pdo->prepare("INSERT INTO products(name,description,price,stock,image,category_id) VALUES('P','D',1,1,?,1)");
    $s->execute([$image]); return (int)$pdo->lastInsertId();
}
function image(PDO $pdo, int $product, string $path, int $main): int {
    $s=$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main) VALUES(?,?,?)');
    $s->execute([$product,$path,$main]); return (int)$pdo->lastInsertId();
}
function invariant(PDO $pdo, int $product): array {
    return $pdo->query("SELECT (SELECT COUNT(*) FROM product_images WHERE product_id=$product AND is_main=1) mains,(SELECT image FROM products WHERE id=$product) product_image")->fetch(PDO::FETCH_ASSOC);
}
function rmTree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.','..']) as $f) { $p="$dir/$f"; is_dir($p) ? rmTree($p) : @unlink($p); }
    @rmdir($dir);
}

try {
    $a=pathN('a'); $b=pathN('b'); $c=pathN('c'); $d=pathN('d');

    // R-01
    resetDb($pdo); touchImage($root,$a); $calls=0;
    $state=delete_unreferenced_product_image($pdo,$a,$root,function()use(&$calls){$calls++;return false;});
    ok($state==='deletion_failed' && $calls===1 && is_file(disk($root,$a)), 'R-01', 'failed callable invoked exactly once');
    echo "R-01 evidence: calls=$calls state=$state file=present\n";

    // R-02
    resetDb($pdo); $pid=product($pdo,$b); $low=image($pdo,$pid,$a,0); $main=image($pdo,$pid,$b,1); $high=image($pdo,$pid,$c,0);
    delete_product_image_record($pdo,$high); $inv=invariant($pdo,$pid);
    $lowMain=(int)$pdo->query("SELECT is_main FROM product_images WHERE id=$low")->fetchColumn();
    $midMain=(int)$pdo->query("SELECT is_main FROM product_images WHERE id=$main")->fetchColumn();
    ok($lowMain===0 && $midMain===1 && (int)$inv['mains']===1 && $inv['product_image']===$b, 'R-02', 'existing main image preserved');
    echo "R-02 SQL: low=$lowMain intermediate=$midMain mains={$inv['mains']} products.image={$inv['product_image']}\n";

    // R-03A
    resetDb($pdo); $pid=product($pdo,'inconsistent'); $one=image($pdo,$pid,$a,0); $removed=image($pdo,$pid,$b,0); image($pdo,$pid,$c,0);
    delete_product_image_record($pdo,$removed); $inv=invariant($pdo,$pid);
    ok((int)$inv['mains']===1 && $inv['product_image']===$a && (int)$pdo->query("SELECT is_main FROM product_images WHERE id=$one")->fetchColumn()===1, 'R-03A', 'missing main repaired');
    echo "R-03A SQL: selected_id=$one mains={$inv['mains']} products.image={$inv['product_image']}\n";

    // R-03B
    resetDb($pdo); $pid=product($pdo,'inconsistent'); $first=image($pdo,$pid,$a,1); image($pdo,$pid,$b,1); $secondary=image($pdo,$pid,$c,0);
    delete_product_image_record($pdo,$secondary); $inv=invariant($pdo,$pid);
    ok((int)$inv['mains']===1 && $inv['product_image']===$a && (int)$pdo->query("SELECT is_main FROM product_images WHERE id=$first")->fetchColumn()===1, 'R-03B', 'multiple mains normalized');
    echo "R-03B SQL: selected_id=$first mains={$inv['mains']} products.image={$inv['product_image']}\n";

    // R-04
    resetDb($pdo); touchImage($root,$d); $pid=product($pdo,$d); $iid=image($pdo,$pid,$d,1);
    $first=delete_product_image_record($pdo,$iid); $productImage=$pdo->query("SELECT image FROM products WHERE id=$pid")->fetchColumn();
    $second=delete_product_image_record($pdo,$iid);
    ok($first['status']==='deleted' && $second['status']==='not_found' && !$pdo->inTransaction() && $productImage===null, 'R-04', 'repeated image deletion is idempotent');

    // R-05
    resetDb($pdo); touchImage($root,$a); $pid=product($pdo,$a); image($pdo,$pid,$a,1); $cleanupCalls=0; $thrown=false; $triggerDropped=false;
    $pdo->exec("CREATE TRIGGER regression_block_product BEFORE DELETE ON products FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='blocked'");
    try {
        $result=delete_product_record($pdo,$pid);
        cleanup_product_images_after_commit($pdo,$result['paths'],$root,function()use(&$cleanupCalls){$cleanupCalls++;return true;});
    } catch (PDOException $e) { $thrown=true; }
    finally { $pdo->exec('DROP TRIGGER IF EXISTS regression_block_product'); $triggerDropped=true; }
    $rows=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE id=$pid")->fetchColumn();
    $images=(int)$pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id=$pid")->fetchColumn();
    ok($thrown && $triggerDropped && $rows===1 && $images===1 && is_file(disk($root,$a)) && $cleanupCalls===0 && !$pdo->inTransaction(), 'R-05', 'SQL failure rolls back without filesystem cleanup');
    echo "R-05 evidence: trigger_created=1 trigger_dropped=".(int)$triggerDropped." product_rows=$rows image_rows=$images cleanup_calls=$cleanupCalls transaction_open=".(int)$pdo->inTransaction()."\n";

    // R-06
    resetDb($pdo); touchImage($root,$b);
    $s=$pdo->prepare('INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?)'); $s->execute(['legacy_product_image',$b]);
    $state=delete_unreferenced_product_image($pdo,$b,$root);
    ok($state==='still_referenced' && is_file(disk($root,$b)), 'R-06', 'store setting reference preserves file');

    if ($passed!==7) throw new RuntimeException("Expected 7 regression tests, ran $passed");
    echo "Regression tests: $passed passed, 0 failed\n";
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS regression_block_product');
    rmTree($root);
}
