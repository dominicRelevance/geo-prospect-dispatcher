<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;

// ── Helpers ───────────────────────────────────────────────────────────────────

function logMsg(string $msg): void
{
    $line = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

/**
 * Build a map of lowercase header name → 0-based column index.
 * Handles any column order or extra columns the client may have added.
 */
function buildColMap(array $headers): array
{
    $map = [];
    foreach ($headers as $i => $header) {
        $map[strtolower(trim($header))] = $i;
    }
    return $map;
}

/** Find a column index by trying multiple possible header names. */
function colIndex(array $colMap, string ...$names): ?int
{
    foreach ($names as $name) {
        if (isset($colMap[strtolower($name)])) {
            return $colMap[strtolower($name)];
        }
    }
    return null;
}

/** Convert 0-based index to sheet column letter (A, B, … Z, AA, …). */
function colLetter(int $index): string
{
    $letter = '';
    $index++;
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index  = intdiv($index, 26);
    }
    return $letter;
}

/** Read a cell by trying multiple possible header names; '' if none match. */
function cell(array $row, array $colMap, string ...$names): string
{
    $idx = colIndex($colMap, ...$names);
    return $idx === null ? '' : trim($row[$idx] ?? '');
}

/** Split a free-text list cell on comma/semicolon into trimmed, non-empty items. */
function splitList(string $value): array
{
    if ($value === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $value))));
}

/** "fast" / "standard" / "thorough" → geo_client.py's 1-5 samples_per_query scale. */
function sampleDepthToInt(string $depth): int
{
    return match (strtolower(trim($depth))) {
        'fast'     => 1,
        'thorough' => 5,
        default    => 3, // 'standard' and anything unrecognised
    };
}

// ── Google Sheets ─────────────────────────────────────────────────────────────

function getSheetsService(): Google\Service\Sheets
{
    $client = new Google\Client();
    // setAuthConfig() accepts either a file path or a decoded array —
    // on Railway there's no persistent credentials file, so config.php
    // decodes GOOGLE_SERVICE_ACCOUNT_JSON into GOOGLE_CREDENTIALS_CONFIG
    // instead.
    $client->setAuthConfig(defined('GOOGLE_CREDENTIALS_CONFIG')
        ? GOOGLE_CREDENTIALS_CONFIG
        : CREDENTIALS_FILE);
    $client->addScope(Google\Service\Sheets::SPREADSHEETS);
    return new Google\Service\Sheets($client);
}

function getRows(Google\Service\Sheets $service): array
{
    $response = $service->spreadsheets_values->get(SPREADSHEET_ID, SHEET_NAME);
    return $response->getValues() ?? [];
}

/** Write one or more columns back to a row by header name. $writes is
 * [ [names[], value], ... ]; columns whose header isn't found are skipped. */
function updateRow(Google\Service\Sheets $service, int $rowNumber, array $writes, array $colMap): void
{
    $data = [];
    foreach ($writes as [$colNames, $value]) {
        $idx = colIndex($colMap, ...$colNames);
        if ($idx === null) continue;
        $range  = SHEET_NAME . '!' . colLetter($idx) . $rowNumber;
        $data[] = new Google\Service\Sheets\ValueRange(['range' => $range, 'values' => [[$value]]]);
    }
    if (!$data) return;
    $body = new Google\Service\Sheets\BatchUpdateValuesRequest([
        'valueInputOption' => 'RAW',
        'data'              => $data,
    ]);
    $service->spreadsheets_values->batchUpdate(SPREADSHEET_ID, $body);
}

// ── geo-prospect API (async job contract) ──────────────────────────────────────

/** POST {base}/generate-report — starts a 30-40 min audit, returns immediately. */
function startAudit(array $inputs): array
{
    return httpJson('POST', GEO_PROSPECT_API_BASE_URL . '/generate-report', $inputs);
}

/** GET {base}/status/{job_id} — queued / running / completed / needs_review / error. */
function pollStatus(string $jobId): array
{
    return httpJson('GET', GEO_PROSPECT_API_BASE_URL . '/status/' . urlencode($jobId));
}

function httpJson(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // this is just starting/polling a job, never the audit itself
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception("cURL error calling $url: $error");
    if ($httpCode >= 400) throw new Exception("$url returned HTTP $httpCode: $response");

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) throw new Exception("Invalid JSON from $url: $response");
    return $decoded;
}

// ── Email ─────────────────────────────────────────────────────────────────────

/** Real SMTP when configured (required on Railway, which has no local
 * mail() the way SiteGround does), otherwise PHP mail() for local/SiteGround. */
function configureMailer(PHPMailer $mail): void
{
    if (SMTP_HOST !== '') {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = SMTP_USER !== '';
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->isMail();
    }
}

