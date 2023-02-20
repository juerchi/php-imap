<?php
/*
* File:     Folder.php
* Category: -
* Author:   M. Goldenbaum
* Created:  19.01.17 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Carbon\Carbon;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\NotSupportedCapabilityException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Traits\HasEvents;

/**
 * Class Folder
 *
 * @package Webklex\PHPIMAP
 */
class Folder
{
    use HasEvents;

    /**
     * Client instance
     *
     * @var Client
     */
    protected Client $client;

    /**
     * Folder full path
     *
     * @var string
     */
    public string $path;

    /**
     * Folder name
     *
     * @var string
     */
    public string $name;

    /**
     * Folder fullname
     *
     * @var string
     */
    public string $full_name;

    /**
     * Children folders
     */
    public FolderCollection $children;

    /**
     * Delimiter for folder
     *
     * @var string
     */
    public string $delimiter;

    /**
     * Indicates if folder can't contain any "children".
     * CreateFolder won't work on this folder.
     *
     * @var boolean
     */
    public bool $no_inferiors;

    /**
     * Indicates if folder is only container, not a mailbox - you can't open it.
     *
     * @var boolean
     */
    public bool $no_select;

    /**
     * Indicates if folder is marked. This means that it may contain new messages since the last time it was checked.
     * Not provided by all IMAP servers.
     *
     * @var boolean
     */
    public bool $marked;

    /**
     * Indicates if folder contains any "children".
     * Not provided by all IMAP servers.
     *
     * @var boolean
     */
    public bool $has_children;

    /**
     * Indicates if folder refers to others.
     * Not provided by all IMAP servers.
     *
     * @var boolean
     */
    public bool $referral;

    /** @var array */
    public array $status;

    /**
     * @param string[] $attributes
     */
    public function __construct(Client $client, string $folderPath, string $delimiter, array $attributes)
    {
        $this->client = $client;

        $this->events['message'] = $client->getDefaultEvents('message');
        $this->events['folder'] = $client->getDefaultEvents('folder');

        $this->setDelimiter($delimiter);
        $this->path = $folderPath;
        $this->full_name = $this->decodeName($folderPath);
        $this->name = $this->getSimpleName($this->delimiter, $this->full_name);

        $this->parseAttributes($attributes);
    }

    /**
     * Get a new search query instance
     * @param string[] $extensions
     *
     * @return WhereQuery
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function query(array $extensions = []): WhereQuery
    {
        $this->getClient()->checkConnection();
        $this->getClient()->openFolder($this->path);
        $extensions = count($extensions) > 0 ? $extensions : $this->getClient()->extensions;

        return new WhereQuery($this->getClient(), $extensions);
    }

    /**
     * Get a new search query instance
     * @param string[] $extensions
     *
     * @return WhereQuery
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function search(array $extensions = []): WhereQuery
    {
        return $this->query($extensions);
    }

    /**
     * Get a new search query instance
     * @param string[] $extensions
     *
     * @return WhereQuery
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function messages(array $extensions = []): WhereQuery
    {
        return $this->query($extensions);
    }

    /**
     * Determine if folder has children.
     *
     * @return bool
     */
    public function hasChildren(): bool
    {
        return $this->has_children;
    }

    /**
     * Set children.
     * @param FolderCollection $children
     *
     * @return self
     */
    public function setChildren(FolderCollection $children): Folder {
        $this->children = $children;

        return $this;
    }

    /**
     * Decode name.
     * It converts UTF7-IMAP encoding to UTF-8.
     *
     * @return array|false|string|string[]|null
     */
    protected function decodeName(string $name)
    {
        return mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
    }

    /**
     * Get simple name (without parent folders).
     */
    protected function getSimpleName(string $delimiter, string $full_name): string|bool
    {
        $arr = explode($delimiter, $full_name);

        return end($arr);
    }

    /**
     * Parse attributes and set it to object properties.
     * @param $attributes
     */
    protected function parseAttributes($attributes)
    {
        $this->no_inferiors = in_array('\NoInferiors', $attributes);
        $this->no_select = in_array('\NoSelect', $attributes);
        $this->marked = in_array('\Marked', $attributes);
        $this->referral = in_array('\Referral', $attributes);
        $this->has_children = in_array('\HasChildren', $attributes);
    }

    /**
     * Move or rename the current folder
     * @param string $new_name
     * @param boolean $expunge
     *
     * @return bool
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function move(string $new_name, bool $expunge = true): bool
    {
        $this->client->checkConnection();
        $status = $this->client->getConnection()->renameFolder($this->full_name, $new_name);
        if ($expunge) {
            $this->client->expunge();
        }

        $folder = $this->client->getFolder($new_name);
        $event = $this->getEvent('folder', 'moved');
        $event::dispatch($this, $folder);

        return $status;
    }

    /**
     * Get a message overview
     * @param string|null $sequence uid sequence
     *
     * @return array
     * @throws ConnectionFailedException
     * @throws Exceptions\InvalidMessageDateException
     * @throws Exceptions\MessageNotFoundException
     * @throws Exceptions\RuntimeException
     */
    public function overview(string $sequence = null): array
    {
        $this->client->openFolder($this->path);
        $sequence ??= '1:*';
        $uid = ClientManager::get('options.sequence', IMAP::ST_MSGN) == IMAP::ST_UID;

        return $this->client->getConnection()->overview($sequence, $uid);
    }

