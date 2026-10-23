<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoverageGateTest extends TestCase
{
    public function test_it_accepts_exactly_complete_line_coverage(): void
    {
        $reportPath = $this->createReport(100, 100);

        try {
            $process = $this->runCoverageCheck($reportPath);

            $this->assertSame(0, $process->getExitCode());
            $this->assertStringContainsString('100.00% line coverage', $process->getOutput());
        } finally {
            unlink($reportPath);
        }
    }

    public function test_it_rejects_coverage_below_one_hundred_even_when_rounded(): void
    {
        $reportPath = $this->createReport(10000, 9999);

        try {
            $process = $this->runCoverageCheck($reportPath);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('99.99%', $process->getErrorOutput());
            $this->assertStringContainsString('100% is required', $process->getErrorOutput());
        } finally {
            unlink($reportPath);
        }
    }

    #[DataProvider('invalidReports')]
    public function test_it_rejects_invalid_reports(string $contents, string $expectedError): void
    {
        $reportPath = $this->createReportFile($contents);

        try {
            $process = $this->runCoverageCheck($reportPath);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString($expectedError, $process->getErrorOutput());
        } finally {
            unlink($reportPath);
        }
    }

    public static function invalidReports(): array
    {
        return [
            'malformed XML' => ['<coverage><project>', 'malformed'],
            'missing metrics' => ['<coverage><project /></coverage>', 'project metrics'],
            'zero executable statements' => [self::clover(0, 0), 'zero executable statements'],
            'zero covered statements' => [self::clover(12, 0), 'covered statement count is zero'],
            'covered exceeds total' => [self::clover(2, 3), 'covered statement count is zero or exceeds'],
            'non-integer metrics' => [self::clover('2.5', 2), 'non-negative integers'],
        ];
    }

    public function test_it_rejects_a_missing_report(): void
    {
        $missingPath = sys_get_temp_dir().'/identity-service-missing-coverage-'.bin2hex(random_bytes(8)).'.xml';
        $process = $this->runCoverageCheck($missingPath);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('missing or unreadable', $process->getErrorOutput());
    }

    private function runCoverageCheck(string $reportPath): Process
    {
        $scriptPath = dirname(__DIR__, 2).'/scripts/verify-coverage.php';
        $process = new Process([PHP_BINARY, $scriptPath, $reportPath]);
        $process->run();

        return $process;
    }

    private function createReport(int|string $statements, int|string $coveredStatements): string
    {
        return $this->createReportFile(self::clover($statements, $coveredStatements));
    }

    private function createReportFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'identity-service-clover-');

        if ($path === false) {
            self::fail('Unable to create a temporary Clover report.');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private static function clover(int|string $statements, int|string $coveredStatements): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><coverage><project><metrics statements="%s" coveredstatements="%s"/></project></coverage>',
            $statements,
            $coveredStatements,
        );
    }
}
