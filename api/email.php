<?php
/**
 * Email & OTP handler
 * 
 * Sends OTP emails via SMTP (using native PHP socket — no PHPMailer dependency)
 * Supports SSL (port 465) and TLS (port 587)
 */

require_once __DIR__ . '/helpers.php';

// =====================================================
// Generate and send OTP to email
// =====================================================
function send_otp_email($email, $userId = null) {
    // Clean old OTPs for this email
    db_exec("DELETE FROM email_otps WHERE email = ? OR expires_at < NOW()", [$email]);

    // Generate random N-digit OTP
    $otpLength = defined('OTP_LENGTH') ? OTP_LENGTH : 6;
    $otp = '';
    for ($i = 0; $i < $otpLength; $i++) {
        $otp .= random_int(0, 9);
    }

    $expiryMin = defined('OTP_EXPIRY_MINUTES') ? OTP_EXPIRY_MINUTES : 10;
    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMin * 60);

    // Save OTP to DB
    db_insert(
        "INSERT INTO email_otps (email, otp_code, user_id, expires_at) VALUES (?, ?, ?, ?)",
        [$email, $otp, $userId, $expiresAt]
    );

    // Send email
    $subject = 'Your VHOENIX verification code: ' . $otp;
    $body = build_otp_email_html($otp, $expiryMin);
    $textBody = "Your VHOENIX verification code is: {$otp}\n\nThis code expires in {$expiryMin} minutes.\n\nIf you didn't request this, ignore this email.";

    $sent = send_smtp_email($email, $subject, $body, $textBody);
    
    return [
        'sent' => $sent,
        'otp' => APP_ENV === 'development' ? $otp : null, // Expose OTP only in dev mode
    ];
}

// =====================================================
// Verify OTP code
// =====================================================
function verify_otp($email, $code) {
    // Cleanup expired
    db_exec("DELETE FROM email_otps WHERE expires_at < NOW()");

    $row = db_one(
        "SELECT * FROM email_otps 
         WHERE email = ? AND used = 0 
         ORDER BY created_at DESC LIMIT 1",
        [$email]
    );

    if (!$row) {
        return ['valid' => false, 'error' => 'No OTP found or expired. Please request a new one.'];
    }

    $maxAttempts = defined('OTP_MAX_ATTEMPTS') ? OTP_MAX_ATTEMPTS : 5;
    if ((int)$row['attempts'] >= $maxAttempts) {
        db_exec("UPDATE email_otps SET used = 1 WHERE id = ?", [$row['id']]);
        return ['valid' => false, 'error' => 'Too many wrong attempts. Please request a new OTP.'];
    }

    if (!hash_equals((string)$row['otp_code'], (string)$code)) {
        db_exec("UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?", [$row['id']]);
        $remaining = $maxAttempts - (int)$row['attempts'] - 1;
        return ['valid' => false, 'error' => "Wrong code. {$remaining} attempts remaining."];
    }

    // Mark as used + verified
    db_exec("UPDATE email_otps SET used = 1 WHERE id = ?", [$row['id']]);

    return ['valid' => true, 'user_id' => $row['user_id']];
}

