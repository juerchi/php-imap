<?php

/*
* File:     Client.php
* Category: -
* Author:   M. Goldenbaum
* Created:  19.01.17 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Doctrine\Common\Collections\Criteria;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\UnicodeString;
use Webklex\PHPIMAP\Connection\Protocols\AbstractProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\LegacyProtocol;
use Webklex\PHPIMAP\Enums\Encryption;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\ProtocolNotSupportedException;
use Webklex\PHPIMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;
use Webklex\PHPIMAP\Support\Masks\MessageMask;
use Webklex\PHPIMAP\Traits\HasEvents;

/**
 * Class Client.
 */
final class Client
{
    use HasEvents;

    /**
     * Connection resource.
     */
    public ?AbstractProtocol $connection = null;

    /**
     * Server hostname.
     */
    public string $host;

    /**
     * Server port.
     */
    public int $port;

    /**
     * Service protocol.
     */
    public string $protocol;

    /**
     * Server encryption.
     * Supported: none, ssl, tls, starttls, or notls.
     */
    public ?string $encryption = null;

    /**
     * If the server has to validate cert.
     */
    public bool $validateCert = true;

    /**
     * Connection timeout.
     */
    public int $timeout;

    /**
     * Account username.
     */
    public string $username;

    /**
     * Account password.
     */
    public string $password;

    /**
     * Additional data fetched from the server.
     */
    public array $extensions;

    /**
     * Account authentication method.
     */
    public ?string $authentication;

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
     * Active folder path.
     */
    protected ?string $activeFolder = null;

    /**
     * Default message mask.
     */
    protected string $defaultMessageMask = MessageMask::class;

    /**
     * Default attachment mask.
     */
    protected string $defaultAttachmentMask = AttachmentMask::class;

    /**
     * Used default account values.
     */
    protected array $defaultAccountConfig = [
        'host' => 'localhost',
        'port' => 993,
        'protocol' => 'imap',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'username' => '',
        'password' => '',
        'authentication' => null,
        'extensions' => [],
        'proxy' => [
            'socket' => null,
            'request_fulluri' => false,
            'username' => null,
            'password' => null,
        ],
        'timeout' => 30,
    ];

    protected ?LoggerInterface $logger = null;

    /**
     * Client constructor.
     *
     * @throws MaskNotFoundException
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);
        $this->setMaskFromConfig($config);
        $this->setEventsFromConfig($config);
    }

    /**
     * Client destructor.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Set the Client configuration.
     */
    public function setConfig(array $config): self
    {
        $defaultAccount = ClientManager::get('default');
        $defaultConfig = ClientManager::get("accounts.{$defaultAccount}");

        foreach (array_keys($this->defaultAccountConfig) as $key) {
            $this->setAccountConfig($key, $config, $defaultConfig);
        }

        return $this;
    }

    /**
     * Get the current imap resource.
     *
     * @throws ConnectionFailedException
     */
    public function getConnection(): AbstractProtocol
    {
        $this->checkConnection();

        return $this->connection;
    }

    /**
     * Determine if a connection was established.
     *
     * @phpstan-assert-if-true AbstractProtocol $this->connection
     *
     * @phpstan-impure
     */
    public function isConnected(): bool
    {
        return null !== $this->connection && $this->connection->connected();
    }

    /**
     * Determine if a connection was established and connect if not.
     *
     * @throws ConnectionFailedException
     *
     * @phpstan-assert AbstractProtocol $this->connection
     */
    public function checkConnection(): void
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    /**
     * Force the connection to reconnect.
     *
     * @throws ConnectionFailedException
     */
    public function reconnect(): void
    {
        if ($this->isConnected()) {
            $this->disconnect();
        }
        $this->connect();
    }

    /**
     * Connect to the server.
     *
     * @return $this
     *
     * @throws ConnectionFailedException
     */
    public function connect(): self
    {
        $this->disconnect();
        $protocol = strtolower($this->protocol);
        $encryption = null !== $this->encryption ? Encryption::tryFrom(strtolower($this->encryption)) : null;

        if (in_array($protocol, ['imap', 'imap4', 'imap4rev1'])) {
            $this->connection = new ImapProtocol($this->validateCert, $encryption);
            $this->connection->connectionTimeout = $this->timeout;
            $this->connection->setProxy($this->proxy);
        } else {
            if (false === extension_loaded('imap')) {
                throw new ConnectionFailedException(
                    'connection setup failed',
                    previous: new ProtocolNotSupportedException($protocol.' is an unsupported protocol')
                );
            }
            $this->connection = new LegacyProtocol($this->validateCert, $encryption);
            if (str_starts_with($protocol, 'legacy-')) {
                $protocol = substr($protocol, 7);
            }
            $this->connection->setProtocol($protocol);
        }
        $this->connection->logger = $this->logger;

        if (ClientManager::get('options.debug')) {
            $this->connection->enableDebug();
        }
        if (!ClientManager::get('options.uid_cache')) {
            $this->connection->disableUidCache();
        }

        try {
            $this->connection->connect($this->host, $this->port);
        } catch (\ErrorException|Exceptions\RuntimeException $e) {
            throw new ConnectionFailedException('connection setup failed', 0, $e);
        }
        $this->authenticate();

        return $this;
    }

