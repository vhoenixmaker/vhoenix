<?php
/**
 * Auth endpoints with Email OTP Verification
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email.php';
setup_cors();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':     handle_register();   break;
    case 'verify_otp':   handle_verify_otp(); break;
    case 'resend_otp':   handle_resend_otp(); break;
    case 'login':        handle_login();      break;
    case 'logout':       handle_logout();     break;
    case 'me':           handle_me();         break;
    default: json_error('Unknown action', 404);
}

// =====================================================
// Register — creates user as unverified, sends OTP
// =====================================================
function handle_register() {
    require_method('POST');
    
    if (!ALLOW_EMAIL_SIGNUP) {
        json_error('Email signup is currently disabled', 403);
    }
    
    rate_limit(get_client_ip(), 'signup', RATE_LIMIT_SIGNUP, 60);
    
    $body = json_body();
    $email = strtolower(sanitize_string($body['email'] ?? '', 255));
    $password = $body['password'] ?? '';
    $displayName = sanitize_string($body['display_name'] ?? '', 100);
    
    if (!validate_email($email)) {
        json_error('Invalid email address', 422);
    }
    if (!validate_password($password)) {
        json_error('Password must be 8–128 characters', 422);
    }
    
    $existing = db_one("SELECT id, email_verified_at FROM users WHERE email = ?", [$email]);
    if ($existing) {
        if ($existing['email_verified_at']) {
            json_error('Email already registered. Please sign in instead.', 409);
        } else {
            // Unverified — resend OTP
            rate_limit($email, 'resend_otp', 3, 15);
            $result = send_otp_email($email, $existing['id']);
            if (!$result['sent']) {
                json_error('Failed to send verification email. Please check SMTP config.', 500);
            }
            json_ok([
                'status' => 'otp_sent',
                'email' => $email,
                'message' => 'Verification code resent to your email.',
                'dev_otp' => $result['otp'],
            ]);
        }
    }
    
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    $userId = db_insert(
        "INSERT INTO users (email, password_hash, display_name, tier, analyses_reset_at, email_verified_at) 
         VALUES (?, ?, ?, 'scout', CURDATE(), NULL)",
        [$email, $hash, $displayName ?: null]
    );
    
    if (!REQUIRE_EMAIL_VERIFICATION) {
        db_exec("UPDATE users SET email_verified_at = NOW() WHERE id = ?", [$userId]);
        create_session($userId);
        $user = db_one("SELECT id, email, wallet_address, display_name, tier, created_at FROM users WHERE id = ?", [$userId]);
        json_ok(['status' => 'verified', 'user' => $user]);
    }
    
    $result = send_otp_email($email, $userId);
    if (!$result['sent']) {
        db_exec("DELETE FROM users WHERE id = ?", [$userId]);
        json_error('Failed to send verification email. Please check SMTP configuration in config.php.', 500);
    }
    
    json_ok([
        'status' => 'otp_sent',
        'email' => $email,
        'message' => 'Verification code sent to your email. Check your inbox.',
        'dev_otp' => $result['otp'],
    ]);
}

// =====================================================
// Verify OTP
// =====================================================
function handle_verify_otp() {
    require_method('POST');
    
    rate_limit(get_client_ip(), 'verify_otp', 10, 15);
    
    $body = json_body();
    $email = strtolower(sanitize_string($body['email'] ?? '', 255));
    $code = preg_replace('/[^0-9]/', '', (string)($body['otp'] ?? ''));
    
    if (!validate_email($email)) {
        json_error('Invalid email', 422);
    }
    if (!$code || strlen($code) < 4) {
        json_error('Invalid OTP code', 422);
    }
    
    $result = verify_otp($email, $code);
    if (!$result['valid']) {
        json_error($result['error'], 401);
    }
    
    $user = db_one("SELECT * FROM users WHERE email = ?", [$email]);
    if (!$user) {
        json_error('User not found', 404);
    }
    
    db_exec("UPDATE users SET email_verified_at = NOW(), last_login_at = NOW(), last_login_ip = ? WHERE id = ?", [get_client_ip(), $user['id']]);
    
    create_session($user['id']);
    
    json_ok([
        'status' => 'verified',
        'user' => [
            'id'             => (int)$user['id'],
            'email'          => $user['email'],
            'wallet_address' => $user['wallet_address'],
            'display_name'   => $user['display_name'],
            'tier'           => $user['tier'],
        ],
    ]);
}

// =====================================================
// Resend OTP
// =====================================================
function handle_resend_otp() {
    require_method('POST');
    
    $body = json_body();
    $email = strtolower(sanitize_string($body['email'] ?? '', 255));
    
    if (!validate_email($email)) {
        json_error('Invalid email', 422);
    }
    
    rate_limit($email, 'resend_otp', 3, 15);
    
    $user = db_one("SELECT id, email_verified_at FROM users WHERE email = ?", [$email]);
    if (!$user) {
        json_ok(['status' => 'otp_sent', 'message' => 'If the email exists, a code was sent.']);
    }
    
    if ($user['email_verified_at']) {
        json_error('Email already verified. Please sign in.', 400);
    }
    
    $result = send_otp_email($email, $user['id']);
    if (!$result['sent']) {
        json_error('Failed to send email', 500);
    }
    
    json_ok([
        'status' => 'otp_sent',
        'message' => 'New verification code sent.',
        'dev_otp' => $result['otp'],
    ]);
}

// =====================================================
// Login
// =====================================================
function handle_login() {
    require_method('POST');
    
    rate_limit(get_client_ip(), 'login', RATE_LIMIT_LOGIN, 15);
    
    $body = json_body();
    $email = strtolower(sanitize_string($body['email'] ?? '', 255));
    $password = $body['password'] ?? '';
    
    if (!$email || !$password) {
        json_error('Email and password required', 422);
    }
    
    $user = db_one("SELECT * FROM users WHERE email = ?", [$email]);
    
    $dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0ZHVtbXk$dummyhashdummyhashdummyhashdummyhashdummyhashdu';
    $valid = password_verify($password, $user['password_hash'] ?? $dummyHash);
    
    if (!$user || !$user['password_hash'] || !$valid) {
        json_error('Invalid email or password', 401);
    }
    
    if (REQUIRE_EMAIL_VERIFICATION && !$user['email_verified_at']) {
        $result = send_otp_email($user['email'], $user['id']);
        json_error('Email not verified. Check your inbox for verification code.', 403, [
            'needs_verification' => true,
            'email' => $user['email'],
            'dev_otp' => $result['otp'] ?? null,
        ]);
    }
    
    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        db_exec("UPDATE users SET password_hash = ? WHERE id = ?", [$newHash, $user['id']]);
    }
    
    db_exec(
        "UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?",
        [get_client_ip(), $user['id']]
    );
    
    create_session($user['id']);
    
    json_ok([
        'user' => [
            'id'             => (int)$user['id'],
            'email'          => $user['email'],
            'wallet_address' => $user['wallet_address'],
            'display_name'   => $user['display_name'],
            'tier'           => $user['tier'],
        ],
    ]);
}

// =====================================================
// Logout
// =====================================================
function handle_logout() {
    require_method('POST');
    destroy_session();
    json_ok(['message' => 'Logged out']);
}

// =====================================================
// Current user info
// =====================================================
function handle_me() {
    require_method('GET');
    
    $user = current_user();
    if (!$user) {
        json_error('Not authenticated', 401);
    }
    
    json_ok([
        'user' => [
            'id'              => (int)$user['id'],
            'email'           => $user['email'],
            'wallet_address'  => $user['wallet_address'],
            'display_name'    => $user['display_name'],
            'tier'            => $user['tier'],
            'analyses_today'  => (int)$user['analyses_today'],
            'created_at'      => $user['created_at'],
        ],
    ]);
}
