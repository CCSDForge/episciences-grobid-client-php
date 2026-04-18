<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Tests\Integration;

use Episciences\GrobidClient\GrobidClient;
use Episciences\GrobidClient\GrobidConfig;
use Episciences\GrobidClient\GrobidService;
use Episciences\GrobidClient\ProcessingOptions;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests requiring a running GROBID server.
 * Set the GROBID_SERVER env variable to enable, e.g.:
 *   GROBID_SERVER=http://localhost:8070 vendor/bin/phpunit --testsuite integration
 */
final class GrobidClientIntegrationTest extends TestCase
{
    private string $tmpDir;
    private GrobidClient $client;

    protected function setUp(): void
    {
        $serverUrl = getenv('GROBID_SERVER');

        if ($serverUrl === false || $serverUrl === '') {
            self::markTestSkipped('GROBID_SERVER env variable not set');
        }

        $config = new GrobidConfig(grobidServer: $serverUrl);

        $this->client = new GrobidClient($config, checkServer: true);

        $tmpDir = sys_get_temp_dir() . '/grobid_integration_' . uniqid();
        mkdir($tmpDir, 0o755, true);
        $this->tmpDir = $tmpDir;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function samplePdfDir(): string
    {
        $path = __DIR__ . '/../../grobid-client-python/resources/test_pdf';

        if (!is_dir($path)) {
            self::markTestSkipped('Sample PDF directory not found: ' . $path);
        }

        return $path;
    }

    public function testPingReturnsTrue(): void
    {
        self::assertTrue($this->client->ping());
    }

    public function testProcessFulltextDocumentOnSamplePdfs(): void
    {
        $options = new ProcessingOptions(outputPath: $this->tmpDir, force: true);
        $result  = $this->client->process(GrobidService::ProcessFulltextDocument, $this->samplePdfDir(), $options);

        self::assertGreaterThan(0, $result->total());
        self::assertSame(0, $result->errors);
    }

    public function testProcessHeaderDocumentOnSamplePdfs(): void
    {
        $options = new ProcessingOptions(outputPath: $this->tmpDir, force: true);
        $result  = $this->client->process(GrobidService::ProcessHeaderDocument, $this->samplePdfDir(), $options);

        self::assertGreaterThan(0, $result->total());
        self::assertSame(0, $result->errors);
    }

    public function testProcessReferencesOnSamplePdfs(): void
    {
        $options = new ProcessingOptions(outputPath: $this->tmpDir, force: true);
        $result  = $this->client->process(GrobidService::ProcessReferences, $this->samplePdfDir(), $options);

        self::assertGreaterThan(0, $result->total());
        self::assertSame(0, $result->errors);
    }

    public function testOutputFilesAreWritten(): void
    {
        $options = new ProcessingOptions(outputPath: $this->tmpDir, force: true);
        $this->client->process(GrobidService::ProcessFulltextDocument, $this->samplePdfDir(), $options);

        $teiFiles = glob($this->tmpDir . '/**/*.grobid.tei.xml') ?: [];
        if ($teiFiles === []) {
            $teiFiles = glob($this->tmpDir . '/*.grobid.tei.xml') ?: [];
        }

        self::assertNotEmpty($teiFiles, 'Expected TEI output files to be written');

        foreach ($teiFiles as $teiFile) {
            $content = file_get_contents($teiFile);
            self::assertIsString($content);
            self::assertStringContainsString('<TEI', $content);
        }
    }
}
