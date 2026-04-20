<?php
/**
 * VHOENIX Configuration
 * 
 * INSTRUCTIONS:
 * 1. Copy this file to `config.php` (same folder)
 * 2. Fill in your actual credentials below
 * 3. NEVER commit config.php to git — add to .gitignore
 * 4. Set file permissions to 600 (owner read/write only)
 */

// ================================================
// Database Configuration (from Hostinger hPanel)
// ================================================
define('DB_HOST', 'localhost');           // Biasanya localhost
define('DB_NAME', 'u123456789_vhoenix');  // Dari hPanel
define('DB_USER', 'u123456789_vhoenix');  // Dari hPanel
define('DB_PASS', 'YOUR_DB_PASSWORD');    // Dari hPanel
define('DB_CHARSET', 'utf8mb4');

// ================================================
// Claude API (from console.anthropic.com)
// ================================================
define('CLAUDE_API_KEY', 'sk-ant-api03-YOUR_KEY_HERE');
define('CLAUDE_MODEL', 'claude-sonnet-4-5'); // atau claude-opus-4-7 untuk kualitas lebih tinggi

// ================================================
// Security
// ================================================
// Generate random 64-char string: https://www.random.org/strings/
define('APP_SECRET', 'CHANGE_THIS_TO_RANDOM_64_CHAR_STRING_FOR_SESSION_SIGNING');
define('SESSION_LIFETIME_DAYS', 30);
define('COOKIE_DOMAIN', '');  // Kosongkan untuk auto-detect, atau ".yourdomain.com"
define('COOKIE_SECURE', true); // true kalau HTTPS, false kalau testing HTTP lokal

// ================================================
// CORS (domain yang diizinkan akses API)
// ================================================
define('ALLOWED_ORIGINS', [
    'https://yourdomain.com',
    'https://www.yourdomain.com',
    'https://vhoenix.yoursubdomain.hostingersite.com', // Hostinger subdomain
    // 'http://localhost:3000', // Uncomment saat testing lokal
]);

// ================================================
// App settings
// ================================================
define('APP_NAME', 'VHOENIX');
define('APP_URL', 'https://yourdomain.com');
define('APP_ENV', 'production'); // 'production' | 'development'

// Rate limiting
define('RATE_LIMIT_LOGIN', 5);      // Max login attempts per 15 min per IP
define('RATE_LIMIT_SIGNUP', 3);     // Max signups per hour per IP
define('RATE_LIMIT_ANALYSIS_FREE', 5);   // Per day untuk tier scout
define('RATE_LIMIT_ANALYSIS_APEX', 100); // Per day untuk tier apex

// Feature flags
define('ALLOW_EMAIL_SIGNUP', true);
define('ALLOW_WALLET_SIGNUP', true);
define('REQUIRE_EMAIL_VERIFICATION', true); // Set true = user harus verifikasi email dengan OTP

// ================================================
// SMTP Settings (for sending OTP emails)
// ================================================
// Hostinger gives you free email hosting! Use their SMTP:
// 1. hPanel → Email → Email Accounts → Create Email (e.g., noreply@vhoenix.xyz)
// 2. Use those credentials below:
define('SMTP_HOST', 'smtp.hostinger.com');   // Or your SMTP provider
define('SMTP_PORT', 465);                     // 465 for SSL, 587 for TLS
define('SMTP_USER', 'noreply@vhoenix.xyz');  // Your email address
define('SMTP_PASS', 'YOUR_EMAIL_PASSWORD');  // Email account password
define('SMTP_FROM_EMAIL', 'noreply@vhoenix.xyz');
define('SMTP_FROM_NAME', 'VHOENIX');
define('SMTP_ENCRYPTION', 'ssl');            // 'ssl' for port 465, 'tls' for 587

// OTP settings
define('OTP_LENGTH', 6);              // 6-digit OTP code
define('OTP_EXPIRY_MINUTES', 10);     // OTP valid for 10 minutes
define('OTP_MAX_ATTEMPTS', 5);        // Max wrong attempts before lockout

// Error reporting (dev vs prod)
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
