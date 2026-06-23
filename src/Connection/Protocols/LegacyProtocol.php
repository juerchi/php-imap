<?php

/*
* File: LegacyProtocol.php
* Category: Protocol
* Author: M.Goldenbaum
* Created: 16.09.20 18:27
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Connection\Protocols;

use IMAP\Connection;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Enums\Encryption;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\MethodNotSupportedException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\IMAP;

/**
 * Class LegacyProtocol.
 *
 * @phpstan-import-type ImapUid from ProtocolInterface
 */
final class LegacyProtocol extends AbstractProtocol
{
    private ?Connection $stream = null;

    private string $protocol = 'imap';
    private ?string $host = null;
    private ?int $port = null;

    /**
     * Imap constructor.
     *
     * @param bool            $certValidation set to false to skip SSL certificate validation
     * @param null|Encryption $encryption     Connection encryption method
     */
    public function __construct(bool $certValidation = true, ?Encryption $encryption = null)
    {
        $this->certValidation = $certValidation;
        $this->encryption = $encryption;
    }

    /**
     * Public destructor.
     */
    #[\Override]
    public function __destruct()
    {
        $this->logout();
    }

    /**
     * Save the information for a nw connection.
     */
    #[\Override]
    public function connect(string $host, ?int $port = null): void
    {
        $this->host = $host;
        $this->port = $port ?? $this->encryption?->defaultPort() ?? 143;
    }

    /**
     * Login to a new session.
     *
     * @param string $user     username
     * @param string $password password
     *
     * @throws AuthFailedException
     * @throws RuntimeException
     */
    #[\Override]
    public function login(string $user, string $password): bool
    {
        try {
            $this->stream = \imap_open(
                mailbox: $this->getAddress(),
                user: $user,
                password: $password,
                retries: 3,
                options: ClientManager::get('options.open')
            ) ?: null;
        } catch (\ValueError $e) {
            $message = $e->getMessage().'. '.implode('; ', \imap_errors() ?: []);

            throw new AuthFailedException($message);
        }

        if (null === $this->stream) {
            $message = implode('; ', \imap_errors() ?: []);

            throw new AuthFailedException($message);
        }

        $errors = \imap_errors() ?: [];
        if ([] !== $errors) {
            $status = $this->examineFolder();
            if (false === $status || 0 !== $status['exists']) {
                $message = implode('; ', $errors);

                throw new RuntimeException($message);
            }
        }

        return null !== $this->stream;
    }

    /**
     * Authenticate your current session.
     *
     * @param string $user  username
     * @param string $token access token
     *
     * @throws AuthFailedException|RuntimeException
     */
    #[\Override]
    public function authenticate(string $user, string $token): bool
    {
        return $this->login($user, $token);
    }

    /**
     * Logout of the current session.
     */
    #[\Override]
    public function logout(): bool
    {
        if ($this->stream) {
            \imap_close($this->stream, IMAP::CL_EXPUNGE);
            $this->stream = null;
            $this->uidCache = null;

            return true;
        }

        return false;
    }

    /**
     * Check if the current session is connected.
     */
    public function connected(): bool
    {
        return (bool) $this->stream;
    }

    /**
     * Get an array of available capabilities.
     *
     * @throws MethodNotSupportedException
     */
    public function getCapabilities(): array
    {
        throw new MethodNotSupportedException(__METHOD__);
    }

    /**
     * Change the current folder.
     *
     * @param string $folder change to this folder
     *
     * @return array|false see examineOrselect()
     *
     * @throws RuntimeException
     */
    public function selectFolder(string $folder = 'INBOX'): array|false
    {
        $flags = IMAP::OP_READONLY;
        if (in_array($this->protocol, ['pop3', 'nntp'], true)) {
            $flags = IMAP::NIL;
        }
        if (null === $this->stream) {
            throw new RuntimeException('failed to reopen stream.');
        }

        \imap_reopen($this->stream, $this->getAddress().$folder, $flags, 3);
        $this->uidCache = null;

        return $this->examineFolder($folder);
    }

    /**
     * Examine a given folder.
     *
     * @param string $folder examine this folder
     *
     * @throws RuntimeException
     *
     * @phpstan-impure
     */
    #[\Override]
    public function examineFolder(string $folder = 'INBOX'): array|false
    {
        if (str_starts_with($folder, '.')) {
            throw new RuntimeException("Segmentation fault prevented. Folders starts with an illegal char '.'.");
        }

        $folder = $this->getAddress().$folder;
        $status = \imap_status($this->stream, $folder, IMAP::SA_ALL);

        return $status ? [
            'flags' => [],
            'exists' => $status->messages,
            'recent' => $status->recent,
            'unseen' => $status->unseen,
            'uidnext' => $status->uidnext,
        ] : false;
    }

