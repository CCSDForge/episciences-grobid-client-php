<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Tests\Unit;

use Episciences\GrobidClient\ProcessingResult;
use PHPUnit\Framework\TestCase;

final class ProcessingResultTest extends TestCase
{
    public function testIsSuccessfulWhenNoErrors(): void
    {
        self::assertTrue((new ProcessingResult(5, 0, 1))->isSuccessful());
    }

    public function testIsSuccessfulReturnsFalseWhenErrors(): void
    {
        self::assertFalse((new ProcessingResult(5, 2, 0))->isSuccessful());
    }

    public function testHasErrorsReturnsTrueWhenErrors(): void
    {
        self::assertTrue((new ProcessingResult(0, 1, 0))->hasErrors());
    }

    public function testHasErrorsReturnsFalseWhenNoErrors(): void
    {
        self::assertFalse((new ProcessingResult(3, 0, 0))->hasErrors());
    }

    public function testSuccessRateWithNoFiles(): void
    {
        self::assertSame(1.0, (new ProcessingResult(0, 0, 0))->successRate());
    }

    public function testSuccessRateHalf(): void
    {
        self::assertEqualsWithDelta(0.5, (new ProcessingResult(2, 2, 0))->successRate(), 0.001);
    }

    public function testSuccessRateAllProcessed(): void
    {
        self::assertSame(1.0, (new ProcessingResult(4, 0, 0))->successRate());
    }

    public function testSuccessRateCountsSkippedInTotal(): void
    {
        // 2 processed out of 4 total (2 processed + 1 error + 1 skipped)
        self::assertEqualsWithDelta(0.5, (new ProcessingResult(2, 1, 1))->successRate(), 0.001);
    }

    public function testTotalSumsAllCounters(): void
    {
        self::assertSame(9, (new ProcessingResult(3, 4, 2))->total());
    }

    public function testMergeAddsCounts(): void
    {
        $a = new ProcessingResult(5, 2, 1);
        $b = new ProcessingResult(3, 1, 0);

        $merged = $a->merge($b);

        self::assertSame(8, $merged->processed);
        self::assertSame(3, $merged->errors);
        self::assertSame(1, $merged->skipped);
    }
}