function sendEmail(string $toEmail, string $clientName, string $pdfUrl, string $caveat = ''): void
{
    if (SMTP_HOST === '' && ON_RAILWAY) {
        // Railway has no local mail() the way SiteGround does, and no
        // SMTP relay has been configured yet — skip rather than crash.
        logMsg("  SMTP not configured — skipping email to $toEmail");
        return;
    }
    // Local/SiteGround with no SMTP configured falls through to PHP
    // mail(), which works there without further setup.

    $mail = new PHPMailer(true);
    configureMailer($mail);
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($toEmail);
    $mail->Subject = "Your AI Visibility Report is ready — $clientName";
    $mail->isHTML(true);
    $caveatHtml = $caveat ? "<p><em>Note: {$caveat}</em></p>" : '';
    $mail->Body    = "
        <p>Hi,</p>
        <p>Your AI Visibility Report for <strong>{$clientName}</strong> is ready.</p>
        <p><a href='{$pdfUrl}'>Download your report</a></p>
        {$caveatHtml}
    ";
    $mail->AltBody = "Your report for {$clientName} is ready: {$pdfUrl}" . ($caveat ? "\nNote: $caveat" : '');
    $mail->send();
}

/** DEBUG_MODE only — attaches the bundled template PDF directly rather
 * than linking to a geo-prospect-hosted URL, since debug mode never
 * calls geo-prospect at all. */
function sendTemplateEmail(string $toEmail, string $clientName): void
{
    if (SMTP_HOST === '' && ON_RAILWAY) {
        logMsg("  SMTP not configured — skipping email to $toEmail");
        return;
    }

    $mail = new PHPMailer(true);
    configureMailer($mail);
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($toEmail);
    $mail->Subject = "[DEBUG] Your AI Visibility Report is ready — $clientName";
    $mail->isHTML(true);
    $mail->addAttachment(TEMPLATE_PDF_PATH, "{$clientName}_AI_Visibility_Report_TEMPLATE.pdf");
    $mail->Body    = "
        <p>Hi,</p>
        <p><strong>[DEBUG MODE]</strong> This is a template report for <strong>{$clientName}</strong> —
        the dispatcher pipeline (Sheet → job → email) is working end-to-end.
        This is not a real audit; geo-prospect was not called.</p>
    ";
    $mail->AltBody = "[DEBUG MODE] Template report for {$clientName} attached. "
        . "Dispatcher pipeline test — not a real audit.";
    $mail->send();
}

// ── Row processing ──────────────────────────────────────────────────────────────

/** DEBUG_MODE only — skips the geo-prospect call entirely and goes
 * straight to DONE with the bundled template PDF, so the Sheet + email
 * plumbing can be demoed/tested independently of geo-prospect being
 * deployed or working. */
function debugRow(Google\Service\Sheets $service, int $rowNumber, array $row, array $colMap): void
{
    $clientName = cell($row, $colMap, 'client (company name)', 'client')
        ?: cell($row, $colMap, 'domain') ?: 'Demo Client';
    $email = cell($row, $colMap, 'emailreport', 'email report', 'email');

    logMsg("Row $rowNumber: DEBUG_MODE — sending template PDF, skipping geo-prospect call");

    updateRow($service, $rowNumber, [
        [['run status', 'status'], 'DONE'],
        [['pdf_report', 'pdf report'], '[DEBUG MODE] template PDF emailed'],
        [['date', 'date completed', 'completed'], date('Y-m-d H:i:s')],
    ], $colMap);

    if ($email) {
        try {
            sendTemplateEmail($email, $clientName);
            logMsg("Row $rowNumber: DEBUG template email sent to $email");
        } catch (Exception $e) {
            logMsg("Row $rowNumber: DEBUG email FAILED — " . $e->getMessage());
        }
    } else {
        logMsg("Row $rowNumber: DEBUG_MODE — no email address on row, skipping send");
    }
}

/** RUN row with no job yet: build inputs from the sheet, start the audit,
 * flip the row to PROCESSING with the returned job_id. */
