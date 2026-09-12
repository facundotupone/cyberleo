<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/images.php';
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
];
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $pdoOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
}
$pdo=new PDO((string)getenv('TEST_DSN'),getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',$pdoOptions);
if ($argc > 1 && getenv('TEST_UPLOAD_CASE') === false) {
    putenv('TEST_UPLOAD_CASE=' . $argv[1]);
    $_ENV['TEST_UPLOAD_CASE'] = $argv[1];
}
$workBase=getenv('TEST_WORK_DIR');
if (!is_string($workBase) || $workBase==='' || !is_dir($workBase)) { $workBase=sys_get_temp_dir(); }
$root=$workBase.'/cyberleo-upload-'.bin2hex(random_bytes(5));
mkdir("$root/assets/images/products",0700,true); mkdir("$root/assets/images/settings",0700,true);
$fixture="$root/pixel.png"; file_put_contents($fixture,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
$move=fn($s,$d)=>copy($s,$d); $passed=0;
$traceLast='boot';
function utrace(string $stage): void {
    global $traceLast;
    $traceLast = $stage;
    if (getenv('TEST_UPLOAD_TRACE') !== '1') {
        return;
    }
    static $origin = null;
    if ($origin === null) {
        $origin = hrtime(true);
    }
    $ms = (hrtime(true) - $origin) / 1e6;
    fprintf(STDERR, "[TRACE pid=%d +%.1fms] %s\n", getmypid(), $ms, $stage);
}
function uwant(string $id): bool {
    $sel = getenv('TEST_UPLOAD_CASE');
    if ($sel === false || $sel === '' || $sel === 'all') {
        return true;
    }
    return in_array($id, array_map('trim', explode(',', $sel)), true);
}
register_shutdown_function(static function (): void {
    global $traceLast;
    if (getenv('TEST_UPLOAD_TRACE') === '1') {
        fprintf(STDERR, "[TRACE pid=%d shutdown] last=%s\n", getmypid(), (string) $traceLast);
    }
});
function uok($v,$id,$text){global $passed;if(!$v)throw new RuntimeException("$id failed");$passed++;echo "$id PASS - $text\n";}
function ureset($pdo){
    utrace('ureset:begin');
    if ($pdo->inTransaction()) {
        utrace('ureset:rollback');
        $pdo->rollBack();
    }
    utrace('ureset:set innodb_lock_wait_timeout');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    utrace('ureset:set lock_wait_timeout');
    $pdo->exec('SET SESSION lock_wait_timeout=5');
    try {
        $pdo->exec('SET SESSION max_statement_time=8');
    } catch (Throwable) {
        // MariaDB 10.4 may reject the session variable name on some builds.
    }
    utrace('ureset:DROP TRIGGER');
    $pdo->exec('DROP TRIGGER IF EXISTS upload_fail');
    utrace('ureset:FOREIGN_KEY_CHECKS=0');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    // DELETE avoids MariaDB/PDO TRUNCATE stalls on Windows after several resets.
    utrace('ureset:DELETE product_images');
    $pdo->exec('DELETE FROM product_images');
    utrace('ureset:DELETE products');
    $pdo->exec('DELETE FROM products');
    utrace('ureset:DELETE store_settings');
    $pdo->exec('DELETE FROM store_settings');
    utrace('ureset:DELETE categories');
    $pdo->exec('DELETE FROM categories');
    utrace('ureset:FOREIGN_KEY_CHECKS=1');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    utrace('ureset:INSERT categories');
    $pdo->exec("INSERT INTO categories(id,name,icon)VALUES(1,'T','bi-cpu')");
    utrace('ureset:end');
}
function upl($f,$error=UPLOAD_ERR_OK){return ['name'=>'x.png','tmp_name'=>$f,'error'=>$error,'size'=>is_file($f)?filesize($f):1];}
function usetting($pdo,$k){
    utrace('usetting:prepare '.$k);
    $s=$pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key=?');
    utrace('usetting:execute '.$k);
    $s->execute([$k]);
    utrace('usetting:fetchColumn '.$k);
    $v=$s->fetchColumn();
    $s->closeCursor();
    utrace('usetting:done '.$k);
    return $v===false?null:$v;
}
function uset($pdo,$k,$v){
    utrace('uset:prepare '.$k);
    $s=$pdo->prepare('INSERT INTO store_settings(setting_key,setting_value)VALUES(?,?)');
    utrace('uset:execute '.$k);
    $s->execute([$k,$v]);
    $s->closeCursor();
    utrace('uset:done '.$k);
}
function uold($root,$c){
    $p='assets/images/settings/'.str_repeat($c,32).'.png';
    utrace('uold:file_put_contents '.$c);
    file_put_contents("$root/$p",'old');
    utrace('uold:done '.$c);
    return $p;
}
function urm($d){
    global $root;
    if (!is_dir($d)) return;
    $real = realpath($d);
    $realRoot = realpath($root);
    if ($real === false || $realRoot === false || ($real !== $realRoot && !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR))) {
        return;
    }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = "$d/$f";
        is_dir($p) ? urm($p) : unlink($p);
    }
    rmdir($d);
}
try{
 utrace('suite:begin');
 if (uwant('U-01')) {
 ureset($pdo);$moves=0;
 try{store_image_batch([upl($fixture),upl($fixture,UPLOAD_ERR_PARTIAL)],'products',$root,function($s,$d)use(&$moves){$moves++;return copy($s,$d);});$failed=false;}catch(Throwable){$failed=true;}
 uok($failed&&$moves===1&&count(glob("$root/assets/images/products/*"))===0&&(int)$pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn()===0,'U-01','partial multiple upload cleans earlier files');
 }
 if (uwant('U-02')) {
 ureset($pdo);$created=[];$pdo->exec("CREATE TRIGGER upload_fail BEFORE INSERT ON product_images FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fail'");
 try{$pdo->beginTransaction();$pdo->exec("INSERT INTO products(name,description,price,stock,category_id)VALUES('P','D',1,1,1)");$created=store_image_batch([upl($fixture)],'products',$root,$move);$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(LAST_INSERT_ID(),?,1)')->execute([$created[0]]);$pdo->commit();$failed=false;}catch(Throwable){$failed=true;if($pdo->inTransaction())$pdo->rollBack();cleanup_stored_images($created,$root);}
 $pdo->exec('DROP TRIGGER upload_fail');uok($failed&&(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()===0&&count(glob("$root/assets/images/products/*"))===0,'U-02','product create rollback cleans all new files');
 }
 if (uwant('U-03')) {
 ureset($pdo);$old='assets/images/products/'.str_repeat('a',32).'.png';file_put_contents("$root/$old",'old');$pdo->exec("INSERT INTO products(id,name,description,price,stock,image,category_id)VALUES(1,'P','D',1,1,'$old',1)");$pdo->exec("INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,'$old',1)");$created=[];
 try{$pdo->beginTransaction();$created=store_image_batch([upl($fixture),upl($fixture)],'products',$root,$move);foreach($created as$p)$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,?,0)')->execute([$p]);$pdo->exec('INSERT INTO nonexistent_table VALUES(1)');$pdo->commit();}catch(Throwable){if($pdo->inTransaction())$pdo->rollBack();cleanup_stored_images($created,$root);}
 uok(is_file("$root/$old")&&array_reduce($created,fn($ok,$p)=>$ok&&!file_exists("$root/$p"),true)&&(int)$pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn()===1,'U-03','product edit rollback preserves existing files');
 }
 if (uwant('U-04')) {
 ureset($pdo);$paths=store_image_batch([upl($fixture,UPLOAD_ERR_NO_FILE),upl($fixture)],'products',$root,$move);$pdo->beginTransaction();$pdo->exec("INSERT INTO products(id,name,description,price,stock,category_id)VALUES(1,'P','D',1,1,1)");$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,?,1)')->execute([$paths[0]]);$pdo->prepare('UPDATE products SET image=? WHERE id=1')->execute([$paths[0]]);$pdo->commit();
 uok(count($paths)===1&&(int)$pdo->query('SELECT is_main FROM product_images')->fetchColumn()===1&&$pdo->query('SELECT image FROM products')->fetchColumn()===$paths[0],'U-04','first successful image becomes main');
 }
 if (uwant('U-05')) {
 ureset($pdo);$pdo->exec("INSERT INTO products(id,name,description,price,stock,category_id)VALUES(1,'P','D',1,1,1)");$paths=store_image_batch([upl($fixture)],'products',$root,$move);$pdo->beginTransaction();$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,?,1)')->execute([$paths[0]]);$pdo->prepare('UPDATE products SET image=? WHERE id=1')->execute([$paths[0]]);$pdo->commit();
 uok((int)$pdo->query('SELECT is_main FROM product_images')->fetchColumn()===1&&$pdo->query('SELECT image FROM products')->fetchColumn()===$paths[0],'U-05','adding first image repairs empty product');
 }
 if (uwant('S-01')) {
 ureset($pdo);$old=uold($root,'1');uset($pdo,'hero_background',$old);uset($pdo,'body_background','');$r=save_settings_with_images($pdo,[],['hero_background'=>upl($fixture)],[],$root,$move);
 uok(usetting($pdo,'hero_background')===$r['backgrounds']['hero_background']&&is_file("$root/{$r['backgrounds']['hero_background']}")&&!is_file("$root/$old"),'S-01','background replacement cleans old after commit');
 }
 if (uwant('S-02')) {
 ureset($pdo);$hero=uold($root,'2');$body=uold($root,'3');uset($pdo,'hero_background',$hero);uset($pdo,'body_background',$body);$moves=0;$new=null;
 try{save_settings_with_images($pdo,[],['hero_background'=>upl($fixture),'body_background'=>upl($fixture)],[],$root,function($s,$d)use(&$moves,&$new){$moves++;if($moves===1){$new=$d;return copy($s,$d);}return false;});$failed=false;}catch(Throwable){$failed=true;}
 uok($failed&&usetting($pdo,'hero_background')===$hero&&usetting($pdo,'body_background')===$body&&!file_exists((string)$new),'S-02','second background failure rolls back both');
 }
 if (uwant('S-03')) {
 utrace('S-03:begin');
 ureset($pdo);$shared=uold($root,'4');uset($pdo,'hero_background',$shared);uset($pdo,'body_background',$shared);$r=save_settings_with_images($pdo,[],['hero_background'=>upl($fixture)],[],$root,$move);
 uok(usetting($pdo,'body_background')===$shared&&is_file("$root/$shared")&&$r['cleanup'][$shared]==='still_referenced','S-03','shared background remains referenced');
 utrace('S-03:end');
 }
 if (uwant('S-04')) {
 utrace('S-04:begin');
 utrace('S-04:before ureset');
 ureset($pdo);
 utrace('S-04:after ureset');
 $old=uold($root,'5');
 utrace('S-04:after uold');
 uset($pdo,'hero_background',$old);
 utrace('S-04:after uset hero');
 uset($pdo,'body_background','');
 utrace('S-04:after uset body');
 utrace('S-04:before save_settings_with_images');
 save_settings_with_images($pdo,[],[],['hero_background'=>true],$root,$move);
 utrace('S-04:after save_settings_with_images');
 uok(usetting($pdo,'hero_background')===''&&!is_file("$root/$old"),'S-04','background removal clears setting');
 utrace('S-04:end');
 }
 if (uwant('S-05')) {
 ureset($pdo);$old=uold($root,'6');uset($pdo,'hero_background',$old);uset($pdo,'body_background','');uset($pdo,'store_name','Old');$pdo->exec("CREATE TRIGGER upload_fail BEFORE UPDATE ON store_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fail'");$new=null;
 try{save_settings_with_images($pdo,['store_name'=>'New'],['hero_background'=>upl($fixture)],[],$root,function($s,$d)use(&$new){$new=$d;return copy($s,$d);});$failed=false;}catch(Throwable){$failed=true;}finally{$pdo->exec('DROP TRIGGER upload_fail');}
 uok($failed&&usetting($pdo,'hero_background')===$old&&usetting($pdo,'store_name')==='Old'&&is_file("$root/$old")&&!file_exists((string)$new),'S-05','database failure preserves old backgrounds');
 }
 if (uwant('S-06')) {
 ureset($pdo);$old=uold($root,'7');uset($pdo,'hero_background',$old);uset($pdo,'body_background','');$calls=0;$r=save_settings_with_images($pdo,[],['hero_background'=>upl($fixture)],[],$root,$move,function()use(&$calls){$calls++;return false;});
 uok($calls===1&&$r['cleanup'][$old]==='deletion_failed'&&usetting($pdo,'hero_background')===$r['backgrounds']['hero_background']&&is_file("$root/$old"),'S-06','failed post-commit unlink keeps new database state');
 }
 if (uwant('U-06')) {
 ureset($pdo);$pdo->exec("INSERT INTO products(id,name,description,price,stock,image,category_id)VALUES(1,'P','D',1,1,'bad',1)");
 $pdo->exec("INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,'$old',0),(1,'$shared',0)");
 $new=store_image_batch([upl($fixture)],'products',$root,$move)[0];$pdo->beginTransaction();$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,?,0)')->execute([$new]);$selected=repair_product_main_image($pdo,1);$pdo->commit();
 uok($selected===$old&&(int)$pdo->query('SELECT COUNT(*) FROM product_images WHERE product_id=1 AND is_main=1')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM product_images WHERE product_id=1')->fetchColumn()===3&&$pdo->query('SELECT image FROM products WHERE id=1')->fetchColumn()===$old,'U-06','editing repairs missing main image');
 }
 if (uwant('S-07')) {
 ureset($pdo);$hero=uold($root,'8');$body=uold($root,'9');uset($pdo,'hero_background',$hero);uset($pdo,'body_background',$body);$moves=0;
 try{save_settings_with_images($pdo,[],['hero_background'=>upl($fixture)],['hero_background'=>true],$root,function()use(&$moves){$moves++;return true;});$conflict=false;}catch(InvalidArgumentException){$conflict=true;}
 uok($conflict&&$moves===0&&usetting($pdo,'hero_background')===$hero&&usetting($pdo,'body_background')===$body&&is_file("$root/$hero")&&is_file("$root/$body")&&!$pdo->inTransaction(),'S-07','conflicting background actions are rejected');
 }
 $caseFilter = getenv('TEST_UPLOAD_CASE');
 if ($caseFilter === false || $caseFilter === '' || $caseFilter === 'all') {
     if($passed!==13)throw new RuntimeException("Expected 13, got $passed");
 }
 echo "Upload/settings tests: $passed passed, 0 failed\n";
 if ($caseFilter === false || $caseFilter === '' || $caseFilter === 'all') {
 $renderPassed=0;$cart=file_get_contents(dirname(__DIR__).'/cart.php');$cartJs=file_get_contents(dirname(__DIR__).'/assets/js/cart-checkout.js');$admin=file_get_contents(dirname(__DIR__).'/admin_products.php');
 uok(str_contains($cart,'is_safe_product_image_path')&&str_contains($cart,'JSON_HEX_TAG')&&str_contains($cartJs,'(?:[a-f0-9]{13}|[a-f0-9]{32})'), 'C-01', 'cart exports only normalized product image paths');$renderPassed++;
 uok(!str_contains($admin,'DEFAULT_PRODUCT_IMAGE')&&str_contains($admin,"addEventListener('error'")&&str_contains($admin,'productImageOrPlaceholder'), 'C-02', 'admin dynamic images use error listeners without default file');$renderPassed++;
 echo "Image rendering checks: $renderPassed passed, 0 failed\n";
 }
}finally{
    utrace('cleanup:begin');
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('DROP TRIGGER IF EXISTS upload_fail');
    urm($root);
    utrace('cleanup:end');
}
