# grobid-client-php

[![CI](https://github.com/CCSDForge/episciences-grobid-client-php/actions/workflows/ci.yml/badge.svg)](https://github.com/CCSDForge/episciences-grobid-client-php/actions/workflows/ci.yml)
![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.1-8892bf.svg)
![License](https://img.shields.io/badge/license-MIT-blue.svg)

PHP 8.1+ client library for the [GROBID](https://github.com/grobidOrg/grobid) REST API.

GROBID converts technical and scientific documents (PDF) into structured TEI XML, extracting full text, metadata, references, citations, and more.

## Requirements

- PHP 8.1 or higher
- A running GROBID server (see [GROBID documentation](https://grobid.readthedocs.io/en/latest/Grobid-service/))

## Installation

As the package is not yet published on Packagist, you can install it by adding the repository to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/CCSDForge/episciences-grobid-client-php"
        }
    ],
    "require": {
        "episciences/grobid-client-php": "dev-main"
    }
}
```

Then run:

```bash
composer update episciences/grobid-client-php
```

## Quick start

```php
use Episciences\GrobidClient\GrobidClient;
use Episciences\GrobidClient\GrobidConfig;
use Episciences\GrobidClient\GrobidService;
use Episciences\GrobidClient\ProcessingOptions;

$config = new GrobidConfig(grobidServer: 'http://localhost:8070');
$client = new GrobidClient($config);

$result = $client->process(
    GrobidService::ProcessFulltextDocument,
    '/path/to/pdfs/',
    new ProcessingOptions(outputPath: '/path/to/output/')
);

echo "Processed: {$result->processed}, Errors: {$result->errors}, Skipped: {$result->skipped}\n";

if ($result->isSuccessful()) {
    echo sprintf("Success rate: %.0f%%\n", $result->successRate() * 100);
}
```

## Configuration

### Constructor parameters

```php
$config = new GrobidConfig(
    grobidServer: 'http://localhost:8070',  // must be http:// or https://
    batchSize:    1000,                     // files per batch (must be >= 1)
    timeout:      180,                      // HTTP timeout in seconds
    sleepTime:    5,                        // seconds between retries on busy server
    maxRetries:   10,                       // max retry attempts on 503 responses
    coordinates:  ['title', 'figure', ...], // TEI elements for coordinate extraction
);
```

### Loading from a JSON file

```php
$config = GrobidConfig::fromFile('/path/to/config.json');
```

Example `config.json`:

```json
{
    "grobid_server": "http://localhost:8070",
    "batch_size": 1000,
    "timeout": 180,
    "sleep_time": 5,
    "max_retries": 10,
    "coordinates": ["title", "persName", "affiliation", "orgName", "formula",
                    "figure", "ref", "biblStruct", "head", "p", "s", "note"]
}
```

### Overriding values

Config values can be overridden after loading from a file:

```php
$config = GrobidConfig::fromFile('config.json')
    ->withOverrides(grobidServer: 'http://prod-server:8070', timeout: 300);
```

## Supported services

| Enum value | GROBID service | Input |
|---|---|---|
| `GrobidService::ProcessFulltextDocument` | Full document structural analysis | PDF |
| `GrobidService::ProcessHeaderDocument` | Header/metadata extraction | PDF |
| `GrobidService::ProcessReferences` | Bibliography extraction | PDF |
| `GrobidService::ProcessCitationList` | Parse raw citation strings | TXT |
| `GrobidService::ProcessCitationPatentST36` | Patent citation extraction (ST36) | XML |
| `GrobidService::ProcessCitationPatentPDF` | Patent citation extraction | PDF |

## Processing documents

Processing is configured via a `ProcessingOptions` object. All fields are optional and have sensible defaults.

### Single file

```php
$result = $client->process(
    GrobidService::ProcessFulltextDocument,
    '/data/paper.pdf',
    new ProcessingOptions(outputPath: '/data/output/')
);
// Writes /data/output/paper.grobid.tei.xml
```

### Directory (recursive)

```php
$result = $client->process(
    GrobidService::ProcessFulltextDocument,
    '/data/pdfs/',                                    // walks subdirectories recursively
    new ProcessingOptions(outputPath: '/data/output/') // mirrors directory structure
);
```

If `outputPath` is not set, TEI files are written alongside the source files.

### ProcessingOptions reference

```php
$options = new ProcessingOptions(
    outputPath:             '/data/output/', // output directory (null = alongside input)
    concurrency:            10,              // concurrent HTTP requests
    generateIds:            false,           // add XML ids to document structures
    consolidateHeader:      1,               // 0=none, 1=CrossRef, 2=PubMed
    consolidateCitations:   0,               // 0=none, 1=CrossRef, 2=PubMed
    includeRawCitations:    false,           // include raw citation strings in TEI
    includeRawAffiliations: false,           // include raw affiliation strings in TEI
    teiCoordinates:         false,           // add PDF bounding box coordinates
    segmentSentences:       false,           // segment body text into sentences
    force:                  true,            // overwrite existing output files
    flavor:                 null,            // optional GROBID flavor string
    startPage:              -1,              // first page to process (-1 = all)
    endPage:                -1,              // last page to process (-1 = all)
);

$result = $client->process(GrobidService::ProcessFulltextDocument, '/data/pdfs/', $options);
```

All properties are `readonly`. Pass `null` as `$options` to use all defaults.

### Output files

For each successfully processed file, a `.grobid.tei.xml` file is written:

```
/data/output/
├── paper.grobid.tei.xml
└── subdir/
    └── other.grobid.tei.xml
```

On per-file error, an error file is written instead (processing continues for remaining files):

```
/data/output/paper_500.txt   # contains the error message
```

### Skipping already-processed files

```php
$result = $client->process(
    GrobidService::ProcessFulltextDocument,
    '/data/pdfs/',
    new ProcessingOptions(outputPath: '/data/output/', force: false)
);
echo "Skipped: {$result->skipped}\n";
```

## ProcessingResult

`process()` returns a `ProcessingResult` with the following API:

```php
$result->processed    // int: files successfully processed
$result->errors       // int: files that failed
$result->skipped      // int: files skipped (force: false + output exists)
$result->total()      // int: processed + errors + skipped

$result->isSuccessful()  // bool: true when errors === 0
$result->hasErrors()     // bool: true when errors > 0
$result->successRate()   // float: processed / total (1.0 when total is 0)
$result->merge($other)   // ProcessingResult: combine two results
```

## Processing a single document to a string

Use `processToString()` to get the TEI XML directly in memory, without writing any file:

```php
use Episciences\GrobidClient\Exception\ProcessingFailedException;

$tei = $client->processToString(
    GrobidService::ProcessFulltextDocument,
    '/data/paper.pdf',
    new ProcessingOptions(consolidateHeader: 1)
);

// $tei is a string containing the full TEI XML
echo $tei;
```

The method accepts the same `ProcessingOptions` as `process()`. It throws `ProcessingFailedException` (a subclass of `GrobidException`) on any non-200 response, and `ServerUnavailableException` on network errors.

```php
try {
    $tei = $client->processToString(GrobidService::ProcessReferences, '/data/paper.pdf');
} catch (ProcessingFailedException $e) {
    // GROBID returned HTTP 4xx/5xx
} catch (ServerUnavailableException $e) {
    // network error or server unreachable
}
```

## Processing a remote document via URL

Use `processUrlToString()` to download a remote PDF and process it in one call — no local file required:

```php
$tei = $client->processUrlToString(
    GrobidService::ProcessFulltextDocument,
    'https://arxiv.org/pdf/2301.01234',
    new ProcessingOptions(consolidateHeader: 1, consolidateCitations: 1)
);
```

### Example: Extracting references from a URL

To extract only the bibliography from a remote PDF:

```php
$tei = $client->processUrlToString(
    GrobidService::ProcessReferences,
    'https://arxiv.org/pdf/2310.02192',
    new ProcessingOptions(consolidateCitations: 1)
);

// $tei contains the structured <listBibl> XML
echo $tei;
```

The library downloads the document with Guzzle, then forwards the content to GROBID. All `ProcessingOptions` are supported. The filename is inferred from the URL path (`paper.pdf`, etc.).

```php
use Episciences\GrobidClient\Exception\ProcessingFailedException;
use Episciences\GrobidClient\Exception\ServerUnavailableException;

try {
    $tei = $client->processUrlToString(
        GrobidService::ProcessReferences,
        'https://example.com/paper.pdf'
    );
} catch (ProcessingFailedException $e) {
    // download returned HTTP 4xx/5xx, or GROBID returned an error
} catch (ServerUnavailableException $e) {
    // network error during download or GROBID request
} catch (\InvalidArgumentException $e) {
    // URL is not http:// or https://
}
```

## Extracting bibliographic references from a PDF

Use `GrobidService::ProcessReferences` to extract only the bibliography section without processing the full document. This also works for remote documents (see [Extracting references from a URL](#example-extracting-references-from-a-url)):

```php
$result = $client->process(
    GrobidService::ProcessReferences,
    '/data/pdfs/',
    new ProcessingOptions(
        outputPath:           '/data/refs/',
        consolidateCitations: 1,   // enrich with CrossRef metadata
        includeRawCitations:  true, // keep original citation strings alongside structured output
    )
);
```

Each PDF produces a `.grobid.tei.xml` file containing a `<listBibl>` element with structured `<biblStruct>` entries. Example output structure:

```xml
<listBibl>
    <biblStruct>
        <analytic>
            <title level="a">Deep Learning for NLP</title>
            <author><persName><forename>John</forename><surname>Smith</surname></persName></author>
        </analytic>
        <monogr>
            <title level="j">Journal of AI Research</title>
            <imprint><date type="published" when="2019"/></imprint>
        </monogr>
    </biblStruct>
    ...
</listBibl>
```

To process a single file and skip already-extracted references:

```php
$result = $client->process(
    GrobidService::ProcessReferences,
    '/data/paper.pdf',
    new ProcessingOptions(outputPath: '/data/refs/', force: false)
);
```

## Citation list processing

For `ProcessCitationList`, the input is a `.txt` file with one citation string per line:

```
Romero, I., Rehm, G., et al. (2020). Language Technologies for Spanish...
Smith, J. (2019). Deep Learning for NLP. Journal of AI Research, 42, 1–20.
```

```php
$result = $client->process(
    GrobidService::ProcessCitationList,
    '/data/citations.txt',
    new ProcessingOptions(outputPath: '/data/output/', consolidateCitations: 1)
);
```

## Server health check

```php
if ($client->ping()) {
    echo "Server is available\n";
}
```

The constructor calls `ping()` automatically unless disabled:

```php
$client = new GrobidClient($config, checkServer: false);
```

## Logging

The client accepts any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger. By default logging is silent (`NullLogger`).

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('grobid');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$client = new GrobidClient($config, logger: $logger);
```

## Error handling

All exceptions thrown by the library extend `GrobidException`, so you can catch everything with a single clause:

```php
use Episciences\GrobidClient\Exception\GrobidException;
use Episciences\GrobidClient\Exception\ServerUnavailableException;
use Episciences\GrobidClient\Exception\FileProcessingException;
use Episciences\GrobidClient\Exception\ProcessingFailedException;
use Episciences\GrobidClient\Exception\IOException;

// Catch all library errors
try {
    $client = new GrobidClient($config);
    $result = $client->process(GrobidService::ProcessFulltextDocument, '/data/pdfs/');
} catch (GrobidException $e) {
    echo "GROBID error: " . $e->getMessage() . "\n";
    echo "Caused by: " . ($e->getPrevious()?->getMessage() ?? 'n/a') . "\n";
}
```

Exception hierarchy:

| Exception | When thrown |
|---|---|
| `ServerUnavailableException` | Server unreachable, or non-200 on `ping()` |
| `FileProcessingException` | Source file cannot be opened or read |
| `ProcessingFailedException` | GROBID or download returned a non-200 HTTP response (used by `processToString`, `processUrlToString`) |
| `IOException` | Output directory creation or file write failed |
| `\InvalidArgumentException` | Invalid input path, invalid URL, or output path escapes output directory |

```php
// Handle specific exceptions
try {
    $client = new GrobidClient($config);
} catch (ServerUnavailableException $e) {
    // server unreachable or returned non-200 on ping
}

try {
    $result = $client->process(GrobidService::ProcessFulltextDocument, '/data/pdfs/');
} catch (FileProcessingException $e) {
    // a source file could not be opened or read
} catch (IOException $e) {
    // output directory or file could not be written
} catch (\InvalidArgumentException $e) {
    // invalid input path, or output path escapes output directory
}

try {
    $tei = $client->processToString(GrobidService::ProcessFulltextDocument, '/data/paper.pdf');
} catch (ProcessingFailedException $e) {
    // GROBID returned HTTP 4xx/5xx
} catch (ServerUnavailableException $e) {
    // network error
}
```

**Per-file errors** (HTTP 4xx/5xx) do not throw. Instead:
- An error file `{name}_{statusCode}.txt` is written with the error message
- The file is counted in `ProcessingResult::$errors`
- Processing continues for remaining files

**503 responses** are retried up to `GrobidConfig::$maxRetries` times, sleeping `$sleepTime` seconds between each attempt.

## Command line interface

### Usage

```bash
vendor/bin/grobid-client <service> --input <path> [options]
```

### Services

```bash
vendor/bin/grobid-client processFulltextDocument   --input /data/pdfs/
vendor/bin/grobid-client processHeaderDocument     --input /data/pdfs/
vendor/bin/grobid-client processReferences         --input /data/pdfs/
vendor/bin/grobid-client processCitationList       --input /data/citations.txt
vendor/bin/grobid-client processCitationPatentST36 --input /data/patents/
vendor/bin/grobid-client processCitationPatentPDF  --input /data/patents/
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--input`, `-i` | *(required)* | Input file or directory |
| `--output`, `-o` | *(same as input)* | Output directory |
| `--config`, `-c` | | Path to a `config.json` file |
| `--server`, `-s` | `http://localhost:8070` | GROBID server URL (overrides config) |
| `--n` | `10` | Number of concurrent requests |
| `--generate-ids` | | Add XML IDs to document structures |
| `--consolidate-header` | `1` | Header consolidation: 0=none, 1=CrossRef, 2=PubMed |
| `--consolidate-citations` | `0` | Citation consolidation: 0=none, 1=CrossRef, 2=PubMed |
| `--include-raw-citations` | | Include raw citation strings in TEI output |
| `--include-raw-affiliations` | | Include raw affiliation strings in TEI output |
| `--tei-coordinates` | | Add PDF bounding box coordinates |
| `--segment-sentences` | | Segment body text into sentences |
| `--no-force` | | Skip files whose `.grobid.tei.xml` already exists |
| `--flavor` | | GROBID flavor |
| `--start-page` | `-1` | First page to process (-1 = all) |
| `--end-page` | `-1` | Last page to process (-1 = all) |

Verbosity: `-v` shows INFO messages (found files, batch progress), `-vv` shows DEBUG messages.

### Examples

```bash
# Extract full text from all PDFs, 20 concurrent requests
vendor/bin/grobid-client processFulltextDocument \
  --input /data/pdfs/ \
  --output /data/tei/ \
  --n 20

# Extract references only, skip already processed files
vendor/bin/grobid-client processReferences \
  --input /data/pdfs/ \
  --output /data/refs/ \
  --no-force

# Full text with TEI coordinates and CrossRef consolidation
vendor/bin/grobid-client processFulltextDocument \
  --input /data/pdfs/ \
  --output /data/tei/ \
  --tei-coordinates \
  --consolidate-header 1 \
  --consolidate-citations 1

# Use a remote server with a config file
vendor/bin/grobid-client processFulltextDocument \
  --input /data/pdfs/ \
  --server https://grobid.example.com \
  --config /etc/grobid-client/config.json

# Parse raw citation strings
vendor/bin/grobid-client processCitationList \
  --input /data/references.txt \
  --output /data/tei/ \
  --consolidate-citations 1

# Verbose mode: show progress per batch
vendor/bin/grobid-client processFulltextDocument \
  --input /data/pdfs/ \
  --output /data/tei/ \
  -v
```

### Exit codes

| Code | Meaning |
|------|---------|
| `0` | All files processed successfully (or skipped) |
| `1` | One or more files failed, or invalid arguments |

---

## Development

```bash
# Install dependencies
composer install

# Static analysis (PHPStan level 6)
vendor/bin/phpstan analyse

# Unit tests
vendor/bin/phpunit --testsuite unit

# Integration tests (requires a running GROBID server)
GROBID_SERVER=http://localhost:8070 vendor/bin/phpunit --testsuite integration
```

## License

MIT
