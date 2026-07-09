<?php

// Env-var-first config. Railway sets real environment variables for
// everything below (dashboard → Variables); when a variable is unset,
// these fall back to today's XAMPP local-dev values so nothing changes
// for local runs. Replaces the old path-based live/local detection,
// which only ever matched SiteGround's __DIR__ shape and would never
// have matched a Railway container anyway.

function envOr(string $name, $default)
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

define('SPREADSHEET_ID', envOr('SPREADSHEET_ID', '1co2Y-o4RoiMs4Y-ElRH_hatUTJPvNHcWCIChMILYwCA'));
define('SHEET_NAME',     envOr('SHEET_NAME', 'Jobs'));

// Google service-account credentials: Railway containers have no
// persistent file, so GOOGLE_SERVICE_ACCOUNT_JSON carries the full key
// as a single-line env var — Google\Client::setAuthConfig() accepts a
// decoded array just as well as a file path. Locally, fall back to the
// gitignored credentials/service-account.json file.
$_credsJson = getenv('GOOGLE_SERVICE_ACCOUNT_JSON');
if ($_credsJson !== false && $_credsJson !== '') {
    define('GOOGLE_CREDENTIALS_CONFIG', json_decode($_credsJson, true));
} else {
    define('CREDENTIALS_FILE', __DIR__ . '/credentials/service-account.json');
}

// Origin only — dispatcher.php builds {base}/generate-report and
// {base}/status/{job_id} from this. The geo-prospect Railway service
// serves finished PDFs itself (at {base}/reports/<file>), so the
// dispatcher never hosts a PDF of its own.
define('GEO_PROSPECT_API_BASE_URL', envOr(
    'GEO_PROSPECT_API_BASE_URL',
    'http://localhost:8091'
));

// Railway sets this automatically on every deployment; nothing to
// configure. Used by dispatcher.php to decide whether PHP mail() is
// available as an email fallback (it isn't on Railway).
define('ON_RAILWAY', getenv('RAILWAY_ENVIRONMENT') !== false);

define('LOG_FILE', __DIR__ . '/dispatcher.log');

define('SMTP_FROM',      envOr('SMTP_FROM', 'reports@apicaution.relevanceweb.com'));
define('SMTP_FROM_NAME', envOr('SMTP_FROM_NAME', 'Geo Prospect Reports'));

// Real SMTP relay — Railway has no local mail() like SiteGround.
// Unset until Dominic supplies a provider; sendEmail() in
// dispatcher.php skips sending (logs + continues) when SMTP_HOST is
// empty, so this being blank does not break a run.
define('SMTP_HOST',     envOr('SMTP_HOST', ''));
define('SMTP_PORT',     (int) envOr('SMTP_PORT', 587));
define('SMTP_USER',     envOr('SMTP_USER', ''));
define('SMTP_PASSWORD', envOr('SMTP_PASSWORD', ''));

// Debug mode: for demoing/testing the dispatcher's Sheet + email
// plumbing independently of whether the geo-prospect Railway service
// is deployed. RUN rows skip the /generate-report call entirely and
// go straight to DONE with a bundled template PDF attached — proves
// the full loop (Sheet read, status write-back, real email send)
// without spending any audit API budget or depending on geo-prospect.
define('DEBUG_MODE', envOr('DEBUG_MODE', '') === 'true');
define('TEMPLATE_PDF_PATH', __DIR__ . '/template_report.pdf');
