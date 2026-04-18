<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Exception;

final class ProcessingFailedException extends GrobidException
{
    public static function httpError(string $file, int $statusCode, string $body): self
    {
        return new self(sprintf('GROBID returned HTTP %d for "%s": %s', $statusCode, $file, $body));
    }

    public static function downloadError(string $url, int $statusCode): self
    {
        return new self(sprintf('Download failed with HTTP %d for "%s"', $statusCode, $url));
    }
}