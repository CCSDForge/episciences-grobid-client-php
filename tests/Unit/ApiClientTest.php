<?php

declare(strict_types=1);

namespace Episciences\GrobidClient\Tests\Unit;

use Episciences\GrobidClient\ApiClient;
use Episciences\GrobidClient\GrobidConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    private function makeClient(GrobidConfig $config, MockHandler $mock): ApiClient
    {
        $stack      = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $stack]);
        return new ApiClient($config, $httpClient);
    }

    public function testGetReturnsResponse(): void
    {
        $config = new GrobidConfig();
        $mock   = new MockHandler([new Response(200, [], 'OK')]);
        $client = $this->makeClient($config, $mock);

        $response = $client->get('api/isalive');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', (string) $response->getBody());
    }

    public function testPostReturnsResponse(): void
    {
        $config = new GrobidConfig();
        $mock   = new MockHandler([new Response(200, [], '<tei/>')]);
        $client = $this->makeClient($config, $mock);

        $response = $client->post('api/processFulltextDocument', [
            'multipart' => [['name' => 'input', 'contents' => 'fake-pdf-content']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<tei/>', (string) $response->getBody());
    }

    public function testPostAsyncReturnsPromise(): void
    {
        $config = new GrobidConfig();
        $mock   = new MockHandler([new Response(202, [], 'accepted')]);
        $client = $this->makeClient($config, $mock);

        $promise  = $client->postAsync('api/processFulltextDocument');
        $response = $promise->wait();

        self::assertSame(202, $response->getStatusCode());
    }

    public function testGetHttpClientReturnsClientInterface(): void
    {
        $config     = new GrobidConfig();
        $httpClient = new Client();
        $apiClient  = new ApiClient($config, $httpClient);

        self::assertSame($httpClient, $apiClient->getHttpClient());
    }
}
