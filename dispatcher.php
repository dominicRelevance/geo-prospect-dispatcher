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

// ── Google Sheets ─────────────────────────────────────────────────────────────

function getSheetsService(): Google\Service\Sheets
{
    $client = new Google\Client();
    $client->setAuthConfig(CREDENTIALS_FILE);
    $client->addScope(Google\Service\Sheets::SPREADSHEETS);
    return new Google\Service\Sheets($client);
}

function getRows(Google\Service\Sheets $service): array
{
    $response = $service->spreadsheets_values->get(SPREADSHEET_ID, SHEET_NAME);
    return $response->getValues() ?? [];
}

/**
 * Write status, PDF URL, and timestamp back to the sheet row.
 * Uses the column map so it works regardless of column order.
 */
function updateRow(
    Google\Service\Sheets $service,
    int $rowNumber,
    string $status,
    string $pdfUrl,
    array $colMap
): void {
    $writes = [
        ['status', 'status'],                          $status,
        ['pdf report', 'pdf_report', 'report', 'pdf'], $pdfUrl,
        ['date', 'date completed', 'completed'],        date('Y-m-d H:i:s'),
    ];

    // Build pairs: [colNames[], value]
    $pairs = [];
    for ($i = 0; $i < count($writes); $i += 2) {
        $pairs[] = [$writes[$i], $writes[$i + 1]];
    }

    foreach ($pairs as [$colNames, $value]) {
        $idx = colIndex($colMap, ...$colNames);
        if ($idx === null) continue;
        $col   = colLetter($idx);
        $range = SHEET_NAME . '!' . $col . $rowNumber;
        $body  = new Google\Service\Sheets\ValueRange(['values' => [[$value]]]);
        $service->spreadsheets_values->update(SPREADSHEET_ID, $range, $body, ['valueInputOption' => 'RAW']);
    }
}

// ── API & PDF ─────────────────────────────────────────────────────────────────

function callGeoProspect(string $client, string $data): array
{
    $payload = json_encode(['client' => $client, 'data' => $data]);

    $ch = curl_init(GEO_PROSPECT_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception("cURL error: $error");
    if ($httpCode !== 200) throw new Exception("API returned HTTP $httpCode: $response");

    $decoded = json_decode($response, true);
    if (!$decoded || empty($decoded['pdf_base64'])) throw new Exception("Invalid API response");

    return $decoded;
}

function savePdf(string $base64Content, string $filename): string
{
    if (!is_dir(REPORTS_DIR)) {
        mkdir(REPORTS_DIR, 0755, true);
    }
    file_put_contents(REPORTS_DIR . $filename, base64_decode($base64Content));
    return REPORTS_URL . $filename;
}

function sendEmail(string $toEmail, string $clientName, string $pdfUrl): void
{
    $mail = new PHPMailer(true);
    $mail->isMail();
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($toEmail);
    $mail->Subject = "Your AI Visibility Report is ready — $clientName";
    $mail->isHTML(true);
    $mail->Body    = "
        <p>Hi,</p>
        <p>Your AI Visibility Report for <strong>{$clientName}</strong> is ready.</p>
        <p><a href='{$pdfUrl}'>Download your report</a></p>
        <p>This is a prototype report — full analysis coming soon.</p>
    ";
    $mail->AltBody = "Your report for {$clientName} is ready: {$pdfUrl}";
    $mail->send();
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

$processed = 0;

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2; // +2 because we shifted the header and rows are 1-indexed
    $row       = array_pad($row, count($headers), '');

    $client = trim($row[colIndex($colMap, 'client') ?? 0] ?? '');
    $data   = trim($row[colIndex($colMap, 'data') ?? 1] ?? '');
    $status = strtoupper(trim($row[colIndex($colMap, 'status') ?? 2] ?? ''));
    $email  = trim($row[colIndex($colMap, 'email') ?? 5] ?? '');

    if ($status !== 'RUN') {
        continue;
    }

    logMsg("Row $rowNumber: '$client' — status RUN, processing...");

    try {
        $result   = callGeoProspect($client, $data);
        $filename = 'report_' . preg_replace('/[^a-z0-9]/i', '_', $client) . '_' . time() . '.pdf';
        $pdfUrl   = savePdf($result['pdf_base64'], $filename);

        updateRow($service, $rowNumber, 'DONE', $pdfUrl, $colMap);
        logMsg("Row $rowNumber: DONE — $pdfUrl");

        if ($email) {
            sendEmail($email, $client, $pdfUrl);
            logMsg("Row $rowNumber: email sent to $email");
        }

        $processed++;
    } catch (Exception $e) {
        updateRow($service, $rowNumber, 'ERROR', $e->getMessage(), $colMap);
        logMsg("Row $rowNumber: ERROR — " . $e->getMessage());
    }
}

logMsg("Finished. Rows processed: $processed");
