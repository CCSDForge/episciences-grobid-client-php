<?php

declare(strict_types=1);

namespace Episciences\GrobidClient;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

final class ApiClient
{
    private ClientInterface $httpClient;

    public function __construct(GrobidConfig $config, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => rtrim($config->grobidServer, '/') . '/',
            'timeout'  => $config->timeout,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @throws GuzzleException
     */
    public function get(string $path, array $options = []): ResponseInterface
    {
        return $this->httpClient->request('GET', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @throws GuzzleException
     */
    public function post(string $path, array $options = []): ResponseInterface
    {
        return $this->httpClient->request('POST', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function postAsync(string $path, array $options = []): PromiseInterface
    {
        return $this->httpClient->requestAsync('POST', $path, $options);
    }

    public function getHttpClient(): ClientInterface
    {
        return $this->httpClient;
    }
}
