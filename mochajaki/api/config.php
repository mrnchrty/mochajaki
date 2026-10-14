<?php
declare(strict_types=1);

/**
 * Mochajaki — API bootstrap.
 *
 * Every endpoint starts with `require __DIR__.'/config.php';` which sets up the
 * session, the JSON/security headers, the database connection and the shared
 * helper functions (validation, throttling, cross-site protection).
 *
 * Requires PHP 8.1+ (uses the `never` return type) with PDO MySQL, mbstring and
 * fileinfo enabled — see README.md.
 */

require_once __DIR__ . '/pricing.php';

/* ═══════════════════════════ 1. Session ═══════════════════════════ */

/**
 * Customer and admin sign-ins are kept in separate sessions so signing out of
 * one does not sign out the other. The area is decided by the endpoint name
 * (admin-*.php), never by a request header, so a client cannot pick which
 * session it wants to be read from.
 */
$scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$area = str_starts_with($scriptName, 'admin-') ? 'admin' : 'customer';

session_name('mj_' . $area);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

/* ═══════════════════════════ 2. Headers ═══════════════════════════ */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
// Authenticated JSON must never sit in a browser or proxy cache.
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ═══════════════════════════ 3. Database ═══════════════════════════ */

$dbHost = '127.0.0.1';   // XAMPP default; change if your MySQL setup differs
$dbName = 'mochajaki';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}

/* ═══════════════════════════ 4. Order lifecycle ═══════════════════════════ */

/**
 * The statuses an order can move through. Kept here (and sent to the admin
 * panel) so the dropdown and the server validation cannot drift apart.
 * Must match the orders.status ENUM in database.sql.
 */
const ORDER_STATUSES = [
    'Order Placed', 'Confirmed', 'Preparing', 'Ready for Pickup',
    'Out for Delivery', 'Completed', 'Cancelled',
];

/** Payment states. Must match the payments.status ENUM in database.sql. */
const PAYMENT_STATUSES = ['Pending', 'Pending Verification', 'Paid', 'Failed', 'Refunded'];

/* ═══════════════════════════ 5. Output helpers ═══════════════════════ */

/** Reads a JSON request body. */
function body_json(): array
{
    $value = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($value) ? $value : [];
}

/** Sends a JSON response and stops. */
function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Rejects anything that is not the expected HTTP method. */
function method_is(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        json_out(['error' => 'Method not allowed.'], 405);
    }
}

/* ═══════════════════════════ 6. Auth helpers ═══════════════════════════ */

/** Returns the signed-in user or stops with 401. */
function require_login(): array
{
    if (empty($_SESSION['user'])) {
        json_out(['error' => 'Please sign in to continue.'], 401);
    }
    return $_SESSION['user'];
}

/** Returns the signed-in administrator or stops with 401/403. */
function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? '') !== 'admin') {
        json_out(['error' => 'Administrator access required.'], 403);
    }
    return $user;
}

/** Strips anything the browser must never see (password hashes). */
function clean_user(array $user): array
{
    unset($user['password_hash']);
    return $user;
}

/**
 * Verifies an email/password pair and returns the user row.
 * Shared by login.php (customer area) and admin-login.php (admin area).
 * Throttled: too many failures from this email or IP stop the request.
 *
 * @param bool $adminOnly when true, non-admin accounts are refused
 */
function attempt_login(PDO $pdo, string $email, string $password, bool $adminOnly = false): array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        json_out(['error' => 'Enter your email and password.'], 422);
    }

    throttle_check($pdo, 'login', $email);

    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // When the e-mail is unknown we still run one bcrypt check against this
    // dummy hash, so the response takes the same time whether or not the
    // account exists. Without it, a reply that skips password_verify() would
    // reveal which addresses are registered.
    $hash  = $user['password_hash'] ?? DUMMY_PASSWORD_HASH;
    $valid = password_verify($password, $hash);

    if (!$user || !$valid) {
        throttle_record($pdo, 'login', $email);
        json_out(['error' => 'Invalid email or password.'], 401);
    }

    if ($adminOnly && $user['role'] !== 'admin') {
        throttle_record($pdo, 'login', $email);
        json_out(['error' => 'Administrator access only.'], 403);
    }

    throttle_clear($pdo, 'login', $email);
    session_regenerate_id(true);   // new session id on every sign-in

    return $user;
}

