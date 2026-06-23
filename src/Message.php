<?php

/*
* File:     Message.php
* Category: -
* Author:   M. Goldenbaum
* Created:  19.01.17 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Symfony\Component\String\UnicodeString;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageFlagException;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\MessageSizeFetchingException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Support\AttachmentCollection;
use Webklex\PHPIMAP\Support\FlagCollection;
use Webklex\PHPIMAP\Support\Masks\MessageMask;
use Webklex\PHPIMAP\Support\MessageCollection;
use Webklex\PHPIMAP\Traits\HasEvents;

/**
 * Class Message.
 *
 * @property null|int  $msglist
 * @property int       $uid
 * @property int       $msgn
 * @property int       $size
 * @property Attribute $subject
 * @property Attribute $messageId
 * @property Attribute $message_no
 * @property Attribute $references
 * @property Attribute $date
 * @property Attribute $from
 * @property Attribute $to
 * @property Attribute $cc
 * @property Attribute $bcc
 * @property Attribute $reply_to
 * @property Attribute $inReplyTo
 * @property Attribute $sender
 *
 * @method int       getMsglist()
 * @method int       setMsglist(?int $msgList)
 * @method int       getUid()
 * @method int       getMsgn()
 * @method int       getSize()
 * @method Attribute getPriority()
 * @method Attribute getSubject()
 * @method Attribute getMessageId()
 * @method Attribute getMessageNo()
 * @method Attribute getReferences()
 * @method Attribute getDate()
 * @method Attribute getFrom()
 * @method Attribute getTo()
 * @method Attribute getCc()
 * @method Attribute getBcc()
 * @method Attribute getReplyTo()
 * @method Attribute getInReplyTo()
 * @method Attribute getSender()
 */
class Message
{
    use HasEvents;

    /**
     * Message body components.
     */
    public private(set) array $bodies = [];

    public AttachmentCollection $attachments;

    public FlagCollection $flags;

    /**
     * Default mask.
     */
    protected string $mask = MessageMask::class;

    /**
     * Used config.
     */
    protected array $config = [];

    /**
     * Attribute holder — values are mostly Attribute objects, but primitive
     * scalars (e.g. int for "size", int|null for "uid"/"msgn") are also stored.
     *
     * @var array<string, null|Attribute|int>
     */
    protected array $attributes = [];

    /**
     * The message folder path.
     */
    protected string $folderPath;

    /**
     * Fetch body options.
     */
    protected ?int $fetchOptions = null {
        get {
            return $this->fetchOptions;
        }
    }

    protected int $sequence = IMAP::NIL;

    /**
     * Fetch body options.
     */
    protected bool $fetchBody = true;

    /**
     * Fetch flags options.
     */
    protected bool $fetchFlags = true;

    public ?Header $header = null {
        get {
            return $this->header;
        }
    }

    /**
     * Raw message body.
     */
    protected ?string $rawBody = null;

    protected ?Structure $structure = null {
        get {
            return $this->structure;
        }
    }

    /**
     * Client instance.
     */
    private Client $client;

    /**
     * A list of all available and supported flags.
     */
    private ?array $availableFlags = null;

    /**
     * @throws ConnectionFailedException
     * @throws InvalidMessageDateException
     * @throws RuntimeException
     * @throws MessageHeaderFetchingException
     * @throws MessageContentFetchingException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws Exceptions\MessageNotFoundException
     */
    public function __construct(
        int $uid,
        ?int $msglist,
        Client $client,
        ?int $fetchOptions = null,
        bool $fetchBody = false,
        bool $fetchFlags = false,
        ?int $sequence = null
    ) {
        $this->boot();

        $defaultMask = $client->getDefaultMessageMask();
        if ('' !== $defaultMask) {
            $this->mask = $defaultMask;
        }
        $this->events['message'] = $client->getDefaultEvents('message');
        $this->events['flag'] = $client->getDefaultEvents('flag');

        $this->folderPath = $client->getFolderPath();

        $this->setSequence($sequence);
        $this->setFetchOption($fetchOptions);
        $this->setFetchBodyOption($fetchBody);
        $this->setFetchFlagsOption($fetchFlags);

        $this->client = $client;
        $this->client->openFolder($this->folderPath);

        $this->setSequenceId($uid, $msglist);

        if (IMAP::FT_PEEK === $this->fetchOptions) {
            $this->parseFlags();
        }

        $this->parseHeader();

        if ($this->getFetchBodyOption()) {
            $this->parseBody();
        }

        if ($this->getFetchFlagsOption() && IMAP::FT_PEEK !== $this->fetchOptions) {
            $this->parseFlags();
        }
    }