// =====================================================
// Build pretty HTML email
// =====================================================
function build_otp_email_html($otp, $expiryMin) {
    $appName = defined('APP_NAME') ? APP_NAME : 'VHOENIX';
    $appUrl = defined('APP_URL') ? APP_URL : '';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { margin:0; padding:0; background:#09090b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color:#e4e4e7; }
.container { max-width:600px; margin:0 auto; padding:40px 20px; }
.card { background:linear-gradient(180deg, #18181b 0%, #09090b 100%); border:1px solid #27272a; border-radius:16px; padding:40px 32px; text-align:center; }
.logo { font-size:24px; font-weight:600; letter-spacing:0.08em; color:#00D26A; margin-bottom:8px; }
.title { font-size:22px; color:#fafafa; margin:24px 0 8px; font-weight:500; }
.subtitle { font-size:14px; color:#a1a1aa; margin-bottom:32px; }
.otp-box { background:#050506; border:1px solid #00D26A; border-radius:12px; padding:24px; margin:24px 0; }
.otp-code { font-size:36px; font-weight:600; letter-spacing:0.4em; color:#00D26A; font-family: 'Courier New', monospace; }
.expiry { font-size:12px; color:#71717a; text-transform:uppercase; letter-spacing:0.15em; margin-top:12px; }
.footer { font-size:12px; color:#52525b; margin-top:32px; line-height:1.5; }
.divider { height:1px; background:#27272a; margin:24px 0; }
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <div class="logo">🦖 {$appName}</div>
    <div class="title">Verify your email</div>
    <div class="subtitle">Enter this code to complete your signup</div>
    <div class="otp-box">
      <div class="otp-code">{$otp}</div>
      <div class="expiry">Expires in {$expiryMin} minutes</div>
    </div>
    <div class="divider"></div>
    <div class="footer">
      If you didn't request this code, you can safely ignore this email.<br>
      This is an automated message — please do not reply.
    </div>
  </div>
  <div class="footer" style="text-align:center; margin-top:24px;">
    © 2026 {$appName} · AI research agent for Solana markets
  </div>
</div>
</body>
</html>
HTML;
}

// =====================================================
// Send email via SMTP (native PHP, no PHPMailer)
// Supports SSL (port 465) and STARTTLS (port 587)
// =====================================================
function send_smtp_email($to, $subject, $htmlBody, $textBody = '') {
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    $port = defined('SMTP_PORT') ? SMTP_PORT : 465;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : $user;
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'VHOENIX';
    $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'ssl';

    if (!$host || !$user || !$pass) {
        error_log('SMTP config missing');
        return false;
    }

    // For port 465 use SSL connection directly
    $connectHost = ($encryption === 'ssl') ? "ssl://{$host}" : $host;

    $errno = 0; $errstr = '';
    $sock = @fsockopen($connectHost, $port, $errno, $errstr, 15);
    if (!$sock) {
        error_log("SMTP connect failed: {$errstr} ({$errno})");
        return false;
    }

    stream_set_timeout($sock, 15);

    // Helper: read server response line
    $read = function() use ($sock) {
        $data = '';
        while ($line = fgets($sock, 515)) {
            $data .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $data;
    };

    // Helper: write command + read response
    $cmd = function($command, $expect) use ($sock, $read) {
        fwrite($sock, $command . "\r\n");
        $resp = $read();
        if ($expect && strpos($resp, (string)$expect) !== 0) {
            error_log("SMTP unexpected response to '{$command}': {$resp}");
            return false;
        }
        return $resp;
    };

    // Greeting
    $read();

    $domain = parse_url(defined('APP_URL') ? APP_URL : 'https://vhoenix.xyz', PHP_URL_HOST) ?: 'vhoenix.xyz';

    if (!$cmd("EHLO {$domain}", '250')) { fclose($sock); return false; }

    // STARTTLS for port 587
    if ($encryption === 'tls') {
        if (!$cmd("STARTTLS", '220')) { fclose($sock); return false; }
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock); return false;
        }
        if (!$cmd("EHLO {$domain}", '250')) { fclose($sock); return false; }
    }

    // Auth
    if (!$cmd("AUTH LOGIN", '334')) { fclose($sock); return false; }
    if (!$cmd(base64_encode($user), '334')) { fclose($sock); return false; }
    if (!$cmd(base64_encode($pass), '235')) { fclose($sock); return false; }

    // MAIL FROM / RCPT TO
    if (!$cmd("MAIL FROM:<{$fromEmail}>", '250')) { fclose($sock); return false; }
    if (!$cmd("RCPT TO:<{$to}>", '250')) { fclose($sock); return false; }

    // DATA
    if (!$cmd("DATA", '354')) { fclose($sock); return false; }

    // Build MIME message
    $boundary = 'VHOENIX_' . bin2hex(random_bytes(8));
    $headers = [];
    $headers[] = "From: " . encode_header_value($fromName) . " <{$fromEmail}>";
    $headers[] = "To: <{$to}>";
    $headers[] = "Subject: " . encode_header_value($subject);
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Date: " . date('r');
    $headers[] = "Message-ID: <" . bin2hex(random_bytes(16)) . "@{$domain}>";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

    $body = implode("\r\n", $headers) . "\r\n\r\n";

    // Plain text part
    if ($textBody) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";
    }

    // HTML part
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";

    $body .= "--{$boundary}--\r\n";

    // SMTP requires dots at start of line escaped
    $body = preg_replace('/^\./m', '..', $body);

    fwrite($sock, $body . "\r\n.\r\n");
    $resp = $read();
    if (strpos($resp, '250') !== 0) {
        error_log("SMTP DATA end error: {$resp}");
        fclose($sock);
        return false;
    }

    $cmd("QUIT", '221');
    fclose($sock);
    return true;
}

function encode_header_value($str) {
    if (preg_match('/[^\x20-\x7e]/', $str)) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
    return $str;
}
