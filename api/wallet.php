<?php
/**
 * Phantom wallet authentication
 * 
 * Flow:
 * 1. Frontend: user clicks "Connect Phantom"
 * 2. Frontend calls POST /api/wallet.php?action=nonce { wallet_address }
 *    → Server generates random nonce, saves, returns message to sign
 * 3. Frontend: phantom.signMessage(message) → gets signature
 * 4. Frontend calls POST /api/wallet.php?action=verify 
 *    { wallet_address, signature, nonce }
 *    → Server verifies Ed25519 signature, creates session
 * 
 * Security:
 * - Nonce prevents replay attacks (one-time use, 10 min expiry)
 * - Ed25519 verification via libsodium (built into PHP 7.2+)
 * - Base58 decoding for Solana addresses
 */

require_once __DIR__ . '/helpers.php';
setup_cors();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'nonce':
        handle_nonce();
        break;
    case 'verify':
        handle_verify();
        break;
    case 'link':
        handle_link();
        break;
    case 'unlink':
        handle_unlink();
        break;
    default:
        json_error('Unknown wallet action', 404);
}

// =====================================================
// Step 1: Generate nonce (challenge to sign)
// =====================================================
function handle_nonce() {
    require_method('POST');
    
    $body = json_body();
    $wallet = sanitize_string($body['wallet_address'] ?? '', 44);
    
    if (!validate_wallet_address($wallet)) {
        json_error('Invalid Solana wallet address', 422);
    }
    
    rate_limit($wallet, 'wallet_nonce', 10, 5);
    
    // Cleanup expired nonces
    db_exec("DELETE FROM wallet_nonces WHERE expires_at < NOW()");
    
    // Generate fresh nonce
    $nonce = generate_token(16);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 min
    
    db_insert(
        "INSERT INTO wallet_nonces (wallet_address, nonce, expires_at) VALUES (?, ?, ?)",
        [$wallet, $nonce, $expiresAt]
    );
    
    // Message user will sign (human-readable + nonce)
    $message = build_sign_message($wallet, $nonce);
    
    json_ok([
        'nonce'   => $nonce,
        'message' => $message,
    ]);
}

// =====================================================
// Step 2: Verify signature + login/signup
// =====================================================
function handle_verify() {
    require_method('POST');
    
    $body = json_body();
    $wallet    = sanitize_string($body['wallet_address'] ?? '', 44);
    $signature = $body['signature'] ?? '';  // base58 or base64 encoded
    $nonce     = sanitize_string($body['nonce'] ?? '', 64);
    
    if (!validate_wallet_address($wallet)) {
        json_error('Invalid wallet address', 422);
    }
    if (!$signature || !$nonce) {
        json_error('Missing signature or nonce', 422);
    }
    
    rate_limit(get_client_ip(), 'wallet_verify', 10, 15);
    
    // Validate nonce exists, not used, not expired
    $nonceRow = db_one(
        "SELECT * FROM wallet_nonces 
         WHERE wallet_address = ? AND nonce = ? AND used = 0 AND expires_at > NOW()",
        [$wallet, $nonce]
    );
    
    if (!$nonceRow) {
        json_error('Invalid or expired nonce — please reconnect wallet', 401);
    }
    
    // Mark nonce as used (prevent replay)
    db_exec("UPDATE wallet_nonces SET used = 1 WHERE id = ?", [$nonceRow['id']]);
    
    // Reconstruct the message that was signed
    $message = build_sign_message($wallet, $nonce);
    
    // Verify Ed25519 signature
    if (!verify_solana_signature($message, $signature, $wallet)) {
        json_error('Signature verification failed', 401);
    }
    
    // Find or create user
    $user = db_one("SELECT * FROM users WHERE wallet_address = ?", [$wallet]);
    
    if (!$user) {
        if (!ALLOW_WALLET_SIGNUP) {
            json_error('Wallet signup is disabled. Please sign up with email first.', 403);
        }
        
        // Create new user with wallet
        $userId = db_insert(
            "INSERT INTO users (wallet_address, tier, analyses_reset_at) 
             VALUES (?, 'scout', CURDATE())",
            [$wallet]
        );
        $user = db_one("SELECT * FROM users WHERE id = ?", [$userId]);
    }
    
    // Update last login
    db_exec(
        "UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?",
        [get_client_ip(), $user['id']]
    );
    
    // Create session
    create_session($user['id']);
    
    json_ok([
        'user' => [
            'id'             => (int) $user['id'],
            'email'          => $user['email'],
            'wallet_address' => $user['wallet_address'],
            'display_name'   => $user['display_name'],
            'tier'           => $user['tier'],
        ],
    ]);
}