    /**
     * Call dynamic attribute setter and getter methods.
     *
     * @throws MethodNotFoundException
     */
    public function __call(string $method, array $arguments): Attribute|int|static
    {
        if (0 === stripos($method, 'get')) {
            $name = new UnicodeString(substr($method, 3))->camel()->toString();

            return $this->get($name);
        }

        if (0 === stripos($method, 'set')) {
            $name = new UnicodeString(substr($method, 3))->camel()->toString();
            if ($this->__isset($name)) {
                $this->__set($name, array_pop($arguments));

                return $this;
            }
        }

        throw new MethodNotFoundException('Method '.self::class.'::'.$method.'() is not supported');
    }

    public function __set(string $name, Attribute|int|null $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __get(string $name): Attribute|int
    {
        return $this->get($name);
    }

    /**
     * Create a new instance without fetching the message header and providing them raw instead.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws \ReflectionException
     * @throws MessageFlagException
     * @throws RuntimeException
     * @throws Exceptions\MessageNotFoundException
     */
    public static function make(
        int $uid,
        ?int $msglist,
        Client $client,
        string $rawHeader,
        string $rawBody,
        array $rawFlags,
        mixed $fetchOptions = null,
        mixed $sequence = null
    ): self {
        $reflection = new \ReflectionClass(self::class);

        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->boot();

        $defaultMask = $client->getDefaultMessageMask();
        if ('' !== $defaultMask) {
            $instance->setMask($defaultMask);
        }
        $instance->setEvents([
            'message' => $client->getDefaultEvents('message'),
            'flag' => $client->getDefaultEvents('flag'),
        ]);
        $instance->setFolderPath($client->getFolderPath());
        $instance->setSequence($sequence);
        $instance->setFetchOption($fetchOptions);

        $instance->setClient($client);
        $instance->setSequenceId($uid, $msglist);

        $instance->parseRawHeader($rawHeader);
        $instance->parseRawFlags($rawFlags);
        $instance->parseRawBody($rawBody);
        $instance->peek();

        return $instance;
    }

    /**
     * Boot a new instance.
     */
    public function boot(): void
    {
        $this->attributes = [];

        $this->config = ClientManager::get('options');
        $this->availableFlags = ClientManager::get('flags');

        $this->attachments = new AttachmentCollection();
        $this->flags = new FlagCollection();
    }

    /**
     * Get an available message or message header attribute.
     * @throws MessageNotFoundException
     * @throws ConnectionFailedException
     */
    public function get(string $name): Attribute|int
    {
        if (isset($this->attributes[$name])) {
            return $this->attributes[$name];
        }

        switch ($name) {
            case 'uid':
                $this->attributes[$name] = $this->client->getConnection()->getUid($this->msgn);

                return $this->attributes[$name];

            case 'msgn':
                $this->attributes[$name] = $this->client->getConnection()->getMessageNumber($this->uid);

                return $this->attributes[$name];

            case 'size':
                $this->fetchSize();

                return $this->attributes[$name];
        }

        return $this->header->get($name);
    }

    /**
     * Check if the Message has a text body.
     */
    public function hasTextBody(): bool
    {
        return isset($this->bodies['text']);
    }

    /**
     * Get the Message text body.
     */
    public function getTextBody(): mixed
    {
        return $this->bodies['text'] ?? null;
    }

    /**
     * Check if the Message has a HTML body.
     */
    public function hasHTMLBody(): bool
    {
        return isset($this->bodies['html']);
    }

    /**
     * Get the Message HTML body.
     */
    public function getHTMLBody(): ?string
    {
        return $this->bodies['html'] ?? null;
    }

    /**
     * @throws InvalidMessageDateException
     */
    public function parseRawHeader(string $rawHeader): void
    {
        $this->header = new Header($rawHeader);
    }

    /**
     * Parse additional raw flags.
     */
    public function parseRawFlags(array $rawFlags): void
    {
        $this->flags = new FlagCollection();

        foreach ($rawFlags as $flag) {
            if (str_starts_with($flag, '\\')) {
                $flag = substr($flag, 1);
            }
            $flagKey = strtolower($flag);
            if (null === $this->availableFlags || in_array($flagKey, $this->availableFlags, true)) {
                $this->flags->set($flagKey, $flag);
            }
        }
    }

    /**
     * Parse the Message body.
     *
     * @return $this
     *
     * @throws ConnectionFailedException
     * @throws MessageContentFetchingException
     * @throws InvalidMessageDateException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws RuntimeException
     */
    public function parseBody(): self
    {
        $this->client->openFolder($this->folderPath);

        $sequenceId = $this->getSequenceId();

        try {
            $contents = $this->client->getConnection()->content(
                [$sequenceId],
                'RFC822',
                $this->sequence
            );
        } catch (RuntimeException $e) {
            throw new MessageContentFetchingException('failed to fetch content', 0, $e);
        }
        if (!isset($contents[$sequenceId])) {
            throw new MessageContentFetchingException('no content found', 0);
        }
        $content = $contents[$sequenceId];

        $body = $this->parseRawBody($content);
        $this->peek();

        return $body;
    }

    /**
     * Handle auto "Seen" flag handling.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws RuntimeException
     */
    public function peek(): void
    {
        if (IMAP::FT_PEEK === $this->fetchOptions) {
            if (null === $this->getFlags()->get('seen')) {
                $this->unsetFlag('Seen');
            }
        } elseif (null !== $this->getFlags()->get('seen')) {
            $this->setFlag('Seen');
        }
    }

    /**
     * Parse a given message body.
     *
     * @return $this
     *
     * @throws ConnectionFailedException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws RuntimeException
     */
    public function parseRawBody(string $rawBody): self
    {
        $this->structure = new Structure($rawBody, $this->header);
        $this->fetchStructure($this->structure);

        return $this;
    }

    /**
     * Fail proof setter for $fetch_option.
     *
     * @return $this
     */
    public function setFetchOption(mixed $option): self
    {
        if (true === is_int($option)) {
            $this->fetchOptions = $option;
        } elseif (true === is_null($option)) {
            $config = ClientManager::get('options.fetch', IMAP::FT_UID);
            $this->fetchOptions = is_int($config) ? $config : 1;
        }

        return $this;
    }

    /**
     * Set the sequence type.
     *
     * @return $this
     */
    public function setSequence(?int $sequence): self
    {
        if (is_int($sequence)) {
            $this->sequence = $sequence;
        } elseif (is_null($sequence)) {
            $config = ClientManager::get('options.sequence', IMAP::ST_MSGN);
            $this->sequence = is_int($config) ? $config : IMAP::ST_MSGN;
        }

        return $this;
    }

    /**
     * Fail proof setter for $fetchBody.
     *
     * @return $this
     */
    public function setFetchBodyOption(mixed $option): self
    {
        if (is_bool($option)) {
            $this->fetchBody = $option;
        } elseif (is_null($option)) {
            $config = ClientManager::get('options.fetchBody', true);
            $this->fetchBody = is_bool($config) ? $config : true;
        }

        return $this;
    }

    /**
     * Fail proof setter for $fetchFlags.
     *
     * @return $this
     */
    public function setFetchFlagsOption(mixed $option): self
    {
        if (is_bool($option)) {
            $this->fetchFlags = $option;
        } elseif (is_null($option)) {
            $config = ClientManager::get('options.fetchFlags', true);
            $this->fetchFlags = is_bool($config) ? $config : true;
        }

        return $this;
    }

    /**
     * Decode a given string.
     *
     * @param int $encoding One of the IMAP::MESSAGE_ENC_* constants
     */
    public function decodeString(string $string, int $encoding): string
    {
        switch ($encoding) {
            case IMAP::MESSAGE_ENC_BINARY:
                if (extension_loaded('imap')) {
                    return base64_decode(\imap_binary($string));
                }

                return base64_decode($string);

            case IMAP::MESSAGE_ENC_BASE64:
                return base64_decode($string);

            case IMAP::MESSAGE_ENC_QUOTED_PRINTABLE:
                return quoted_printable_decode($string);

            case IMAP::MESSAGE_ENC_8BIT:
            case IMAP::MESSAGE_ENC_7BIT:
            case IMAP::MESSAGE_ENC_OTHER:
            default:
                return $string;
        }
    }

    /**
     * Convert the encoding.
     *
     * @return mixed|string
     */
    public function convertEncoding($str, string $from = 'ISO-8859-2', string $to = 'UTF-8'): mixed
    {
        $from = EncodingAliases::get($from);
        $to = EncodingAliases::get($to);

        if ($from === $to) {
            return $str;
        }

        // We don't need to do convertEncoding() if charset is ASCII (us-ascii):
        //     ASCII is a subset of UTF-8, so all ASCII files are already UTF-8 encoded
        //     https://stackoverflow.com/a/11303410
        //
        // us-ascii is the same as ASCII:
        //     ASCII is the traditional name for the encoding system; the Internet Assigned Numbers Authority (IANA)
        //     prefers the updated name US-ASCII, which clarifies that this system was developed in the US and
        //     based on the typographical symbols predominantly in use there.
        //     https://en.wikipedia.org/wiki/ASCII
        //
        // convertEncoding() function basically means convertToUtf8(), so when we convert ASCII string into UTF-8 it gets broken.
        if ('us-ascii' === strtolower($from) && 'UTF-8' === $to) {
            return $str;
        }

        if (function_exists('iconv') && 'UTF-7' !== $from && 'UTF-7' !== $to) {
            return @iconv($from, $to.'//IGNORE', $str);
        }

        if (!$from) {
            return mb_convert_encoding($str, $to);
        }

        return mb_convert_encoding($str, $to, $from);
    }

    /**
     * Get the encoding of a given abject.
     */
    public function getEncoding(object|string $structure): string
    {
        if (is_object($structure) && property_exists($structure, 'parameters')) {
            foreach ($structure->parameters as $parameter) {
                if ('charset' === strtolower($parameter->attribute)) {
                    return EncodingAliases::get($parameter->value, 'ISO-8859-2');
                }
            }
        } elseif (is_object($structure) && property_exists($structure, 'charset')) {
            return EncodingAliases::get($structure->charset, 'ISO-8859-2');
        } elseif (true === is_string($structure)) {
            return mb_detect_encoding($structure);
        }

        return 'UTF-8';
    }

    /**
     * Get the messages folder.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\FolderFetchingException
     * @throws RuntimeException
     */
    public function getFolder(): ?Folder
    {
        return $this->client->getFolderByPath($this->folderPath);
    }

    /**
     * Create a message thread based on the current message.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\FolderFetchingException
     * @throws Exceptions\GetMessagesFailedException
     * @throws RuntimeException
     */
    public function thread(
        ?Folder $sentFolder = null,
        ?MessageCollection &$thread = null,
        ?Folder $folder = null
    ): MessageCollection {
        $thread ??= new MessageCollection();
        $folder ??= $this->getFolder();
        $sentFolder = $sentFolder ?: $this->client->getFolderByPath(
            ClientManager::get('options.common_folders.sent', 'INBOX/Sent')
        );

        foreach ($thread as $message) {
            if ($message->messageId->first() === $this->messageId->first()) {
                return $thread;
            }
        }
        $thread->add($this);

        $this->fetchThreadByInReplyTo($thread, $this->messageId->first(), $folder, $folder, $sentFolder);
        $this->fetchThreadByInReplyTo($thread, $this->messageId->first(), $sentFolder, $folder, $sentFolder);

        foreach ($this->inReplyTo->all() as $inReplyTo) {
            $this->fetchThreadByMessageId($thread, $inReplyTo, $folder, $folder, $sentFolder);
            $this->fetchThreadByMessageId($thread, $inReplyTo, $sentFolder, $folder, $sentFolder);
        }

        return $thread;
    }

    /**
     * Copy the current Messages to a mailbox.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\FolderFetchingException
     * @throws RuntimeException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageHeaderFetchingException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws Exceptions\MessageNotFoundException
     */
    public function copy(string $folderPath, bool $expunge = false): ?self
    {
        $this->client->openFolder($folderPath);
        $status = $this->client->getConnection()->examineFolder($folderPath);

        if (isset($status['uidnext'])) {
            $nextUid = $status['uidnext'];

            /** @var Folder $folder */
            $folder = $this->client->getFolderByPath($folderPath);

            $this->client->openFolder($this->folderPath);
            if ($this->client->getConnection()->copyMessage(
                $folder->path,
                $this->getSequenceId(),
                null,
                $this->sequence
            )) {
                return $this->fetchNewMail($folder, $nextUid, 'copied', $expunge);
            }
        }

        return null;
    }

    /**
     * Move the current Messages to a mailbox.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\FolderFetchingException
     * @throws RuntimeException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageHeaderFetchingException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws Exceptions\MessageNotFoundException
     */
    public function move(string $folderPath, bool $expunge = false): ?self
    {
        $this->client->openFolder($folderPath);
        $status = $this->client->getConnection()->examineFolder($folderPath);

        if (isset($status['uidnext'])) {
            $nextUid = $status['uidnext'];

            /** @var Folder $folder */
            $folder = $this->client->getFolderByPath($folderPath);

            $this->client->openFolder($this->folderPath);
            if ($this->client->getConnection()->moveMessage(
                $folder->path,
                $this->getSequenceId(),
                null,
                $this->sequence
            )) {
                return $this->fetchNewMail($folder, $nextUid, 'moved', $expunge);
            }
        }

        return null;
    }

    /**
     * Delete the current Message.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\FolderFetchingException
     * @throws Exceptions\MessageNotFoundException
     * @throws RuntimeException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageFlagException
     * @throws MessageHeaderFetchingException
     */
    public function delete(bool $expunge = true, ?string $trashPath = null, bool $forceMove = false): bool|self|null
    {
        $status = $this->setFlag('Deleted');
        if ($forceMove) {
            $trashPath ??= $this->config['common_folders']['trash'];
            $status = $this->move($trashPath);
        }
        if ($expunge) {
            $this->client->expunge();
        }

        $this->dispatch('message', 'deleted', $this);

        return $status;
    }

    /**
     * Restore a deleted Message.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws RuntimeException
     */
    public function restore(bool $expunge = true): bool
    {
        $status = $this->unsetFlag('Deleted');
        if ($expunge) {
            $this->client->expunge();
        }

        $this->dispatch('message', 'restored', $this);

        return $status;
    }

    /**
     * Set a given flag.
     *
     * @param string|string[] $flag
     *
     * @throws ConnectionFailedException
     * @throws MessageFlagException
     * @throws Exceptions\EventNotFoundException
     * @throws RuntimeException
     */
    public function setFlag(array|string $flag): bool
    {
        $this->client->openFolder($this->folderPath);
        $flag = '\\'.trim(is_array($flag) ? implode(' \\', $flag) : $flag);
        $sequenceId = $this->getSequenceId();

        try {
            $status = $this->client->getConnection()->store(
                [$flag],
                $sequenceId,
                $sequenceId,
                '+',
                true,
                $this->sequence
            );
        } catch (RuntimeException $e) {
            throw new MessageFlagException('flag could not be set', 0, $e);
        }
        $this->parseFlags();

        $this->dispatch('flag', 'new', $this, $flag);

        return (bool) $status;
    }

    /**
     * Unset a given flag.
     *
     * @param array|string $flag
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws RuntimeException
     */
    public function unsetFlag($flag): bool
    {
        $this->client->openFolder($this->folderPath);

        $flag = '\\'.trim(is_array($flag) ? implode(' \\', $flag) : $flag);
        $sequenceId = $this->getSequenceId();

        try {
            $status = $this->client->getConnection()->store(
                [$flag],
                $sequenceId,
                $sequenceId,
                '-',
                true,
                $this->sequence
            );
        } catch (RuntimeException $e) {
            throw new MessageFlagException('flag could not be removed', 0, $e);
        }
        $this->parseFlags();

        $this->dispatch('flag', 'deleted', $this, $flag);

        return (bool) $status;
    }

    /**
     * Set a given flag.
     *
     * @param string|string[] $flag
     *
     * @throws ConnectionFailedException
     * @throws MessageFlagException
     * @throws Exceptions\EventNotFoundException
     * @throws RuntimeException
     */
    public function addFlag(array|string $flag): bool
    {
        return $this->setFlag($flag);
    }

    /**
     * Unset a given flag.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws MessageFlagException
     * @throws RuntimeException
     */
    public function removeFlag(array|string $flag): bool
    {
        return $this->unsetFlag($flag);
    }

    /**
     * Get all message attachments.
     */
    public function getAttachments(): AttachmentCollection
    {
        return $this->attachments;
    }

    /**
     * Get all message attachments.
     */
    public function attachments(): AttachmentCollection
    {
        return $this->getAttachments();
    }

    /**
     * Checks if there are any attachments present.
     */
    public function hasAttachments(): bool
    {
        return !$this->attachments->isEmpty();
    }

    /**
     * Get the raw body.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function getRawBody(): string
    {
        if (null === $this->rawBody) {
            $this->client->openFolder($this->folderPath);

            $this->rawBody = $this->structure->raw;
        }

        return $this->rawBody;
    }

    /**
     * Get the current client.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the used fetch body option.
     */
    public function getFetchBodyOption(): bool
    {
        return $this->fetchBody;
    }

    /**
     * Get the used fetch flags option.
     */
    public function getFetchFlagsOption(): bool
    {
        return $this->fetchFlags;
    }

    /**
     * Get all set flags.
     */
    public function getFlags(): FlagCollection
    {
        return $this->flags;
    }

    /**
     * Get all set flags.
     */
    public function flags(): FlagCollection
    {
        return $this->getFlags();
    }

    /**
     * Check if a message matches another by comparing basic attributes.
     */
    public function is(?self $message = null): bool
    {
        if (null === $message) {
            return false;
        }

        return $this->uid === $message->uid
            && $this->messageId->first() === $message->messageId->first()
            && $this->subject->first() === $message->subject->first()
            && $this->date->toDate()->getTimestamp() === $message->date->toDate()->getTimestamp();
    }

    /**
     * Get all message attributes.
     */
    public function getAttributes(): array
    {
        return array_merge($this->attributes, $this->header->attributes);
    }

    /**
     * Set the message mask.
     *
     * @return $this
     */
    public function setMask(string $mask): self
    {
        if (class_exists($mask)) {
            $this->mask = $mask;
        }

        return $this;
    }

    /**
     * Get the used message mask.
     */
    public function getMask(): string
    {
        return $this->mask;
    }

    /**
     * Get a masked instance by providing a mask name.
     *
     * @throws MaskNotFoundException
     */
    public function mask(?string $mask = null): mixed
    {
        $mask ??= $this->mask;
        if (class_exists($mask)) {
            return new $mask($this);
        }

        throw new MaskNotFoundException('Unknown mask provided: '.$mask);
    }

    /**
     * Get the message path aka folder path.
     */
    public function getFolderPath(): string
    {
        return $this->folderPath;
    }

    /**
     * Set the message path aka folder path.
     *
     * @return $this
     */
    public function setFolderPath($folderPath): Message
    {
        $this->folderPath = $folderPath;

        return $this;
    }

    /**
     * Set the config.
     *
     * @return $this
     */
    public function setConfig($config): Message
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Set the available flags.
     *
     * @return $this
     */
    public function setAvailableFlags($availableFlags): Message
    {
        $this->availableFlags = $availableFlags;

        return $this;
    }

    /**
     * Set the attachment collection.
     *
     * @return $this
     */
    public function setAttachments($attachments): Message
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * Set the flag collection.
     *
     * @return $this
     */
    public function setFlags($flags): Message
    {
        $this->flags = $flags;

        return $this;
    }

    /**
     * Set the client.
     *
     * @return $this
     *
     * @throws RuntimeException
     * @throws ConnectionFailedException
     */
    public function setClient($client): Message
    {
        $this->client = $client;
        $this->client->openFolder($this->folderPath);

        return $this;
    }

    /**
     * Set the message number.
     *
     * @return $this
     *
     * @throws Exceptions\MessageNotFoundException
     * @throws ConnectionFailedException
     */
    public function setUid(int $uid): Message
    {
        $this->uid = $uid;
        $this->msgn = $this->client->getConnection()->getMessageNumber($this->uid);
        $this->msglist = null;

        return $this;
    }

    /**
     * Set the message number.
     *
     * @return $this
     *
     * @throws Exceptions\MessageNotFoundException
     * @throws ConnectionFailedException
     */
    public function setMsgn(int $msgn, ?int $msglist = null): Message
    {
        $this->msgn = $msgn;
        $this->msglist = $msglist;
        $this->uid = $this->client->getConnection()->getUid($this->msgn);

        return $this;
    }

    /**
     * Get the current sequence type.
     */
    public function getSequence(): int
    {
        return $this->sequence;
    }

    /**
     * Get the sequence type.
     */
    public function getSequenceId(): int
    {
        return IMAP::ST_UID === $this->sequence ? $this->uid : $this->msgn;
    }

    /**
     * Set the sequence id.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\MessageNotFoundException
     */
    public function setSequenceId(int $uid, ?int $msglist = null)
    {
        if (IMAP::ST_UID === $this->getSequence()) {
            $this->setUid($uid);
            $this->setMsglist($msglist);
        } else {
            $this->setMsgn($uid, $msglist);
        }
    }

    /**
     * Safe the entire message in a file.
     */
    public function save(string $filename): bool|int
    {
        return file_put_contents($filename, $this->header->raw."\r\n\r\n".$this->structure->raw);
    }

    /**
     * Fetch the Message attachment.
     */
    protected function fetchAttachment(Part $part): void
    {
        $oAttachment = new Attachment($this, $part);

        if (null !== $oAttachment->getName() && $oAttachment->getSize() > 0) {
            if (null !== $oAttachment->getId()) {
                $this->attachments->set($oAttachment->getId(), $oAttachment);
            } else {
                $this->attachments->add($oAttachment);
            }
        }
    }

    /**
     * Fetch a partial thread by message id.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\GetMessagesFailedException
     * @throws RuntimeException
     * @throws Exceptions\FolderFetchingException
     */
    protected function fetchThreadByInReplyTo(
        MessageCollection $thread,
        string $inReplyTo,
        Folder $primaryFolder,
        Folder $secondaryFolder,
        Folder $sentFolder
    ): void {
        foreach ($primaryFolder->query()->inReplyTo($inReplyTo)
            ->setFetchBody($this->getFetchBodyOption())
            ->leaveUnread()->get() as $message) {
            // @var Message $message
            $message->thread($sentFolder, $thread, $secondaryFolder);
        }
    }

    /**
     * Fetch a partial thread by message id.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\GetMessagesFailedException
     * @throws RuntimeException
     * @throws Exceptions\FolderFetchingException
     */
    protected function fetchThreadByMessageId(
        MessageCollection $thread,
        string $messageId,
        Folder $primaryFolder,
        Folder $secondaryFolder,
        Folder $sentFolder
    ): void {
        foreach ($primaryFolder->query()->messageId($messageId)
            ->setFetchBody($this->getFetchBodyOption())
            ->leaveUnread()->get() as $message) {
            // @var Message $message
            $message->thread($sentFolder, $thread, $secondaryFolder);
        }
    }

    /**
     * Fetch a new message and fire a given event.
     *
     * @throws ConnectionFailedException
     * @throws Exceptions\EventNotFoundException
     * @throws Exceptions\MessageNotFoundException
     * @throws RuntimeException
     * @throws InvalidMessageDateException
     * @throws MessageContentFetchingException
     * @throws MessageFlagException
     * @throws MessageHeaderFetchingException
     */
    protected function fetchNewMail(Folder $folder, int $nextUid, string $event, bool $expunge): self
    {
        if ($expunge) {
            $this->client->expunge();
        }

        $this->client->openFolder($folder->path);

        $sequenceId = IMAP::ST_UID === $this->sequence
            ? $nextUid
            : $this->client->getConnection()->getMessageNumber($nextUid);

        $message = $folder->query()->getMessage($sequenceId, null, $this->sequence);
        if ('moved' === $event) {
            $this->dispatch('message', $event, $this, $message);
        } else {
            $this->dispatch('message', $event, $message);
        }

        return $message;
    }

    /**
     * Parse all defined headers.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     * @throws InvalidMessageDateException
     * @throws MessageHeaderFetchingException
     */
    private function parseHeader(): void
    {
        $sequenceId = $this->getSequenceId();
        $headers = $this->client->getConnection()->headers([$sequenceId], 'RFC822', $this->sequence);
        if (!isset($headers[$sequenceId])) {
            throw new MessageHeaderFetchingException('no headers found', 0);
        }

        $this->parseRawHeader($headers[$sequenceId]);
    }

    /**
     * Parse additional flags.
     *
     * @throws ConnectionFailedException
     * @throws MessageFlagException
     * @throws RuntimeException
     */
    private function parseFlags(): void
    {
        $this->client->openFolder($this->folderPath);
        $this->flags = new FlagCollection();

        $sequenceId = $this->getSequenceId();

        try {
            $flags = $this->client->getConnection()->flags([$sequenceId], $this->sequence);
        } catch (RuntimeException $e) {
            throw new MessageFlagException('flag could not be fetched', 0, $e);
        }

        if (isset($flags[$sequenceId])) {
            $this->parseRawFlags($flags[$sequenceId]);
        }
    }

    /**
     * Fetches the size for this message.
     *
     * @throws ConnectionFailedException|MessageSizeFetchingException|RuntimeException
     */
    private function fetchSize(): void
    {
        $sequenceId = $this->getSequenceId();
        $sizes = $this->client->getConnection()->sizes($sequenceId, 'RFC822', $this->sequence);
        if (!isset($sizes[$sequenceId])) {
            throw new MessageSizeFetchingException('sizes did not set an array entry for the supplied sequence_id', 0);
        }
        $this->attributes['size'] = $sizes[$sequenceId];
    }

    /**
     * Fetch the Message structure.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    private function fetchStructure(Structure $structure): void
    {
        $this->client->openFolder($this->folderPath);

        foreach ($structure->parts as $part) {
            $this->fetchPart($part);
        }
    }

    /**
     * Fetch a given part.
     */
    private function fetchPart(Part $part): void
    {
        if ($part->isAttachment()) {
            $this->fetchAttachment($part);
        } else {
            $encoding = $this->getEncoding($part);

            $content = $this->decodeString($part->content, $part->encoding);

            // We don't need to do convertEncoding() if charset is ASCII (us-ascii):
            //     ASCII is a subset of UTF-8, so all ASCII files are already UTF-8 encoded
            //     https://stackoverflow.com/a/11303410
            //
            // us-ascii is the same as ASCII:
            //     ASCII is the traditional name for the encoding system; the Internet Assigned Numbers Authority (IANA)
            //     prefers the updated name US-ASCII, which clarifies that this system was developed in the US and
            //     based on the typographical symbols predominantly in use there.
            //     https://en.wikipedia.org/wiki/ASCII
            //
            // convertEncoding() function basically means convertToUtf8(), so when we convert ASCII string into UTF-8 it gets broken.
            if ('us-ascii' !== $encoding) {
                $content = $this->convertEncoding($content, $encoding);
            }

            $subtype = strtolower($part->subtype ?? '');
            $subtype = 'plain' === $subtype || '' == $subtype ? 'text' : $subtype;

            if (isset($this->bodies[$subtype])) {
                $this->bodies[$subtype] .= "\n".$content;
            } else {
                $this->bodies[$subtype] = $content;
            }
        }
    }
}
