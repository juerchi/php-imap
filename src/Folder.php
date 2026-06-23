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

use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\NotSupportedCapabilityException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Traits\HasEvents;

final class Folder
{
    use HasEvents;

    /**
     * Folder full path.
     */
    public string $path;

    /**
     * Folder name.
     */
    public string $name;

    /**
     * Folder fullname.
     */
    public string $fullName;

    /**
     * Children folders.
     */
    public FolderCollection $children;

    /**
     * Delimiter for folder.
     */
    public string $delimiter;

    /**
     * Indicates if the folder can't contain any "children".
     * CreateFolder won't work on this folder.
     */
    public bool $noInferiors;

    /**
     * Indicates if the folder is only a container, not a mailbox - you can't open it.
     */
    public bool $noSelect;

    /**
     * Indicates if the folder is marked. This means that it may contain new messages since the last time it was checked.
     * Not provided by all IMAP servers.
     */
    public bool $marked;

    /**
     * Indicates if the folder contains any "children".
     * Not provided by all IMAP servers.
     */
    public bool $hasChildren;

    /**
     * Indicates if the folder refers to others.
     * Not provided by all IMAP servers.
     */
    public bool $referral;

    public array $status;

    /**
     * Client instance.
     */
    private Client $client {
        get {
            return $this->client;
        }
    }

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
        $this->fullName = $this->decodeName($folderPath);
        $this->name = $this->getSimpleName($this->delimiter, $this->fullName);

