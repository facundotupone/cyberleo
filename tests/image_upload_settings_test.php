<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/images.php';
$pdo=new PDO((string)getenv('TEST_DSN'),getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root=sys_get_temp_dir().'/cyberleo-upload-'.bin2hex(random_bytes(5));
mkdir("$root/assets/images/products",0700,true); mkdir("$root/assets/images/settings",0700,true);
$fixture="$root/pixel.png"; file_put_contents($fixture,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
$move=fn($s,$d)=>copy($s,$d); $passed=0;
function uok($v,$id,$text){global $passed;if(!$v)throw new RuntimeException("$id failed");$passed++;echo "$id PASS - $text\n";}
function ureset($pdo){$pdo->exec('DROP TRIGGER IF EXISTS upload_fail;SET FOREIGN_KEY_CHECKS=0;TRUNCATE product_images;TRUNCATE products;TRUNCATE store_settings;TRUNCATE categories;SET FOREIGN_KEY_CHECKS=1');$pdo->exec("INSERT INTO categories(id,name,icon)VALUES(1,'T','bi-cpu')");}
function upl($f,$error=UPLOAD_ERR_OK){return ['name'=>'x.png','tmp_name'=>$f,'error'=>$error,'size'=>is_file($f)?filesize($f):1];}
function usetting($pdo,$k){$s=$pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key=?');$s->execute([$k]);$v=$s->fetchColumn();return $v===false?null:$v;}
function uset($pdo,$k,$v){$s=$pdo->prepare('INSERT INTO store_settings(setting_key,setting_value)VALUES(?,?)');$s->execute([$k,$v]);}
function uold($root,$c){$p='assets/images/settings/'.str_repeat($c,32).'.png';file_put_contents("$root/$p",'old');return $p;}
function urm($d){if(!is_dir($d))return;foreach(array_diff(scandir($d),['.','..'])as$f){$p="$d/$f";is_dir($p)?urm($p):@unlink($p);}@rmdir($d);}
try{
 // U-01
 ureset($pdo);$moves=0;
 try{store_image_batch([upl($fixture),upl($fixture,UPLOAD_ERR_PARTIAL)],'products',$root,function($s,$d)use(&$moves){$moves++;return copy($s,$d);});$failed=false;}catch(Throwable){$failed=true;}
 uok($failed&&$moves===1&&count(glob("$root/assets/images/products/*"))===0&&(int)$pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn()===0,'U-01','partial multiple upload cleans earlier files');
 // U-02
 ureset($pdo);$created=[];$pdo->exec("CREATE TRIGGER upload_fail BEFORE INSERT ON product_images FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fail'");
 try{$pdo->beginTransaction();$pdo->exec("INSERT INTO products(name,description,price,stock,category_id)VALUES('P','D',1,1,1)");$created=store_image_batch([upl($fixture)],'products',$root,$move);$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(LAST_INSERT_ID(),?,1)')->execute([$created[0]]);$pdo->commit();$failed=false;}catch(Throwable){$failed=true;if($pdo->inTransaction())$pdo->rollBack();cleanup_stored_images($created,$root);}
 $pdo->exec('DROP TRIGGER upload_fail');uok($failed&&(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()===0&&count(glob("$root/assets/images/products/*"))===0,'U-02','product create rollback cleans all new files');
 // U-03
 ureset($pdo);$old='assets/images/products/'.str_repeat('a',32).'.png';file_put_contents("$root/$old",'old');$pdo->exec("INSERT INTO products(id,name,description,price,stock,image,category_id)VALUES(1,'P','D',1,1,'$old',1)");$pdo->exec("INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,'$old',1)");$created=[];
 try{$pdo->beginTransaction();$created=store_image_batch([upl($fixture),upl($fixture)],'products',$root,$move);foreach($created as$p)$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,?,0)')->execute([$p]);$pdo->exec('INSERT INTO nonexistent_table VALUES(1)');$pdo->commit();}catch(Throwable){if($pdo->inTransaction())$pdo->rollBack();cleanup_stored_images($created,$root);}
 uok(is_file("$root/$old")&&array_reduce($created,fn($ok,$p)=>$ok&&!file_exists("$root/$p"),true)&&(int)$pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn()===1,'U-03','product edit rollback preserves existing files');
 // U-04
 ureset($pdo);$paths=store_image_batch([upl($fixture,UPLOAD_ERR_NO_FILE),upl($fixture)],'products',$root,$move);$pdo->beginTransaction();$pdo->exec("INSERT INTO products(id,name,description,price,stock,category_id)VALUES(1,'P','D',1,1,1)");$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,?,1)')->execute([$paths[0]]);$pdo->prepare('UPDATE products SET image=? WHERE id=1')->execute([$paths[0]]);$pdo->commit();
 uok(count($paths)===1&&(int)$pdo->query('SELECT is_main FROM product_images')->fetchColumn()===1&&$pdo->query('SELECT image FROM products')->fetchColumn()===$paths[0],'U-04','first successful image becomes main');
 // U-05
 ureset($pdo);$pdo->exec("INSERT INTO products(id,name,description,price,stock,category_id)VALUES(1,'P','D',1,1,1)");$paths=store_image_batch([upl($fixture)],'products',$root,$move);$pdo->beginTransaction();$pdo->prepare('INSERT INTO product_images(product_id,image_path,is_main)VALUES(1,?,1)')->execute([$paths[0]]);$pdo->prepare('UPDATE products SET image=? WHERE id=1')->execute([$paths[0]]);$pdo->commit();
 uok((int)$pdo->query('SELECT is_main FROM product_images')->fetchColumn()===1&&$pdo->query('SELECT image FROM products')->fetchColumn()===$paths[0],'U-05','adding first image repairs empty product');
 // S-01
 ureset($pdo);$old=uold($root,'1');uset($pdo,'hero_background',$old);uset($pdo,'body_background','');$r=save_settings_with_images($pdo,[],['hero_background'=>upl($fixture)],[],$root,$move);
 uok(usetting($pdo,'hero_background')===$r['backgrounds']['hero_background']&&is_file("$root/{$r['backgrounds']['hero_background']}")&&!is_file("$root/$old"),'S-01','background replacement cleans old after commit');
 // S-02
 ureset($pdo);$hero=uold($root,'2');$body=uold($root,'3');uset($pdo,'hero_background',$hero);uset($pdo,'body_background',$body);$moves=0;$new=null;
 try{save_settings_with_images($pdo,[],['hero_background'=>upl($fixture),'body_background'=>upl($fixture)],[],$root,function($s,$d)use(&$moves,&$new){$moves++;if($moves===1){$new=$d;return copy($s,$d);}return false;});$failed=false;}catch(Throwable){$failed=true;}
 uok($failed&&usetting($pdo,'hero_background')===$hero&&usetting($pdo,'body_background')===$body&&!file_exists((string)$new),'S-02','second background failure rolls back both');
 // S-03
 ureset($pdo);$shared=uold($root,'4');uset($pdo,'hero_background',$shared);uset($pdo,'body_background',$shared);$r=save_settings_with_images($pdo,[],['hero_background'=>upl($fixture)],[],$root,$move);
 uok(usetting($pdo,'body_background')===$shared&&is_file("$root/$shared")&&$r['cleanup'][$shared]==='still_referenced','S-03','shared background remains referenced');
 // S-04
 ureset($pdo);$old=uold($root,'5');uset($pdo,'hero_background',$old);uset($pdo,'body_background','');save_settings_with_images($pdo,[],[],['hero_background'=>true],$root,$move);
 uok(usetting($pdo,'hero_background')===''&&!is_file("$root/$old"),'S-04','background removal clears setting');
 // S-05
 ureset($pdo);$old=uold($root,'6');uset($pdo,'hero_background',$old);uset($pdo,'body_background','');uset($pdo,'store_name','Old');$pdo->exec("CREATE TRIGGER upload_fail BEFORE UPDATE ON store_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fail'");$new=null;
 try{save_settings_with_images($pdo,['store_name'=>'New'],['hero_background'=>upl($fixture)],[],$root,function($s,$d)use(&$new){$new=$d;return copy($s,$d);});$failed=false;}catch(Throwable){$failed=true;}finally{$pdo->exec('DROP TRIGGER upload_fail');}
 uok($failed&&usetting($pdo,'hero_background')===$old&&usetting($pdo,'store_name')==='Old'&&is_file("$root/$old")&&!file_exists((string)$new),'S-05','database failure preserves old backgrounds');
 // S-06
 ureset($pdo);$old=uold($root,'7');uset($pdo,'hero_background',$old);uset($pdo,'body_background','');$calls=0;$r=save_settings_with_images($pdo,[],['hero_background'=>upl($fixture)],[],$root,$move,function()use(&$calls){$calls++;return false;});
 uok($calls===1&&$r['cleanup'][$old]==='deletion_failed'&&usetting($pdo,'hero_background')===$r['backgrounds']['hero_background']&&is_file("$root/$old"),'S-06','failed post-commit unlink keeps new database state');
 if($passed!==11)throw new RuntimeException("Expected 11, got $passed");echo "Upload/settings tests: $passed passed, 0 failed\n";
}finally{$pdo->exec('DROP TRIGGER IF EXISTS upload_fail');urm($root);}
