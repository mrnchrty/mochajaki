<?php
/**
 * Admin-area sign-in.
 *
 * Separate endpoint (and therefore separate session) from login.php, so signing
 * out of the shop does not sign the administrator out of the panel and vice
 * versa. Only accounts with role = admin are accepted.
 */
require __DIR__ . '/config.php';
method_is('POST');

$data  = body_json();
$email = strtolower(trim((string) ($data['email'] ?? '')));
$pass  = (string) ($data['password'] ?? '');

$user = attempt_login($pdo, $email, $pass, true);

$_SESSION['user'] = clean_user($user);
json_out(['user' => $_SESSION['user']]);
