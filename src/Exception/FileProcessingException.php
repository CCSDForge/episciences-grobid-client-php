<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Exception;

final class FileProcessingException extends GrobidException
{
    public static function cannotOpen(string $path, \Throwable $previous): self
    {
        return new self(sprintf('Failed to open file: %s', $path), 0, $previous);
    }

    public static function cannotRead(string $path): self
    {
        return new self(sprintf('Failed to read file: %s', $path));
    }
}