    /**
     * Fetch message content.
     *
     * @param int|string $uid set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @phpstan-param iterable<ImapUid>|ImapUid  $uids
     */
    public function content(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array
    {
        $result = [];
        $uids = is_iterable($uids) ? $uids : [$uids];
        foreach ($uids as $id) {
            $result[$id] = \imap_fetchbody($this->stream, $id, '', $uid ? IMAP::ST_UID : IMAP::NIL);
        }

        return $result;
    }

    /**
     * Fetch message headers.
     *
     * @param int|string $uid set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @phpstan-param iterable<ImapUid>|ImapUid $uids
     */
    public function headers(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array
    {
        $result = [];
        $uids = is_iterable($uids) ? $uids : [$uids];
        foreach ($uids as $id) {
            $result[$id] = \imap_fetchheader($this->stream, $id, $uid ? IMAP::ST_UID : IMAP::NIL);
        }

        return $result;
    }

    /**
     * Fetch message flags.
     *
     * @param int|string $uid set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @phpstan-param iterable<ImapUid>|ImapUid $uids
     */
    public function flags(int|iterable $uids, int|string $uid = IMAP::ST_UID): array
    {
        $result = [];
        $uids = is_iterable($uids) ? $uids : [$uids];
        foreach ($uids as $id) {
            $rawFlags = \imap_fetch_overview($this->stream, (string) $id, $uid ? IMAP::ST_UID : IMAP::NIL);
            $flags = [];
            if (is_array($rawFlags) && isset($rawFlags[0])) {
                $rawFlags = (array) $rawFlags[0];
                foreach ($rawFlags as $flag => $value) {
                    if (1 === $value && !\in_array($flag, ['size', 'uid', 'msgno', 'update'], true)) {
                        $flags[] = '\\'.ucfirst($flag);
                    }
                }
            }
            $result[$id] = $flags;
        }

        return $result;
    }

    /**
     * fetch message sizes.
     *
     * @param int|string $uid set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @phpstan-param iterable<ImapUid>|ImapUid $uids
     *
     * @return array<ImapUid, int>
     */
    public function sizes(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::FT_UID): array
    {
        $sequence = is_iterable($uids) ? implode("','", [...$uids]) : (string) $uids;
        $rawOverview = \imap_fetch_overview($this->stream, $sequence, $uid ? IMAP::FT_UID : IMAP::NIL);
        if (false === $rawOverview) {
            return [];
        }
        $result = [];
        foreach ($rawOverview as $overviewElement) {
            $key = $overviewElement->{IMAP::FT_UID === $uid ? 'uid' : 'msgno'};
            $result[$key] = (int) $overviewElement->size;
        }

        return $result;
    }

    /**
     * @param ImapUid         $uid
     * @param resource|string $stream
     *
     * @throws RuntimeException
     */
    #[\Override]
    public function saveMessage(int $uid, mixed $stream): void
    {
        \imap_savebody($this->stream, $stream, $uid, '', IMAP::ST_UID)
            || throw new RuntimeException('failed to send literal string');
    }

    /**
     * Get uid for a given message number.
     *
     * @return array<int, ImapUid>|ImapUid uid for given message or all messages as array
     *
     * @throws MessageNotFoundException
     */
    #[\Override]
    public function getUid(?int $messageNumber = null): array|int
    {
        if (null === $messageNumber) {
            if ($this->enableUidCache && $this->uidCache) {
                return $this->uidCache;
            }

            $overview = $this->overview('1:*');
            $uids = [];
            foreach ($overview as $set) {
                $uids[$set->msgno] = $set->uid;
            }

            $this->setUidCache($uids);

            return $uids;
        }

        return \imap_uid($this->stream, $messageNumber)
            ?: throw new MessageNotFoundException('unique id not found');
    }

    /**
     * Get a message number for a uid.
     *
     * @param ImapUid $uid
     */
    public function getMessageNumber(int $uid): int
    {
        return \imap_msgno($this->stream, $uid);
    }

    /**
     * Get a message overview.
     *
     * @param string     $sequence uid sequence
     * @param int|string $uid      set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @return false|list<object>
     */
    public function overview(string $sequence, int|string $uid = IMAP::ST_UID): array|false
    {
        return \imap_fetch_overview($this->stream, $sequence, $uid ? IMAP::ST_UID : IMAP::NIL);
    }

    /**
     * Get a list of available folders.
     *
     * @param string $reference mailbox reference for list
     * @param string $folder    mailbox name match with wildcards
     *
     * @return array<string, array{delimiter: string, flags: list<string>}> folders that matched $folder as array(name => array('delimiter' => .., 'flags' => ..))
     *
     * @throws RuntimeException
     */
    public function folders(string $reference = '', string $folder = '*'): array
    {
        $items = \imap_getmailboxes($this->stream, $this->getAddress(), $reference.$folder);
        if (!is_array($items)) {
            throw new RuntimeException(\imap_last_error());
        }

        $result = [];
        foreach ($items as $item) {
            $name = $this->getShortPath($item->name);
            $result[$name] = ['delimiter' => $item->delimiter, 'flags' => []];
        }

        return $result;
    }

    /**
     * Manage flags.
     *
     * @param array          $flags  flags to set, add or remove - see $mode
     * @param null|float|int $to     if null only one message ($from) is fetched, else it's the
     *                               last message, INF means last message available
     * @param null|string    $mode   '+' to add flags, '-' to remove flags, everything else sets the flags as given
     * @param bool           $silent if false the return values are the new flags for the wanted messages
     * @param int|string     $uid    set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     * @param null|string    $item   unused attribute
     *
     * @phpstan-param int|iterable<int> $from    message for items or start message if $to !== null
     *
     * @return array|bool new flags if $silent is false, else true or false depending on success
     */
    #[\Override]
    public function store(
        array $flags,
        int|iterable $from,
        float|int|null $to = null,
        ?string $mode = null,
        bool $silent = true,
        int|string $uid = IMAP::ST_UID,
        ?string $item = null
    ): array|bool {
        $flag = trim(implode(' ', $flags));
        $sequence = $this->buildSequence($from, $to);

        if ('+' === $mode) {
            $status = \imap_setflag_full($this->stream, $sequence, $flag, $uid ? IMAP::ST_UID : IMAP::NIL);
        } else {
            $status = \imap_clearflag_full($this->stream, $sequence, $flag, $uid ? IMAP::ST_UID : IMAP::NIL);
        }

        if ($silent) {
            return $status;
        }

        return $this->flags($from);
    }

    /**
     * Append a new message to a given folder.
     *
     * @param string                         $folder  name of target folder
     * @param string                         $message full message content
     * @param null|array                     $flags   flags for new message
     * @param null|\DateTimeInterface|string $date    date for new message
     *
     * @return bool success
     */
    #[\Override]
    public function appendMessage(string $folder, string $message, ?array $flags = null, \DateTimeInterface|string|null $date = null): bool
    {
        $options = null === $flags ? null : implode(' ', $flags);

        if ($date instanceof \DateTimeInterface) {
            $date = $date->format('d-M-Y H:i:s O');
        }

        return \imap_append($this->stream, $folder, $message, $options, $date);
    }

    /**
     * Copy message set from the current folder to another folder.
     *
     * @param string         $folder destination folder
     * @param null|float|int $to     if null only one message ($from) is fetched, else it's the
     *                               last message, INF means last message available
     * @param int|string     $uid    set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @phpstan-param int|iterable<int> $from
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function copyMessage(string $folder, int|iterable $from, float|int|null $to = null, int|string $uid = IMAP::ST_UID): bool
    {
        $sequence = $this->buildSequence($from, $to);

        return \imap_mail_copy($this->stream, $sequence, $folder, $uid ? IMAP::ST_UID : IMAP::NIL);
    }

    /**
     * Copy multiple messages to the target folder.
     *
     * @param iterable<int> $messages List of message identifiers
     * @param string        $folder   Destination folder
     * @param int|string    $uid      set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @return array<int>|false Tokens if operation successful, false if an error occurred
     */
    #[\Override]
    public function copyManyMessages(iterable $messages, string $folder, int|string $uid = IMAP::ST_UID): array|false
    {
        if (array_any(
            [...$messages],
            fn (int $msg): bool => !$this->copyMessage($folder, $msg, uid: $uid)
        )) {
            return false;
        }

        return $messages;
    }

    /**
     * Move a message set from current folder to another folder.
     *
     * @param string         $folder destination folder
     * @param null|float|int $to     if null only one message ($from) is fetched, else it's the
     *                               last message, INF means last message available
     * @param int|string     $uid    set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @phpstan-param int|iterable<int> $from
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function moveMessage(string $folder, int|iterable $from, float|int|null $to = null, int|string $uid = IMAP::ST_UID): bool
    {
        $sequence = $this->buildSequence($from, $to);

        return \imap_mail_move($this->stream, $sequence, $folder, $uid ? IMAP::CP_UID : IMAP::NIL);
    }

    /**
     * Move multiple messages to the target folder.
     *
     * @param iterable<int> $messages List of message identifiers
     * @param string        $folder   Destination folder
     * @param int|string    $uid      set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @return false|list<int> Tokens if operation successful, false if an error occurred
     *
     * @throws RuntimeException
     */
    public function moveManyMessages(iterable $messages, string $folder, int|string $uid = IMAP::ST_UID): array|false
    {
        $messages = [...$messages];
        if (array_any(
            $messages,
            fn (int $msg): bool => !$this->moveMessage($folder, $msg, uid: $uid)
        )) {
            return false;
        }

        return $messages;
    }

    /**
     * Exchange identification information
     * Ref.: https://datatracker.ietf.org/doc/html/rfc2971.
     *
     * @throws MethodNotSupportedException
     */
    public function ID(mixed $ids = null): never
    {
        throw new MethodNotSupportedException(__METHOD__);
    }

    /**
     * Create a new folder (and parent folders if needed).
     *
     * @param string $folder folder name
     *
     * @return bool success
     */
    public function createFolder(string $folder): bool
    {
        return \imap_createmailbox($this->stream, $folder);
    }

    /**
     * Rename an existing folder.
     *
     * @param string $old old name
     * @param string $new new name
     *
     * @return bool success
     */
    public function renameFolder(string $old, string $new): bool
    {
        return \imap_renamemailbox($this->stream, $old, $new);
    }

    /**
     * Delete a folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     */
    public function deleteFolder(string $folder): bool
    {
        return \imap_deletemailbox($this->stream, $folder);
    }

    /**
     * Subscribe to a folder.
     *
     * @param string $folder folder name
     *
     * @throws MethodNotSupportedException
     */
    public function subscribeFolder(string $folder): bool
    {
        throw new MethodNotSupportedException();
    }

    /**
     * Unsubscribe from a folder.
     *
     * @param string $folder folder name
     *
     * @throws MethodNotSupportedException
     */
    public function unsubscribeFolder(string $folder): bool
    {
        throw new MethodNotSupportedException();
    }

    /**
     * Apply session saved changes to the server.
     *
     * @return bool success
     */
    public function expunge(): bool
    {
        return \imap_expunge($this->stream);
    }

    /**
     * Send noop command.
     *
     * @throws MethodNotSupportedException
     */
    public function noop(): bool
    {
        throw new MethodNotSupportedException();
    }

    /**
     * Send idle command.
     *
     * @throws MethodNotSupportedException
     */
    public function idle(): void
    {
        throw new MethodNotSupportedException();
    }

    /**
     * Send done command.
     *
     * @throws MethodNotSupportedException
     *
     * @never-return
     */
    public function done(): void
    {
        throw new MethodNotSupportedException();
    }

    /**
     * Search for matching messages.
     *
     * @param int|string $uid set to IMAP::ST_UID if you pass message unique identifiers instead of numbers
     *
     * @return list<int> message ids
     */
    public function search(array $params, int|string $uid = IMAP::ST_UID): array
    {
        $result = \imap_search($this->stream, $params[0], $uid ? IMAP::ST_UID : IMAP::NIL);

        return $result ?: [];
    }

    /**
     * Enable the debug mode.
     */
    public function enableDebug(): void
    {
        $this->debug = true;
    }

    /**
     * Disable the debug mode.
     */
    public function disableDebug(): void
    {
        $this->debug = false;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * Retrieve the quota level settings, and usage statics per mailbox.
     *
     * @return array<mixed>
     */
    public function getQuota(string $username): array
    {
        return \imap_get_quota($this->stream, 'user.'.$username);
    }

    /**
     * Retrieve the quota settings per user.
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): array
    {
        return \imap_get_quotaroot($this->stream, $quotaRoot);
    }

    public function setProtocol(string $protocol): LegacyProtocol
    {
        if (str_starts_with($protocol, 'legacy-')) {
            $protocol = substr($protocol, strlen('legacy-'));
        }
        $this->protocol = $protocol;

        return $this;
    }

    /**
     * Get the full address of the mailbox.
     */
    protected function getAddress(): string
    {
        $address = '{'.$this->host.':'.$this->port.'/'.$this->protocol;
        if (!$this->certValidation) {
            $address .= '/novalidate-cert';
        }
        if (null !== $this->encryption) {
            $address .= $this->encryption->toFlag();
        }
        $address .= '}';

        return $address;
    }

    protected function getShortPath(string $name): string
    {
        if (1 !== preg_match('#\{(.*)}(.*)#', $name, $preg)) {
            return $name;
        }

        return $preg[2];
    }
}