    /**
     * Append a string message to the current mailbox
     * @param string $message
     * @param array|null $options
     * @param string|null|Carbon $internal_date
     *
     * @return bool
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function appendMessage(string $message, array $options = null, $internal_date = null): bool
    {
        /**
         * Check if $internal_date is parsed. If it is null it should not be set. Otherwise, the message can't be stored.
         * If this parameter is set, it will set the INTERNALDATE on the appended message. The parameter should be a
         * date string that conforms to the rfc2060 specifications for a date_time value or be a Carbon object.
         */

        if ($internal_date instanceof Carbon) {
            $internal_date = $internal_date->format('d-M-Y H:i:s O');
        }

        return $this->client->getConnection()->appendMessage($this->path, $message, $options, $internal_date);
    }

    public function saveMessage(int $uuid, string $filename = 'email.eml'): void
    {
        $stream = fopen($filename, 'wb');
        if (false === $stream) {
            throw new RuntimeException('cannot write email stream.');
        }
        $this->client->getConnection()->saveMessage($uuid, $stream);
        fclose($stream);
    }

    /**
     * Rename the current folder
     * @param string $new_name
     * @param boolean $expunge
     *
     * @return bool
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\FolderFetchingException
     * @throws Exceptions\RuntimeException
     */
    public function rename(string $new_name, bool $expunge = true): bool
    {
        return $this->move($new_name, $expunge);
    }

    /**
     * Delete the current folder
     * @param boolean $expunge
     *
     * @return bool
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     * @throws Exceptions\EventNotFoundException
     */
    public function delete(bool $expunge = true): bool
    {
        $status = $this->client->getConnection()->deleteFolder($this->path);
        if ($expunge) {
            $this->client->expunge();
        }

        $event = $this->getEvent('folder', 'deleted');
        $event::dispatch($this);

        return $status;
    }

    /**
     * Subscribe the current folder
     *
     * @return bool
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function subscribe(): bool
    {
        $this->client->openFolder($this->path);

        return $this->client->getConnection()->subscribeFolder($this->path);
    }

    /**
     * Unsubscribe the current folder
     *
     * @return bool
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function unsubscribe(): bool
    {
        $this->client->openFolder($this->path);

        return $this->client->getConnection()->unsubscribeFolder($this->path);
    }

    /**
     * Idle the current connection
     * @param callable $callback
     * @param integer $timeout max 1740 seconds - recommended by rfc2177 §3. Should not be lower than the servers "* OK Still here" message interval
     * @param boolean $auto_reconnect try to reconnect on connection close (@deprecated is no longer required)
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\InvalidMessageDateException
     * @throws Exceptions\MessageContentFetchingException
     * @throws Exceptions\MessageHeaderFetchingException
     * @throws Exceptions\RuntimeException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\MessageFlagException
     * @throws Exceptions\MessageNotFoundException
     * @throws Exceptions\NotSupportedCapabilityException
     */
    public function idle(callable $callback, int $timeout = 300, bool $auto_reconnect = false)
    {
        $this->client->setTimeout($timeout);
        if (!in_array('IDLE', $this->client->getConnection()->getCapabilities(), true)) {
            throw new NotSupportedCapabilityException('IMAP server does not support IDLE');
        }
        $this->client->openFolder($this->path, true);
        $connection = $this->client->getConnection();
        $connection->idle();

        $sequence = ClientManager::get('options.sequence', IMAP::ST_MSGN);

        while (true) {
            try {
                // This polymorphic call is fine - Protocol::idle() will throw an exception beforehand
                $line = $connection->nextLine();

                if (($pos = strpos($line, 'EXISTS')) !== false) {
                    $connection->done();
                    $msgn = (int)substr($line, 2, $pos - 2);

                    $this->client->openFolder($this->path, true);
                    $message = $this->query()->getMessageByMsgn($msgn);
                    $message->setSequence($sequence);
                    $callback($message);

                    $event = $this->getEvent('message', 'new');
                    $event::dispatch($message);
                    $connection->idle();
                } elseif (!str_contains($line, 'OK')) {
                    $connection->done();
                    $connection->idle();
                }
            } catch (Exceptions\RuntimeException $e) {
                if (strpos($e->getMessage(), 'empty response') >= 0 && $connection->connected()) {
                    $connection->done();
                    $connection->idle();
                    continue;
                }
                if (!str_contains($e->getMessage(), 'connection closed')) {
                    throw $e;
                }

                $this->client->reconnect();
                $this->client->openFolder($this->path, true);

                $connection = $this->client->getConnection();
                $connection->idle();
            }
        }
    }

    /**
     * Get folder status information
     *
     * @return array
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function getStatus(): array
    {
        return $this->examine();
    }

    /**
     * @throws RuntimeException
     * @throws ConnectionFailedException
     */
    public function loadStatus(): Folder
    {
        $this->status = $this->getStatus();

        return $this;
    }

    /**
     * Examine the current folder
     *
     * @return array
     * @throws Exceptions\ConnectionFailedException
     * @throws Exceptions\RuntimeException
     */
    public function examine(): array
    {
        $result = $this->client->getConnection()->examineFolder($this->path);

        return is_array($result) ? $result : [];
    }

    /**
     * Get the current Client instance
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Set the delimiter
     * @param $delimiter
     */
    public function setDelimiter($delimiter)
    {
        if (in_array($delimiter, [null, '', ' ', false]) === true) {
            $delimiter = ClientManager::get('options.delimiter', '/');
        }

        $this->delimiter = $delimiter;
    }
}
