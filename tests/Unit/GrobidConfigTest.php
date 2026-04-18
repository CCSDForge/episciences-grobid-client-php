<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Tests\Unit;

use Episciences\GrobidClient\GrobidConfig;
use PHPUnit\Framework\TestCase;

final class GrobidConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new GrobidConfig();

        self::assertSame('http://localhost:8070', $config->grobidServer);
        self::assertSame(1000, $config->batchSize);
        self::assertSame(180, $config->timeout);
        self::assertSame(5, $config->sleepTime);
        self::assertSame(10, $config->maxRetries);
        self::assertNotEmpty($config->coordinates);
        self::assertContains('title', $config->coordinates);
    }

    public function testFromArrayOverridesDefaults(): void
    {
        $config = GrobidConfig::fromArray([
            'grobid_server' => 'http://myserver:9090',
            'batch_size'    => 50,
            'timeout'       => 60,
            'sleep_time'    => 3,
            'max_retries'   => 5,
            'coordinates'   => ['title', 'figure'],
        ]);

        self::assertSame('http://myserver:9090', $config->grobidServer);
        self::assertSame(50, $config->batchSize);
        self::assertSame(60, $config->timeout);
        self::assertSame(3, $config->sleepTime);
        self::assertSame(5, $config->maxRetries);
        self::assertSame(['title', 'figure'], $config->coordinates);
    }

    public function testFromArrayUsesDefaultsForMissingKeys(): void
    {
        $config = GrobidConfig::fromArray([]);

        self::assertSame('http://localhost:8070', $config->grobidServer);
        self::assertSame(1000, $config->batchSize);
    }

    public function testFromArrayUsesDefaultsForWrongTypes(): void
    {
        $config = GrobidConfig::fromArray([
            'grobid_server' => 42,      // should be string → default used
            'batch_size'    => 'large', // should be int → default used
        ]);

        self::assertSame('http://localhost:8070', $config->grobidServer);
        self::assertSame(1000, $config->batchSize);
    }

    public function testFromArrayIgnoresNonStringCoordinates(): void
    {
        $config = GrobidConfig::fromArray([
            'coordinates' => ['title', 42, 'figure', null, 'ref'],
        ]);

        self::assertSame(['title', 'figure', 'ref'], $config->coordinates);
    }

    public function testFromFileLoadsValidJson(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'grobid_test_');
        self::assertIsString($tmpFile);

        file_put_contents($tmpFile, json_encode([
            'grobid_server' => 'http://file-server:8080',
            'batch_size'    => 200,
        ]));

        try {
            $config = GrobidConfig::fromFile($tmpFile);
            self::assertSame('http://file-server:8080', $config->grobidServer);
            self::assertSame(200, $config->batchSize);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found or not readable/');

        GrobidConfig::fromFile('/nonexistent/path/config.json');
    }

    public function testFromFileThrowsOnInvalidJson(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'grobid_test_');
        self::assertIsString($tmpFile);

        file_put_contents($tmpFile, 'this is not json');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/Invalid JSON/');

            GrobidConfig::fromFile($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testWithOverridesAppliesNonNullValues(): void
    {
        $base    = new GrobidConfig(grobidServer: 'http://base:8070', batchSize: 100);
        $updated = $base->withOverrides(grobidServer: 'http://override:9090');

        self::assertSame('http://override:9090', $updated->grobidServer);
        self::assertSame(100, $updated->batchSize);
    }

    public function testWithOverridesPreservesUnchangedValues(): void
    {
        $base    = new GrobidConfig(batchSize: 500, sleepTime: 10);
        $updated = $base->withOverrides(timeout: 30);

        self::assertSame(500, $updated->batchSize);
        self::assertSame(10, $updated->sleepTime);
        self::assertSame(30, $updated->timeout);
    }

    public function testWithOverridesNullArgumentsDoNotOverride(): void
    {
        $base    = new GrobidConfig(grobidServer: 'http://keep-me:8070');
        $updated = $base->withOverrides(grobidServer: null, batchSize: null);

        self::assertSame('http://keep-me:8070', $updated->grobidServer);
    }

    // -------------------------------------------------------------------------
    // URL validation
    // -------------------------------------------------------------------------

    public function testValidHttpsUrlAccepted(): void
    {
        $config = new GrobidConfig(grobidServer: 'https://grobid.example.com:8443');
        self::assertSame('https://grobid.example.com:8443', $config->grobidServer);
    }

    /**
     * @dataProvider invalidUrlProvider
     */
    public function testInvalidUrlThrows(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid GROBID server URL/');

        new GrobidConfig(grobidServer: $url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'ftp scheme'      => ['ftp://server:21'],
            'no scheme'       => ['localhost:8070'],
            'file scheme'     => ['file:///etc/passwd'],
            'empty string'    => [''],
            'http no host'    => ['http://'],
            'just a word'     => ['notaurl'],
        ];
    }

    public function testFromArrayWithInvalidUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GrobidConfig::fromArray(['grobid_server' => 'ftp://bad-server']);
    }
}
