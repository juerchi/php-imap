<?php

/*
* File:     Query.php
* Category: -
* Author:   M. Goldenbaum
* Created:  21.07.18 18:54
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Query;

use Doctrine\Common\Collections\ArrayCollection;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\EventNotFoundException;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageFlagException;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\MessageSearchValidationException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;

class Query
{
    protected ArrayCollection $query;

    protected string $rawQuery;

    /** @var string[] */
    protected array $extensions;

    protected Client $client;

    protected ?int $limit = null;

    protected int $page = 1;

    protected ?int $fetchOptions = null;

    protected bool $fetchBody = true;

    protected bool $fetchFlags = true;

    protected int|string $sequence = IMAP::NIL;

    protected string $fetchOrder;

    protected string $dateFormat;

    protected bool $softFail = false;

    /** @var array<\Exception> */
    protected array $errors = [] {
        get {
            return $this->errors;
        }
    }

    /**
     * @param string[] $extensions
     */
    public function __construct(Client $client, array $extensions = [])
    {
        $this->setClient($client);

        $this->sequence = ClientManager::get('options.sequence', IMAP::ST_MSGN);
        if (IMAP::FT_PEEK === ClientManager::get('options.fetch')) {
            $this->leaveUnread();
        }

        if ('desc' === ClientManager::get('options.fetchOrder')) {
            $this->fetchOrder = 'desc';
        } else {
            $this->fetchOrder = 'asc';
        }

        $this->dateFormat = ClientManager::get('date_format', 'd M y');
        $this->softFail = ClientManager::get('options.soft_fail', false);

        $this->setExtensions($extensions);
        $this->query = new ArrayCollection();
        $this->boot();
    }

    /**
     * Get the raw IMAP search query.
     */
    public function generate_query(): string
    {
        $query = '';
        foreach ($this->query as $statement) {
            if (1 === count($statement)) {
                $query .= $statement[0];
            } elseif (null === $statement[1]) {
                $query .= $statement[0];
            } elseif (is_numeric($statement[1])) {
                $query .= $statement[0].' '.$statement[1];
            } else {
                $query .= $statement[0].' "'.$statement[1].'"';
            }
            $query .= ' ';
        }

        $this->rawQuery = trim($query);

        return $this->rawQuery;
    }

    /**
     * Count all available messages matching the current search criteria.
     *
     * @throws GetMessagesFailedException
     */
    public function count(): int
    {
        return $this->search()->count();
    }

    /**
     * Curates a given collection of messages.
     *
     * @param ArrayCollection<int, int> $availableMessages
     *
     * @throws GetMessagesFailedException
     */
    public function curate_messages(ArrayCollection $availableMessages): MessageCollection
    {
        try {
            if (!$availableMessages->isEmpty()) {
                return $this->populate($availableMessages);
            }

            return new MessageCollection();
        } catch (\Exception $e) {
            throw new GetMessagesFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Fetch the current query and return all found messages.
     *
     * @throws GetMessagesFailedException
     */
    public function get(): MessageCollection
    {
        return $this->curate_messages($this->search());
    }

    /**
     * Fetch the current query as chunked requests.
     *
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws GetMessagesFailedException
     * @throws \ReflectionException
     * @throws RuntimeException
     */
    public function chunked(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $availableMessages = $this->search();
        $availableMessagesCount = $availableMessages->count();
        if ($availableMessagesCount > 0) {
            $oldLimit = $this->limit;
            $oldPage = $this->page;

            $this->limit = $chunkSize;
            $this->page = $startChunk;
            $handledMessagesCount = 0;
            do {
                $messages = $this->populate($availableMessages);
                $handledMessagesCount += $messages->count();
                $callback($messages, $this->page);
                ++$this->page;
            } while ($handledMessagesCount < $availableMessagesCount);
            $this->limit = $oldLimit;
            $this->page = $oldPage;
        }
    }

    /**
     * Get a new Message instance.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageHeaderFetchingException
     * @throws EventNotFoundException
     * @throws MessageFlagException
     * @throws MessageNotFoundException
     */
    public function getMessage(int $uid, ?int $msglist = null, int|string|null $sequence = null): Message
    {
        return new Message($uid, $msglist, $this->getClient(), $this->getFetchOptions(), $this->getFetchBody(), $this->getFetchFlags(), $sequence ?: $this->sequence);
    }

    /**
     * Get a message by its message number.
     *
     * @throws ConnectionFailedException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageHeaderFetchingException
     * @throws RuntimeException
     * @throws EventNotFoundException
     * @throws MessageFlagException
     * @throws MessageNotFoundException
     */
    public function getMessageByMsgn(mixed $msgn, ?int $msglist = null): Message
    {
        return $this->getMessage($msgn, $msglist, IMAP::ST_MSGN);
    }

    /**
     * Get a message by its uid.
     *
     * @throws ConnectionFailedException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageHeaderFetchingException
     * @throws RuntimeException
     * @throws EventNotFoundException
     * @throws MessageFlagException
     * @throws MessageNotFoundException
     */
    public function getMessageByUid(mixed $uid): Message
    {
        return $this->getMessage($uid, null, IMAP::ST_UID);
    }

    /**
     * Filter all available uids by a given closure and get a curated list of messages.
     *
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageNotFoundException
     */
    public function filter(callable $closure): MessageCollection
    {
        $connection = $this->getClient()->getConnection();

        $uids = $connection->getUid();
        $availableMessages = new ArrayCollection();
        if (\is_array($uids)) {
            foreach ($uids as $uid) {
                if ($closure($uid)) {
                    $availableMessages->add($uid);
                }
            }
        }

        return $this->curate_messages($availableMessages);
    }

    /**
     * Get all messages with an uid greater or equal to a given UID.
     *
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageNotFoundException
     */
    public function getByUidGreaterOrEqual(int $uid): MessageCollection
    {
        return $this->filter(function ($id) use ($uid) {
            return $id >= $uid;
        });
    }

    /**
     * Get all messages with an uid greater than a given UID.
     *
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageNotFoundException
     */
    public function getByUidGreater(int $uid): MessageCollection
    {
        return $this->filter(function ($id) use ($uid) {
            return $id > $uid;
        });
    }

    /**
     * Get all messages with an uid lower than a given UID.
     *
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageNotFoundException
     */
    public function getByUidLower(int $uid): MessageCollection
    {
        return $this->filter(function ($id) use ($uid) {
            return $id < $uid;
        });
    }

    /**
     * Get all messages with an uid lower or equal to a given UID.
     *
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageNotFoundException
     */
    public function getByUidLowerOrEqual(int $uid): MessageCollection
    {
        return $this->filter(function ($id) use ($uid) {
            return $id <= $uid;
        });
    }

    /**
     * Get all messages with an uid lower than or equal to a given UID.
     *
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageNotFoundException
     */
    public function getByUidLowerThan(int $uid): MessageCollection
    {
        return $this->filter(function ($id) use ($uid) {
            return $id <= $uid;
        });
    }

    /**
     * Don't mark messages as read when fetching.
     *
     * @return $this
     */
    public function leaveUnread(): self
    {
        $this->setFetchOptions(IMAP::FT_PEEK);

        return $this;
    }

    /**
     * Mark all messages as read when fetching.
     *
     * @return $this
     */
    public function markAsRead(): self
    {
        $this->setFetchOptions(IMAP::FT_UID);

        return $this;
    }

    /**
     * Set the sequence type.
     *
     * @return $this
     */
    public function setSequence(int|string $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    /**
     * Get the sequence type.
     */
    public function getSequence(): int|string
    {
        return $this->sequence;
    }

    /**
     * @throws ConnectionFailedException
     */
    public function getClient(): Client
    {
        $this->client->checkConnection();

        return $this->client;
    }

    /**
     * Set the limit and page for the current query.
     *
     * @return $this
     */
    public function limit(int $limit, int $page = 1): self
    {
        if ($page >= 1) {
            $this->page = $page;
        }
        $this->limit = $limit;

        return $this;
    }

    public function getQuery(): ArrayCollection
    {
        return $this->query;
    }

    public function setQuery(array $query): self
    {
        $this->query = new ArrayCollection($query);

        return $this;
    }

    public function getRawQuery(): string
    {
        return $this->rawQuery;
    }

    /**
     * @return $this
     */
    public function setRawQuery(string $rawQuery): self
    {
        $this->rawQuery = $rawQuery;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * @param string[] $extensions
     *
     * @return $this
     */
    public function setExtensions(array $extensions): self
    {
        $this->extensions = $extensions;
        if ([] !== $this->extensions && !\in_array('UID', $this->extensions, true)) {
            $this->extensions[] = 'UID';
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function setClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Get the set fetch limit.
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): self
    {
        $this->limit = $limit <= 0 ? null : $limit;

        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * @return $this
     */
    public function setPage(int $page): self
    {
        $this->page = $page;

        return $this;
    }

    /**
     * @return $this
     */
    public function setFetchOptions(int $fetchOptions): self
    {
        $this->fetchOptions = $fetchOptions;

        return $this;
    }

    /**
     * @return $this
     */
    public function fetchOptions(int $fetchOptions): self
    {
        return $this->setFetchOptions($fetchOptions);
    }

    public function getFetchOptions(): ?int
    {
        return $this->fetchOptions;
    }

    public function getFetchBody(): bool
    {
        return $this->fetchBody;
    }

    /**
     * @return $this
     */
    public function setFetchBody(bool $fetchBody): self
    {
        $this->fetchBody = $fetchBody;

        return $this;
    }

    /**
     * @return $this
     */
    public function fetchBody(bool $fetchBody): self
    {
        return $this->setFetchBody($fetchBody);
    }

    public function getFetchFlags(): bool
    {
        return $this->fetchFlags;
    }

    /**
     * @return $this
     */
    public function setFetchFlags(bool $fetchFlags): self
    {
        $this->fetchFlags = $fetchFlags;

        return $this;
    }

    /**
     * @return $this
     */
    public function setFetchOrder(string $fetchOrder): self
    {
        $fetchOrder = strtolower($fetchOrder);

        if (in_array($fetchOrder, ['asc', 'desc'])) {
            $this->fetchOrder = $fetchOrder;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function fetchOrder(string $fetchOrder): self
    {
        return $this->setFetchOrder($fetchOrder);
    }

    public function getFetchOrder(): string
    {
        return $this->fetchOrder;
    }

    /**
     * @return $this
     */
    public function setFetchOrderAsc(): self
    {
        return $this->setFetchOrder('asc');
    }

    /**
     * @return $this
     */
    public function fetchOrderAsc(): self
    {
        return $this->setFetchOrderAsc();
    }

    /**
     * @return $this
     */
    public function setFetchOrderDesc(): self
    {
        return $this->setFetchOrder('desc');
    }

    /**
     * @return $this
     */
    public function fetchOrderDesc(): self
    {
        return $this->setFetchOrderDesc();
    }

    /**
     * @return $this
     */
    public function softFail(bool $state = true): self
    {
        return $this->setSoftFail($state);
    }

    /**
     * @return $this
     */
    public function setSoftFail(bool $state = true): self
    {
        $this->softFail = $state;

        return $this;
    }

    public function getSoftFail(): bool
    {
        return $this->softFail;
    }

    /**
     * Check if there are any errors / exceptions present.
     */
    public function hasErrors(?int $uid = null): bool
    {
        if (null !== $uid) {
            return $this->hasError($uid);
        }

        return [] !== $this->errors;
    }

    /**
     * Check if there is an error / exception present.
     */
    public function hasError(int $uid): bool
    {
        return isset($this->errors[$uid]);
    }

    /**
     * Get all available errors / exceptions.
     *
     * @return array<int, \Exception>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get a specific error / exception.
     */
    public function error(int $uid): ?\Exception
    {
        return $this->getError($uid);
    }

    /**
     * Get a specific error / exception.
     */
    public function getError(int $uid): ?\Exception
    {
        return $this->errors[$uid] ?? null;
    }

    /**
     * Instance boot method for additional functionality.
     */
    protected function boot(): void {}

    protected function parse_value(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format($this->dateFormat);
        }

        return (string) $value;
    }

    /**
     * Check if a given date is a valid DateTime object and if not try to convert it.
     *
     * @throws MessageSearchValidationException
     */
    protected function parse_date(\DateTimeInterface|string $date): \DateTimeImmutable
    {
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        if ($date instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($date);
        }

        try {
            return new \DateTimeImmutable($date);
        } catch (\DateMalformedStringException) {
            throw new MessageSearchValidationException('invalid date');
        }
    }

    /**
     * Perform an imap search request.
     *
     * @return ArrayCollection<int, int>
     *
     * @throws GetMessagesFailedException
     */
    protected function search(): ArrayCollection
    {
        $this->generate_query();

        try {
            $availableMessages = $this->client->getConnection()->search([$this->getRawQuery()], $this->sequence);

            return new ArrayCollection($availableMessages);
        } catch (ConnectionFailedException|RuntimeException $e) {
            throw new GetMessagesFailedException('failed to fetch messages', 0, $e);
        }
    }

    /**
     * Fetch a given id collection.
     *
     * @param ArrayCollection<int, int> $availableMessages
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    protected function fetch(ArrayCollection $availableMessages): array
    {
        $messageArray = $availableMessages->toArray();
        if ('desc' === $this->fetchOrder) {
            $messageArray = array_reverse($messageArray, true);
        }
        if (null !== $this->limit) {
            $offset = $this->page * $this->limit - $this->limit;
            $uids = \array_slice($messageArray, $offset, $this->limit);
        } else {
            $uids = $messageArray;
        }

        $extensions = $this->getExtensions();
        if ([] !== $extensions && method_exists($this->client->getConnection(), 'fetch')) {
            $extensions = $this->client->getConnection()->fetch($extensions, $uids, null, $this->sequence);
        }
        $flags = $this->client->getConnection()->flags($uids, $this->sequence);
        $headers = $this->client->getConnection()->headers($uids, 'RFC822', $this->sequence);

        $contents = [];
        if ($this->getFetchBody()) {
            $contents = $this->client->getConnection()->content($uids, 'RFC822', $this->sequence);
        }

        return [
            'uids' => $uids,
            'flags' => $flags,
            'headers' => $headers,
            'contents' => $contents,
            'extensions' => $extensions,
        ];
    }

    /**
     * Make a new message from given raw components.
     *
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws GetMessagesFailedException
     * @throws \ReflectionException
     */
    protected function make(int $uid, int $msglist, string $header, string $content, array $flags): ?Message
    {
        try {
            return Message::make($uid, $msglist, $this->getClient(), $header, $content, $flags, $this->getFetchOptions(), $this->sequence);
        } catch (InvalidMessageDateException|MessageContentFetchingException|MessageFlagException|MessageNotFoundException|RuntimeException $e) {
            $this->setError($uid, $e);
        }

        $this->handleException($uid);

        return null;
    }

    /**
     * Get the message key for a given message.
     */
    protected function getMessageKey(string $messageKey, int $msglist, Message $message): string
    {
        return (string) match ($messageKey) {
            'number' => $message->getMessageNo(),
            'list' => $msglist,
            'uid' => $message->getUid(),
            default => $message->getMessageId(),
        };
    }

    /**
     * Populate a given id collection and receive a fully fetched message collection.
     *
     * @param ArrayCollection<int, int> $availableMessages
     *
     * @throws ConnectionFailedException
     * @throws EventNotFoundException
     * @throws GetMessagesFailedException
     * @throws \ReflectionException
     * @throws RuntimeException
     */
    protected function populate(ArrayCollection $availableMessages): MessageCollection
    {
        $messages = new MessageCollection();
        $messageKey = ClientManager::get('options.message_key');

        $rawMessages = $this->fetch($availableMessages);

        $msglist = 0;
        foreach ($rawMessages['headers'] as $uid => $header) {
            $content = $rawMessages['contents'][$uid] ?? '';
            $flag = $rawMessages['flags'][$uid] ?? [];
            $extensions = $rawMessages['extensions'][$uid] ?? [];

            $message = $this->make($uid, $msglist, $header, $content, $flag);
            if (null !== $message) {
                foreach ($extensions as $key => $extension) {
                    $message->header->set($key, $extension);
                }
                $key = $this->getMessageKey($messageKey, $msglist, $message);
                $messages->set($key, $message);
            }
            ++$msglist;
        }

        return $messages;
    }

    /**
     * Handle the exception for a given uid.
     *
     * @throws GetMessagesFailedException
     */
    protected function handleException(int $uid): void
    {
        if (false === $this->softFail && $this->hasError($uid)) {
            /** @var \Exception $error */
            $error = $this->getError($uid);

            throw new GetMessagesFailedException($error->getMessage(), 0, $error);
        }
    }

    /**
     * Add a new error to the error holder.
     */
    protected function setError(int $uid, \Exception $error): void
    {
        $this->errors[$uid] = $error;
    }
}
