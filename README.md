<p align="center">
  <img src="./banner.png" alt="VHOENIX Banner" width="100%">
</p>

# VHOENIX — AI Research Agent for Solana

![VHOENIX](https://img.shields.io/badge/VHOENIX-v1.0-00E676?style=flat-square&logoColor=white)
![Solana](https://img.shields.io/badge/Solana-Mainnet-9945FF?style=flat-square&logo=solana&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=flat-square&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-00E676?style=flat-square)
![Token](https://img.shields.io/badge/Token-%24VNIX-00E676?style=flat-square)

> **Evolved intelligence for crypto markets.** AI research agent that hunts alpha, analyzes on-chain data, and delivers trading intelligence — 24/7 on Solana.

🌐 **Live:** [vhoenix.xyz](https://vhoenix.xyz)

---

## What is VHOENIX?

VHOENIX is an AI-powered research terminal built for Solana memecoin traders. Paste any Solana contract address — VHOENIX fetches on-chain data from DexScreener, sends it to Claude AI for analysis, and delivers a verdict in under 15 seconds.

Built for pump.fun apers and memecoin degens who are tired of getting rekt by hidden rug pulls.

```
User pastes CA → DexScreener API → Claude AI Analysis → Verdict (APE / WAIT / AVOID)
```

---

## Features

- **Token Intelligence** — Paste any Solana CA, get instant on-chain analysis, risk score, and trading verdict
- **AI Verdict Engine** — Claude Sonnet AI reads liquidity, volume, buy/sell pressure, and risk signals
- **Risk Flag Detection** — Auto-detects rug indicators, low liquidity, heavy sell pressure, wash trading
- **Follow-up AI Chat** — Ask questions about any analysis with full context loaded
- **Live Market Intelligence** — Real-time trending tokens from DexScreener on landing page
- **Phantom Wallet Auth** — Ed25519 signature verification, non-custodial
- **Email + OTP Auth** — 6-digit OTP verification via SMTP
- **Watchlist & History** — Track tokens, revisit past analyses
- **Trade AI Page** — Manual trade guidance + AI auto-monitoring with signal alerts
- **HOLDTOKEN Tier** — Hold $VNIX to unlock access
- **Admin Dashboard** — User management panel at `/admin.html`

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Frontend** | Vanilla HTML/CSS/JS, Tailwind CSS CDN |
| **Backend** | PHP 8.x (procedural) |
| **Database** | MySQL 8.x |
| **AI Model** | Anthropic Claude Sonnet 4.5 |
| **On-chain Data** | DexScreener API (public) |
| **Wallet Auth** | Phantom + libsodium Ed25519 |
| **Hosting** | Hostinger Shared Hosting |

---

## Project Structure

```
vhoenix/
├── index.html              # Landing page (animated, live trending data)
├── login.html              # Auth — email OTP + Phantom wallet
├── dashboard.html          # Main app — analyze, chat, watchlist, history
├── trade.html              # Trade AI — manual + auto-monitoring modes
├── admin.html              # Admin dashboard — user management
├── docs.html               # Documentation
│
├── api/
│   ├── .htaccess           # Security headers + PHP file protection
│   ├── config.example.php  # Config template (copy to config.php + fill credentials)
│   ├── auth.php            # Email/OTP signup + login endpoints
│   ├── wallet.php          # Phantom Ed25519 signature verification
│   ├── analyze.php         # Core engine — DexScreener + Claude AI + caching
│   ├── chat.php            # Follow-up AI chat with context
│   ├── trending.php        # Public trending tokens from DexScreener
│   ├── admin.php           # Admin user list + stats endpoints
│   ├── email.php           # SMTP email sender (native PHP, no PHPMailer)
│   ├── helpers.php         # Session, rate limiting, CORS, validation
│   └── db.php              # PDO singleton + query helpers
│
└── db/
    ├── schema.sql           # Full database schema (8 tables)
    └── migration-otp.sql    # Add email_otps table (run if upgrading)
```

---

## Setup & Deployment

### Requirements

- PHP 8.x with `sodium`, `curl`, `pdo_mysql` extensions
- MySQL 8.x
- SMTP email account (for OTP verification)
- [Anthropic API key](https://console.anthropic.com)

### Installation

**1. Clone this repo**
```bash
git clone https://github.com/YOUR_USERNAME/vhoenix.git
cd vhoenix
```

**2. Create the database**

Import the schema into your MySQL database:
```sql
-- In phpMyAdmin or MySQL CLI:
CREATE DATABASE vhoenix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vhoenix;
SOURCE db/schema.sql;
```

**3. Configure the backend**

Copy the example config and fill in your credentials:
```bash
cp api/config.example.php api/config.php
```

Edit `api/config.php`:
```php
// Database
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Claude AI
define('CLAUDE_API_KEY', 'sk-ant-api03-...');
define('CLAUDE_MODEL', 'claude-sonnet-4-5');

// Security
define('APP_SECRET', 'your_64_char_random_string');
define('APP_URL', 'https://yourdomain.com');

// SMTP (for OTP emails)
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_USER', 'noreply@yourdomain.com');
define('SMTP_PASS', 'your_email_password');

// Admin access
define('ADMIN_EMAILS', ['your@email.com']);
```

**4. Upload to server**

Upload all files to your web root. Make sure `api/config.php` is **never committed to git** (it's in `.gitignore`).

**5. Test the installation**

Open in browser:
```
https://yourdomain.com/api/auth.php?action=me
```

Expected response: `{"success":false,"error":"Not authenticated"}`

---

## Security

- `config.php` is in `.gitignore` — API keys never hit git
- Passwords hashed with **Argon2id**
- Wallet auth via **Ed25519 signature** (libsodium) — private keys never touch the server
- **HttpOnly + Secure + SameSite=Lax** session cookies
- **PDO prepared statements** — no SQL injection
- Rate limiting on all auth endpoints
- `.htaccess` blocks direct access to PHP files in `/api/`

---

## Database Schema

8 tables: `users`, `sessions`, `wallet_nonces`, `rate_limits`, `analyses`, `watchlists`, `chat_messages`, `email_otps`

See [`db/schema.sql`](db/schema.sql) for full schema.

---

## API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/auth.php?action=register` | POST | Sign up with email |
| `/api/auth.php?action=verify_otp` | POST | Verify 6-digit OTP |
| `/api/auth.php?action=login` | POST | Sign in |
| `/api/auth.php?action=me` | GET | Get current session |
| `/api/wallet.php?action=nonce` | POST | Get challenge nonce |
| `/api/wallet.php?action=verify` | POST | Verify Phantom signature |
| `/api/analyze.php?action=run` | POST | Analyze a Solana token |
| `/api/chat.php?action=send` | POST | Follow-up AI chat |
| `/api/trending.php` | GET | Trending tokens (public) |
| `/api/admin.php?action=users` | GET | Admin: user list |
| `/api/admin.php?action=stats` | GET | Admin: overview stats |

---

## Token — $VNIX

$VNIX is the native token of the VHOENIX ecosystem, launching on pump.fun.

- **Ticker:** $VNIX
- **Network:** Solana
- **Launchpad:** pump.fun
- **Contract:** TBA at launch

> ⚠️ Always verify the contract address at [vhoenix.xyz](https://vhoenix.xyz) before buying. Anything else is a scam.

---

## Roadmap

| Phase | Status | Features |
|---|---|---|
| **v1** | ✅ Live | AI analysis, risk flags, verdict, chat, watchlist |
| **v1.5** | 🔜 Soon | $VNIX token launch, HOLDTOKEN tier |
| **v2** | 📅 Q3 2026 | Multi-timeframe AI chart analysis |
| **v3** | 📅 Q4 2026 | Jupiter quick-trade integration |
| **v4** | 📅 2027 | Public API, Telegram bot, alerts |

---

## Contributing

Pull requests welcome. For major changes, please open an issue first.

1. Fork the repo
2. Create your branch: `git checkout -b feature/your-feature`
3. Commit: `git commit -m 'Add your feature'`
4. Push: `git push origin feature/your-feature`
5. Open a Pull Request

---

## License

MIT License — see [LICENSE](LICENSE) for details.

---

## Disclaimer

VHOENIX is an AI research tool, not financial advice. AI signals can be wrong. Markets are unpredictable. Always DYOR. Never invest more than you can afford to lose.

---

*Built with 🦖 by the VHOENIX team*
