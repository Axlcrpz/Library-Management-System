<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();

$report = isset($_GET['report']) ? $_GET['report'] : 'monthly';
$allowedReports = ['monthly', 'quarterly', 'section', 'annual'];
if (!in_array($report, $allowedReports, true)) {
    $report = 'monthly';
}

$now = new DateTime();
$currentYear = (int) $now->format('Y');
$currentMonth = (int) $now->format('n');
$currentQuarter = (int) ceil($currentMonth / 3);

function normalizeIntParam(string $key, int $default, array $allowed = []): int {
    if (!isset($_GET[$key]) || !is_numeric($_GET[$key])) {
        return $default;
    }
    $value = (int) $_GET[$key];
    return $allowed && !in_array($value, $allowed, true) ? $default : $value;
}

function fetchDocuments(PDO $pdo): array {
    $stmt = $pdo->query(
        'SELECT d.*, u.full_name AS created_by_name
         FROM library_documents d
         LEFT JOIN users u ON d.created_by = u.id
         WHERE COALESCE(d.is_deleted, 0) = 0
         ORDER BY d.upload_date DESC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getReportData(array $documents, string $reportType, array $params): array {
    $year = $params['year'] ?? (int) (new DateTime())->format('Y');
    $month = $params['month'] ?? (int) (new DateTime())->format('n');
    $quarter = $params['quarter'] ?? (int) ceil($month / 3);

    $result = ['title' => '', 'subtitle' => '', 'summary' => [], 'rows' => []];

    switch ($reportType) {
        case 'monthly':
            $result['title'] = 'Monthly Document Report';
            $result['subtitle'] = 'Documents uploaded in ' . DateTime::createFromFormat('!m', sprintf('%02d', $month))->format('F') . " {$year}";
            $filtered = array_filter($documents, function ($d) use ($year, $month) {
                return (int) ($d['year'] ?? 0) === $year && (int) ($d['month'] ?? 0) === $month;
            });
            $result['summary'] = [
                'Total documents' => count($filtered),
                'Borrowed' => count(array_filter($filtered, fn($d) => !empty($d['is_borrowed']))),
                'Archived' => count(array_filter($filtered, fn($d) => !empty($d['is_archived']))),
            ];
            $result['rows'] = array_values($filtered);
            break;
        case 'quarterly':
            $result['title'] = 'Quarterly Document Review';
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $startMonth + 2;
            $result['subtitle'] = "Documents uploaded in Q{$quarter} {$year}";
            $filtered = array_filter($documents, function ($d) use ($year, $startMonth, $endMonth) {
                $docYear = (int) ($d['year'] ?? 0);
                $docMonth = (int) ($d['month'] ?? 0);
                return $docYear === $year && $docMonth >= $startMonth && $docMonth <= $endMonth;
            });
            $result['summary'] = [
                'Total documents' => count($filtered),
                'Borrowed' => count(array_filter($filtered, fn($d) => !empty($d['is_borrowed']))),
                'Archived' => count(array_filter($filtered, fn($d) => !empty($d['is_archived']))),
            ];
            $result['rows'] = array_values($filtered);
            break;
        case 'section':
            $result['title'] = 'Section-wise Document Report';
            $result['subtitle'] = "Documents by section for {$year}";
            $sectionMap = [];
            foreach ($documents as $doc) {
                if ((int) ($doc['year'] ?? 0) !== $year) {
                    continue;
                }
                $section = $doc['section'] ?: 'Unspecified';
                if (!isset($sectionMap[$section])) {
                    $sectionMap[$section] = ['section' => $section, 'documents' => 0, 'borrowed' => 0, 'archived' => 0];
                }
                $sectionMap[$section]['documents'] += 1;
                if (!empty($doc['is_borrowed'])) {
                    $sectionMap[$section]['borrowed'] += 1;
                }
                if (!empty($doc['is_archived'])) {
                    $sectionMap[$section]['archived'] += 1;
                }
            }
            $result['rows'] = array_values($sectionMap);
            $result['summary'] = [
                'Categories' => count($result['rows']),
                'Total documents' => array_sum(array_column($result['rows'], 'documents')),
            ];
            break;
        case 'annual':
            $result['title'] = 'Annual Document Summary';
            $result['subtitle'] = "Documents uploaded in {$year}";
            $filtered = array_filter($documents, fn($d) => (int) ($d['year'] ?? 0) === $year);
            $result['summary'] = [
                'Total documents' => count($filtered),
                'Borrowed' => count(array_filter($filtered, fn($d) => !empty($d['is_borrowed']))),
                'Archived' => count(array_filter($filtered, fn($d) => !empty($d['is_archived']))),
            ];
            $result['rows'] = array_values($filtered);
            break;
    }

    return $result;
}

$documents = fetchDocuments($pdo);
$selectedYear = normalizeIntParam('year', $currentYear, range($currentYear - 5, $currentYear + 1));
$selectedMonth = normalizeIntParam('month', $currentMonth, range(1, 12));
$selectedQuarter = normalizeIntParam('quarter', $currentQuarter, [1, 2, 3, 4]);
$reportData = getReportData($documents, $report, ['year' => $selectedYear, 'month' => $selectedMonth, 'quarter' => $selectedQuarter]);

$reportTitles = [
    'monthly' => 'Monthly Document Report',
    'quarterly' => 'Quarterly Document Review',
    'section' => 'Section-wise Document Report',
    'annual' => 'Annual Document Summary',
];
$reportHeader = $reportTitles[$report] ?? 'Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($reportHeader) ?> - SDO Library</title>
    <script src="../../assets/js/theme.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="../../assets/css/theme.css" rel="stylesheet">
    <style>
        .report-summary dt { font-weight: 600; }
        .print-toolbar { margin-bottom: 1.5rem; }
        @media print { .print-toolbar { display: none; } }
    </style>
</head>
<body class="theme-tnf-user">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><?= htmlspecialchars($reportHeader) ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($reportData['subtitle']) ?></p>
        </div>
        <div class="print-toolbar">
            <button type="button" class="theme-toggle me-2" data-theme-toggle>
                <span data-theme-icon>D</span>
            </button>
            <a href="index.php" class="btn btn-outline-secondary me-2"><i class="fas fa-arrow-left me-1"></i> Back</a>
            <button type="button" class="btn btn-primary" onclick="window.print();"><i class="fas fa-print me-1"></i> Print / Save PDF</button>
        </div>
    </div>
    <div class="row mb-4">
        <?php foreach ($reportData['summary'] as $label => $value): ?>
        <div class="col-md-3 mb-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><?= htmlspecialchars($label) ?></h6>
                    <h4 class="mb-0"><?= htmlspecialchars($value) ?></h4>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="card">
        <div class="card-body">
            <?php if (empty($reportData['rows'])): ?>
                <p class="text-muted">No data available for this report.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <?php foreach (array_keys((array) $reportData['rows'][0]) as $header): ?>
                                    <th><?= htmlspecialchars(ucwords(str_replace('_', ' ', $header))) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reportData['rows'] as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= htmlspecialchars((string) $cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
