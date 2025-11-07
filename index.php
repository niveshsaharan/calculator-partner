<?php
/**
 * Business Partnership Transaction Analyzer
 * Analyzes CSV transaction data for multiple partners (NB, NS)
 * Handles common expenses and unspecified transactions
 *
 * Compatible with PHP 7.4+
 */

// Initialize variables
$uploadError = '';
$uploadSuccess = '';
$transactions = [];
$summary = [];
$lastTransaction = [];
$fileInfo = [];
$previousBalances = [
        'NB' => 0,
        'NS' => 0,
        'C' => 0
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get previous balances if provided
    if (isset($_POST['prev_balance_nb'])) {
        $previousBalances['NB'] = floatval(str_replace(',', '', $_POST['prev_balance_nb']));
    }
    if (isset($_POST['prev_balance_ns'])) {
        $previousBalances['NS'] = floatval(str_replace(',', '', $_POST['prev_balance_ns']));
    }
    if (isset($_POST['prev_balance_common'])) {
        $previousBalances['C'] = floatval(str_replace(',', '', $_POST['prev_balance_common']));
    }

    // Check if file was uploaded
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileSize = $_FILES['csv_file']['size'];
        $fileType = $_FILES['csv_file']['type'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Validate file extension
        if ($fileExtension !== 'csv') {
            $uploadError = 'Please upload a valid CSV file.';
        }
        // Validate file size (max 10MB)
        elseif ($fileSize > 10 * 1024 * 1024) {
            $uploadError = 'File size exceeds 10MB limit.';
        }
        else {
            // Process the CSV file
            $result = processCSV($fileTmpPath, $previousBalances);

            if ($result['success']) {
                $transactions = $result['transactions'];
                $summary = $result['summary'];
                $lastTransaction = $result['last_transaction'];
                $fileInfo = [
                        'name' => $fileName,
                        'size' => formatBytes($fileSize),
                        'rows' => count($transactions)
                ];
                $uploadSuccess = 'File uploaded and processed successfully!';
            } else {
                $uploadError = $result['error'];
            }
        }
    } elseif (isset($_FILES['csv_file'])) {
        $uploadError = 'Error uploading file. Please try again.';
    }
}

/**
 * Process CSV file and extract transaction data
 */
