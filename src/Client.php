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

use ErrorException;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\LegacyProtocol;
use Webklex\PHPIMAP\Connection\Protocols\AbstractProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
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
 * Class Client
 *
 * @package Webklex\PHPIMAP
 */
class Client
{
    use HasEvents;

    /**
     * Connection resource
     *
     * @var boolean|AbstractProtocol|ProtocolInterface
     */
    public $connection = false;

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
     * Supported: none, ssl, tls, starttls or notls.
     */
    public string $encryption;

    /**
     * If server has to validate cert.
     */
    public bool $validate_cert = true;

    /**
     * Proxy settings
     */
    protected array $proxy = [
        'socket' => null,
        'request_fulluri' => false,
        'username' => null,
        'password' => null,
    ];

    /**
     * Connection timeout
     */
    public int $timeout;

    /**
     * Account username
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
     * Active folder path.
     */
    protected ?string $active_folder = null;

    /**
     * Default message mask
     *
     * @var string $default_message_mask
     */
    protected string $default_message_mask = MessageMask::class;

    /**
     * Default attachment mask
     */
    protected string $default_attachment_mask = AttachmentMask::class;

    /**
     * Used default account values
     */
    protected array $default_account_config = [
        'host' => 'localhost',
        'port' => 993,
        'protocol' => 'imap',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'username' => '',
        'password' => '',
        'authentication' => null,
        "extensions" => [],
        'proxy' => [
            'socket' => null,
            'request_fulluri' => false,
            'username' => null,
            'password' => null,
        ],
        "timeout" => 30,
    ];

    protected ?LoggerInterface $logger = null;

    /**
     * Client constructor.
     * @param array $config
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
     * Client destructor
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
     * Set the Client configuration
     * @param array $config
     *
     * @return self
     */
    public function setConfig(array $config): Client
    {
        $default_account = ClientManager::get('default');
        $default_config = ClientManager::get("accounts.$default_account");

        foreach ($this->default_account_config as $key => $value) {
            $this->setAccountConfig($key, $config, $default_config);
        }

        return $this;
    }

    /**
     * Set a specific account config
     */
    private function setAccountConfig(string $key, array $config, array $default_config)
    {
        $value = $this->default_account_config[$key];
        if (isset($config[$key])) {
            $value = $config[$key];
        } elseif (isset($default_config[$key])) {
            $value = $default_config[$key];
        }
        $this->$key = $value;
    }

    /**
     * Look for a possible events in any available config
     * @param $config
     */
    protected function setEventsFromConfig($config): void
    {
        $this->events = ClientManager::get("events");
        if (isset($config['events'])) {
            foreach ($config['events'] as $section => $events) {
                $this->events[$section] = array_merge($this->events[$section], $events);
            }
        }
    }

    /**
     * Look for a possible mask in any available config
     * @param $config
     *
     * @throws MaskNotFoundException
     */
    protected function setMaskFromConfig($config): void
    {
        $default_config = ClientManager::get("masks");

        if (isset($config['masks'])) {
            if (isset($config['masks']['message'])) {
                if (class_exists($config['masks']['message'])) {
                    $this->default_message_mask = $config['masks']['message'];
                } else {
                    throw new MaskNotFoundException("Unknown mask provided: ".$config['masks']['message']);
                }
            } else {
                if (class_exists($default_config['message'])) {
                    $this->default_message_mask = $default_config['message'];
                } else {
                    throw new MaskNotFoundException("Unknown mask provided: ".$default_config['message']);
                }
            }
            if (isset($config['masks']['attachment'])) {
                if (class_exists($config['masks']['attachment'])) {
                    $this->default_attachment_mask = $config['masks']['attachment'];
                } else {
                    throw new MaskNotFoundException("Unknown mask provided: ".$config['masks']['attachment']);
                }
            } else {
                if (class_exists($default_config['attachment'])) {
                    $this->default_attachment_mask = $default_config['attachment'];
                } else {
                    throw new MaskNotFoundException("Unknown mask provided: ".$default_config['attachment']);
                }
            }
        } else {
            if (class_exists($default_config['message'])) {
                $this->default_message_mask = $default_config['message'];
            } else {
                throw new MaskNotFoundException("Unknown mask provided: ".$default_config['message']);
            }

            if (class_exists($default_config['attachment'])) {
                $this->default_attachment_mask = $default_config['attachment'];
            } else {
                throw new MaskNotFoundException("Unknown mask provided: ".$default_config['attachment']);
            }
        }

    }

    /**
     * Get the current imap resource
     *
     * @return bool|AbstractProtocol|ProtocolInterface
     * @throws ConnectionFailedException
     */
    public function getConnection()
    {
        $this->checkConnection();

        return $this->connection;
    }

