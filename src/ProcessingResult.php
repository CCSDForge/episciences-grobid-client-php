<?php

declare(strict_types=1);

namespace Episciences\GrobidClient;

final class ProcessingResult
{
    public function __construct(
        public readonly int $processed,
        public readonly int $errors,
        public readonly int $skipped,
    ) {
    }

    public function total(): int
    {
        return $this->processed + $this->errors + $this->skipped;
    }

    public function isSuccessful(): bool
    {
        return $this->errors === 0;
    }

    public function hasErrors(): bool
    {
        return $this->errors > 0;
    }

    public function successRate(): float
    {
        $total = $this->total();

        return $total === 0 ? 1.0 : $this->processed / $total;
    }

    public function merge(ProcessingResult $other): self
    {
        return new self(
            $this->processed + $other->processed,
            $this->errors + $other->errors,
            $this->skipped + $other->skipped,
        );
    }
}
