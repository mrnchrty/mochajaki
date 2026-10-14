<?php
/**
 * Changes the signed-in user's password after re-checking the current one.
 * Throttled like sign-in so the current password cannot be brute forced.
 */
require __DIR__ . '/config.php';
method_is('POST');

$user = require_login();
$data = body_json();

$current = (string) ($data['current_password'] ?? '');
$new     = (string) ($data['new_password'] ?? '');

if (strlen($new) < 8) {
    json_out(['error' => 'New password must be at least 8 characters.'], 422);
}
if ($new === $current) {
    json_out(['error' => 'Your new password must be different from the current one.'], 422);
}

$email = (string) $user['email'];
throttle_check($pdo, 'password', $email);

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$hash = (string) ($stmt->fetchColumn() ?: '');

if ($hash === '' || !password_verify($current, $hash)) {
    throttle_record($pdo, 'password', $email);
    json_out(['error' => 'Current password is incorrect.'], 403);
}

$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);

throttle_clear($pdo, 'password', $email);

// A password change is a good moment to rotate the session id as well.
session_regenerate_id(true);

json_out(['ok' => true]);
