<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Tests\Unit;

use Episciences\GrobidClient\ApiClient;
use Episciences\GrobidClient\Command\ProcessCommand;
use Episciences\GrobidClient\GrobidClient;
use Episciences\GrobidClient\GrobidConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ProcessCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $tmpDir = sys_get_temp_dir() . '/grobid_cmd_test_' . uniqid();
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

    private function makeTester(MockHandler $mock): CommandTester
    {
        $config     = new GrobidConfig(batchSize: 100, sleepTime: 0);
        $stack      = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $stack]);
        $apiClient  = new ApiClient($config, $httpClient);

        $grobidClient = new GrobidClient($config, checkServer: false, apiClient: $apiClient);
        $command      = new ProcessCommand($grobidClient);

        $app = new Application();
        $app->add($command);
        $app->setDefaultCommand($command->getName() ?? 'grobid:process', true);

        return new CommandTester($command);
    }

    public function testSuccessfulProcessing(): void
    {
        $pdfFile   = $this->tmpDir . '/doc.pdf';
        $outputDir = $this->tmpDir . '/out';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock   = new MockHandler([new Response(200, [], '<TEI/>')]);
        $tester = $this->makeTester($mock);

        $tester->execute([
            'service'  => 'processFulltextDocument',
            '--input'  => $pdfFile,
            '--output' => $outputDir,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertFileExists($outputDir . '/doc.grobid.tei.xml');
        self::assertStringContainsString('processed: 1', $tester->getDisplay());
    }

    public function testFailsOnInvalidService(): void
    {
        $mock   = new MockHandler([]);
        $tester = $this->makeTester($mock);

        $tester->execute(['service' => 'notAService', '--input' => $this->tmpDir]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unknown service', $tester->getDisplay());
    }

    public function testFailsWhenInputNotProvided(): void
    {
        $mock   = new MockHandler([]);
        $tester = $this->makeTester($mock);

        $tester->execute(['service' => 'processFulltextDocument']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--input is required', $tester->getDisplay());
    }

    public function testNoForceSkipsExistingOutput(): void
    {
        $pdfFile    = $this->tmpDir . '/doc.pdf';
        $outputFile = $this->tmpDir . '/doc.grobid.tei.xml';
        file_put_contents($pdfFile, '%PDF-1.4');
        file_put_contents($outputFile, '<TEI>existing</TEI>');

        $mock   = new MockHandler([]);
        $tester = $this->makeTester($mock);

        $tester->execute([
            'service'    => 'processFulltextDocument',
            '--input'    => $pdfFile,
            '--no-force' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('skipped: 1', $tester->getDisplay());
        self::assertSame(0, $mock->count());
    }

    public function testReturnsFailureWhenProcessingErrors(): void
    {
        $pdfFile = $this->tmpDir . '/bad.pdf';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock   = new MockHandler([new Response(500, [], 'error')]);
        $tester = $this->makeTester($mock);

        $tester->execute([
            'service' => 'processFulltextDocument',
            '--input' => $pdfFile,
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('errors: 1', $tester->getDisplay());
    }

    public function testSummaryLineAlwaysPresent(): void
    {
        $pdfFile = $this->tmpDir . '/doc.pdf';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock   = new MockHandler([new Response(200, [], '<TEI/>')]);
        $tester = $this->makeTester($mock);

        $tester->execute(['service' => 'processFulltextDocument', '--input' => $pdfFile]);

        // Summary line is always written regardless of verbosity
        self::assertStringContainsString('Done in', $tester->getDisplay());
        self::assertStringContainsString('processed: 1', $tester->getDisplay());
    }
}
