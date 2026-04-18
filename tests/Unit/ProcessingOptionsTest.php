<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Tests\Unit;

use Episciences\GrobidClient\ProcessingOptions;
use PHPUnit\Framework\TestCase;

final class ProcessingOptionsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $opts = new ProcessingOptions();

        self::assertNull($opts->outputPath);
        self::assertSame(10, $opts->concurrency);
        self::assertFalse($opts->generateIds);
        self::assertSame(1, $opts->consolidateHeader);
        self::assertSame(0, $opts->consolidateCitations);
        self::assertFalse($opts->includeRawCitations);
        self::assertFalse($opts->includeRawAffiliations);
        self::assertFalse($opts->teiCoordinates);
        self::assertFalse($opts->segmentSentences);
        self::assertTrue($opts->force);
        self::assertNull($opts->flavor);
        self::assertSame(-1, $opts->startPage);
        self::assertSame(-1, $opts->endPage);
    }

    public function testOverrideIndividualFields(): void
    {
        $opts = new ProcessingOptions(
            outputPath:   '/data/out',
            concurrency:  20,
            generateIds:  true,
            teiCoordinates: true,
            force:        false,
            flavor:       'scibert',
            startPage:    2,
            endPage:      10,
        );

        self::assertSame('/data/out', $opts->outputPath);
        self::assertSame(20, $opts->concurrency);
        self::assertTrue($opts->generateIds);
        self::assertTrue($opts->teiCoordinates);
        self::assertFalse($opts->force);
        self::assertSame('scibert', $opts->flavor);
        self::assertSame(2, $opts->startPage);
        self::assertSame(10, $opts->endPage);
    }

    public function testIsReadonly(): void
    {
        $opts = new ProcessingOptions(concurrency: 5);

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $opts->concurrency = 99;
    }
}