/** Destroys the current (customer or admin) session and expires its cookie. */
function destroy_current_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ═══════════════════════════ 7. Cross-site protection ═══════════════════════════ */

/**
 * Blocks requests that did not come from our own pages.
 *
 * The session cookie already uses SameSite=Lax, which stops a plain cross-site
 * form post from carrying the cookie. This adds a second, independent lock:
 * every state-changing call must (a) be sent by our JavaScript with the
 * X-Requested-With header — a custom header a cross-site form cannot set — and
 * (b) when the browser tells us where it came from, come from the same host.
 */
function require_same_origin_request(): void
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        json_out(['error' => 'This action must be sent from the Mochajaki app.'], 400);
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $origin = (string) parse_url($referer, PHP_URL_SCHEME) . '://' . (string) parse_url($referer, PHP_URL_HOST);
        }
    }
    if ($origin !== '') {
        $selfHost = (string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (strcasecmp((string) parse_url($origin, PHP_URL_HOST), $selfHost) !== 0) {
            json_out(['error' => 'Cross-site requests are not allowed.'], 403);
        }
    }
}

// Applied automatically to every write request, so no endpoint can forget it.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
    require_same_origin_request();
}

/* ═══════════════════════════ 8. Sign-in throttling ═══════════════════════════ */

/**
 * A bcrypt (cost 12) digest of a random filler string that no customer knows.
 * attempt_login() verifies it when the e-mail address is not registered, so an
 * unknown account and a wrong password cost exactly the same time.
 */
const DUMMY_PASSWORD_HASH = '$2y$12$l2T4wT2GN.OaRzZsfIaSx.R/qk3mbc189LOgKSbL2smohz.ZX703O';

const THROTTLE_MAX_ATTEMPTS = 8;      // failures allowed inside the window
const THROTTLE_WINDOW_MINUTES = 15;   // rolling window length

/** Best-effort client address (no proxy headers are trusted). */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Counts recent failures for this email/IP and stops the request when the
 * limit is reached. Scopes: 'login', 'password' (change password), 'register'.
 */
function throttle_check(PDO $pdo, string $scope, string $email): void
{
    $sql = 'SELECT COUNT(*) FROM login_attempts
            WHERE scope = :scope AND created_at > (NOW() - INTERVAL :mins MINUTE)
              AND (email = :email OR ip = :ip)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['scope' => $scope, 'mins' => THROTTLE_WINDOW_MINUTES, 'email' => $email, 'ip' => client_ip()]);
    $failures = (int) $stmt->fetchColumn();

    if ($failures >= THROTTLE_MAX_ATTEMPTS) {
        json_out([
            'error' => 'Too many failed attempts. Please wait ' . THROTTLE_WINDOW_MINUTES . ' minutes and try again.',
        ], 429);
    }
}

/** Records one failed attempt. */
function throttle_record(PDO $pdo, string $scope, string $email): void
{
    $stmt = $pdo->prepare('INSERT INTO login_attempts (scope, email, ip) VALUES (?, ?, ?)');
    $stmt->execute([$scope, mb_substr($email, 0, 190), client_ip()]);

    // Keep the table small: prune day-old rows about once every ten failures.
    if (random_int(1, 10) === 1) {
        $pdo->prepare('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)')->execute();
    }
}

/** Clears the counter after a successful sign-in / password change. */
function throttle_clear(PDO $pdo, string $scope, string $email): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE scope = ? AND (email = ? OR ip = ?)');
    $stmt->execute([$scope, $email, client_ip()]);
}