function processCSV($filePath, $previousBalances) {
    $transactions = [];
    $lastTransaction = null;
    $summary = [
            'NB' => ['deposits' => 0, 'withdrawals' => 0, 'net' => 0, 'count' => 0],
            'NS' => ['deposits' => 0, 'withdrawals' => 0, 'net' => 0, 'count' => 0],
            'C' => ['deposits' => 0, 'withdrawals' => 0, 'net' => 0, 'count' => 0],
            'Unspecified' => ['deposits' => 0, 'withdrawals' => 0, 'net' => 0, 'count' => 0]
    ];

    // Open CSV file
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return ['success' => false, 'error' => 'Unable to open CSV file.'];
    }

    // Read header row
    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($handle);
        return ['success' => false, 'error' => 'CSV file is empty or invalid.'];
    }

    // Normalize header names (case-insensitive)
    $header = array_map('trim', $header);
    $headerMap = [];

    foreach ($header as $index => $columnName) {
        $normalized = strtolower($columnName);

        // Map various column name variations
        if (strpos($normalized, 'value date') !== false) {
            $headerMap['value_date'] = $index;
        } elseif (strpos($normalized, 'transaction date') !== false && strpos($normalized, 'posted') === false) {
            $headerMap['transaction_date'] = $index;
        } elseif (strpos($normalized, 'transaction remarks') !== false || strpos($normalized, 'description') !== false) {
            $headerMap['remarks'] = $index;
        } elseif (strpos($normalized, 'withdrawal') !== false) {
            $headerMap['withdrawal'] = $index;
        } elseif (strpos($normalized, 'deposit') !== false) {
            $headerMap['deposit'] = $index;
        } elseif (strpos($normalized, 'balance') !== false && strpos($normalized, 'closing') === false) {
            $headerMap['balance'] = $index;
        } elseif ($normalized === 'who') {
            $headerMap['who'] = $index;
        }
    }

    // Validate required columns
    $requiredColumns = ['remarks', 'withdrawal', 'deposit', 'who', 'balance'];
    foreach ($requiredColumns as $col) {
        if (!isset($headerMap[$col])) {
            fclose($handle);
            return ['success' => false, 'error' => "Required column '$col' not found in CSV."];
        }
    }

    // Process each row
    $rowNumber = 1;
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rowNumber++;

        // Skip empty rows
        if (empty(array_filter($row))) {
            continue;
        }

        // Extract data
        $date = isset($headerMap['value_date']) && isset($row[$headerMap['value_date']])
                ? trim($row[$headerMap['value_date']])
                : (isset($headerMap['transaction_date']) && isset($row[$headerMap['transaction_date']])
                        ? trim($row[$headerMap['transaction_date']])
                        : '');

        $remarks = isset($row[$headerMap['remarks']]) ? trim($row[$headerMap['remarks']]) : '';

        $withdrawalStr = isset($row[$headerMap['withdrawal']]) ? trim($row[$headerMap['withdrawal']]) : '';
        $depositStr = isset($row[$headerMap['deposit']]) ? trim($row[$headerMap['deposit']]) : '';
        $balanceStr = isset($headerMap['balance']) && isset($row[$headerMap['balance']])
                ? trim($row[$headerMap['balance']])
                : '';

        $whoStr = isset($row[$headerMap['who']]) ? trim($row[$headerMap['who']]) : '';

        // Parse amounts (handle commas, nan, empty values)
        $withdrawal = parseAmount($withdrawalStr);
        $deposit = parseAmount($depositStr);
        $balance = parseAmount($balanceStr);

        // Normalize "Who" field
        $who = normalizeWho($whoStr);

        // Add to transactions array
        $transactions[] = [
                'date' => $date,
                'remarks' => $remarks,
                'withdrawal' => $withdrawal,
                'deposit' => $deposit,
                'balance' => $balance,
                'who' => $who
        ];

        if(! $lastTransaction || ($lastTransaction && $date && strtotime($date) >= strtotime($lastTransaction['date']))) {
            $lastTransaction = [
                    'date' => $date,
                    'remarks' => $remarks,
                    'withdrawal' => $withdrawal,
                    'deposit' => $deposit,
                    'balance' => $balance,
                    'who' => $who
            ];
        }

        if ($who === 'C') {
            // Split common transactions 50-50 between partners
            $summary['NB']['deposits'] += ($deposit / 2);
            $summary['NB']['withdrawals'] += ($withdrawal / 2);
            $summary['NS']['deposits'] += ($deposit / 2);
            $summary['NS']['withdrawals'] += ($withdrawal / 2);

            // Still track in common for display purposes
//            $summary[$who]['deposits'] += $deposit;
//            $summary[$who]['withdrawals'] += $withdrawal;
            $summary[$who]['count']++;
        } else {
            // Regular transaction
            $summary[$who]['deposits'] += $deposit;
            $summary[$who]['withdrawals'] += $withdrawal;
            $summary[$who]['count']++;
        }
    }

    fclose($handle);

    // Calculate net amounts including previous balances
    // Calculate net amounts from transactions only (no previous balance here)
    foreach ($summary as $who => &$data) {
        $data['net'] = $data['withdrawals'] - $data['deposits'];
        $data['previous_balance'] = 0;
    }

    // Store previous balances separately (for display only, not in net calculation)
    $summary['NB']['previous_balance'] = $previousBalances['NB'];
    $summary['NS']['previous_balance'] = $previousBalances['NS'];
    $summary['C']['previous_balance'] = $previousBalances['C'];

    return [
            'success' => true,
            'transactions' => $transactions,
            'summary' => $summary,
            'last_transaction' => $lastTransaction,
    ];
}

/**
 * Parse amount string to float
 */
function parseAmount($str) {
    // Handle empty, nan, or invalid values
    if (empty($str) || strtolower($str) === 'nan' || $str === '-') {
        return 0;
    }

    // Remove commas and convert to float
    $cleaned = str_replace(',', '', $str);
    return floatval($cleaned);
}

/**
 * Normalize "Who" field
 */
function normalizeWho($str) {
    $normalized = strtoupper(trim($str));

    if ($normalized === 'NB') {
        return 'NB';
    } elseif ($normalized === 'NS') {
        return 'NS';
    } elseif ($normalized === 'C') {
        return 'C';
    } else {
        return 'Unspecified';
    }
}

