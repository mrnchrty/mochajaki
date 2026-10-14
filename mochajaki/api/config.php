<?php
declare(strict_types=1);
$area=(($_SERVER['HTTP_X_AREA']??'')==='admin')?'admin':'customer';session_name('mj_'.$area);
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
$host='127.0.0.1';$db='mochajaki';$dbUser='root';$dbPass='';
try{$pdo=new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Database connection failed.']);exit;}
function body_json():array{$v=json_decode(file_get_contents('php://input'),true);return is_array($v)?$v:[];}
function json_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function method_is(string $method):void{if(($_SERVER['REQUEST_METHOD']??'GET')!==$method)json_out(['error'=>'Method not allowed.'],405);}
function require_login():array{if(empty($_SESSION['user']))json_out(['error'=>'Please sign in to continue.'],401);return $_SESSION['user'];}
function require_admin():array{$u=require_login();if(($u['role']??'')!=='admin')json_out(['error'=>'Administrator access required.'],403);return $u;}
function clean_user(array $u):array{unset($u['password_hash']);return $u;}