    /**
     * Disconnect from the server.
     *
     * @return $this
     */
    public function disconnect(): self
    {
        if ($this->isConnected()) {
            $this->connection->logout();
        }
        $this->activeFolder = null;

        return $this;
    }

    /**
     * Get a folder instance by a folder name.
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolder(string $folderName, bool|string|null $delimiter = null): ?Folder
    {
        if (false !== $delimiter && null !== $delimiter) {
            return $this->getFolderByPath($folderName);
        }

        // Set delimiter to false to force selection via getFolderByName (maybe useful for uncommon folder names)
        $delimiter ??= ClientManager::get('options.delimiter', '/');
        if (str_contains($folderName, (string) $delimiter)) {
            return $this->getFolderByPath($folderName);
        }

        return $this->getFolderByName($folderName);
    }

    /**
     * Get a folder instance by a folder name.
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolderByName(string $folderName): ?Folder
    {
        $criteria = Criteria::create()->where(Criteria::expr()->eq('name', $folderName));

        return $this->getFolders(false)
            ->matching($criteria)
            ->first() ?: null;
    }

    /**
     * Get a folder instance by a folder path.
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolderByPath(string $folderPath): ?Folder
    {
        $criteria = Criteria::create()->where(Criteria::expr()->eq('path', $folderPath));

        return $this->getFolders(false)
            ->matching($criteria)
            ->first() ?: null;
    }

    /**
     * Get a folder list.
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return a flat array.
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolders(bool $hierarchical = true, ?string $parentFolder = null): FolderCollection
    {
        $this->checkConnection();
        $folders = new FolderCollection();

        $pattern = $parentFolder.($hierarchical ? '%' : '*');
        $items = $this->connection->folders('', $pattern);

        foreach ($items as $folderName => $item) {
            $folder = new Folder($this, $folderName, $item['delimiter'], $item['flags']);

            if ($hierarchical && $folder->hasChildren()) {
                $pattern = $folder->fullName.$folder->delimiter.'%';

                $children = $this->getFolders(true, $pattern);
                $folder->setChildren($children);
            }

            $folders->add($folder);
        }

        return $folders;
    }

    /**
     * Get a folder list.
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return a flat array.
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFoldersWithStatus(bool $hierarchical = true, ?string $parentFolder = null): FolderCollection
    {
        $this->checkConnection();
        $folders = new FolderCollection();

        $pattern = $parentFolder.($hierarchical ? '%' : '*');
        $items = $this->connection->folders('', $pattern);

        foreach ($items as $folderName => $item) {
            $folder = new Folder($this, $folderName, $item['delimiter'], $item['flags']);

            if ($hierarchical && $folder->hasChildren()) {
                $pattern = $folder->fullName.$folder->delimiter.'%';

                $children = $this->getFoldersWithStatus(true, $pattern);
                $folder->setChildren($children);
            }

            $folder->loadStatus();
            $folders->add($folder);
        }

        return $folders;
    }

    /**
     * Open a given folder.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function openFolder(string $folderPath, bool $forceSelect = false): array|bool
    {
        if (false === $forceSelect && $this->activeFolder === $folderPath && $this->isConnected()) {
            return true;
        }
        $this->checkConnection();
        $this->activeFolder = $folderPath;

        return $this->connection->selectFolder($folderPath);
    }

    /**
     * Create a new Folder.
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\RuntimeException
     */
    public function createFolder(string $folderName, bool $expunge = true): Folder
    {
        $this->checkConnection();
        $status = $this->connection->createFolder($folderName);

        if ($expunge) {
            $this->expunge();
        }

        $folder = $this->getFolderByPath($folderName)
            ?? throw new FolderFetchingException("Failed to fetch created folder: {$folderName}");

        if ($status) {
            $this->dispatch('folder', 'new', $folder);
        }

        return $folder;
    }

    /**
     * Check a given folder.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function checkFolder(string $folderName): array|bool
    {
        $this->checkConnection();

        return $this->connection->examineFolder($folderName);
    }

    /**
     * Get the current active folder.
     */
    public function getFolderPath(): ?string
    {
        return $this->activeFolder;
    }

    /**
     * Exchange identification information
     * Ref.: https://datatracker.ietf.org/doc/html/rfc2971.
     *
     * @param null|list<int|string> $ids
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function Id(?array $ids = null): array|bool|null
    {
        $this->checkConnection();

        return $this->connection->ID($ids);
    }

    /**
     * Retrieve the quota level settings, and usage statics per mailbox.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function getQuota(): array
    {
        $this->checkConnection();

        return $this->connection->getQuota($this->username);
    }

    /**
     * Retrieve the quota settings per user.
     *
     * @throws ConnectionFailedException
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): array
    {
        $this->checkConnection();

        return $this->connection->getQuotaRoot($quotaRoot);
    }

    /**
     * Delete all messages marked for deletion.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function expunge(): bool
    {
        $this->checkConnection();

        return $this->connection->expunge();
    }

    /**
     * Set the connection timeout.
     *
     * @throws ConnectionFailedException
     */
    public function setTimeout(int $timeout): AbstractProtocol
    {
        $this->timeout = $timeout;
        if ($this->isConnected()) {
            $this->connection->connectionTimeout = $timeout;
            $this->reconnect();
        }

        $this->checkConnection();

        return $this->connection;
    }

