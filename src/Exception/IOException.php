<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Exception;

final class IOException extends GrobidException
{
    public static function cannotCreateDirectory(string $path): self
    {
        return new self(sprintf('Failed to create directory: %s', $path));
    }

    public static function cannotWriteFile(string $path): self
    {
        return new self(sprintf('Failed to write file: %s', $path));
    }

    public static function cannotReadFile(string $path): self
    {
        return new self(sprintf('Failed to read file: %s', $path));
    }
}
