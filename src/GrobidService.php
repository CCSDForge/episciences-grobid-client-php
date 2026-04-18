<?php

declare(strict_types=1);

namespace Episciences\GrobidClient;

enum GrobidService: string
{
    case ProcessFulltextDocument   = 'processFulltextDocument';
    case ProcessHeaderDocument     = 'processHeaderDocument';
    case ProcessReferences         = 'processReferences';
    case ProcessCitationList       = 'processCitationList';
    case ProcessCitationPatentST36 = 'processCitationPatentST36';
    case ProcessCitationPatentPDF  = 'processCitationPatentPDF';
}
