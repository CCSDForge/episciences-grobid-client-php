<?php

declare(strict_types=1);

namespace Episciences\GrobidClient;

final class ProcessingOptions
{
    public function __construct(
        public readonly ?string $outputPath             = null,
        public readonly int     $concurrency            = 10,
        public readonly bool    $generateIds            = false,
        public readonly int     $consolidateHeader      = 1,
        public readonly int     $consolidateCitations   = 0,
        public readonly bool    $includeRawCitations    = false,
        public readonly bool    $includeRawAffiliations = false,
        public readonly bool    $teiCoordinates         = false,
        public readonly bool    $segmentSentences       = false,
        public readonly bool    $force                  = true,
        public readonly ?string $flavor                 = null,
        public readonly int     $startPage              = -1,
        public readonly int     $endPage                = -1,
    ) {
    }
}