// =====================================================
// Link wallet to existing email account
// =====================================================
function handle_link() {
    require_method('POST');
    
    $me = require_auth();
    
    $body = json_body();
    $wallet    = sanitize_string($body['wallet_address'] ?? '', 44);
    $signature = $body['signature'] ?? '';
    $nonce     = sanitize_string($body['nonce'] ?? '', 64);
    
    if (!validate_wallet_address($wallet)) {
        json_error('Invalid wallet address', 422);
    }
    
    // Verify signature first
    $nonceRow = db_one(
        "SELECT * FROM wallet_nonces 
         WHERE wallet_address = ? AND nonce = ? AND used = 0 AND expires_at > NOW()",
        [$wallet, $nonce]
    );
    if (!$nonceRow) {
        json_error('Invalid or expired nonce', 401);
    }
    db_exec("UPDATE wallet_nonces SET used = 1 WHERE id = ?", [$nonceRow['id']]);
    
    $message = build_sign_message($wallet, $nonce);
    if (!verify_solana_signature($message, $signature, $wallet)) {
        json_error('Signature verification failed', 401);
    }
    
    // Check if wallet already linked to another user
    $existing = db_one(
        "SELECT id FROM users WHERE wallet_address = ? AND id != ?",
        [$wallet, $me['id']]
    );
    if ($existing) {
        json_error('This wallet is already linked to another account', 409);
    }
    
    // Link
    db_exec("UPDATE users SET wallet_address = ? WHERE id = ?", [$wallet, $me['id']]);
    
    json_ok(['message' => 'Wallet linked successfully']);
}

// =====================================================
// Unlink wallet from account
// =====================================================
function handle_unlink() {
    require_method('POST');
    
    $me = require_auth();
    
    if (!$me['email']) {
        json_error('Cannot unlink wallet — you have no email on this account. Add an email first.', 400);
    }
    
    db_exec("UPDATE users SET wallet_address = NULL WHERE id = ?", [$me['id']]);
    json_ok(['message' => 'Wallet unlinked']);
}

// =====================================================
// Message builder (must match what frontend signs)
// =====================================================
function build_sign_message($wallet, $nonce) {
    // Human-readable message — user sees this in Phantom popup
    return "Sign in to VHOENIX\n\n"
         . "Wallet: {$wallet}\n"
         . "Nonce: {$nonce}\n\n"
         . "This signature does not trigger any transaction or cost gas.";
}

// =====================================================
// Solana signature verification
// =====================================================
function verify_solana_signature($message, $signatureInput, $walletAddress) {
    // Sodium extension check
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        error_log('Sodium extension not available');
        return false;
    }
    
    try {
        // Decode signature (frontend sends base58)
        $signature = base58_decode($signatureInput);
        if ($signature === false || strlen($signature) !== 64) {
            // Try base64 as fallback (some Phantom versions)
            $signature = base64_decode($signatureInput, true);
            if ($signature === false || strlen($signature) !== 64) {
                return false;
            }
        }
        
        // Decode public key (wallet address is base58)
        $publicKey = base58_decode($walletAddress);
        if ($publicKey === false || strlen($publicKey) !== 32) {
            return false;
        }
        
        // Verify Ed25519 signature
        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        
    } catch (Exception $e) {
        error_log('Signature verification error: ' . $e->getMessage());
        return false;
    }
}

// =====================================================
// Base58 decoder (Bitcoin/Solana alphabet)
// =====================================================
function base58_decode($input) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $base = strlen($alphabet);
    
    if (!is_string($input) || strlen($input) === 0) {
        return false;
    }
    
    // Convert string to decimal
    $decimal = '0';
    for ($i = 0; $i < strlen($input); $i++) {
        $pos = strpos($alphabet, $input[$i]);
        if ($pos === false) return false;
        $decimal = bcadd(bcmul($decimal, (string)$base), (string)$pos);
    }
    
    // Convert decimal to binary string
    $bytes = '';
    while (bccomp($decimal, '0') > 0) {
        $mod = bcmod($decimal, '256');
        $bytes = chr((int)$mod) . $bytes;
        $decimal = bcdiv($decimal, '256', 0);
    }
    
    // Handle leading zeros (encoded as '1' in base58)
    for ($i = 0; $i < strlen($input) && $input[$i] === '1'; $i++) {
        $bytes = "\x00" . $bytes;
    }
    
    return $bytes;
}
