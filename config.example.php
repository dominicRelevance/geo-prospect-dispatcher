<?php

// Copy this file to config.php.
//
// Env-var-first: every setting below reads from a real environment
// variable first (set these in the Railway dashboard → Variables) and
// falls back to a local XAMPP default when the variable is unset, so
// the same file works unchanged locally and on Railway.

function envOr(string $name, $default)
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

define('SPREADSHEET_ID', envOr('SPREADSHEET_ID', 'YOUR_GOOGLE_SHEET_ID'));
define('SHEET_NAME',     envOr('SHEET_NAME', 'Jobs'));

// Paste the full service-account JSON key as GOOGLE_SERVICE_ACCOUNT_JSON
// on Railway (no persistent file there). Locally, fall back to the
// gitignored credentials/service-account.json file.
$_credsJson = getenv('GOOGLE_SERVICE_ACCOUNT_JSON');
if ($_credsJson !== false && $_credsJson !== '') {
    define('GOOGLE_CREDENTIALS_CONFIG', json_decode($_credsJson, true));
} else {
    define('CREDENTIALS_FILE', __DIR__ . '/credentials/service-account.json');
}

// Origin only — dispatcher.php builds {base}/generate-report and
// {base}/status/{job_id} from this. The geo-prospect Railway service
// serves finished PDFs itself, so the dispatcher never hosts one.
define('GEO_PROSPECT_API_BASE_URL', envOr(
    'GEO_PROSPECT_API_BASE_URL',
    'https://YOUR-GEO-PROSPECT-APP.up.railway.app'
));

// Railway sets this automatically; nothing to configure. Used to
// decide whether PHP mail() is available as an email fallback (it
// isn't on Railway).
define('ON_RAILWAY', getenv('RAILWAY_ENVIRONMENT') !== false);

define('LOG_FILE', __DIR__ . '/dispatcher.log');

define('SMTP_FROM',      envOr('SMTP_FROM', 'reports@yourdomain.com'));
define('SMTP_FROM_NAME', envOr('SMTP_FROM_NAME', 'Geo Prospect Reports'));

// Real SMTP relay — Railway has no local mail() like SiteGround.
// Leave unset until you have a provider; sendEmail() skips sending
// (logs + continues) when SMTP_HOST is empty.
define('SMTP_HOST',     envOr('SMTP_HOST', ''));
define('SMTP_PORT',     (int) envOr('SMTP_PORT', 587));
define('SMTP_USER',     envOr('SMTP_USER', ''));
define('SMTP_PASSWORD', envOr('SMTP_PASSWORD', ''));
