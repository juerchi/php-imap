<?php

/*
* File: ImapProtocol.php
* Category: Protocol
* Author: M.Goldenbaum
* Created: 16.09.20 18:27
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Connection\Protocols;

use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Enums\Encryption;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;

/**
 * Class AbstractProtocol.
 *
 * @phpstan-import-type ImapUid from ProtocolInterface
 */
abstract class AbstractProtocol implements ProtocolInterface
{
    public const int DEFAULT_CONNECTION_TIMEOUT = 30;

    /**
     * Default connection timeout in seconds.
     */
    public int $connectionTimeout = self::DEFAULT_CONNECTION_TIMEOUT {
        set(?int $value) {
            $this->connectionTimeout = $value ?? self::DEFAULT_CONNECTION_TIMEOUT;
        }
    }

    public ?Encryption $encryption = null;

    /**
     * Set to false to ignore SSL certificate validation.
     */
    public bool $certValidation = true;

    public ?LoggerInterface $logger = null {
        set {
            $this->logger = $value;
        }
    }

    /**
     * Get an available cryptographic method.
     */
    public int $cryptoMethod {
        get => \STREAM_CRYPTO_METHOD_TLS_CLIENT;
    }

    protected bool $enableUidCache = true;

    protected bool $debug = false;

    /**
     * Proxy settings.
     */
    protected array $proxy = [
        'socket' => null,
        'request_fulluri' => false,
        'username' => null,
        'password' => null,
    ];

    /**
     * Cache for messageNumber => uid of the active folder.
     *
     * @var null|array<int, ImapUid>
     */
    protected ?array $uidCache = null;

    /**
     * Enable SSL certificate validation.
     *
     * @return $this
     */
    public function enableCertValidation(): static
    {
        $this->certValidation = true;

        return $this;
    }

    /**
     * Disable SSL certificate validation.
     *
     * @return $this
     */
    public function disableCertValidation(): static
    {
        $this->certValidation = false;

        return $this;
    }

    /**
     * Set connection proxy settings.
     *
     * @param array{socket?: null|string, request_fulluri?: bool, username?: null|string, password?: null|string} $options
     *
     * @return $this
     */
    public function setProxy(array $options): static
    {
        foreach ($this->proxy as $key => $val) {
            $this->proxy[$key] = $options[$key] ?? $val;
        }

        return $this;
    }

    /**
     * Get the current proxy settings.
     *
     * @return array{socket: null|string, request_fulluri: bool, username: null|string, password: null|string}
     */
    public function getProxy(): array
    {
        return $this->proxy;
    }

    /**
     * Get the UID key string.
     */
    public function getUIDKey(int|string $uid): string
    {
        if (IMAP::ST_UID === $uid) {
            return 'UID';
        }
        $uid = (string) $uid;
        if ('' !== $uid && !is_numeric($uid)) {
            return $uid;
        }

        return '';
    }

    public function buildUIDCommand(string $command, int|string $uid): string
    {
        return trim($this->getUIDKey($uid).' '.$command);
    }

    /**
     * Set the uid cache of the current active folder.
     *
     * @param null|list<ImapUid> $uids
     */
    public function setUidCache(?array $uids): void
    {
        if (null === $uids) {
            $this->uidCache = null;

            return;
        }

        $messageNumber = 1;
        $uidCache = [];
        foreach ($uids as $uid) {
            $uidCache[$messageNumber++] = $uid;
        }

        $this->uidCache = $uidCache;
    }

    public function enableUidCache(): void
    {
        $this->enableUidCache = true;
    }

    public function disableUidCache(): void
    {
        $this->enableUidCache = false;
    }

    /**
     * @phpstan-param int|iterable<int> $from
     * @phpstan-param int|float|null    $to
     *
     * Build a valid UID sequence.
     *
     * @throws RuntimeException
     */
    public function buildSequence(int|iterable $from, float|int|null $to = null): string
    {
        if (\is_iterable($from)) {
            if (null !== $to) {
                throw new RuntimeException('setting to is not allowed if from is given an arrays');
            }

            return implode(',', [...$from]);
        }

        if (null === $to) {
            return (string) $from;
        }

        if (INF === $to) {
            return $from.':*';
        }

        return $from.':'.(int) $to;
    }

    /**
     * Create a new resource stream.
     *
     * @return resource the socket created
     *
     * @throws ConnectionFailedException
     */
    protected function createStream(string $transport, string $host, int $port, int $timeout): mixed
    {
        $socket = "{$transport}://{$host}:{$port}";
        $stream = stream_socket_client(
            $socket,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($this->defaultSocketOptions($transport))
        );

        if (false === $stream) {
            throw new ConnectionFailedException($errstr, $errno);
        }

        if (false === stream_set_timeout($stream, $timeout)) {
            throw new ConnectionFailedException('Failed to set stream timeout');
        }

        return $stream;
    }

    /**
     * Prepare socket options.
     *
     * @return array<string, mixed>
     */
    private function defaultSocketOptions(string $transport): array
    {
        $options = [];
        if (null !== $this->encryption) {
            $options['ssl'] = [
                'verify_peer_name' => $this->certValidation,
                'verify_peer' => $this->certValidation,
            ];
        }

        if ($this->proxy['socket']) {
            $options[$transport]['proxy'] = $this->proxy['socket'];
            $options[$transport]['request_fulluri'] = $this->proxy['request_fulluri'];

            if ($this->proxy['username']) {
                $auth = base64_encode($this->proxy['username'].':'.$this->proxy['password']);
                $options[$transport]['header'] = [
                    "Proxy-Authorization: Basic {$auth}",
                ];
            }
        }

        return $options;
    }
}
