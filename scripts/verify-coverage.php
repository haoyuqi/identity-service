<?php

declare(strict_types=1);

const COVERAGE_GATE_PERCENTAGE = 100.0;

function failCoverageCheck(string $message): never
{
    fwrite(STDERR, "Coverage check failed: {$message}\n");

    exit(1);
}

function readCoverageMetrics(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        failCoverageCheck("report is missing or unreadable: {$path}");
    }

    $previousErrors = libxml_use_internal_errors(true);
    $report = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    if ($report === false || $report->getName() !== 'coverage' || ! isset($report->project->metrics)) {
        failCoverageCheck('report is malformed or does not contain project metrics');
    }

    $metrics = $report->project->metrics;

    if (! isset($metrics['statements'], $metrics['coveredstatements'])) {
        failCoverageCheck('report is missing statement coverage metrics');
    }

    $statementsValue = (string) $metrics['statements'];
    $coveredValue = (string) $metrics['coveredstatements'];

    if (! ctype_digit($statementsValue) || ! ctype_digit($coveredValue)) {
        failCoverageCheck('statement coverage metrics must be non-negative integers');
    }

    $statements = (int) $statementsValue;
    $coveredStatements = (int) $coveredValue;

    if ($statements === 0) {
        failCoverageCheck('report contains zero executable statements');
    }

    if ($coveredStatements === 0 || $coveredStatements > $statements) {
        failCoverageCheck('covered statement count is zero or exceeds the total');
    }

    return [$statements, $coveredStatements];
}

if ($argc !== 2) {
    failCoverageCheck('usage: php scripts/verify-coverage.php <clover-report.xml>');
}

[$statements, $coveredStatements] = readCoverageMetrics($argv[1]);
$percentage = ($coveredStatements / $statements) * 100;

if ($coveredStatements !== $statements) {
    failCoverageCheck(sprintf(
        '%.2f%% line coverage (%d of %d statements); 100%% is required',
        $percentage,
        $coveredStatements,
        $statements,
    ));
}

fwrite(STDOUT, sprintf(
    "Coverage check passed: 100.00%% line coverage (%d of %d statements).\n",
    $coveredStatements,
    $statements,
));