    /**
     * Determine if connection was established.
     */
    public function isConnected(): bool
    {
        return $this->connection && $this->connection->connected();
    }

    /**
     * Determine if connection was established and connect if not.
     *
     * @throws ConnectionFailedException
     */
    public function checkConnection(): void
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    /**
     * Force the connection to reconnect
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
     * Connect to server.
     *
     * @return $this
     * @throws ConnectionFailedException
     */
    public function connect(): Client
    {
        $this->disconnect();
        $protocol = strtolower($this->protocol);

        if (in_array($protocol, ['imap', 'imap4', 'imap4rev1'])) {
            $this->connection = new ImapProtocol($this->validate_cert, $this->encryption);
            $this->connection->setConnectionTimeout($this->timeout);
            $this->connection->setProxy($this->proxy);
            $this->connection->setLogger($this->logger);
        } else {
            if (extension_loaded('imap') === false) {
                throw new ConnectionFailedException(
                    "connection setup failed",
                    previous: new ProtocolNotSupportedException($protocol." is an unsupported protocol")
                );
            }
            $this->connection = new LegacyProtocol($this->validate_cert, $this->encryption);
            if (str_starts_with($protocol, 'legacy-')) {
                $protocol = substr($protocol, 7);
            }
            $this->connection->setProtocol($protocol);
            $this->connection->setLogger($this->logger);
        }

        if (ClientManager::get('options.debug')) {
            $this->connection->enableDebug();
        }

        if (!ClientManager::get('options.uid_cache')) {
            $this->connection->disableUidCache();
        }

        try {
            $this->connection->connect($this->host, $this->port);
        } catch (ErrorException|Exceptions\RuntimeException $e) {
            throw new ConnectionFailedException("connection setup failed", 0, $e);
        }
        $this->authenticate();

        return $this;
    }

    /**
     * Authenticate the current session
     *
     * @throws ConnectionFailedException
     */
    protected function authenticate(): void
    {
        try {
            if ($this->authentication === 'oauth') {
                if (!$this->connection->authenticate($this->username, $this->password)) {
                    throw new AuthFailedException();
                }
            } elseif (!$this->connection->login($this->username, $this->password)) {
                throw new AuthFailedException();
            }
        } catch (AuthFailedException $e) {
            throw new ConnectionFailedException("connection setup failed", 0, $e);
        }
    }

    /**
     * Disconnect from server.
     *
     * @return $this
     */
    public function disconnect(): Client
    {
        if ($this->isConnected() && $this->connection !== false) {
            $this->connection->logout();
        }
        $this->active_folder = null;

        return $this;
    }

    /**
     * Get a folder instance by a folder name
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolder(string $folder_name, string|bool|null $delimiter = null): ?Folder
    {
        if ($delimiter !== false && $delimiter !== null) {
            return $this->getFolderByPath($folder_name);
        }

        // Set delimiter to false to force selection via getFolderByName (maybe useful for uncommon folder names)
        $delimiter = is_null($delimiter) ? ClientManager::get('options.delimiter', "/") : $delimiter;
        if (str_contains($folder_name, (string) $delimiter)) {
            return $this->getFolderByPath($folder_name);
        }

        return $this->getFolderByName($folder_name);
    }

    /**
     * Get a folder instance by a folder name
     * @param $folder_name
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolderByName($folder_name): ?Folder
    {
        return $this->getFolders(false)->where("name", $folder_name)->first();
    }

    /**
     * Get a folder instance by a folder path
     * @param $folder_path
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolderByPath($folder_path): ?Folder
    {
        return $this->getFolders(false)->where("path", $folder_path)->first();
    }

    /**
     * Get folders list.
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return flat array.
     *
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFolders(bool $hierarchical = true, ?string $parent_folder = null): FolderCollection
    {
        $this->checkConnection();
        $folders = FolderCollection::make();

        $pattern = $parent_folder.($hierarchical ? '%' : '*');
        $items = $this->connection->folders('', $pattern);

        foreach ($items as $folder_name => $item) {
            $folder = new Folder($this, $folder_name, $item["delimiter"], $item["flags"]);

            if ($hierarchical && $folder->hasChildren()) {
                $pattern = $folder->full_name.$folder->delimiter.'%';

                $children = $this->getFolders(true, $pattern);
                $folder->setChildren($children);
            }

            $folders->push($folder);
        }

        return $folders;
    }

    /**
     * Get folders list.
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return flat array.
     *
     * @param boolean $hierarchical
     * @param string|null $parent_folder
     *
     * @return FolderCollection
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function getFoldersWithStatus(bool $hierarchical = true, string $parent_folder = null): FolderCollection
    {
        $this->checkConnection();
        $folders = FolderCollection::make();

        $pattern = $parent_folder.($hierarchical ? '%' : '*');
        $items = $this->connection->folders('', $pattern);

        foreach ($items as $folder_name => $item) {
            $folder = new Folder($this, $folder_name, $item["delimiter"], $item["flags"]);

            if ($hierarchical && $folder->hasChildren()) {
                $pattern = $folder->full_name.$folder->delimiter.'%';

                $children = $this->getFoldersWithStatus(true, $pattern);
                $folder->setChildren($children);
            }

            $folder->loadStatus();
            $folders->push($folder);
        }

        return $folders;
    }

    /**
     * Open a given folder.
     * @param string $folder_path
     * @param boolean $force_select
     *
     * @return array|bool
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function openFolder(string $folder_path, bool $force_select = false)
    {
        if ($force_select === false && $this->active_folder === $folder_path && $this->isConnected()) {
            return true;
        }
        $this->checkConnection();
        $this->active_folder = $folder_path;

        return $this->connection->selectFolder($folder_path);
    }

    /**
     * Create a new Folder
     * @param string $folder
     * @param boolean $expunge
     *
     * @return Folder
     * @throws ConnectionFailedException
     * @throws FolderFetchingException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\RuntimeException
     */
    public function createFolder(string $folder, bool $expunge = true): Folder
    {
        $this->checkConnection();
        $status = $this->connection->createFolder($folder);

        if ($expunge) {
            $this->expunge();
        }

        $folder = $this->getFolderByPath($folder);
        if ($status && $folder) {
            $event = $this->getEvent("folder", "new");
            $event::dispatch($folder);
        }

        return $folder;
    }

