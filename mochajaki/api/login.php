<?php
/**
 * Customer-area sign-in.
 * Admins may also sign in here (they will simply see the customer site).
 */
require __DIR__ . '/config.php';
method_is('POST');

$data  = body_json();
$email = strtolower(trim((string) ($data['email'] ?? '')));
$pass  = (string) ($data['password'] ?? '');

$user = attempt_login($pdo, $email, $pass, false);

$_SESSION['user'] = clean_user($user);
json_out(['user' => $_SESSION['user']]);
