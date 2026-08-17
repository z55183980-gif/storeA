<?php
declare(strict_types=1);
$root=dirname(__DIR__);$file=$root.'/database/store.sqlite';if(file_exists($file))unlink($file);
$db=new PDO('sqlite:'.$file);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec(file_get_contents($root.'/database/schema.sql'));$db->exec(file_get_contents($root.'/database/seed.sql'));
$hash=password_hash(getenv('STORE_ADMIN_PASSWORD') ?: 'a12345678',PASSWORD_DEFAULT);$q=$db->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)');$q->execute(['a123456',$hash]);echo "SQLite initialized: $file\nAdmin: a123456\n";