    /**
     * Check a given folder
     * @param $folder
     *
     * @return array|bool
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function checkFolder($folder)
    {
        $this->checkConnection();

        return $this->connection->examineFolder($folder);
    }

    /**
     * Get the current active folder
     *
     * @return string
     */
    public function getFolderPath()
    {
        return $this->active_folder;
    }

    /**
     * Exchange identification information
     * Ref.: https://datatracker.ietf.org/doc/html/rfc2971
     *
     * @param array|null $ids
     * @return array|bool|void|null
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function Id(array $ids = null)
    {
        $this->checkConnection();

        return $this->connection->ID($ids);
    }

    /**
     * Retrieve the quota level settings, and usage statics per mailbox
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function getQuota(): array
    {
        $this->checkConnection();

        return $this->connection->getQuota($this->username);
    }

    /**
     * Retrieve the quota settings per user
     * @param string $quota_root
     *
     * @return array
     * @throws ConnectionFailedException
     */
    public function getQuotaRoot(string $quota_root = 'INBOX'): array
    {
        $this->checkConnection();

        return $this->connection->getQuotaRoot($quota_root);
    }

    /**
     * Delete all messages marked for deletion
     *
     * @return bool
     * @throws ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function expunge(): bool
    {
        $this->checkConnection();

        return $this->connection->expunge();
    }

    /**
     * Set the connection timeout
     * @param integer $timeout
     *
     * @return AbstractProtocol
     * @throws ConnectionFailedException
     */
    public function setTimeout(int $timeout): AbstractProtocol
    {
        $this->timeout = $timeout;
        if ($this->isConnected()) {
            $this->connection->setConnectionTimeout($timeout);
            $this->reconnect();
        }

        return $this->connection;
    }

    /**
     * Get the connection timeout
     *
     * @return int
     * @throws ConnectionFailedException
     */
    public function getTimeout(): int
    {
        $this->checkConnection();

        return $this->connection->getConnectionTimeout();
    }

    /**
     * Get the default message mask
     *
     * @return string
     */
    public function getDefaultMessageMask(): string
    {
        return $this->default_message_mask;
    }

    /**
     * Get the default events for a given section
     * @param $section
     *
     * @return array
     */
    public function getDefaultEvents($section): array
    {
        if (isset($this->events[$section])) {
            return is_array($this->events[$section]) ? $this->events[$section] : [];
        }

        return [];
    }

    /**
     * Set the default message mask
     * @param string $mask
     *
     * @return $this
     * @throws MaskNotFoundException
     */
    public function setDefaultMessageMask(string $mask): Client
    {
        if (class_exists($mask)) {
            $this->default_message_mask = $mask;

            return $this;
        }

        throw new MaskNotFoundException("Unknown mask provided: ".$mask);
    }

    /**
     * Get the default attachment mask
     *
     * @return string
     */
    public function getDefaultAttachmentMask(): string
    {
        return $this->default_attachment_mask;
    }

    /**
     * Set the default attachment mask
     * @param string $mask
     *
     * @return $this
     * @throws MaskNotFoundException
     */
    public function setDefaultAttachmentMask(string $mask): Client
    {
        if (class_exists($mask)) {
            $this->default_attachment_mask = $mask;

            return $this;
        }

        throw new MaskNotFoundException("Unknown mask provided: ".$mask);
    }
}