function startRow(Google\Service\Sheets $service, int $rowNumber, array $row, array $colMap): void
{
    $domain      = cell($row, $colMap, 'domain');
    $clientName  = cell($row, $colMap, 'client (company name)', 'client');

    $competitorDomains = [];
    foreach ([1, 2, 3, 4, 5] as $n) {
        $d = cell($row, $colMap, "competitor $n url");
        if ($d !== '') $competitorDomains[] = $d;
    }

    if ($domain === '' || !$competitorDomains) {
        updateRow($service, $rowNumber, [
            [['run status', 'status'], 'ERROR'],
            [['pdf_report', 'pdf report'], 'missing domain or competitor URLs'],
        ], $colMap);
        logMsg("Row $rowNumber: ERROR — missing domain or competitor URLs, not starting");
        return;
    }

    $inputs = [
        'client_domain'         => $domain,
        'client_name'           => $clientName ?: $domain,
        'competitor_domains'    => $competitorDomains,
        'category_descriptor'   => cell($row, $colMap, 'category descriptor'),
        'geographic_focus'      => cell($row, $colMap, 'geographic focus'),
        'brand_variants'        => splitList(cell($row, $colMap, 'brand variants')),
        'namesake_exclusion_terms' => splitList(cell($row, $colMap, 'namesake exclusions')),
        'custom_queries'        => splitList(cell($row, $colMap, 'custom queries')),
        'samples_per_query'     => sampleDepthToInt(cell($row, $colMap, 'sample depth (fast / standard / thorough)', 'sample depth')),
    ];

    logMsg("Row $rowNumber: '$clientName' ($domain) vs " . count($competitorDomains) . " competitor(s) — starting audit...");

    try {
        $result = startAudit($inputs);
        $jobId  = $result['job_id'] ?? '';
        if ($jobId === '') throw new Exception('generate-report response missing job_id');

        updateRow($service, $rowNumber, [
            [['run status', 'status'], 'PROCESSING'],
            [['job id', 'job_id'], $jobId],
        ], $colMap);
        logMsg("Row $rowNumber: PROCESSING — job_id=$jobId");
    } catch (Exception $e) {
        updateRow($service, $rowNumber, [
            [['run status', 'status'], 'ERROR'],
            [['pdf_report', 'pdf report'], $e->getMessage()],
        ], $colMap);
        logMsg("Row $rowNumber: ERROR — " . $e->getMessage());
    }
}

/** PROCESSING row with a job_id: poll once. Finishes the row on
 * completed/needs_review/error; leaves it as PROCESSING otherwise so the
 * next cron tick polls again — a run spans several ticks, same as the
 * old blocking call spanned one. */
function pollRow(Google\Service\Sheets $service, int $rowNumber, array $row, array $colMap): void
{
    $jobId      = cell($row, $colMap, 'job id', 'job_id');
    $clientName = cell($row, $colMap, 'client (company name)', 'client') ?: cell($row, $colMap, 'domain');
    $email      = cell($row, $colMap, 'emailreport', 'email report', 'email');

    if ($jobId === '') {
        logMsg("Row $rowNumber: PROCESSING but no job_id recorded — leaving as-is");
        return;
    }

    try {
        $status = pollStatus($jobId);
    } catch (Exception $e) {
        logMsg("Row $rowNumber: status poll failed (will retry next tick) — " . $e->getMessage());
        return;
    }

    $state = $status['status'] ?? '';

    if ($state === 'completed' || $state === 'needs_review') {
        $pdfUrl = GEO_PROSPECT_API_BASE_URL . ($status['pdf_url'] ?? '');
        updateRow($service, $rowNumber, [
            [['run status', 'status'], $state === 'completed' ? 'DONE' : 'REVIEW'],
            [['pdf_report', 'pdf report'], $pdfUrl],
            [['date', 'date completed', 'completed'], date('Y-m-d H:i:s')],
        ], $colMap);
        logMsg("Row $rowNumber: " . strtoupper($state) . " — $pdfUrl");

        if ($email) {
            try {
                sendEmail($email, $clientName, $pdfUrl, $status['review_reason'] ?? '');
                logMsg("Row $rowNumber: email sent to $email");
            } catch (Exception $e) {
                // A failed email should not turn an already-recorded
                // DONE/REVIEW row back into ERROR.
                logMsg("Row $rowNumber: email FAILED — " . $e->getMessage());
            }
        }
    } elseif ($state === 'error') {
        updateRow($service, $rowNumber, [
            [['run status', 'status'], 'ERROR'],
            [['pdf_report', 'pdf report'], $status['error'] ?? 'unknown error'],
        ], $colMap);
        logMsg("Row $rowNumber: ERROR — " . ($status['error'] ?? 'unknown error'));
    } else {
        logMsg("Row $rowNumber: still $state — will poll again next tick");
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

logMsg("Dispatcher started");

$service = getSheetsService();
$rows    = getRows($service);

if (empty($rows)) {
    logMsg("No data in sheet.");
    exit;
}

// First row is headers — build column map from it
$headers = array_shift($rows);
$colMap  = buildColMap($headers);

logMsg("Columns found: " . implode(', ', array_keys($colMap)));
if (DEBUG_MODE) {
    logMsg("DEBUG_MODE is ON — RUN rows will get a template PDF, geo-prospect will not be called");
}

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2; // +2 because we shifted the header and rows are 1-indexed
    $row       = array_pad($row, count($headers), '');
    $status    = strtoupper(cell($row, $colMap, 'run status', 'status'));

    if ($status === 'RUN' && DEBUG_MODE) {
        debugRow($service, $rowNumber, $row, $colMap);
    } elseif ($status === 'RUN') {
        startRow($service, $rowNumber, $row, $colMap);
    } elseif ($status === 'PROCESSING') {
        pollRow($service, $rowNumber, $row, $colMap);
    }
}

logMsg("Finished.");
