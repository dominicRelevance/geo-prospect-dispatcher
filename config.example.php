<?php

// Copy this file to config.php — it auto-detects local vs live from the HOME path.
// To add a new environment: extend the $_live detection logic below.

// SiteGround PHP sees paths as /home/customer/ regardless of the actual username
$_live = str_contains(__DIR__, '/home/customer');

define('SPREADSHEET_ID',   'YOUR_GOOGLE_SHEET_ID');
define('SHEET_NAME',       'Jobs');
define('CREDENTIALS_FILE', __DIR__ . '/credentials/service-account.json');

define('GEO_PROSPECT_API_URL', 'https://YOUR-APP.railway.app/generate-report');

define('REPORTS_DIR', $_live
    ? '/home/customer/www/yourdomain.com/reports/'
    : __DIR__ . '/reports/'
);
define('REPORTS_URL', $_live
    ? 'https://yourdomain.com/reports/'
    : 'http://localhost/rel_geo_tool/reports/'
);
define('LOG_FILE', __DIR__ . '/dispatcher.log');

// Email from-address (uses PHP mail(), no SMTP credentials needed on SiteGround)
define('SMTP_FROM',      'reports@yourdomain.com');
define('SMTP_FROM_NAME', 'Geo Prospect Reports');
