<?php
require __DIR__.'/config.php';
method_is('POST');
$u=require_login();
$d=body_json();
$cur=(string)($d['current_password']??'');
$new=(string)($d['new_password']??'');
if(strlen($new)<8)json_out(['error'=>'New password must be at least 8 characters.'],422);
$s=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');
$s->execute([$u['id']]);
$row=$s->fetch();
if(!$row||!password_verify($cur,$row['password_hash']))json_out(['error'=>'Current password is incorrect.'],403);
$s=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
$s->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);
json_out(['ok'=>true]);
