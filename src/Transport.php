<?php
/**
 * Elastic Transport
 *
 * @link      https://github.com/elastic/elastic-transport-php
 * @copyright Copyright (c) Elasticsearch B.V (https://www.elastic.co)
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 *
 * Licensed to Elasticsearch B.V under one or more agreements.
 * Elasticsearch B.V licenses this file to you under the Apache 2.0 License.
 * See the LICENSE file in the project root for more information.
 */
declare(strict_types=1);

namespace Elastic\Transport;

use Elastic\Transport\ConnectionPool\ConnectionPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

use function json_encode;
use function sprintf;

final class Transport implements ClientInterface
{
    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ConnectionPoolInterface
     */
    private $connectionPool;

    /**
     * @var array
     */
    private $headers = [];

    /**
     * @var string
     */
    private $user;

    /**
     * @var string
     */
    private $password;

    /**
     * @var RequestInterface
     */
    private $lastRequest;

    /**
     * @var ResponseInterface
     */
    private $lastResponse;

    /**
     * @var string
     */
    private $OSVersion;

    public function __construct(
        ClientInterface $client,
        ConnectionPoolInterface $connectionPool,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->connectionPool = $connectionPool;
        $this->logger = $logger;
    }

    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    public function getConnectionPool(): ConnectionPoolInterface
    {
        return $this->connectionPool;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setUserInfo(string $user, string $password = ''): self
    {
        $this->user = $user;
        $this->password = $password;
        return $this;
    }

    public function setUserAgent(string $name, string $version)
    {
        $this->headers['User-Agent'] = sprintf(
            "%s/%s (%s %s; PHP %s)",
            $name,
            $version,
            PHP_OS,
            $this->getOSVersion(),
            phpversion()
        );
    }

    public function getLastRequest(): RequestInterface
    {
        return $this->lastRequest;
    }

    public function getLastResponse(): ResponseInterface
    {
        return $this->lastResponse;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {      
        // Set the host if empty
        if (empty($request->getUri()->getHost())) {
            $path = $request->getUri()->getPath();
            $connection = $this->connectionPool->nextConnection();
            $request = $request->withUri($connection->getUri()->withPath($path));
        }
        
        // Set the global headers, if not already set
        foreach ($this->headers as $name => $value) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }
        // Set user info, if not already set
        $uri = $request->getUri();
        if (empty($uri->getUserInfo())) {
            if (isset($this->user)) {
                $request = $request->withUri($uri->withUserInfo($this->user, $this->password));
            }
        }
        
        $this->lastRequest = $request;
        $this->logger->info(sprintf(
            "Request: %s %s\nBody: %s", 
            $request->getMethod(),
            (string) $request->getUri(),
            $request->getBody()->getContents()
        ));
        $this->logger->debug(sprintf(
            "Request Headers: %s", 
            json_encode($request->getHeaders())
        ));
        try {
            $response = $this->client->sendRequest($request);
            $this->lastResponse = $response;

            $this->logger->info(sprintf(
                "Response: %d\nBody: %s", 
                $response->getStatusCode(),
                $response->getBody()->getContents()
            ));
            $this->logger->debug(sprintf(
                "Response Headers: %s", 
                json_encode($response->getHeaders())
            ));

            return $response;
        } catch (NetworkExceptionInterface $e) {
            $this->logger->error($e->getMessage());
            $connection->markAlive(false);
            throw $e;
        } catch (ClientExceptionInterface $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the OS version using php_uname if available
     * otherwise it returns an empty string
     */
    private function getOSVersion(): string
    {
        if ($this->OSVersion === null) {
            $this->OSVersion = strpos(strtolower(ini_get('disable_functions')), 'php_uname') !== false
                ? ''
                : php_uname("r");
        }
        return $this->OSVersion;
    }
}