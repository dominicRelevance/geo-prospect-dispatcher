<?php

// Copy this file to config.php and fill in your values

define('SPREADSHEET_ID', 'YOUR_GOOGLE_SHEET_ID');
define('SHEET_NAME', 'Jobs');
define('CREDENTIALS_FILE', __DIR__ . '/credentials/service-account.json');

define('GEO_PROSPECT_API_URL', 'https://YOUR-APP.railway.app/generate-report');

define('REPORTS_DIR', '/path/to/public_html/reports/');
define('REPORTS_URL', 'https://yourdomain.com/reports/');

// SMTP
define('SMTP_HOST',     'mail.yourdomain.com');
define('SMTP_PORT',     465);
define('SMTP_USERNAME', 'you@yourdomain.com');
define('SMTP_PASSWORD', 'your-password');
define('SMTP_FROM',     'you@yourdomain.com');
define('SMTP_FROM_NAME', 'Geo Prospect Reports');
