<?php

require_once __DIR__ . '/vendor/autoload.php';

define('SPREADSHEET_ID', '1co2Y-o4RoiMs4Y-ElRH_hatUTJPvNHcWCIChMILYwCA');
define('SHEET_NAME', 'Jobs');
define('CREDENTIALS_FILE', __DIR__ . '/credentials/service-account.json');

function getSheetData(): array
{
    $client = new Google\Client();
    $client->setAuthConfig(CREDENTIALS_FILE);
    $client->addScope(Google\Service\Sheets::SPREADSHEETS);

    $service = new Google\Service\Sheets($client);

    $response = $service->spreadsheets_values->get(
        SPREADSHEET_ID,
        SHEET_NAME
    );

    return $response->getValues() ?? [];
}

function displayRows(array $rows): void
{
    if (empty($rows)) {
        echo "No data found in sheet.\n";
        return;
    }

    $headers = array_shift($rows);
    $colCount = count($headers);

    echo str_repeat('-', 80) . "\n";
    echo implode(' | ', array_map(fn($h) => str_pad($h, 15), $headers)) . "\n";
    echo str_repeat('-', 80) . "\n";

    foreach ($rows as $i => $row) {
        // Pad row to match header count
        $row = array_pad($row, $colCount, '');
        echo implode(' | ', array_map(fn($v) => str_pad(substr($v, 0, 15), 15), $row)) . "\n";
    }

    echo str_repeat('-', 80) . "\n";
    echo count($rows) . " row(s) found.\n";
}

try {
    echo "Connecting to Google Sheets...\n";
    $rows = getSheetData();
    displayRows($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
