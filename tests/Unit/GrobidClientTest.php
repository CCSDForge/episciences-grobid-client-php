<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Tests\Unit;

use Episciences\GrobidClient\ApiClient;
use Episciences\GrobidClient\Exception\GrobidException;
use Episciences\GrobidClient\Exception\ProcessingFailedException;
use Episciences\GrobidClient\Exception\ServerUnavailableException;
use Episciences\GrobidClient\GrobidClient;
use Episciences\GrobidClient\GrobidConfig;
use Episciences\GrobidClient\GrobidService;
use Episciences\GrobidClient\ProcessingOptions;
use Episciences\GrobidClient\ProcessingResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * @phpstan-type ClientFactory callable(MockHandler, MockHandler=): GrobidClient
 */

final class GrobidClientTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $tmpDir = sys_get_temp_dir() . '/grobid_test_' . uniqid();
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

    private function makeClient(
        MockHandler  $mock,
        ?GrobidConfig $config         = null,
        ?MockHandler  $downloadMock   = null,
    ): GrobidClient {
        $cfg        = $config ?? new GrobidConfig(batchSize: 100, sleepTime: 0);
        $stack      = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $stack]);
        $apiClient  = new ApiClient($cfg, $httpClient);

        $downloadClient = null;
        if ($downloadMock !== null) {
            $downloadClient = new Client(['handler' => HandlerStack::create($downloadMock)]);
        }

        return new GrobidClient(
            $cfg,
            checkServer:    false,
            logger:         new NullLogger(),
            apiClient:      $apiClient,
            downloadClient: $downloadClient,
        );
    }

    // -------------------------------------------------------------------------
    // process() — null options uses defaults
    // -------------------------------------------------------------------------

    public function testProcessWithNullOptionsUsesDefaults(): void
    {
        $pdfFile = $this->tmpDir . '/doc.pdf';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock   = new MockHandler([new Response(200, [], '<TEI/>')]);
        $client = $this->makeClient($mock);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $pdfFile);

        self::assertSame(1, $result->processed);
        self::assertTrue($result->isSuccessful());
    }

    public function testProcessWithExplicitOptions(): void
    {
        $pdfFile   = $this->tmpDir . '/doc.pdf';
        $outputDir = $this->tmpDir . '/out';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock    = new MockHandler([new Response(200, [], '<TEI/>')]);
        $client  = $this->makeClient($mock);
        $options = new ProcessingOptions(outputPath: $outputDir, concurrency: 5);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $pdfFile, $options);

        self::assertSame(1, $result->processed);
        self::assertFileExists($outputDir . '/doc.grobid.tei.xml');
    }

    // -------------------------------------------------------------------------
    // process() — empty directory / no files
    // -------------------------------------------------------------------------

    public function testProcessEmptyDirectoryReturnsZeroResult(): void
    {
        $client = $this->makeClient(new MockHandler([]));
        $result = $client->process(GrobidService::ProcessFulltextDocument, $this->tmpDir);

        self::assertSame(0, $result->total());
        self::assertTrue($result->isSuccessful());
    }

    // -------------------------------------------------------------------------
    // process() — directory recursive discovery
    // -------------------------------------------------------------------------

    public function testProcessDirectoryFindsNestedPdfs(): void
    {
        $subDir = $this->tmpDir . '/sub';
        mkdir($subDir, 0o755, true);
        file_put_contents($this->tmpDir . '/a.pdf', '%PDF-1.4');
        file_put_contents($subDir . '/b.pdf', '%PDF-1.4');

        $mock = new MockHandler([
            new Response(200, [], '<TEI>a</TEI>'),
            new Response(200, [], '<TEI>b</TEI>'),
        ]);
        $client = $this->makeClient($mock);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $this->tmpDir);

        self::assertSame(2, $result->processed);
    }

    // -------------------------------------------------------------------------
    // force / skip logic
    // -------------------------------------------------------------------------

    public function testProcessSkipsExistingOutputWhenForceIsFalse(): void
    {
        $pdfFile    = $this->tmpDir . '/doc.pdf';
        $outputFile = $this->tmpDir . '/doc.grobid.tei.xml';
        file_put_contents($pdfFile, '%PDF-1.4');
        file_put_contents($outputFile, '<TEI>existing</TEI>');

        $mock    = new MockHandler([]);
        $client  = $this->makeClient($mock);
        $options = new ProcessingOptions(force: false);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $pdfFile, $options);

        self::assertSame(0, $result->processed);
        self::assertSame(1, $result->skipped);
        self::assertSame(0, $mock->count());
    }

    public function testProcessOverwritesExistingOutputWhenForceIsTrue(): void
    {
        $pdfFile    = $this->tmpDir . '/doc.pdf';
        $outputFile = $this->tmpDir . '/doc.grobid.tei.xml';
        file_put_contents($pdfFile, '%PDF-1.4');
        file_put_contents($outputFile, '<TEI>old</TEI>');

        $mock    = new MockHandler([new Response(200, [], '<TEI>new</TEI>')]);
        $client  = $this->makeClient($mock);
        $options = new ProcessingOptions(force: true);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $pdfFile, $options);

        self::assertSame(1, $result->processed);
        self::assertStringContainsString('<TEI>new</TEI>', file_get_contents($outputFile) ?: '');
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testProcessWritesErrorFileOnNon200Response(): void
    {
        $pdfFile   = $this->tmpDir . '/fail.pdf';
        $outputDir = $this->tmpDir . '/output';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock    = new MockHandler([new Response(500, [], 'Internal error')]);
        $client  = $this->makeClient($mock);
        $options = new ProcessingOptions(outputPath: $outputDir);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $pdfFile, $options);

        self::assertSame(0, $result->processed);
        self::assertSame(1, $result->errors);
        self::assertTrue($result->hasErrors());
        self::assertFileExists($outputDir . '/fail_500.txt');
    }

    // -------------------------------------------------------------------------
    // Mixed concurrent results (200 + 500)
    // -------------------------------------------------------------------------

    public function testProcessMixedResultsCountedCorrectly(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            file_put_contents($this->tmpDir . "/file{$i}.pdf", '%PDF-1.4');
        }

        $mock = new MockHandler([
            new Response(200, [], '<TEI>1</TEI>'),
            new Response(500, [], 'error'),
            new Response(200, [], '<TEI>3</TEI>'),
        ]);
        $client = $this->makeClient($mock);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $this->tmpDir);

        self::assertSame(2, $result->processed);
        self::assertSame(1, $result->errors);
        self::assertSame(0, $result->skipped);
        self::assertFalse($result->isSuccessful());
    }

    // -------------------------------------------------------------------------
    // Citation list (txt) processing
    // -------------------------------------------------------------------------

    public function testProcessCitationListReadsTxtFile(): void
    {
        $txtFile = $this->tmpDir . '/refs.txt';
        file_put_contents($txtFile, "Reference one\nReference two\nReference three\n");

        $mock    = new MockHandler([new Response(200, [], '<listBibl>parsed</listBibl>')]);
        $client  = $this->makeClient($mock);

        $result = $client->process(GrobidService::ProcessCitationList, $txtFile);

        self::assertSame(1, $result->processed);
        self::assertFileExists($this->tmpDir . '/refs.grobid.tei.xml');
    }

    // -------------------------------------------------------------------------
    // ping()
    // -------------------------------------------------------------------------

    public function testPingReturnsTrueOn200(): void
    {
        self::assertTrue($this->makeClient(new MockHandler([new Response(200)]))->ping());
    }

    public function testPingReturnsFalseOnNon200(): void
    {
        self::assertFalse($this->makeClient(new MockHandler([new Response(503)]))->ping());
    }

    public function testPingThrowsServerUnavailableOnConnectException(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'api/isalive')),
        ]);

        $this->expectException(ServerUnavailableException::class);
        $this->makeClient($mock)->ping();
    }

    public function testServerUnavailableExtendsGrobidException(): void
    {
        $e = new ServerUnavailableException('test');
        self::assertInstanceOf(GrobidException::class, $e);
    }

    // -------------------------------------------------------------------------
    // Constructor server check
    // -------------------------------------------------------------------------

    public function testConstructorThrowsWhenServerUnreachable(): void
    {
        $config     = new GrobidConfig();
        $mock       = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'api/isalive')),
        ]);
        $stack      = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $stack]);
        $apiClient  = new ApiClient($config, $httpClient);

        $this->expectException(ServerUnavailableException::class);

        new GrobidClient($config, checkServer: true, apiClient: $apiClient);
    }

    public function testConstructorPreservesPreviousException(): void
    {
        $config     = new GrobidConfig();
        $cause      = new ConnectException('refused', new Request('GET', 'api/isalive'));
        $mock       = new MockHandler([$cause]);
        $stack      = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $stack]);
        $apiClient  = new ApiClient($config, $httpClient);

        try {
            new GrobidClient($config, checkServer: true, apiClient: $apiClient);
            self::fail('Expected ServerUnavailableException');
        } catch (ServerUnavailableException $e) {
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testConstructorSucceedsWithCheckServerFalse(): void
    {
        $client = new GrobidClient(new GrobidConfig(), checkServer: false);
        self::assertInstanceOf(GrobidClient::class, $client);
    }

    // -------------------------------------------------------------------------
    // Output path — nested subdirectory mirroring
    // -------------------------------------------------------------------------

    public function testOutputFileInSubdirPreservesRelativePath(): void
    {
        $subDir = $this->tmpDir . '/sub';
        mkdir($subDir, 0o755, true);
        $pdfFile   = $subDir . '/paper.pdf';
        $outputDir = $this->tmpDir . '/out';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock    = new MockHandler([new Response(200, [], '<TEI/>')]);
        $client  = $this->makeClient($mock);
        $options = new ProcessingOptions(outputPath: $outputDir);

        $client->process(GrobidService::ProcessFulltextDocument, $this->tmpDir, $options);

        self::assertFileExists($outputDir . '/sub/paper.grobid.tei.xml');
    }

    // -------------------------------------------------------------------------
    // Path traversal protection (assertPathWithinDirectory)
    // -------------------------------------------------------------------------

    public function testAssertPathWithinDirectoryThrowsOnEscape(): void
    {
        $client = $this->makeClient(new MockHandler([]));

        $ref    = new \ReflectionClass(GrobidClient::class);
        $method = $ref->getMethod('assertPathWithinDirectory');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/escapes the output directory/');

        // /tmp/out/../../etc is outside /tmp/out
        $method->invoke($client, '/tmp/out/../../etc/passwd.xml', '/tmp/out');
    }

    public function testAssertPathWithinDirectoryAllowsValidPath(): void
    {
        $client = $this->makeClient(new MockHandler([]));

        $ref    = new \ReflectionClass(GrobidClient::class);
        $method = $ref->getMethod('assertPathWithinDirectory');
        $method->setAccessible(true);

        // Should not throw — no assertion needed, the absence of exception is the assertion
        $method->invoke($client, '/tmp/out/subdir/file.xml', '/tmp/out');
        $this->addToAssertionCount(1);
    }

    public function testProcessThrowsOnNonexistentPath(): void
    {
        $client = $this->makeClient(new MockHandler([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $client->process(GrobidService::ProcessFulltextDocument, '/nonexistent/path/xyz');
    }

    // -------------------------------------------------------------------------
    // processToString()
    // -------------------------------------------------------------------------

    public function testProcessToStringReturnsTeiXmlOnSuccess(): void
    {
        $pdfFile = $this->tmpDir . '/doc.pdf';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock   = new MockHandler([new Response(200, [], '<TEI>hello</TEI>')]);
        $client = $this->makeClient($mock);

        $xml = $client->processToString(GrobidService::ProcessFulltextDocument, $pdfFile);

        self::assertSame('<TEI>hello</TEI>', $xml);
        self::assertSame(0, $mock->count(), 'Mock should be empty — one request was consumed');
    }

    public function testProcessToStringThrowsOnNon200Response(): void
    {
        $pdfFile = $this->tmpDir . '/doc.pdf';
        file_put_contents($pdfFile, '%PDF-1.4');

        $mock   = new MockHandler([new Response(500, [], 'Internal error')]);
        $client = $this->makeClient($mock);

        $this->expectException(ProcessingFailedException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $client->processToString(GrobidService::ProcessFulltextDocument, $pdfFile);
    }

    public function testProcessToStringThrowsOnNonExistentFile(): void
    {
        $client = $this->makeClient(new MockHandler([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a file/');

        $client->processToString(GrobidService::ProcessFulltextDocument, '/no/such/file.pdf');
    }

    public function testProcessToStringThrowsOnDirectory(): void
    {
        $client = $this->makeClient(new MockHandler([]));

        $this->expectException(\InvalidArgumentException::class);

        $client->processToString(GrobidService::ProcessFulltextDocument, $this->tmpDir);
    }

    public function testProcessToStringExceptionExtendsGrobidException(): void
    {
        $e = ProcessingFailedException::httpError('/some/file.pdf', 422, 'Unprocessable');
        self::assertInstanceOf(GrobidException::class, $e);
        self::assertStringContainsString('HTTP 422', $e->getMessage());
    }

    // -------------------------------------------------------------------------
    // processUrlToString()
    // -------------------------------------------------------------------------

    public function testProcessUrlToStringReturnsTeiXml(): void
    {
        $downloadMock = new MockHandler([new Response(200, [], '%PDF-1.4 fake content')]);
        $grobidMock   = new MockHandler([new Response(200, [], '<TEI>from-url</TEI>')]);
        $client       = $this->makeClient($grobidMock, downloadMock: $downloadMock);

        $xml = $client->processUrlToString(
            GrobidService::ProcessFulltextDocument,
            'https://example.com/paper.pdf'
        );

        self::assertSame('<TEI>from-url</TEI>', $xml);
    }

    public function testProcessUrlToStringUsesFilenameFromUrl(): void
    {
        $downloadMock = new MockHandler([new Response(200, [], '%PDF-1.4')]);
        $grobidMock   = new MockHandler([new Response(200, [], '<TEI/>')]);
        $client       = $this->makeClient($grobidMock, downloadMock: $downloadMock);

        // Should not throw — filename extraction from URL is transparent
        $client->processUrlToString(
            GrobidService::ProcessFulltextDocument,
            'https://example.com/path/to/document.pdf'
        );
        $this->addToAssertionCount(1);
    }

    public function testProcessUrlToStringThrowsOnDownloadHttpError(): void
    {
        $downloadMock = new MockHandler([new Response(404, [], 'Not Found')]);
        $client       = $this->makeClient(new MockHandler([]), downloadMock: $downloadMock);

        $this->expectException(ProcessingFailedException::class);
        $this->expectExceptionMessageMatches('/HTTP 404/');

        $client->processUrlToString(
            GrobidService::ProcessFulltextDocument,
            'https://example.com/missing.pdf'
        );
    }

    public function testProcessUrlToStringThrowsOnDownloadNetworkError(): void
    {
        $downloadMock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://example.com/paper.pdf')),
        ]);
        $client = $this->makeClient(new MockHandler([]), downloadMock: $downloadMock);

        $this->expectException(ServerUnavailableException::class);

        $client->processUrlToString(
            GrobidService::ProcessFulltextDocument,
            'https://example.com/paper.pdf'
        );
    }

    public function testProcessUrlToStringThrowsOnInvalidUrl(): void
    {
        $client = $this->makeClient(new MockHandler([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid URL/');

        $client->processUrlToString(GrobidService::ProcessFulltextDocument, 'ftp://example.com/file.pdf');
    }

    public function testProcessUrlToStringThrowsOnGrobidError(): void
    {
        $downloadMock = new MockHandler([new Response(200, [], '%PDF-1.4')]);
        $grobidMock   = new MockHandler([new Response(503, [], 'Service Unavailable')]);
        $config       = new GrobidConfig(batchSize: 100, sleepTime: 0, maxRetries: 0);
        $client       = $this->makeClient($grobidMock, config: $config, downloadMock: $downloadMock);

        $this->expectException(ProcessingFailedException::class);
        $this->expectExceptionMessageMatches('/HTTP 503/');

        $client->processUrlToString(
            GrobidService::ProcessFulltextDocument,
            'https://example.com/paper.pdf'
        );
    }

    public function testProcessUrlToStringDownloadErrorExtendsGrobidException(): void
    {
        $e = ProcessingFailedException::downloadError('https://example.com/paper.pdf', 403);
        self::assertInstanceOf(GrobidException::class, $e);
        self::assertStringContainsString('HTTP 403', $e->getMessage());
    }

    // -------------------------------------------------------------------------
    // Batching
    // -------------------------------------------------------------------------

    public function testProcessRespectsBatchSize(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            file_put_contents($this->tmpDir . "/file{$i}.pdf", '%PDF-1.4');
        }

        $config = new GrobidConfig(batchSize: 2, sleepTime: 0);
        $mock   = new MockHandler(array_fill(0, 5, new Response(200, [], '<TEI/>')));
        $client = $this->makeClient($mock, $config);

        $result = $client->process(GrobidService::ProcessFulltextDocument, $this->tmpDir);

        self::assertSame(5, $result->processed);
    }

    // -------------------------------------------------------------------------
    // Batch logging
    // -------------------------------------------------------------------------

    public function testBatchDebugMessagesAreLogged(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            file_put_contents($this->tmpDir . "/file{$i}.pdf", '%PDF-1.4');
        }

        $config = new GrobidConfig(batchSize: 2, sleepTime: 0);
        $mock   = new MockHandler(array_fill(0, 3, new Response(200, [], '<TEI/>')));

        $stack      = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $stack]);
        $apiClient  = new ApiClient($config, $httpClient);

        $logger = new class extends AbstractLogger {
            /** @var string[] */
            public array $messages = [];

            /**
             * @param mixed   $level
             * @param string|\Stringable $message
             * @param array<mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $client = new GrobidClient($config, checkServer: false, logger: $logger, apiClient: $apiClient);
        $client->process(GrobidService::ProcessFulltextDocument, $this->tmpDir);

        $batchMessages = array_filter($logger->messages, fn (string $m): bool => str_contains($m, 'Batch'));
        self::assertNotEmpty($batchMessages, 'Expected batch debug messages to be logged');
    }
}
