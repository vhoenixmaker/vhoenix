<?php
/**
 * Common helpers for API endpoints
 */

require_once __DIR__ . '/db.php';

// =====================================================
// CORS + JSON headers
// =====================================================
function setup_cors() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, ALLOWED_ORIGINS, true)) {
        header("Access-Control-Allow-Origin: $origin");
    }
    
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');
    
    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// =====================================================
// JSON response helpers
// =====================================================
function json_ok($data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function json_error($message, $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => false,
        'error' => $message,
    ], $extra));
    exit;
}

// =====================================================
// Read JSON body
// =====================================================
function json_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// =====================================================
// Request method check
// =====================================================
function require_method($method) {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        json_error('Method not allowed', 405);
    }
}

// =====================================================
// Session management
// =====================================================
function generate_token($bytes = 32) {
    return bin2hex(random_bytes($bytes));
}

function create_session($userId) {
    $sessionId = generate_token(32);
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME_DAYS * 86400);
    
    db_exec(
        "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at) 
         VALUES (?, ?, ?, ?, ?)",
        [
            $sessionId,
            $userId,
            get_client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            $expiresAt,
        ]
    );
    
    // Set cookie
    setcookie('vhoenix_session', $sessionId, [
        'expires'  => time() + SESSION_LIFETIME_DAYS * 86400,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    
    return $sessionId;
}

function destroy_session($sessionId = null) {
    $sessionId = $sessionId ?? ($_COOKIE['vhoenix_session'] ?? null);
    
    if ($sessionId) {
        db_exec("DELETE FROM sessions WHERE id = ?", [$sessionId]);
    }
    
    // Clear cookie
    setcookie('vhoenix_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_user() {
    static $user = null;
    static $checked = false;
    
    if ($checked) return $user;
    $checked = true;
    
    $sessionId = $_COOKIE['vhoenix_session'] ?? null;
    if (!$sessionId) return null;
    
    $row = db_one(
        "SELECT u.*, s.id as session_id, s.expires_at 
         FROM sessions s 
         JOIN users u ON u.id = s.user_id 
         WHERE s.id = ? AND s.expires_at > NOW()",
        [$sessionId]
    );
    
    if (!$row) return null;
    
    // Update last activity
    db_exec("UPDATE sessions SET last_activity_at = NOW() WHERE id = ?", [$sessionId]);
    
    $user = $row;
    return $user;
}

function require_auth() {
    $user = current_user();
    if (!$user) {
        json_error('Unauthorized — please log in', 401);
    }
    return $user;
}

// =====================================================
// Rate limiting
// =====================================================
function rate_limit($identifier, $action, $maxAttempts, $windowMinutes = 15) {
    // Cleanup expired windows
    db_exec(
        "DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$windowMinutes]
    );
    
    $row = db_one(
        "SELECT count FROM rate_limits 
         WHERE identifier = ? AND action = ? 
         AND window_start >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$identifier, $action, $windowMinutes]
    );
    
    if ($row && $row['count'] >= $maxAttempts) {
        json_error(
            "Too many attempts. Please wait {$windowMinutes} minutes and try again.",
            429
        );
    }
    
    // Increment
    db_exec(
        "INSERT INTO rate_limits (identifier, action, count, window_start) 
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE count = count + 1",
        [$identifier, $action]
    );
}

// =====================================================
// Validation
// =====================================================
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false 
        && strlen($email) <= 255;
}

function validate_password($password) {
    return is_string($password) 
        && strlen($password) >= 8 
        && strlen($password) <= 128;
}

function validate_wallet_address($address) {
    // Solana wallet: base58, 32-44 chars
    return is_string($address) 
        && preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
}

// =====================================================
// Misc
// =====================================================
function get_client_ip() {
    // Hostinger + Cloudflare support
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function sanitize_string($str, $maxLength = 255) {
    $str = trim((string) $str);
    return substr($str, 0, $maxLength);
}
