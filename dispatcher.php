<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

// Column indexes (0-based)
const COL_CLIENT      = 0;
const COL_DATA        = 1;
const COL_STATUS      = 2;
const COL_PDF_REPORT  = 3;
const COL_DATE        = 4;
const COL_EMAIL       = 5;

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

function updateRow(Google\Service\Sheets $service, int $rowNumber, string $status, string $pdfUrl): void
{
    $range = SHEET_NAME . '!C' . $rowNumber . ':E' . $rowNumber;
    $body  = new Google\Service\Sheets\ValueRange([
        'values' => [[$status, $pdfUrl, date('Y-m-d H:i:s')]]
    ]);
    $service->spreadsheets_values->update(
        SPREADSHEET_ID,
        $range,
        $body,
        ['valueInputOption' => 'RAW']
    );
}

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
    $mail->Body = "
        <p>Hi,</p>
        <p>Your AI Visibility Report for <strong>{$clientName}</strong> is ready.</p>
        <p><a href='{$pdfUrl}'>Download your report</a></p>
        <p>This is a prototype report — full analysis coming soon.</p>
    ";
    $mail->AltBody = "Your report for {$clientName} is ready: {$pdfUrl}";

    $mail->send();
}

// ── Main ──────────────────────────────────────────────────────────────────────

echo "Dispatcher started: " . date('Y-m-d H:i:s') . "\n";

$service = getSheetsService();
$rows    = getRows($service);

if (empty($rows)) {
    echo "No data in sheet.\n";
    exit;
}

array_shift($rows);

$processed = 0;

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2;
    $row       = array_pad($row, 6, '');

    $client = trim($row[COL_CLIENT]);
    $data   = trim($row[COL_DATA]);
    $status = strtoupper(trim($row[COL_STATUS]));
    $email  = trim($row[COL_EMAIL]);

    if ($status !== 'RUN') {
        continue;
    }

    echo "Processing row $rowNumber: $client\n";

    try {
        $result   = callGeoProspect($client, $data);
        $filename = 'report_' . preg_replace('/[^a-z0-9]/i', '_', $client) . '_' . time() . '.pdf';
        $pdfUrl   = savePdf($result['pdf_base64'], $filename);

        updateRow($service, $rowNumber, 'DONE', $pdfUrl);
        echo "  PDF: $pdfUrl\n";

        if ($email) {
            sendEmail($email, $client, $pdfUrl);
            echo "  Email sent to: $email\n";
        } else {
            echo "  No email address — skipping email\n";
        }

        $processed++;
    } catch (Exception $e) {
        updateRow($service, $rowNumber, 'ERROR', $e->getMessage());
        echo "  Error: " . $e->getMessage() . "\n";
    }
}

echo "Finished. Rows processed: $processed\n";
