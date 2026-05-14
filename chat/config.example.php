<?php
// ============================================================
// Chat System Configuration — EXAMPLE FILE
// Copy this to config.php and fill in your values.
// NEVER commit config.php to version control.
// ============================================================

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'chat_db');
define('DB_USER', 'chat_user');
define('DB_PASS', '');

// Full URL to this chat/ directory (no trailing slash)
define('APP_URL', 'https://your-domain.com/chat');

// Secret key for JWT signing — generate with: php -r "echo bin2hex(random_bytes(32));"
define('JWT_SECRET', '');

// JWT token lifetime in seconds (default 8 hours)
define('JWT_TTL', 28800);

// WhatsApp Cloud API — from Meta Developer Portal
define('WA_PHONE_NUMBER_ID', '');
define('WA_ACCESS_TOKEN', '');
define('WA_VERIFY_TOKEN', '');
define('WA_API_VERSION', 'v18.0');

// Meta App Secret — used to verify X-Hub-Signature-256 on webhook POSTs.
// Found in: Meta Developer Portal → Your App → Settings → Basic → App Secret.
// Leave empty to skip verification (not recommended in production).
define('WA_APP_SECRET', '');

// CORS: comma-separated allowed origins, or '*' for any
// e.g. 'https://yourwebsite.com,https://www.yourwebsite.com'
define('ALLOWED_ORIGINS', 'https://yourwebsite.com');

// Uploads directory (relative to chat/ root)
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');
define('MAX_UPLOAD_MB', 10);

// aBillity API
define('ABILLITY_API_BASE', 'https://web.abillity.co.uk/YOURSITE');
define('ABILLITY_USERNAME', '');
define('ABILLITY_PASSWORD', '');

// Giacom Mobile API — credentials managed via Settings in the admin UI.
// These are fallbacks only; DB settings take priority.
define('GIACOM_USERNAME', '');
define('GIACOM_PASSWORD', '');
