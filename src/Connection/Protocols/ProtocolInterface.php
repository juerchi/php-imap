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

use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;

/**
 * @phpstan-type ImapUid int<1, 4294967295>
 */
interface ProtocolInterface
{
    /**
     * Public destructor.
     */
    public function __destruct();

    /**
     * Open a new connection / session.
     *
     * @param string   $host hostname or IP address of IMAP server
     * @param null|int $port of service server
     *
     * @throws \ErrorException
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    public function connect(string $host, ?int $port = null): void;

    /**
     * Login to a new session.
     *
     * @param string $user     username
     * @param string $password password
     *
     * @return bool success
     *
     * @throws AuthFailedException
     */
    public function login(string $user, string $password): bool;

    /**
     * Authenticate your current session.
     *
     * @param string $user  username
     * @param string $token access token
     *
     * @throws AuthFailedException
     */
    public function authenticate(string $user, string $token): bool;

    /**
     * Logout of the current server session.
     *
     * @return bool success
     */
    public function logout(): bool;

    /**
     * Check if the current session is connected.
     */
    public function connected(): bool;

    /**
     * Get an array of available capabilities.
     *
     * @return list<string> list of capabilities
     *
     * @throws RuntimeException
     */
    public function getCapabilities(): array;

    /**
     * Change the current folder.
     *
     * @param string $folder change to this folder
     *
     * @return array{flags: list<string>, exists: int, recent: int, uidvalidity?: int, uidnext?: int, unseen?: int}|bool|false see examineOrSelect()
     *
     * @throws RuntimeException
     */
    public function selectFolder(string $folder = 'INBOX'): array|bool;

    /**
     * Examine a given folder.
     *
     * @return array{flags: list<string>, exists: int, recent: int, uidvalidity?: int, uidnext?: int, unseen?: int}|bool|false
     *
     * @throws RuntimeException
     */
    public function examineFolder(string $folder = 'INBOX'): array|bool;