        $this->parseAttributes($attributes);
    }

    /**
     * Get a new search query instance.
     *
     * @param string[] $extensions
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function query(array $extensions = []): WhereQuery
    {
        $this->client->checkConnection();
        $this->client->openFolder($this->path);
        $extensions = count($extensions) > 0 ? $extensions : $this->client->extensions;

        return new WhereQuery($this->client, $extensions);
    }

    /**
     * Get a new search query instance.
     *
     * @param string[] $extensions
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function search(array $extensions = []): WhereQuery
    {
        return $this->query($extensions);
    }

    /**
     * Get a new search query instance.
     *
     * @param string[] $extensions
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function messages(array $extensions = []): WhereQuery
    {
        return $this->query($extensions);
    }

    /**
     * Determine if the folder has children.
     */
    public function hasChildren(): bool
    {
        return $this->hasChildren;
    }

    /**
     * Set children.
     *
     * @return $this
     */
    public function setChildren(FolderCollection $children): self
    {
        $this->children = $children;

        return $this;
    }

    /**
     * Move or rename the current folder.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\FolderFetchingException
     * @throws RuntimeException
     */
    public function move(string $newName, bool $expunge = true): bool
    {
        $this->client->checkConnection();
        $status = $this->client->getConnection()->renameFolder($this->fullName, $newName);
        if ($expunge) {
            $this->client->expunge();
        }

        $folder = $this->client->getFolder($newName);
        $event = $this->getEvent('folder', 'moved');
        $event::dispatch($this, $folder);

        return $status;
    }

    /**
     * Get a message overview.
     *
     * @param null|string $sequence uid sequence
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\InvalidMessageDateException
     * @throws Exceptions\MessageNotFoundException
     * @throws RuntimeException
     */
    public function overview(?string $sequence = null): array
    {
        $this->client->openFolder($this->path);
        $sequence ??= '1:*';
        $sequenceType = IMAP::ST_UID === ClientManager::get('options.sequence', IMAP::ST_MSGN) ? IMAP::ST_UID : IMAP::ST_MSGN;

        return $this->client->getConnection()->overview($sequence, $sequenceType);
    }

    /**
     * Append a string message to the current mailbox.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function appendMessage(string $message, ?array $options = null, \DateTimeInterface|string|null $internalDate = null): bool
    {
        /*
         * Check if $internal_date is parsed. If it is null, it should not be set. Otherwise, the message can't be stored.
         * If this parameter is set, it will set the INTERNALDATE on the appended message. The parameter should be a
         * date string that conforms to the rfc2060 specifications for a date_time value or be a DateTimeInterface object.
         */

        if ($internalDate instanceof \DateTimeInterface) {
            $internalDate = $internalDate->format('d-M-Y H:i:s O');
        }

        return $this->client->getConnection()->appendMessage($this->path, $message, $options, $internalDate);
    }

    /**
     * @throws RuntimeException
     * @throws ConnectionFailedException
     */
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
     * Rename the current folder.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\FolderFetchingException
     * @throws RuntimeException
     */
    public function rename(string $newName, bool $expunge = true): bool
    {
        return $this->move($newName, $expunge);
    }

    /**
     * Delete the current folder.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
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
     * Subscribe to the current folder.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function subscribe(): bool
    {
        $this->client->openFolder($this->path);

        return $this->client->getConnection()->subscribeFolder($this->path);
    }

    /**
     * Unsubscribe the current folder.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function unsubscribe(): bool
    {
        $this->client->openFolder($this->path);

        return $this->client->getConnection()->unsubscribeFolder($this->path);
    }

    /**
     * Idle the current connection.
     *
     * @param int  $timeout       max 1740 seconds - recommended by rfc2177 §3. Should not be lower than the servers "* OK Still here" message interval
     * @param bool $autoReconnect try to reconnect on connection close (@deprecated is no longer required)
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\InvalidMessageDateException
     * @throws Exceptions\MessageContentFetchingException
     * @throws Exceptions\MessageHeaderFetchingException
     * @throws RuntimeException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\MessageFlagException
     * @throws Exceptions\MessageNotFoundException
     * @throws NotSupportedCapabilityException
     */
    public function idle(callable $callback, int $timeout = 300, bool $autoReconnect = false): void
    {
        $this->client->setTimeout($timeout);
        if (!\in_array('IDLE', $this->client->getConnection()->getCapabilities(), true)) {
            throw new NotSupportedCapabilityException('IMAP server does not support IDLE');
        }
        $this->client->openFolder($this->path, true);
        $connection = $this->client->getConnection();
        assert($connection instanceof ImapProtocol);
        $connection->idle();

        $sequence = ClientManager::get('options.sequence', IMAP::ST_MSGN);

        while (true) {
            try {
                assert($connection instanceof ImapProtocol);
                $line = $connection->nextLine();

                if (($pos = strpos($line, 'EXISTS')) !== false) {
                    $connection->done();
                    $msgn = (int) substr($line, 2, $pos - 2);

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
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'empty response') && $connection->connected()) {
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
     * Get folder status information.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function getStatus(): array
    {
        return $this->examine();
    }

    /**
     * @throws RuntimeException
     * @throws ConnectionFailedException
     */
    public function loadStatus(): self
    {
        $this->status = $this->getStatus();

        return $this;
    }

    /**
     * Examine the current folder.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function examine(): array
    {
        $result = $this->client->getConnection()->examineFolder($this->path);

        return is_array($result) ? $result : [];
    }

    /**
     * Set the delimiter.
     */
    public function setDelimiter(false|string|null $delimiter): void
    {
        if (in_array($delimiter, [null, '', ' ', false], true)) {
            $delimiter = ClientManager::get('options.delimiter', '/');
        }

        $this->delimiter = $delimiter;
    }

    /**
     * Decode name.
     *
     * It converts UTF7-IMAP encoding to UTF-8.
     */
    protected function decodeName(string $name): string
    {
        return mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
    }

    /**
     * Get a simple name (without parent folders).
     */
    protected function getSimpleName(string $delimiter, string $fullName): string
    {
        $arr = explode($delimiter, $fullName);

        return array_last($arr);
    }

    /**
     * Parse attributes and set it to object properties.
     *
     * @param string[] $attributes
     */
    protected function parseAttributes(array $attributes): void
    {
        $this->noInferiors = \in_array('\NoInferiors', $attributes, true);
        $this->noSelect = \in_array('\NoSelect', $attributes, true);
        $this->marked = \in_array('\Marked', $attributes, true);
        $this->referral = \in_array('\Referral', $attributes, true);
        $this->hasChildren = \in_array('\HasChildren', $attributes, true);
    }
}
