<?php

declare(strict_types=1);

namespace Episciences\GrobidClient;

final class GrobidConfig
{
    private const DEFAULT_COORDINATES = [
        'title', 'persName', 'affiliation', 'orgName', 'formula',
        'figure', 'ref', 'biblStruct', 'head', 'p', 's', 'note',
    ];

    public readonly string $grobidServer;
    /** @var positive-int */
    public readonly int $batchSize;
    public readonly int    $timeout;
    public readonly int    $sleepTime;
    public readonly int    $maxRetries;
    /** @var string[] */
    public readonly array $coordinates;

    /**
     * @param string[] $coordinates
     */
    public function __construct(
        string $grobidServer = 'http://localhost:8070',
        int    $batchSize    = 1000,
        int    $timeout      = 180,
        int    $sleepTime    = 5,
        int    $maxRetries   = 10,
        array  $coordinates  = self::DEFAULT_COORDINATES,
    ) {
        self::assertValidServerUrl($grobidServer);

        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be at least 1');
        }

        $this->grobidServer = $grobidServer;
        $this->batchSize    = $batchSize;
        $this->timeout      = $timeout;
        $this->sleepTime    = $sleepTime;
        $this->maxRetries   = $maxRetries;
        $this->coordinates  = $coordinates;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            grobidServer: self::getString($data, 'grobid_server', 'http://localhost:8070'),
            batchSize:    self::getInt($data, 'batch_size', 1000),
            timeout:      self::getInt($data, 'timeout', 180),
            sleepTime:    self::getInt($data, 'sleep_time', 5),
            maxRetries:   self::getInt($data, 'max_retries', 10),
            coordinates:  self::getStringArray($data, 'coordinates', self::DEFAULT_COORDINATES),
        );
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Config file not found or not readable: %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \InvalidArgumentException(sprintf('Failed to read config file: %s', $path));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException(sprintf('Invalid JSON in config file: %s', $path));
        }

        return self::fromArray($data);
    }

    /**
     * Return a new config with overrides applied (non-null values only).
     *
     * @param string[]|null $coordinates
     */
    public function withOverrides(
        ?string $grobidServer = null,
        ?int    $batchSize    = null,
        ?int    $timeout      = null,
        ?int    $sleepTime    = null,
        ?int    $maxRetries   = null,
        ?array  $coordinates  = null,
    ): self {
        return new self(
            grobidServer: $grobidServer ?? $this->grobidServer,
            batchSize:    $batchSize    ?? $this->batchSize,
            timeout:      $timeout      ?? $this->timeout,
            sleepTime:    $sleepTime    ?? $this->sleepTime,
            maxRetries:   $maxRetries   ?? $this->maxRetries,
            coordinates:  $coordinates  ?? $this->coordinates,
        );
    }

    private static function assertValidServerUrl(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false
            || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            || empty($parsed['host'])
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid GROBID server URL "%s": must be http:// or https:// with a host',
                $url
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function getString(array $data, string $key, string $default): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function getInt(array $data, string $key, int $default): int
    {
        return isset($data[$key]) && is_int($data[$key]) ? $data[$key] : $default;
    }

    /**
     * @param array<string, mixed> $data
     * @param string[]             $default
     * @return string[]
     */
    private static function getStringArray(array $data, string $key, array $default): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return $default;
        }

        return array_values(array_filter($data[$key], 'is_string'));
    }
}