    /**
     * Fetch message headers.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param iterable<ImapUid>|ImapUid $uids
     *
     * @return array<int, string>
     *
     * @throws RuntimeException
     */
    public function content(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array;

    /**
     * Fetch message headers.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param iterable<ImapUid>|ImapUid $uids
     *
     * @return array<int, string>
     *
     * @throws RuntimeException
     */
    public function headers(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array;

    /**
     * Fetch message flags.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param iterable<ImapUid>|ImapUid $uids
     *
     * @return array<int, list<string>>
     *
     * @throws RuntimeException
     */
    public function flags(int|iterable $uids, int|string $uid = IMAP::ST_UID): array;

    /**
     * Fetch message sizes.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param iterable<ImapUid>|ImapUid $uids
     *
     * @return array<int, int>
     *
     * @throws RuntimeException
     */
    public function sizes(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array;

    /**
     * Write a specific message to a stream (headers and body).
     *
     * @param ImapUid  $uid
     * @param resource $stream
     */
    public function saveMessage(int $uid, mixed $stream): void;

    /**
     * Get uid for a given message number.
     *
     * @return ImapUid|list<ImapUid> uid for given message or all messages as array
     *
     * @throws MessageNotFoundException
     */
    public function getUid(?int $messageNumber = null): array|int;

    /**
     * Get a message number for a uid.
     *
     * @param ImapUid $uid
     *
     * @return int message number
     *
     * @throws MessageNotFoundException
     */
    public function getMessageNumber(int $uid): int;

    /**
     * Get a list of available folders.
     *
     * @param string $reference mailbox reference for list
     * @param string $folder    mailbox / folder name match with wildcards
     *
     * @return array<string, array{delimiter: string, flags: list<string>}> mailboxes that matched $folder as array(globalName => array('delimiter' => .., 'flags' => ..))
     *
     * @throws RuntimeException
     */
    public function folders(string $reference = '', string $folder = '*'): array;

    /**
     * Set message flags.
     *
     * @param array          $flags  flags to set, add or remove
     * @param null|float|int $to     if null only one message ($from) is fetched, else it's the
     *                               last message, INF means last message available
     * @param null|string    $mode   '+' to add flags, '-' to remove flags, everything else sets the flags as given
     * @param bool           $silent if false the return values are the new flags for the wanted messages
     * @param int|string     $uid    set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                               message numbers instead
     * @param null|string    $item   command used to store a flag
     *
     * @phpstan-param int|iterable<int> $from message for items or start message if $to !== null
     *
     * @return array<int, list<string>>|bool new flags if $silent is false, else true or false depending on success
     *
     * @throws RuntimeException
     */
    public function store(
        array $flags,
        int|iterable $from,
        float|int|null $to = null,
        ?string $mode = null,
        bool $silent = true,
        int|string $uid = IMAP::ST_UID,
        ?string $item = null
    ): array|bool;

    /**
     * Append a new message to a given folder.
     *
     * @param string                         $folder  name of target folder
     * @param string                         $message full message content
     * @param null|array                     $flags   flags for new message
     * @param null|\DateTimeInterface|string $date    date for new message
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, \DateTimeInterface|string|null $date = null): bool;

    /**
     * Copy message set from the current folder to another folder.
     *
     * @param string         $folder destination folder
     * @param null|float|int $to     if null only one message ($from) is fetched, else it's the
     *                               last message, INF means last message available
     * @param int|string     $uid    set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                               message numbers instead
     *
     * @phpstan-param int|iterable<int> $from
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function copyMessage(string $folder, int|iterable $from, float|int|null $to = null, int|string $uid = IMAP::ST_UID): bool;

    /**
     * Copy multiple messages to the target folder.
     *
     * @param iterable<int> $messages List of message identifiers
     * @param string        $folder   Destination folder
     * @param int|string    $uid      set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                                message numbers instead
     *
     * @return array|false Tokens if operation successful, false if an error occurred
     *
     * @throws RuntimeException
     */
    public function copyManyMessages(iterable $messages, string $folder, int|string $uid = IMAP::ST_UID): array|false;

    /**
     * Move a message set from the current folder to another folder.
     *
     * @param string         $folder destination folder
     * @param null|float|int $to     if null only one message ($from) is fetched, else it's the
     *                               last message, INF means last message available
     * @param int|string     $uid    set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                               message numbers instead
     *
     * @phpstan-param int|iterable<int> $from
     *
     * @return bool success
     */
    public function moveMessage(string $folder, int|iterable $from, float|int|null $to = null, int|string $uid = IMAP::ST_UID): bool;

    /**
     * Move multiple messages to the target folder.
     *
     * @param iterable<int> $messages List of message identifiers
     * @param string        $folder   Destination folder
     * @param int|string    $uid      set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                                message numbers instead
     *
     * @return array|false Tokens if operation successful, false if an error occurred
     *
     * @throws RuntimeException
     */
    public function moveManyMessages(iterable $messages, string $folder, int|string $uid = IMAP::ST_UID): array|false;

    /**
     * Exchange identification information
     * Ref.: https://datatracker.ietf.org/doc/html/rfc2971.
     *
     * @throws RuntimeException
     */
    public function ID(?array $ids = null): array|bool|null;

    /**
     * Create a new folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function createFolder(string $folder): bool;

    /**
     * Rename an existing folder.
     *
     * @param string $old old name
     * @param string $new new name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function renameFolder(string $old, string $new): bool;

    /**
     * Delete a folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function deleteFolder(string $folder): bool;

    /**
     * Subscribe to a folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function subscribeFolder(string $folder): bool;

    /**
     * Unsubscribe from a folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function unsubscribeFolder(string $folder): bool;

    /**
     * Send idle command.
     *
     * @throws RuntimeException
     */
    public function idle(): void;

    /**
     * Send done command.
     *
     * @throws RuntimeException
     */
    public function done(): void;

    /**
     * Apply session saved changes to the server.
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function expunge(): bool;

    /**
     * Retrieve the quota level settings, and usage statics per mailbox.
     *
     * @throws RuntimeException
     */
    public function getQuota(string $username): array;

    /**
     * Retrieve the quota settings per user.
     *
     * @throws ConnectionFailedException
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): array;

    /**
     * Send noop command.
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function noop(): bool;

    /**
     * Do a search request.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @return list<int> message ids
     *
     * @throws RuntimeException
     */
    public function search(array $params, int|string $uid = IMAP::ST_UID): array;

    /**
     * Get a message overview.
     *
     * @param string     $sequence uid sequence
     * @param int|string $uid      set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                             message numbers instead
     *
     * @return array<int, mixed>|false
     *
     * @throws RuntimeException
     * @throws MessageNotFoundException
     * @throws InvalidMessageDateException
     */
    public function overview(string $sequence, int|string $uid = IMAP::ST_UID): array|false;

    /**
     * Enable the debug mode.
     */
    public function enableDebug(): void;

    /**
     * Disable the debug mode.
     */
    public function disableDebug(): void;

    /**
     * Enable uid caching.
     */
    public function enableUidCache(): void;

    /**
     * Disable uid caching.
     */
    public function disableUidCache(): void;

    /**
     * Set the uid cache of the current active folder.
     *
     * @param null|list<ImapUid> $uids
     */
    public function setUidCache(?array $uids): void;
}