    /**
     * Get the connection timeout.
     *
     * @throws ConnectionFailedException
     */
    public function getTimeout(): int
    {
        $this->checkConnection();

        return $this->connection->connectionTimeout;
    }

    /**
     * Get the default message mask.
     */
    public function getDefaultMessageMask(): string
    {
        return $this->defaultMessageMask;
    }

    /**
     * Get the default events for a given section.
     */
    public function getDefaultEvents(string $section): array
    {
        if (isset($this->events[$section])) {
            return \is_array($this->events[$section]) ? $this->events[$section] : [];
        }

        return [];
    }

    /**
     * Set the default message mask.
     *
     * @return $this
     *
     * @throws MaskNotFoundException
     */
    public function setDefaultMessageMask(string $mask): self
    {
        if (class_exists($mask)) {
            $this->defaultMessageMask = $mask;

            return $this;
        }

        throw new MaskNotFoundException('Unknown mask provided: '.$mask);
    }

    /**
     * Get the default attachment mask.
     */
    public function getDefaultAttachmentMask(): string
    {
        return $this->defaultAttachmentMask;
    }

    /**
     * Set the default attachment mask.
     *
     * @return $this
     *
     * @throws MaskNotFoundException
     */
    public function setDefaultAttachmentMask(string $mask): self
    {
        if (class_exists($mask)) {
            $this->defaultAttachmentMask = $mask;

            return $this;
        }

        throw new MaskNotFoundException('Unknown mask provided: '.$mask);
    }

    /**
     * Look for possible events in any available config.
     */
    protected function setEventsFromConfig($config): void
    {
        $this->events = ClientManager::get('events');
        if (isset($config['events'])) {
            foreach ($config['events'] as $section => $events) {
                $this->events[$section] = array_merge($this->events[$section], $events);
            }
        }
    }

    /**
     * Look for a possible mask in any available config.
     *
     * @throws MaskNotFoundException
     */
    protected function setMaskFromConfig($config): void
    {
        $defaultConfig = ClientManager::get('masks');

        if (isset($config['masks'])) {
            if (isset($config['masks']['message'])) {
                if (class_exists($config['masks']['message'])) {
                    $this->defaultMessageMask = $config['masks']['message'];
                } else {
                    throw new MaskNotFoundException('Unknown mask provided: '.$config['masks']['message']);
                }
            } elseif (class_exists($defaultConfig['message'])) {
                $this->defaultMessageMask = $defaultConfig['message'];
            } else {
                throw new MaskNotFoundException('Unknown mask provided: '.$defaultConfig['message']);
            }
            if (isset($config['masks']['attachment'])) {
                if (class_exists($config['masks']['attachment'])) {
                    $this->defaultAttachmentMask = $config['masks']['attachment'];
                } else {
                    throw new MaskNotFoundException('Unknown mask provided: '.$config['masks']['attachment']);
                }
            } elseif (class_exists($defaultConfig['attachment'])) {
                $this->defaultAttachmentMask = $defaultConfig['attachment'];
            } else {
                throw new MaskNotFoundException('Unknown mask provided: '.$defaultConfig['attachment']);
            }
        } else {
            if (class_exists($defaultConfig['message'])) {
                $this->defaultMessageMask = $defaultConfig['message'];
            } else {
                throw new MaskNotFoundException('Unknown mask provided: '.$defaultConfig['message']);
            }

            if (class_exists($defaultConfig['attachment'])) {
                $this->defaultAttachmentMask = $defaultConfig['attachment'];
            } else {
                throw new MaskNotFoundException('Unknown mask provided: '.$defaultConfig['attachment']);
            }
        }
    }

    /**
     * Authenticate the current session.
     *
     * @throws ConnectionFailedException
     */
    protected function authenticate(): void
    {
        try {
            if ('oauth' === $this->authentication) {
                if (!$this->connection->authenticate($this->username, $this->password)) {
                    throw new AuthFailedException('auth failed');
                }
            } elseif (!$this->connection->login($this->username, $this->password)) {
                throw new AuthFailedException('auth failed');
            }
        } catch (AuthFailedException $e) {
            throw new ConnectionFailedException('connection setup failed', 0, $e);
        }
    }

    /**
     * Set a specific account config.
     */
    private function setAccountConfig(string $key, array $config, array $defaultConfig): void
    {
        $property = new UnicodeString($key)->camel()->toString();
        $this->$property = $config[$key] ?? $defaultConfig[$key] ?? $this->defaultAccountConfig[$key];
    }
}