/**
 * Format bytes to human-readable size
 */
function formatBytes($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Format currency for display
 */
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Partnership Transaction Analyzer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.95;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .upload-section {
            text-align: center;
        }

        .upload-area {
            border: 3px dashed #667eea;
            border-radius: 12px;
            padding: 40px;
            background: #f8f9ff;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .upload-area:hover {
            background: #f0f2ff;
            border-color: #764ba2;
        }

        .upload-area input[type="file"] {
            display: none;
        }

        .upload-icon {
            font-size: 3em;
            margin-bottom: 15px;
            color: #667eea;
        }

        .upload-text {
            font-size: 1.2em;
            color: #666;
            margin-bottom: 10px;
        }

        .upload-hint {
            font-size: 0.9em;
            color: #999;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 1em;
            cursor: pointer;
            transition: transform 0.2s ease;
            margin-top: 15px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-small {
            padding: 8px 20px;
            font-size: 0.9em;
            margin: 5px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95em;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border-left: 4px solid #3c3;
        }

        .file-info {
            background: #f8f9ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            text-align: left;
        }

        .file-info-item {
            display: inline-block;
            margin-right: 20px;
            color: #666;
        }

        .file-info-item strong {
            color: #333;
        }

        .previous-balance-section {
            margin-top: 25px;
            padding: 20px;
            background: #f8f9ff;
            border-radius: 8px;
        }

        .previous-balance-section h3 {
            margin-bottom: 15px;
            color: #667eea;
        }

        .balance-inputs {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .input-group {
            display: flex;
            flex-direction: column;
        }

        .input-group label {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .input-group input {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1em;
            transition: border-color 0.3s ease;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }

        .summary-card:hover {
            transform: translateY(-5px);
        }

        .summary-card.nb {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        }

        .summary-card.ns {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
        }

        .summary-card.common {
            background: linear-gradient(135deg, #d299c2 0%, #fef9d7 100%);
        }

        .summary-card.unspecified {
            background: linear-gradient(135deg, #fbc2eb 0%, #a6c1ee 100%);
        }

        .summary-card h3 {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #333;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.95em;
        }

        .summary-item.net {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid rgba(0,0,0,0.1);
            font-weight: bold;
            font-size: 1.1em;
        }

        .summary-item.previous {
            color: #666;
            font-size: 0.9em;
            font-style: italic;
        }

        .summary-label {
            color: #555;
        }

        .summary-value {
            font-weight: 600;
            color: #333;
        }

        .summary-value.positive {
            color: #27ae60;
        }

        .summary-value.negative {
            color: #e74c3c;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }

        .filter-buttons {
            margin-bottom: 20px;
            text-align: center;
        }

        .btn-filter {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 0.9em;
            cursor: pointer;
            margin: 5px;
            transition: all 0.3s ease;
        }

        .btn-filter:hover, .btn-filter.active {
            background: #667eea;
            color: white;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
        }

        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        th {
            padding: 15px 10px;
            text-align: left;
            font-weight: 600;
        }

        td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
        }

        tbody tr {
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: #f8f9ff;
        }

        tbody tr.nb {
            background: rgba(168, 237, 234, 0.2);
        }

        tbody tr.ns {
            background: rgba(255, 236, 210, 0.2);
        }

        tbody tr.common {
            background: rgba(210, 153, 194, 0.2);
        }

        tbody tr.unspecified {
            background: rgba(251, 194, 235, 0.3);
            font-weight: 500;
        }

        .who-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .who-badge.nb {
            background: #a8edea;
            color: #333;
        }

        .who-badge.ns {
            background: #ffecd2;
            color: #333;
        }

        .who-badge.common {
            background: #d299c2;
            color: #333;
        }

        .who-badge.unspecified {
            background: #fbc2eb;
            color: #333;
        }

        .amount-withdrawal {
            color: #e74c3c;
            font-weight: 600;
        }

        .amount-deposit {
            color: #27ae60;
            font-weight: 600;
        }

        .hidden {
            display: none;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 1.8em;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            table {
                font-size: 0.85em;
            }

            th, td {
                padding: 8px 5px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>💼 Business Partnership Transaction Analyzer</h1>
        <p>Analyze CSV transaction data for NB & NS partnership</p>
    </div>

    <div class="card">
        <div class="upload-section">
            <h2 style="margin-bottom: 20px;">Upload Transaction CSV File</h2>

            <?php if ($uploadError): ?>
                <div class="alert alert-error">
                    ⚠️ <?php echo htmlspecialchars($uploadError); ?>
                </div>
            <?php endif; ?>

            <?php if ($uploadSuccess): ?>
                <div class="alert alert-success">
                    ✓ <?php echo htmlspecialchars($uploadSuccess); ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="upload-area" onclick="document.getElementById('csv_file').click()">
                    <div class="upload-icon">📁</div>
                    <div class="upload-text">Click to browse or drag & drop your CSV file</div>
                    <div class="upload-hint">Maximum file size: 10MB</div>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" onchange="showFileName(this)">
                </div>

                <div id="selectedFile" style="margin-top: 15px; color: #667eea; font-weight: 500;"></div>

                <div class="previous-balance-section">
                    <h3>📊 Previous Balances (Optional)</h3>
                    <p style="color: #666; margin-bottom: 15px; font-size: 0.9em;">
                        Enter previous balances to include in net calculations. Leave blank if starting fresh.
                    </p>
                    <div class="balance-inputs">
                        <div class="input-group"></div>
                        <div class="input-group">
                            <label for="prev_balance_nb">NS paid to NB (₹)</label>
                            <input type="number" step="0.01" name="prev_balance_nb" id="prev_balance_nb"
                                   placeholder="0.00" value="<?php echo isset($_POST['prev_balance_nb']) ? htmlspecialchars($_POST['prev_balance_nb']) : ''; ?>">
                        </div>
                        <div class="input-group">
                            <label for="prev_balance_ns">NB paid to NS (₹)</label>
                            <input type="number" step="0.01" name="prev_balance_ns" id="prev_balance_ns"
                                   placeholder="0.00" value="<?php echo isset($_POST['prev_balance_ns']) ? htmlspecialchars($_POST['prev_balance_ns']) : ''; ?>">
                        </div>
                        <div class="input-group"></div>
                    </div>
                </div>

                <button type="submit" class="btn">Analyze Transactions</button>
            </form>

            <?php if (!empty($fileInfo)): ?>
                <div class="file-info">
                    <div class="file-info-item"><strong>File:</strong> <?php echo htmlspecialchars($fileInfo['name']); ?></div>
                    <div class="file-info-item"><strong>Size:</strong> <?php echo htmlspecialchars($fileInfo['size']); ?></div>
                    <div class="file-info-item"><strong>Transactions:</strong> <?php echo htmlspecialchars($fileInfo['rows']); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>



    <?php if (!empty($summary)): ?>

        <!-- Key Statistics -->
        <div class="card">
            <h2 style="margin-bottom: 20px;">📊 Key Statistics</h2>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-value"><?php echo count($transactions); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Deposits</div>
                    <div class="stat-value">
                        <?php
                        $totalDeposits = $summary['NB']['deposits'] + $summary['NS']['deposits'] +
                                $summary['C']['deposits'] + $summary['Unspecified']['deposits'];
                        echo formatCurrency($totalDeposits);
                        ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Withdrawals</div>
                    <div class="stat-value">
                        <?php
                        $totalWithdrawals = $summary['NB']['withdrawals'] + $summary['NS']['withdrawals'] +
                                $summary['C']['withdrawals'] + $summary['Unspecified']['withdrawals'];
                        echo formatCurrency($totalWithdrawals);
                        ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Latest Balance</div>
                    <div class="stat-value">
                        <?php
                        echo isset($lastTransaction['balance']) ? formatCurrency($lastTransaction['balance']) : '-';
                        ?>
                    </div>
                </div>
                <?php if ($summary['Unspecified']['count'] > 0): ?>
                    <div class="stat-box" style="background: linear-gradient(135deg, #fbc2eb 0%, #a6c1ee 100%);">
                        <div class="stat-label">⚠️ Needs Review</div>
                        <div class="stat-value"><?php echo $summary['Unspecified']['count']; ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <!-- Settlement Summary -->
        <div class="card">
            <h2 style="margin-bottom: 20px;">💰 Partnership Settlement Summary</h2>
            <?php
            // Calculate settlement between partners (excluding common transactions)
            $nbNetOriginal = $summary['NB']['net'];
            $nsNetOriginal = $summary['NS']['net'];
            $commonNet = $summary['C']['net'];

            // Calculate base settlement from transaction data
            $difference = $nsNetOriginal - $nbNetOriginal;
            $base_settlement_amount = abs($difference / 2);

            // Determine who owes whom before applying previous balance
            if ($difference > 0) {
                // NS has higher net (took more out), so NS owes NB
                $who_owes = "NS";
                $who_receives = "NB";
                // Apply previous balance: positive NB balance means NS already paid NB
                $settlement_amount = $base_settlement_amount - $previousBalances['NB'] + $previousBalances['NS'];
            } elseif ($difference < 0) {
                // NB has higher net (took more out), so NB owes NS
                $who_owes = "NB";
                $who_receives = "NS";
                // Apply previous balance: positive NS balance means NB already paid NS
                $settlement_amount = $base_settlement_amount - $previousBalances['NS'] + $previousBalances['NB'];
            } else {
                $settlement_amount = 0 - $previousBalances['NB'] - $previousBalances['NS'];
                $who_owes = "";
                $who_receives = "";
            }

            // Determine final direction after applying previous balance
            if ($settlement_amount > 0) {
                // Original direction holds
                if ($difference > 0) {
                    $settlement_detail = "💸 NS should pay this amount to NB to settle accounts";
                    $owes_who = "NS owes NB";
                } else {
                    $settlement_detail = "💸 NB should pay this amount to NS to settle accounts";
                    $owes_who = "NB owes NS";
                }
            } elseif ($settlement_amount < 0) {
                // Direction reversed
                $settlement_amount = abs($settlement_amount);
                if ($difference > 0) {
                    $settlement_detail = "💸 NB should pay this amount to NS to settle accounts";
                    $owes_who = "NB owes NS";
                    $who_owes = "NB";
                    $who_receives = "NS";
                } else {
                    $settlement_detail = "💸 NS should pay this amount to NB to settle accounts";
                    $owes_who = "NS owes NB";
                    $who_owes = "NS";
                    $who_receives = "NB";
                }
            } else {
                $settlement_amount = 0;
                $settlement_detail = "✅ Both partners are even";
                $owes_who = "Accounts are settled";
            }
            ?>

            <div style="background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); padding: 30px; border-radius: 12px; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 25px;">
                    <div style="text-align: center;">
                        <div style="font-size: 0.9em; color: #666; margin-bottom: 8px;">NB Net</div>
                        <div style="font-size: 1.8em; font-weight: bold; color: <?php echo $nbNetOriginal >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                            <?php echo formatCurrency($nbNetOriginal); ?>
                        </div>
                        <div style="font-size: 0.85em; color: #888; margin-top: 5px;">
                            (<?php echo $summary['NB']['count']; ?> transactions)
                        </div>
                    </div>

                    <div style="text-align: center;">
                        <div style="font-size: 0.9em; color: #666; margin-bottom: 8px;">NS Net</div>
                        <div style="font-size: 1.8em; font-weight: bold; color: <?php echo $nsNetOriginal >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                            <?php echo formatCurrency($nsNetOriginal); ?>
                        </div>
                        <div style="font-size: 0.85em; color: #888; margin-top: 5px;">
                            (<?php echo $summary['NS']['count']; ?> transactions)
                        </div>
                    </div>

                    <div style="text-align: center; display:none;">
                        <div style="font-size: 0.9em; color: #666; margin-bottom: 8px;">Common Balance</div>
                        <div style="font-size: 1.8em; font-weight: bold; color: #666;">
                            <?php echo formatCurrency($commonNet); ?>
                        </div>
                        <div style="font-size: 0.85em; color: #888; margin-top: 5px;">
                            (Split 50-50 between partners)
                        </div>
                    </div>
                </div>

                <div style="border-top: 3px solid rgba(0,0,0,0.1); padding-top: 25px; margin-top: 10px;">

                    <div style="background: white; padding: 25px; border-radius: 10px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">

                        <?php if ($settlement_amount > 0.01 && $who_owes == "NS"): ?>
                            <div style="font-size: 1.3em; color: #333; margin-bottom: 10px;">
                                <strong style="color: #667eea;">NS</strong> owes
                                <strong style="color: #764ba2;">NB</strong>
                            </div>
                            <div style="font-size: 2.5em; font-weight: bold; color: #e74c3c; margin: 15px 0;">
                                <?php echo formatCurrency($settlement_amount); ?>
                            </div>
                            <div style="font-size: 0.95em; color: #666; margin-top: 10px;">
                                💸 NS should pay this amount to NB to settle accounts
                            </div>

                        <?php elseif($settlement_amount > 0.01 && $who_owes == "NB"): ?>
                            <div style="font-size: 1.3em; color: #333; margin-bottom: 10px;">
                                <strong style="color: #764ba2;">NB</strong> owes
                                <strong style="color: #667eea;">NS</strong>
                            </div>
                            <div style="font-size: 2.5em; font-weight: bold; color: #e74c3c; margin: 15px 0;">
                                <?php echo formatCurrency($settlement_amount); ?>
                            </div>
                            <div style="font-size: 0.95em; color: #666; margin-top: 10px;">
                                💸 NB should pay this amount to NS to settle accounts
                            </div>

                        <?php else: ?>
                            <div style="font-size: 1.5em; color: #27ae60; font-weight: bold;">
                                ✅ Accounts are settled! No money owed.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($previousBalances['NB'] != 0 || $previousBalances['NS'] != 0): ?>
                        <div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 15px; border-radius: 8px; margin-top: 20px;">
                            <strong>💰 Previous Balance Applied:</strong>
                            <div style="margin-top: 10px; font-size: 0.9em; color: #555;">
                                <?php if ($previousBalances['NB'] != 0): ?>
                                    <div style="margin-bottom: 5px;">
                                        • NB Previous Balance: <?php echo formatCurrency($previousBalances['NB']); ?>
                                        <?php if ($previousBalances['NB'] > 0): ?>
                                            (NS already paid NB this amount)
                                        <?php else: ?>
                                            (NB already paid NS this amount)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($previousBalances['NS'] != 0): ?>
                                    <div style="margin-bottom: 5px;">
                                        • NS Previous Balance: <?php echo formatCurrency($previousBalances['NS']); ?>
                                        <?php if ($previousBalances['NS'] > 0): ?>
                                            (NB already paid NS this amount)
                                        <?php else: ?>
                                            (NS already paid NB this amount)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #c8e6c9;">
                                    <strong>Calculation:</strong> Base settlement of <?php echo formatCurrency($base_settlement_amount); ?>
                                    <?php if ($previousBalances['NB'] > 0): ?>
                                        - <?php echo formatCurrency($previousBalances['NB']); ?> (already paid)
                                    <?php elseif ($previousBalances['NB'] < 0): ?>
                                        + <?php echo formatCurrency(abs($previousBalances['NB'])); ?> (owed back)
                                    <?php endif; ?>
                                    <?php if ($previousBalances['NS'] > 0): ?>
                                        + <?php echo formatCurrency($previousBalances['NS']); ?> (owed back)
                                    <?php elseif ($previousBalances['NS'] < 0): ?>
                                        - <?php echo formatCurrency(abs($previousBalances['NS'])); ?> (already paid)
                                    <?php endif; ?>
                                    = <?php echo formatCurrency($settlement_amount); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($summary['Unspecified']['count'] > 0): ?>
                        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; border-radius: 8px; margin-top: 20px;">
                            <strong>⚠️ Note:</strong> There are <?php echo $summary['Unspecified']['count']; ?> unspecified transaction(s)
                            totaling <?php echo formatCurrency($summary['Unspecified']['net']); ?> that need to be categorized
                            for accurate settlement calculation.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background: #f8f9ff; padding: 20px; border-radius: 8px; font-size: 0.9em; color: #666;">
                <strong>📝 Calculation Method:</strong>
                <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li>Each partner's net = (Withdrawals - Deposits + Previous Balance)</li>
                    <li>Common expenses/income are split 50-50 between NB and NS</li>
                    <li>Settlement amount = |NB Net - NS Net| ÷ 2</li>
                    <li>The partner with higher net balance receives the settlement amount</li>
                </ul>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="card">
            <h2 style="margin-bottom: 20px;">📈 Financial Summary by Partner</h2>
            <div class="summary-grid">
                <!-- NB Summary -->
                <div class="summary-card nb">
                    <h3>👤 Partner NB</h3>

                    <div class="summary-item">
                        <span class="summary-label">Total Deposits:</span>
                        <span class="summary-value amount-deposit"><?php echo formatCurrency($summary['NB']['deposits']); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Withdrawals:</span>
                        <span class="summary-value amount-withdrawal"><?php echo formatCurrency($summary['NB']['withdrawals']); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Transactions:</span>
                        <span class="summary-value"><?php echo $summary['NB']['count']; ?></span>
                    </div>
                    <div class="summary-item net">
                        <span class="summary-label">Net Amount:</span>
                        <span class="summary-value <?php echo $summary['NB']['net'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo formatCurrency($summary['NB']['net']); ?>
                            </span>
                    </div>

                    <?php if ($summary['NB']['previous_balance'] != 0): ?>
                        <div class="summary-item previous">
                            <span class="summary-label">Previous Balance:</span>
                            <span class="summary-value"><?php echo formatCurrency($summary['NB']['previous_balance']); ?></span>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-small" onclick="filterTransactions('NB')">View Transactions</button>
                </div>

                <!-- NS Summary -->
                <div class="summary-card ns">
                    <h3>👤 Partner NS</h3>

                    <div class="summary-item">
                        <span class="summary-label">Total Deposits:</span>
                        <span class="summary-value amount-deposit"><?php echo formatCurrency($summary['NS']['deposits']); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Withdrawals:</span>
                        <span class="summary-value amount-withdrawal"><?php echo formatCurrency($summary['NS']['withdrawals']); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Transactions:</span>
                        <span class="summary-value"><?php echo $summary['NS']['count']; ?></span>
                    </div>
                    <div class="summary-item net">
                        <span class="summary-label">Net Amount:</span>
                        <span class="summary-value <?php echo $summary['NS']['net'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo formatCurrency($summary['NS']['net']); ?>
                            </span>
                    </div>

                    <?php if ($summary['NS']['previous_balance'] != 0): ?>
                        <div class="summary-item previous">
                            <span class="summary-label">Previous Balance:</span>
                            <span class="summary-value"><?php echo formatCurrency($summary['NS']['previous_balance']); ?></span>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-small" onclick="filterTransactions('NS')">View Transactions</button>
                </div>

                <!-- Common Summary -->
                <div class="summary-card common" style="display:none;">
                    <h3>🤝 Common Expenses</h3>

                    <div class="summary-item">
                        <span class="summary-label">Total Deposits:</span>
                        <span class="summary-value amount-deposit"><?php echo formatCurrency($summary['C']['deposits']); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Withdrawals:</span>
                        <span class="summary-value amount-withdrawal"><?php echo formatCurrency($summary['C']['withdrawals']); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Transactions:</span>
                        <span class="summary-value"><?php echo $summary['C']['count']; ?></span>
                    </div>
                    <div class="summary-item net">
                        <span class="summary-label">Net Amount:</span>
                        <span class="summary-value <?php echo $summary['C']['net'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo formatCurrency($summary['C']['net']); ?>
                            </span>
                    </div>
                    <?php if ($summary['C']['previous_balance'] != 0): ?>
                        <div class="summary-item previous">
                            <span class="summary-label">Previous Balance:</span>
                            <span class="summary-value"><?php echo formatCurrency($summary['C']['previous_balance']); ?></span>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-small" onclick="filterTransactions('C')">View Transactions</button>
                </div>

                <!-- Unspecified Summary -->
                <?php if ($summary['Unspecified']['count'] > 0): ?>
                    <div class="summary-card unspecified">
                        <h3>❓ Unspecified</h3>
                        <div class="summary-item">
                            <span class="summary-label">Total Deposits:</span>
                            <span class="summary-value amount-deposit"><?php echo formatCurrency($summary['Unspecified']['deposits']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Total Withdrawals:</span>
                            <span class="summary-value amount-withdrawal"><?php echo formatCurrency($summary['Unspecified']['withdrawals']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Transactions:</span>
                            <span class="summary-value"><?php echo $summary['Unspecified']['count']; ?></span>
                        </div>
                        <div class="summary-item net">
                            <span class="summary-label">Net Amount:</span>
                            <span class="summary-value <?php echo $summary['Unspecified']['net'] >= 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo formatCurrency($summary['Unspecified']['net']); ?>
                                </span>
                        </div>
                        <button class="btn btn-small" onclick="filterTransactions('Unspecified')">View Transactions</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detailed Transaction Table -->
        <div class="card">
            <h2 style="margin-bottom: 20px;">📋 Detailed Transaction Breakdown</h2>

            <div class="filter-buttons">
                <button class="btn-filter active" onclick="filterTransactions('all')">All Transactions</button>
                <button class="btn-filter" onclick="filterTransactions('NB')">NB Only</button>
                <button class="btn-filter" onclick="filterTransactions('NS')">NS Only</button>
                <button class="btn-filter" onclick="filterTransactions('C')">Common Only</button>
                <?php if ($summary['Unspecified']['count'] > 0): ?>
                    <button class="btn-filter" onclick="filterTransactions('Unspecified')">Unspecified Only</button>
                <?php endif; ?>
            </div>

            <div class="table-container">
                <table id="transactionTable">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transaction Remarks</th>
                        <th>Withdrawal (₹)</th>
                        <th>Deposit (₹)</th>
                        <th>Balance (₹)</th>
                        <th>Who</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr class="<?php echo strtolower($transaction['who']); ?>" data-who="<?php echo $transaction['who']; ?>">
                            <td><?php echo htmlspecialchars($transaction['date']); ?></td>
                            <td><?php echo htmlspecialchars($transaction['remarks']); ?></td>
                            <td class="amount-withdrawal">
                                <?php echo $transaction['withdrawal'] > 0 ? formatCurrency($transaction['withdrawal']) : '-'; ?>
                            </td>
                            <td class="amount-deposit">
                                <?php echo $transaction['deposit'] > 0 ? formatCurrency($transaction['deposit']) : '-'; ?>
                            </td>
                            <td><?php echo $transaction['balance'] > 0 ? formatCurrency($transaction['balance']) : '-'; ?></td>
                            <td>
                                        <span class="who-badge <?php echo strtolower($transaction['who']); ?>">
                                            <?php echo htmlspecialchars($transaction['who']); ?>
                                        </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Show selected file name
    function showFileName(input) {
        const fileDisplay = document.getElementById('selectedFile');
        if (input.files && input.files[0]) {
            fileDisplay.textContent = '📄 Selected: ' + input.files[0].name;
        }
    }

    // Filter transactions by partner
    let currentFilter = 'all';

    function filterTransactions(who) {
        currentFilter = who;
        const rows = document.querySelectorAll('#transactionTable tbody tr');
        const buttons = document.querySelectorAll('.btn-filter');

        // Update button states
        buttons.forEach(btn => {
            btn.classList.remove('active');
            if ((who === 'all' && btn.textContent.includes('All')) ||
                (who !== 'all' && btn.textContent.includes(who))) {
                btn.classList.add('active');
            }
        });

        // Filter rows
        rows.forEach(row => {
            if (who === 'all') {
                row.style.display = '';
            } else {
                if (row.getAttribute('data-who') === who) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        });

        document.querySelector('#transactionTable').closest('.card').scrollIntoView({
            behavior: 'smooth',
            block: 'start',    // 'start', 'center', 'end', or 'nearest'
            inline: 'start'  // 'start', 'center', 'end', or 'nearest'
        });
    }

    // Drag and drop functionality
    const uploadArea = document.querySelector('.upload-area');
    const fileInput = document.getElementById('csv_file');

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        uploadArea.addEventListener(eventName, highlight, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, unhighlight, false);
    });

    function highlight(e) {
        uploadArea.style.background = '#f0f2ff';
        uploadArea.style.borderColor = '#764ba2';
    }

    function unhighlight(e) {
        uploadArea.style.background = '#f8f9ff';
        uploadArea.style.borderColor = '#667eea';
    }

    uploadArea.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;

        if (files.length > 0) {
            fileInput.files = files;
            showFileName(fileInput);
        }
    }
</script>
</body>
</html>