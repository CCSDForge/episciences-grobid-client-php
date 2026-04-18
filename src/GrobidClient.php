<?php

declare(strict_types=1);

namespace Episciences\GrobidClient;

use Episciences\GrobidClient\Exception\FileProcessingException;
use Episciences\GrobidClient\Exception\IOException;
use Episciences\GrobidClient\Exception\ProcessingFailedException;
use Episciences\GrobidClient\Exception\ServerUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class GrobidClient
{
    private const TEI_SUFFIX  = '.grobid.tei.xml';
    private const API_PATH    = 'api/%s';

    private readonly LoggerInterface $logger;
    private readonly ApiClient $apiClient;
    private readonly ClientInterface $downloadClient;
    private readonly RequestOptionsBuilder $requestBuilder;

    public function __construct(
        private readonly GrobidConfig $config,
        bool                          $checkServer    = true,
        ?LoggerInterface              $logger         = null,
        ?ApiClient                    $apiClient      = null,
        ?ClientInterface              $downloadClient = null,
    ) {
        $this->logger         = $logger         ?? new NullLogger();
        $this->apiClient      = $apiClient      ?? new ApiClient($config);
        $this->downloadClient = $downloadClient ?? new Client(['timeout' => $config->timeout]);
        $this->requestBuilder = new RequestOptionsBuilder($config);

        if ($checkServer) {
            $this->assertServerAvailable();
        }
    }

    private function assertServerAvailable(): void
    {
        if (!$this->ping()) {
            throw new ServerUnavailableException(
                sprintf('GROBID server is not available at %s', $this->config->grobidServer)
            );
        }
    }

    public function ping(): bool
    {
        try {
            $response = $this->apiClient->get('api/isalive', ['timeout' => 10]);
            return $response->getStatusCode() === 200;
        } catch (ConnectException $e) {
            $this->logger->warning('GROBID server connection failed: {message}', ['message' => $e->getMessage()]);
            throw new ServerUnavailableException(
                sprintf('Cannot connect to GROBID server at %s: %s', $this->config->grobidServer, $e->getMessage()),
                0,
                $e
            );
        } catch (GuzzleException $e) {
            $this->logger->warning('GROBID server ping failed: {message}', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Process a single file and return the TEI XML as a string.
     *
     * @throws \InvalidArgumentException     if $filePath is not a file
     * @throws FileProcessingException       if the file cannot be opened or read
     * @throws ProcessingFailedException     if GROBID returns a non-200 response
     * @throws ServerUnavailableException    if the server is busy after all retries
     */
    public function processToString(
        GrobidService      $service,
        string             $filePath,
        ?ProcessingOptions $options = null,
    ): string {
        $options = $options ?? new ProcessingOptions();

        if (!is_file($filePath)) {
            throw new \InvalidArgumentException(sprintf('Input path is not a file: %s', $filePath));
        }

        $path       = sprintf(self::API_PATH, $service->value);
        $guzzleOpts = $this->requestBuilder->fromFile($service, $filePath, $options) + ['http_errors' => false];

        $this->logger->debug('processToString: {file}', ['file' => $filePath]);

        return $this->postToGrobid($path, $guzzleOpts, $filePath);
    }

    /**
     * Download a remote PDF (or XML/TXT) and process it, returning the TEI XML as a string.
     *
     * @throws \InvalidArgumentException  if $url is not a valid http/https URL
     * @throws ProcessingFailedException  if the download or GROBID processing fails
     * @throws ServerUnavailableException on network errors
     */
    public function processUrlToString(
        GrobidService      $service,
        string             $url,
        ?ProcessingOptions $options = null,
    ): string {
        $options = $options ?? new ProcessingOptions();

        $this->assertValidUrl($url);
        $content  = $this->downloadContent($url);
        $filename = $this->filenameFromUrl($url);

        $path       = sprintf(self::API_PATH, $service->value);
        $guzzleOpts = $this->requestBuilder->fromStream($service, $content, $filename, $options) + ['http_errors' => false];

        $this->logger->debug('processUrlToString: {url}', ['url' => $url]);

        return $this->postToGrobid($path, $guzzleOpts, $url);
    }

    public function process(
        GrobidService     $service,
        string            $inputPath,
        ?ProcessingOptions $options = null,
    ): ProcessingResult {
        $options = $options ?? new ProcessingOptions();
        $files   = $this->discoverFiles($service, $inputPath);

        if ($files === []) {
            $this->logger->info('No eligible files found in {path}', ['path' => $inputPath]);
            return new ProcessingResult(0, 0, 0);
        }

        $this->logger->info('Found {count} file(s) to process', ['count' => count($files)]);

        $result     = new ProcessingResult(0, 0, 0);
        $batchIndex = 0;
        $batchTotal = (int) ceil(count($files) / $this->config->batchSize);

        foreach (array_chunk($files, $this->config->batchSize) as $batch) {
            ++$batchIndex;
            $this->logger->debug('Batch {n}/{total} — {count} file(s)', [
                'n'     => $batchIndex,
                'total' => $batchTotal,
                'count' => count($batch),
            ]);

            $batchResult = $this->processBatch($service, $batch, $inputPath, $options);
            $result      = $result->merge($batchResult);
        }

        $this->logger->info(
            'Processing complete: {processed} processed, {errors} errors, {skipped} skipped',
            ['processed' => $result->processed, 'errors' => $result->errors, 'skipped' => $result->skipped]
        );

        return $result;
    }

    /**
     * @param string[] $files
     */
    private function processBatch(
        GrobidService    $service,
        array            $files,
        string           $inputPath,
        ProcessingOptions $options,
    ): ProcessingResult {
        $processed = 0;
        $errors    = 0;
        $skipped   = 0;

        $filesToProcess = [];

        foreach ($files as $file) {
            $outputFile = $this->outputFileName($file, $inputPath, $options->outputPath);

            if (!$options->force && file_exists($outputFile)) {
                $this->logger->debug('Skipping already processed file: {file}', ['file' => $file]);
                ++$skipped;
                continue;
            }

            $filesToProcess[] = $file;
        }

        if ($filesToProcess === []) {
            return new ProcessingResult($processed, $errors, $skipped);
        }

        $fileList = $filesToProcess;

        /** @var array<int, array{status: int, body: string}> $poolResults */
        $poolResults = [];

        $requests = function () use ($service, $fileList, $options): \Generator {
            foreach ($fileList as $index => $file) {
                $path       = sprintf(self::API_PATH, $service->value);
                $guzzleOpts = $this->requestBuilder->fromFile($service, $file, $options);

                yield $index => fn (): \GuzzleHttp\Promise\PromiseInterface
                    => $this->apiClient->postAsync($path, $guzzleOpts);
            }
        };

        $pool = new Pool($this->apiClient->getHttpClient(), $requests(), [
            'concurrency' => $options->concurrency,
            'fulfilled'   => function (ResponseInterface $response, int $index) use (&$poolResults): void {
                $poolResults[$index] = [
                    'status' => $response->getStatusCode(),
                    'body'   => (string) $response->getBody(),
                ];
            },
            'rejected' => function (\Throwable $reason, int $index) use (&$poolResults): void {
                $code = $reason instanceof RequestException && $reason->getResponse() !== null
                    ? $reason->getResponse()->getStatusCode()
                    : 500;

                $poolResults[$index] = ['status' => $code, 'body' => $reason->getMessage()];
            },
        ]);

        try {
            $pool->promise()->wait();
        } catch (\Throwable $e) {
            $this->logger->error('Pool execution failed: {message}', ['message' => $e->getMessage()]);
        }

        /** @var list<string> $toRetry */
        $toRetry = [];

        foreach ($poolResults as $index => $result) {
            $file       = $fileList[$index];
            $outputFile = $this->outputFileName($file, $inputPath, $options->outputPath);

            if ($result['status'] === 200) {
                $this->writeOutput($outputFile, $result['body']);
                $this->logger->debug('Processed: {file}', ['file' => $file]);
                ++$processed;
            } elseif ($result['status'] === 503) {
                $toRetry[] = $file;
            } else {
                $this->writeErrorFile($outputFile, $result['status'], $result['body']);
                $this->logger->warning('Error {code} for {file}', ['code' => $result['status'], 'file' => $file]);
                ++$errors;
            }
        }

        foreach ($toRetry as $file) {
            [$retryFile, $retryCode, $retryBody] = $this->retryOnBusy($service, $file, $options);
            $outputFile = $this->outputFileName($retryFile, $inputPath, $options->outputPath);

            if ($retryCode === 200) {
                $this->writeOutput($outputFile, $retryBody);
                ++$processed;
            } else {
                $this->writeErrorFile($outputFile, $retryCode, $retryBody);
                ++$errors;
            }
        }

        return new ProcessingResult($processed, $errors, $skipped);
    }

    /**
     * POST to GROBID, handle 503 retries, and return the response body.
     *
     * @param  array<string, mixed>  $guzzleOpts Must include http_errors => false.
     * @throws ServerUnavailableException on network error
     * @throws ProcessingFailedException  on non-200 final status
     */
    private function postToGrobid(string $path, array $guzzleOpts, string $label): string
    {
        try {
            $response   = $this->apiClient->post($path, $guzzleOpts);
            $statusCode = $response->getStatusCode();
            $body       = (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new ServerUnavailableException(
                sprintf('Request failed for "%s": %s', $label, $e->getMessage()),
                0,
                $e
            );
        }

        if ($statusCode === 503) {
            [$statusCode, $body] = $this->retryRequest($path, $guzzleOpts, $label);
        }

        if ($statusCode !== 200) {
            throw ProcessingFailedException::httpError($label, $statusCode, $body);
        }

        return $body;
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function retryOnBusy(GrobidService $service, string $file, ProcessingOptions $options): array
    {
        $path       = sprintf(self::API_PATH, $service->value);
        $guzzleOpts = $this->requestBuilder->fromFile($service, $file, $options) + ['http_errors' => false];
        [$statusCode, $body] = $this->retryRequest($path, $guzzleOpts, $file);

        return [$file, $statusCode, $body];
    }

    /**
     * Retry a request up to maxRetries times when the server is busy (503).
     *
     * @param  array<string, mixed>  $guzzleOpts Must include http_errors => false.
     * @return array{0: int, 1: string}
     */
    private function retryRequest(string $apiPath, array $guzzleOpts, string $label): array
    {
        for ($attempt = 0; $attempt < $this->config->maxRetries; ++$attempt) {
            $this->logger->info(
                'Server busy (503), retrying {label} in {sleep}s (attempt {attempt}/{max})',
                [
                    'label'   => $label,
                    'sleep'   => $this->config->sleepTime,
                    'attempt' => $attempt + 1,
                    'max'     => $this->config->maxRetries,
                ]
            );

            sleep($this->config->sleepTime);

            try {
                $response   = $this->apiClient->post($apiPath, $guzzleOpts);
                $statusCode = $response->getStatusCode();

                if ($statusCode !== 503) {
                    return [$statusCode, (string) $response->getBody()];
                }
            } catch (GuzzleException $e) {
                $this->logger->error('Retry request failed for {label}: {message}', [
                    'label'   => $label,
                    'message' => $e->getMessage(),
                ]);
                return [500, $e->getMessage()];
            }
        }

        return [503, sprintf('Server busy after %d retries', $this->config->maxRetries)];
    }

    private function downloadContent(string $url): string
    {
        try {
            $response = $this->downloadClient->request('GET', $url, ['http_errors' => false]);
        } catch (GuzzleException $e) {
            throw new ServerUnavailableException(
                sprintf('Failed to download "%s": %s', $url, $e->getMessage()),
                0,
                $e
            );
        }

        if ($response->getStatusCode() !== 200) {
            throw ProcessingFailedException::downloadError($url, $response->getStatusCode());
        }

        return (string) $response->getBody();
    }

    private function assertValidUrl(string $url): void
    {
        $parsed = parse_url($url);
        if ($parsed === false
            || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            || empty($parsed['host'])
        ) {
            throw new \InvalidArgumentException(
                sprintf('Invalid URL "%s": must be http:// or https:// with a host', $url)
            );
        }
    }

    private function filenameFromUrl(string $url): string
    {
        $urlPath  = parse_url($url, PHP_URL_PATH);
        $basename = is_string($urlPath) ? basename($urlPath) : '';

        return ($basename !== '' && $basename !== '/') ? $basename : 'document.pdf';
    }

    /**
     * @return string[]
     */
    private function discoverFiles(GrobidService $service, string $inputPath): array
    {
        if (is_file($inputPath)) {
            return [$inputPath];
        }

        $realInput = realpath($inputPath);
        if ($realInput === false || !is_dir($realInput)) {
            throw new \InvalidArgumentException(sprintf('Input path does not exist: %s', $inputPath));
        }

        $extensions = $this->eligibleExtensions($service);
        $files      = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realInput, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $ext = strtolower($fileInfo->getExtension());
            if (in_array($ext, $extensions, true)) {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return string[]
     */
    private function eligibleExtensions(GrobidService $service): array
    {
        return match ($service) {
            GrobidService::ProcessCitationList       => ['txt'],
            GrobidService::ProcessCitationPatentST36 => ['xml'],
            default                                   => ['pdf'],
        };
    }

    private function outputFileName(string $inputFile, string $inputPath, ?string $outputPath): string
    {
        $basename = pathinfo($inputFile, PATHINFO_FILENAME);

        if ($outputPath !== null) {
            $normalizedInput = rtrim(realpath($inputPath) ?: $inputPath, DIRECTORY_SEPARATOR);
            $fileDir         = rtrim(realpath(dirname($inputFile)) ?: dirname($inputFile), DIRECTORY_SEPARATOR);

            if (str_starts_with($fileDir, $normalizedInput)) {
                $relative = ltrim(substr($fileDir, strlen($normalizedInput)), DIRECTORY_SEPARATOR);
                $dir      = $relative !== ''
                    ? $outputPath . DIRECTORY_SEPARATOR . $relative
                    : $outputPath;
            } else {
                $dir = $outputPath;
            }

            $candidate    = $dir . DIRECTORY_SEPARATOR . $basename . self::TEI_SUFFIX;
            $realOutputDir = rtrim(realpath($outputPath) ?: $outputPath, DIRECTORY_SEPARATOR);

            $this->assertPathWithinDirectory($candidate, $realOutputDir);

            return $candidate;
        }

        return dirname($inputFile) . DIRECTORY_SEPARATOR . $basename . self::TEI_SUFFIX;
    }

    private function assertPathWithinDirectory(string $path, string $directory): void
    {
        $normalizedDir  = rtrim($this->normalizePath($directory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = $this->normalizePath($path) . DIRECTORY_SEPARATOR;

        if (!str_starts_with($normalizedPath, $normalizedDir)) {
            throw new \InvalidArgumentException(
                sprintf('Output path "%s" escapes the output directory "%s"', $path, $directory)
            );
        }
    }

    private function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $parts      = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return ($isAbsolute ? DIRECTORY_SEPARATOR : '') . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function writeOutput(string $outputFile, string $content): void
    {
        $dir = dirname($outputFile);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw IOException::cannotCreateDirectory($dir);
        }

        if (file_put_contents($outputFile, $content) === false) {
            throw IOException::cannotWriteFile($outputFile);
        }

        $this->logger->debug('Wrote output: {file}', ['file' => $outputFile]);
    }

    private function writeErrorFile(string $outputFile, int $statusCode, string $message): void
    {
        $errorFile = substr($outputFile, 0, -strlen(self::TEI_SUFFIX)) . '_' . $statusCode . '.txt';
        $dir       = dirname($errorFile);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw IOException::cannotCreateDirectory($dir);
        }

        if (file_put_contents($errorFile, $message) === false) {
            throw IOException::cannotWriteFile($errorFile);
        }
    }
}
