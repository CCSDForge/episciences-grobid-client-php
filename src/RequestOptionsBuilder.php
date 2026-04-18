<?php

declare(strict_types=1);

namespace Episciences\GrobidClient;

use Episciences\GrobidClient\Exception\FileProcessingException;
use GuzzleHttp\Psr7\Utils;

/**
 * Builds Guzzle multipart request options for GROBID API calls.
 */
final class RequestOptionsBuilder
{
    public function __construct(private readonly GrobidConfig $config) {}

    /**
     * Build options from a local file path.
     *
     * @return array<string, mixed>
     */
    public function fromFile(GrobidService $service, string $file, ProcessingOptions $options): array
    {
        return $service === GrobidService::ProcessCitationList
            ? $this->fromTxt($file, $options)
            : $this->fromPdf($service, $file, $options);
    }

    /**
     * Build options from in-memory content (e.g. a downloaded document).
     *
     * @return array<string, mixed>
     */
    public function fromStream(
        GrobidService     $service,
        string            $content,
        string            $filename,
        ProcessingOptions $options,
    ): array {
        $contentType = $service === GrobidService::ProcessCitationPatentST36
            ? 'application/xml'
            : 'application/pdf';

        $multipart = [[
            'name'     => 'input',
            'contents' => Utils::streamFor($content),
            'filename' => $filename,
            'headers'  => ['Content-Type' => $contentType],
        ]];

        return ['multipart' => $this->appendProcessingParams($multipart, $options)];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromPdf(GrobidService $service, string $file, ProcessingOptions $options): array
    {
        try {
            $fileStream = Utils::tryFopen($file, 'rb');
        } catch (\RuntimeException $e) {
            throw FileProcessingException::cannotOpen($file, $e);
        }

        $contentType = $service === GrobidService::ProcessCitationPatentST36
            ? 'application/xml'
            : 'application/pdf';

        $multipart = [[
            'name'     => 'input',
            'contents' => $fileStream,
            'filename' => basename($file),
            'headers'  => ['Content-Type' => $contentType],
        ]];

        return ['multipart' => $this->appendProcessingParams($multipart, $options)];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromTxt(string $file, ProcessingOptions $options): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw FileProcessingException::cannotRead($file);
        }

        $multipart = [];
        foreach ($lines as $line) {
            $multipart[] = ['name' => 'citations[]', 'contents' => $line];
        }

        if ($options->consolidateCitations > 0) {
            $multipart[] = ['name' => 'consolidateCitations', 'contents' => (string) $options->consolidateCitations];
        }
        if ($options->includeRawCitations) {
            $multipart[] = ['name' => 'includeRawCitations', 'contents' => '1'];
        }

        return ['multipart' => $multipart];
    }

    /**
     * @param  list<array<string, mixed>>  $multipart
     * @return list<array<string, mixed>>
     */
    private function appendProcessingParams(array $multipart, ProcessingOptions $options): array
    {
        if ($options->generateIds) {
            $multipart[] = ['name' => 'generateIDs', 'contents' => '1'];
        }
        if ($options->consolidateHeader > 0) {
            $multipart[] = ['name' => 'consolidateHeader', 'contents' => (string) $options->consolidateHeader];
        }
        if ($options->consolidateCitations > 0) {
            $multipart[] = ['name' => 'consolidateCitations', 'contents' => (string) $options->consolidateCitations];
        }
        if ($options->includeRawCitations) {
            $multipart[] = ['name' => 'includeRawCitations', 'contents' => '1'];
        }
        if ($options->includeRawAffiliations) {
            $multipart[] = ['name' => 'includeRawAffiliations', 'contents' => '1'];
        }
        if ($options->teiCoordinates) {
            foreach ($this->config->coordinates as $coord) {
                $multipart[] = ['name' => 'teiCoordinates', 'contents' => $coord];
            }
        }
        if ($options->segmentSentences) {
            $multipart[] = ['name' => 'segmentSentences', 'contents' => '1'];
        }
        if ($options->flavor !== null) {
            $multipart[] = ['name' => 'flavor', 'contents' => $options->flavor];
        }
        if ($options->startPage > 0) {
            $multipart[] = ['name' => 'start', 'contents' => (string) $options->startPage];
        }
        if ($options->endPage > 0) {
            $multipart[] = ['name' => 'end', 'contents' => (string) $options->endPage];
        }

        return $multipart;
    }
}
