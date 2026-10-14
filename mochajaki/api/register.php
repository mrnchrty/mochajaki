<?php
/**
 * Creates a customer account and signs it in.
 * New accounts are always role = customer; only database.sql creates admins.
 */
require __DIR__ . '/config.php';
method_is('POST');

$data  = body_json();
$name  = trim((string) ($data['full_name'] ?? ''));
$email = strtolower(trim((string) ($data['email'] ?? '')));
$pass  = (string) ($data['password'] ?? '');

if ($name === '' || mb_strlen($name) > 120) {
    json_out(['error' => 'Enter your full name (up to 120 characters).'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    json_out(['error' => 'Enter a valid email address.'], 422);
}
if (strlen($pass) < 8) {
    json_out(['error' => 'Password must be at least 8 characters.'], 422);
}

$ip = client_ip();
throttle_check($pdo, 'register', $email);

try {
    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'customer')");
    $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
} catch (PDOException $e) {
    throttle_record($pdo, 'register', $email);
    if ($e->getCode() === '23000') {
        json_out(['error' => 'This email is already registered.'], 409);
    }
    json_out(['error' => 'Unable to create account.'], 500);
}

throttle_clear($pdo, 'register', $email);
session_regenerate_id(true);

$_SESSION['user'] = [
    'id'        => (int) $pdo->lastInsertId(),
    'full_name' => $name,
    'email'     => $email,
    'role'      => 'customer',
];

json_out(['user' => $_SESSION['user']], 201);
