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

use Webklex\PHPIMAP\Enums\Encryption;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;

/**
 * Class ImapProtocol.
 *
 * @phpstan-import-type ImapUid from ProtocolInterface
 */
final class ImapProtocol extends AbstractProtocol
{
    /** @var null|resource */
    protected mixed $stream = null;

    /**
     * Request noun.
     */
    private int $noun = 0;

    /**
     * Imap constructor.
     *
     * @param bool $certValidation set to false to skip SSL certificate validation
     */
    public function __construct(bool $certValidation = true, ?Encryption $encryption = null)
    {
        $this->certValidation = $certValidation;
        $this->encryption = $encryption;
    }

    /**
     * Public destructor.
     */
    public function __destruct()
    {
        $this->logout();
    }

    /**
     * Open connection to the IMAP server.
     *
     * @param string   $host hostname or IP address of IMAP server
     * @param null|int $port of IMAP server, default is 143 and 993 for ssl
     *
     * @throws ConnectionFailedException
     */
    public function connect(string $host, ?int $port = null): void
    {
        $transport = 'tcp';
        $encryption = '';

        if (null !== $this->encryption) {
            $encryption = $this->encryption->value;
            if (\in_array($encryption, ['ssl', 'tls'], true)) {
                $transport = $encryption;
                $port ??= 993;
            }
        }
        $port ??= 143;

        try {
            $this->stream = $this->createStream($transport, $host, $port, $this->connectionTimeout);
            if (!$this->assumedNextLine('* OK')) {
                throw new ConnectionFailedException('connection refused');
            }
            if ('starttls' === $encryption) {
                $this->enableStartTls();
            }
        } catch (\Exception $e) {
            throw new ConnectionFailedException('connection failed', 0, $e);
        }
    }

    /**
     * Get the next line from the stream.
     *
     * @throws RuntimeException
     */
    public function nextLine(): string
    {
        $line = fgets($this->stream);
        if (false === $line || '' === $line) {
            throw new RuntimeException('empty response');
        }
        if ($this->debug) {
            if (null !== $this->logger) {
                $this->logger->debug('IMAP: << '.$line);
            } else {
                echo '<< '.$line;
            }
        }

        return $line;
    }

    /**
     * Read and decode a response "line".
     *
     * @phpstan-param-out ($dontParse is true ? string : list<string|list<mixed>>) $tokens
     *
     * @param string        $wantedTag targeted tag
     * @param bool          $dontParse if true only the unparsed line is returned in $tokens
     * @param null|resource $stream
     *
     * @phpstan-param list<string|list<mixed>>|string $tokens
     *
     * @return bool true if end is reached
     *
     * @throws RuntimeException
     */
    public function readLine(array|string &$tokens, string $wantedTag = '*', bool $dontParse = false, mixed $stream = null): bool
    {
        $tag = '';
        $line = $this->nextTaggedLine($tag); // get next tag
        $tokens = $dontParse ? $line : $this->decodeLine($line, $stream);

        // if tag is wanted tag we might be at the end of a multiline response
        return $tag === $wantedTag;
    }

    /**
     * Read all lines of response until the given tag is found.
     *
     * @param string $tag       request tag
     * @param bool   $dontParse if true every line is returned unparsed instead of the decoded tokens
     *
     * @return null|false|list<list<list<mixed>|string>> tokens if success, false if error, null if bad request
     *
     * @throws RuntimeException
     */
    public function readResponse(string $tag, bool $dontParse = false): array|bool|null
    {
        $lines = [];

        /** @var array|string $tokens */
        $tokens = [];
        do {
            $readAll = $this->readLine($tokens, $tag, $dontParse);
            $lines[] = $tokens;
        } while (!$readAll);

        if ($dontParse) {
            // First two chars are still needed for the response code
            $tokens = [substr($tokens, 0, 2)];
        }

        // last line has response code
        if ('OK' === $tokens[0]) {
            return $lines;
        }

        if ('NO' === $tokens[0]) {
            return false;
        }

        return null;
    }

    /**
     * Send a new request.
     *
     * @param list<array{string, string}|string> $tokens additional parameters to command, use escapeString() to prepare
     * @param null|string                        $tag    provide a tag otherwise an autogenerated is returned
     *
     * @param-out string $tag
     *
     * @throws RuntimeException
     */
    public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): void
    {
        if (!$tag) {
            ++$this->noun;
            $tag = 'TAG'.$this->noun;
        }

        $line = $tag.' '.$command;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $this->write($line.' '.$token[0]);
                if (!$this->assumedNextLine('+ ')) {
                    throw new RuntimeException('failed to send literal string');
                }
                $line = $token[1];
            } else {
                $line .= ' '.$token;
            }
        }
        $this->write($line);
    }

    /**
     * Write data to the current stream.
     *
     * @throws RuntimeException
     */
    public function write(string $data): void
    {
        if ($this->debug) {
            if (null !== $this->logger) {
                $this->logger->debug('IMAP: >> '.$data);
            } else {
                echo '>> '.$data."\n";
            }
        }

        if (false === fwrite($this->stream, $data."\r\n")) {
            throw new RuntimeException('failed to write - connection closed?');
        }
    }

    /**
     * Send a request and get response at once.
     *
     * @param list<array{string, string}|string> $tokens    parameters as in sendRequest()
     * @param bool                               $dontParse if true unparsed lines are returned instead of tokens
     *
     * @return null|array|bool response as in readResponse()
     *
     * @throws RuntimeException
     */
    public function requestAndResponse(string $command, array $tokens = [], bool $dontParse = false): array|bool|null
    {
        $this->sendRequest($command, $tokens, $tag);

        return $this->readResponse($tag, $dontParse);
    }

    /**
     * Escape one or more literals i.e. for sendRequest.
     *
     * @return list<array{string, string}|string>|string escape literals, literals with newline are returned
     *                                                   as array('{size}', 'string');
     */
    public function escapeString(string ...$strings): array|string
    {
        $count = count($strings);
        if (0 === $count) {
            throw new \LogicException('at least one string has to be given.');
        }

        if (1 === $count) {
            $string = $strings[0];
            if (str_contains($string, "\n")) {
                return ['{'.strlen($string).'}', $string];
            }

            return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $string).'"';
        }

        return array_map($this->escapeString(...), $strings);
    }

    /**
     * Escape a list with literals or lists.
     *
     * @param list<list<mixed>|string> $list list with literals or lists as PHP array
     *
     * @return string escaped list for imap
     */
    public function escapeList(array $list): string
    {
        $result = [];
        foreach ($list as $v) {
            $result[] = is_array($v) ? $this->escapeList($v) : $v;
        }

        return '('.implode(' ', $result).')';
    }

    /**
     * Login to a new session.
     *
     * @param string $user     username
     * @param string $password password
     *
     * @throws AuthFailedException
     */
    public function login(string $user, string $password): bool
    {
        try {
            $response = $this->requestAndResponse('LOGIN', $this->escapeString($user, $password), true);

            return null !== $response && false !== $response;
        } catch (RuntimeException $e) {
            throw new AuthFailedException('failed to authenticate', 0, $e);
        }
    }

    /**
     * Authenticate your current IMAP session.
     *
     * @param string $user  username
     * @param string $token access token
     *
     * @throws AuthFailedException
     */
    public function authenticate(string $user, string $token): bool
    {
        try {
            $authenticateParams = ['XOAUTH2', base64_encode("user=$user\1auth=Bearer $token\1\1")];
            $this->sendRequest('AUTHENTICATE', $authenticateParams);

            while (true) {
                $response = '';
                $isPlus = $this->readLine($response, '+', true);
                if ($isPlus) {
                    // try to log the challenge somewhere where it can be found
                    // error_log("got an extra server challenge: $response");
                    // respond with an empty response.
                    $this->sendRequest('');
                } else {
                    if (preg_match('/^NO /i', $response)
                        || preg_match('/^BAD /i', $response)) {
                        // error_log("got failure response: $response");

                        return false;
                    }

                    if (preg_match('/^OK /i', $response)) {
                        return true;
                    }
                }
            }
        } catch (RuntimeException $e) {
            throw new AuthFailedException('failed to authenticate', 0, $e);
        }
    }

    /**
     * Logout of imap server.
     *
     * @return bool success
     */
    public function logout(): bool
    {
        $result = false;
        if ($this->stream) {
            try {
                $result = $this->requestAndResponse('LOGOUT', dontParse: true);
            } catch (\Exception) {
            }
            fclose($this->stream);
            $this->stream = null;
            $this->uidCache = null;
        }

        return false !== $result;
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
     * @return list<string> list of capabilities
     *
     * @throws RuntimeException
     */
    public function getCapabilities(): array
    {
        $response = $this->requestAndResponse('CAPABILITY');
        if (!$response) {
            return [];
        }

        $capabilities = [];
        foreach ($response as $line) {
            array_push($capabilities, ...$line);
        }

        return $capabilities;
    }

    /**
     * Examine and select have the same response.
     *
     * @param string $command can be 'EXAMINE' or 'SELECT'
     * @param string $folder  target folder
     *
     * @return array{flags: list<string>, exists: int, recent: int, uidvalidity?: int, uidnext?: int, unseen?: int}|false
     *
     * @throws RuntimeException
     */
    public function examineOrSelect(string $command = 'EXAMINE', string $folder = 'INBOX'): array|false
    {
        $this->sendRequest($command, [$this->escapeString($folder)], $tag);

        $result = [];
        $tokens = [];
        while (!$this->readLine($tokens, $tag)) {
            if ('FLAGS' === $tokens[0]) {
                array_shift($tokens);
                $result['flags'] = $tokens;

                continue;
            }

            switch ($tokens[1]) {
                case 'EXISTS':
                case 'RECENT':
                    $result[strtolower($tokens[1])] = (int) $tokens[0];

                    break;

                case '[UIDVALIDITY':
                    $result['uidvalidity'] = (int) $tokens[2];

                    break;

                case '[UIDNEXT':
                    $result['uidnext'] = (int) $tokens[2];

                    break;

                case '[UNSEEN':
                    $result['unseen'] = (int) $tokens[2];

                    break;

                case '[NONEXISTENT]':
                    throw new RuntimeException("folder doesn't exist");

                default:
                    // ignore
                    break;
            }
        }

        if ('OK' !== $tokens[0]) {
            return false;
        }

        return $result;
    }

    /**
     * Change the current folder.
     *
     * @param string $folder change to this folder
     *
     * @return array|bool see examineOrselect()
     *
     * @throws RuntimeException
     */
    public function selectFolder(string $folder = 'INBOX'): array|bool
    {
        $this->uidCache = null;

        return $this->examineOrSelect('SELECT', $folder);
    }

    /**
     * Examine a given folder.
     *
     * @param string $folder examine this folder
     *
     * @return array|false see examineOrselect()
     *
     * @throws RuntimeException
     */
    public function examineFolder(string $folder = 'INBOX'): array|false
    {
        return $this->examineOrSelect('EXAMINE', $folder);
    }

    /**
     * Fetch one or more items of one or more messages.
     *
     * @param string|string[] $items items to fetch [RFC822.HEADER, FLAGS, RFC822.TEXT, etc]
     * @param null|float|int  $to    if null only one message ($from) is fetched, else it's the
     *                               last message, INF means last message available
     * @param int|string      $uid   set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                               message numbers instead
     *
     * @phpstan-param int|iterable<int> $from message for items or start message if $to !== null
     *
     * @return array<int, array<string, mixed>|string>|string if only one item of one message is fetched it's returned as string
     *                                                        if items of one message are fetched it's returned as (name => value)
     *                                                        if one item of messages are fetched it's returned as (msgno => value)
     *                                                        if items of messages are fetched it's returned as (msgno => (name => value))
     *
     * @throws RuntimeException
     */
    public function fetch(
        array|string $items,
        int|iterable $from,
        float|int|null $to = null,
        int|string $uid = IMAP::ST_UID,
    ): array|string {
        $set = $this->buildSequence($from, $to);
        $items = (array) $items;
        $itemList = $this->escapeList($items);

        $this->sendRequest($this->buildUIDCommand('FETCH', $uid), [$set, $itemList], $tag);
        $result = [];
        $tokens = []; // define $tokens variable before first use
        while (!$this->readLine($tokens, $tag)) {
            // ignore other responses
            if ('FETCH' !== $tokens[1]) {
                continue;
            }

            $uidKey = 0; // initialize to avoid undefined variable warning
            $data = null; // initialize to avoid undefined variable warning

            // find array key of UID value; try the last elements, or search for it
            if ($uid) {
                $uidKeyCandidate = $this->resolveUidKey($tokens[2]);
                if (null === $uidKeyCandidate) {
                    continue;
                }
                $uidKey = $uidKeyCandidate;
            }

            // ignore other messages
            if (null === $to && is_int($from) && $this->resolveMessageId($tokens, $uid, $uidKey) !== $from) {
                continue;
            }

            // if we only want one item, we return that one directly
            if (1 === count($items)) {
                if ($tokens[2][0] === $items[0]) {
                    $data = $tokens[2][1];
                } elseif ($uid && $tokens[2][2] === $items[0]) {
                    $data = $tokens[2][3];
                } else {
                    $expectedResponse = false;
                    // maybe the server sends another field we didn't wanted
                    $count = count($tokens[2]);
                    // we start with 2, because 0 was already checked
                    for ($i = 2; $i < $count; $i += 2) {
                        if ($tokens[2][$i] === $items[0]) {
                            $data = $tokens[2][$i + 1];
                            $expectedResponse = true;

                            break;
                        }
                    }
                    if (!$expectedResponse) {
                        continue;
                    }
                }
            } else {
                $data = [];
                while (null !== key($tokens[2])) {
                    $data[current($tokens[2])] = next($tokens[2]);
                    next($tokens[2]);
                }
            }

            // if we want only one message, we can ignore everything else and just return
            if (null === $to && is_int($from) && $this->resolveMessageId($tokens, $uid, $uidKey) === $from) {
                // we still need to read all lines
                do {
                    $lastLine = $this->readLine($tokens, $tag);
                } while (!$lastLine);

                return $data;
            }

            if ($uid) {
                $result[$tokens[2][$uidKey]] = $data;
            } else {
                $result[$tokens[0]] = $data;
            }
        }

        return $result;
    }

    /**
     * Write a specific message to a stream (headers and body).
     *
     * @param resource $stream
     *
     * @throws RuntimeException
     */
    #[\Override]
    public function saveMessage(int $uid, mixed $stream): void
    {
        $this->fetchOneByUid('RFC822.HEADER', $uid, $stream);
        fwrite($stream, "\r\n\r\n");
        $this->fetchOneByUid('RFC822.TEXT', $uid, $stream);
    }

    /**
     * Fetch message content.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param ImapUid|iterable<ImapUid> $uids
     *
     * @throws RuntimeException
     */
    #[\Override]
    public function content(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array
    {
        $result = $this->fetch([$rfc.'.TEXT'], $uids, null, $uid);

        return is_array($result) ? $result : [];
    }

    /**
     * Fetch message headers.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param ImapUid|iterable<ImapUid> $uids
     *
     * @throws RuntimeException
     */
    #[\Override]
    public function headers(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array
    {
        $result = $this->fetch([$rfc.'.HEADER'], $uids, null, $uid);

        return '' === $result ? [] : $result;
    }

    /**
     * Fetch message flags.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param ImapUid|iterable<ImapUid> $uids
     *
     * @return array<int, mixed>
     *
     * @throws RuntimeException
     */
    #[\Override]
    public function flags(int|iterable $uids, int|string $uid = IMAP::ST_UID): array
    {
        $result = $this->fetch(['FLAGS'], $uids, uid: $uid);

        return is_array($result) ? $result : [];
    }

    /**
     * Get uid for a given message number.
     *
     * @phpstan-return ($messageNumber is null ? ImapUid[] : ImapUid) message uid for given message or all messages as array
     *
     * @throws MessageNotFoundException
     */
    #[\Override]
    public function getUid(?int $messageNumber = null): array|int
    {
        if ($this->useUidCache()) {
            try {
                $fetched = $this->fetch('UID', 1, INF);
                if (is_array($fetched)) {
                    $this->setUidCache(array_values(array_map(intval(...), $fetched))); // set cache for this folder
                }
            } catch (RuntimeException) {
            }
        }

        $uids = $this->uidCache;
        if (null === $messageNumber) {
            return $uids;
        }

        foreach ($uids as $k => $v) {
            if ($k === $messageNumber) {
                return $v;
            }
        }

        // clear uid cache and run method again
        if ($this->enableUidCache && $this->uidCache) {
            $this->setUidCache(null);

            return $this->getUid($messageNumber);
        }

        throw new MessageNotFoundException('unique id not found');
    }

    /**
     * Get a message number for a uid.
     *
     * @param ImapUid $uid uid
     *
     * @return int message number
     *
     * @throws MessageNotFoundException
     */
    public function getMessageNumber(int $uid): int
    {
        $ids = $this->getUid();
        foreach ($ids as $k => $v) {
            if ($v === $uid) {
                return (int) $k;
            }
        }

        throw new MessageNotFoundException('message number not found');
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
        $result = [];
        $list = $this->requestAndResponse('LIST', $this->escapeString($reference, $folder));
        if (!$list || true === $list) {
            return $result;
        }

        foreach ($list as $item) {
            if (4 !== count($item) || 'LIST' !== $item[0]) {
                continue;
            }
            $result[$item[3]] = ['delimiter' => $item[2], 'flags' => $item[1]];
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
     * @param int|string     $uid    set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                               message numbers instead
     * @param null|string    $item   command used to store a flag
     *
     * @phpstan-param int|iterable<int> $from    message for items or start message if $to !== null
     *
     * @return ($silent is true ? bool : array) new flags if $silent is false, else true or false depending on success
     *
     * @throws RuntimeException
     */
    #[\Override]
    public function store(
        array $flags,
        int|iterable $from,
        float|int|null $to = null,
        ?string $mode = null,
        bool $silent = true,
        int|string $uid = IMAP::ST_UID,
        ?string $item = null,
    ): array|bool {
        $escapedFlags = $this->escapeList($flags);
        $set = $this->buildSequence($from, $to);

        $command = $this->buildUIDCommand('STORE', $uid);
        $item = ('-' === $mode ? '-' : '+').($item ?? 'FLAGS').($silent ? '.SILENT' : '');

        $response = $this->requestAndResponse($command, [$set, $item, $escapedFlags], $silent);
        if ($silent) {
            return (bool) $response;
        }

        $result = [];
        foreach ($response as $token) {
            if ('FETCH' !== $token[1] || 'FLAGS' !== $token[2][0]) {
                continue;
            }
            $result[$token[0]] = $token[2][1];
        }

        return $result;
    }

    /**
     * Append a new message to given folder.
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
    public function appendMessage(string $folder, string $message, ?array $flags = null, \DateTimeInterface|string|null $date = null): bool
    {
        $tokens = [];
        $tokens[] = $this->escapeString($folder);
        if (null !== $flags) {
            $tokens[] = $this->escapeList($flags);
        }
        if (null !== $date) {
            $tokens[] = $this->escapeString($date);
        }
        $tokens[] = $this->escapeString($message);

        return (bool) $this->requestAndResponse('APPEND', $tokens, true);
    }

    /**
     * Copy a message set from current folder to another folder.
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
    #[\Override]
    public function copyMessage(string $folder, int|iterable $from, float|int|null $to = null, int|string $uid = IMAP::ST_UID): bool
    {
        $set = $this->buildSequence($from, $to);
        $command = $this->buildUIDCommand('COPY', $uid);

        return (bool) $this->requestAndResponse($command, [$set, $this->escapeString($folder)], true);
    }

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
    public function copyManyMessages(iterable $messages, string $folder, int|string $uid = IMAP::ST_UID): array|false
    {
        $command = $this->buildUIDCommand('COPY', $uid);

        $set = implode(',', [...$messages]);
        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse($command, $tokens, true);
    }

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
     *
     * @throws RuntimeException
     */
    public function moveMessage(string $folder, int|iterable $from, float|int|null $to = null, int|string $uid = IMAP::ST_UID): bool
    {
        $set = $this->buildSequence($from, $to);
        $command = $this->buildUIDCommand('MOVE', $uid);

        return (bool) $this->requestAndResponse($command, [$set, $this->escapeString($folder)], true);
    }

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
    #[\Override]
    public function moveManyMessages(iterable $messages, string $folder, int|string $uid = IMAP::ST_UID): array|false
    {
        $command = $this->buildUIDCommand('MOVE', $uid);

        $set = implode(',', [...$messages]);
        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse($command, $tokens, true);
    }

    /**
     * Exchange identification information
     * Ref.: https://datatracker.ietf.org/doc/html/rfc2971.
     *
     * @param null|list<int|string> $ids
     *
     * @throws RuntimeException
     */
    #[\Override]
    public function ID(?array $ids = null): array|bool|null
    {
        $token = 'NIL';
        if (is_array($ids) && [] !== $ids) {
            $token = '(';
            foreach ($ids as $id) {
                $token .= '"'.$id.'" ';
            }
            $token = rtrim($token).')';
        }

        return $this->requestAndResponse('ID', [$token], true);
    }

    /**
     * Create a new folder (and parent folders if needed).
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function createFolder(string $folder): bool
    {
        return (bool) $this->requestAndResponse('CREATE', [$this->escapeString($folder)], true);
    }

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
    public function renameFolder(string $old, string $new): bool
    {
        return (bool) $this->requestAndResponse('RENAME', $this->escapeString($old, $new), true);
    }

    /**
     * Delete a folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function deleteFolder(string $folder): bool
    {
        return (bool) $this->requestAndResponse('DELETE', [$this->escapeString($folder)], true);
    }

    /**
     * Subscribe to a folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function subscribeFolder(string $folder): bool
    {
        return (bool) $this->requestAndResponse('SUBSCRIBE', [$this->escapeString($folder)], true);
    }

    /**
     * Unsubscribe from a folder.
     *
     * @param string $folder folder name
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function unsubscribeFolder(string $folder): bool
    {
        return (bool) $this->requestAndResponse('UNSUBSCRIBE', [$this->escapeString($folder)], true);
    }

    /**
     * Apply session saved changes to the server.
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function expunge(): bool
    {
        return (bool) $this->requestAndResponse('EXPUNGE');
    }

    /**
     * Send noop command.
     *
     * @return bool success
     *
     * @throws RuntimeException
     */
    public function noop(): bool
    {
        return (bool) $this->requestAndResponse('NOOP');
    }

    /**
     * Retrieve the quota level settings, and usage statics per mailbox.
     *
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    public function getQuota(string $username): array
    {
        $result = $this->requestAndResponse('GETQUOTA', ['"#user/'.$username.'"']);

        return is_array($result) ? $result : [];
    }

    /**
     * Retrieve the quota settings per user.
     *
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): array
    {
        $result = $this->requestAndResponse('QUOTA', [$quotaRoot]);

        return is_array($result) ? $result : [];
    }

    /**
     * Send idle command.
     *
     * @throws RuntimeException
     */
    public function idle(): void
    {
        $this->sendRequest('IDLE');
        if (!$this->assumedNextLineIgnoreUntagged('+ ')) {
            throw new RuntimeException('idle failed');
        }
    }

    /**
     * Send done command.
     *
     * @throws RuntimeException
     */
    public function done(): void
    {
        $this->write('DONE');
        if (!$this->assumedNextTaggedLine('OK', $tags)) {
            throw new RuntimeException('done failed');
        }
    }

    /**
     * Search for matching messages.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @return list<int> message ids
     *
     * @throws RuntimeException
     */
    public function search(array $params, int|string $uid = IMAP::ST_UID): array
    {
        $command = $this->buildUIDCommand('SEARCH', $uid);
        $response = $this->requestAndResponse($command, $params);
        if (!$response) {
            return [];
        }

        foreach ($response as $ids) {
            if ('SEARCH' === $ids[0]) {
                array_shift($ids);

                return $ids;
            }
        }

        return [];
    }

    /**
     * Get a message overview.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @return array<int, mixed>
     *
     * @throws RuntimeException
     * @throws MessageNotFoundException
     * @throws InvalidMessageDateException
     */
    public function overview(string $sequence, int|string $uid = IMAP::ST_UID): array
    {
        $result = [];
        [$from, $to] = explode(':', $sequence);

        $uids = $this->getUid();
        $ids = [];
        foreach ($uids as $msgn => $v) {
            $id = $uid ? $v : $msgn;
            if (($to >= $id && $from <= $id) || ('*' === $to && $from <= $id)) {
                $ids[] = $id;
            }
        }
        $headers = $this->headers($ids, 'RFC822', $uid);
        foreach ($headers as $id => $rawHeader) {
            $result[$id] = new Header($rawHeader)->attributes;
        }

        return $result;
    }

    /**
     * Fetch message sizes.
     *
     * @param int|string $uid set to IMAP::ST_UID or any string representing the UID - set to IMAP::ST_MSGN to use
     *                        message numbers instead
     *
     * @phpstan-param int|iterable<int> $uids
     *
     * @return array<int, mixed> sizes
     *
     * @throws RuntimeException
     */
    public function sizes(int|iterable $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): array
    {
        $result = $this->fetch($rfc.'.SIZE', from: $uids, uid: $uid);

        return \is_array($result) ? $result : [];
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

    /**
     * Enable tls on the current connection.
     *
     * @throws ConnectionFailedException
     * @throws RuntimeException
     */
    protected function enableStartTls(): void
    {
        $response = $this->requestAndResponse('STARTTLS');
        $result = $response && stream_socket_enable_crypto($this->stream, true, $this->cryptoMethod);
        if (!$result) {
            throw new ConnectionFailedException('failed to enable TLS');
        }
    }

    /**
     * Get the next line and check if it starts with a given string.
     *
     * @throws RuntimeException
     */
    protected function assumedNextLine(string $start): bool
    {
        return str_starts_with($this->nextLine(), $start);
    }

    /**
     * Get the next line and check if it starts with a given string
     * The server can send untagged status updates starting with '*' if we are not looking for a status update,
     * the untagged lines will be ignored.
     *
     * @throws RuntimeException
     */
    protected function assumedNextLineIgnoreUntagged(string $start): bool
    {
        do {
            $line = $this->nextLine();
        } while (!str_starts_with($start, '*') && $this->isUntaggedLine($line));

        return str_starts_with($line, $start);
    }

    /**
     * Get the next line and split the tag.
     *
     * @param null|string $tag reference tag
     *
     * @return string next line
     *
     * @throws RuntimeException
     */
    protected function nextTaggedLine(?string &$tag): string
    {
        $line = $this->nextLine();
        if (str_contains($line, ' ')) {
            [$tag, $line] = explode(' ', $line, 2);
        }

        return $line;
    }

    /**
     * Get the next line and split the tag
     * The server can send untagged status updates starting with '*', the untagged lines will be ignored.
     *
     * @param string $tag reference tag
     *
     * @return string next line
     *
     * @throws RuntimeException
     */
    protected function nextTaggedLineIgnoreUntagged(string &$tag): string
    {
        do {
            $line = $this->nextLine();
        } while ($this->isUntaggedLine($line));

        [$tag, $line] = explode(' ', $line, 2);

        return $line;
    }

    /**
     * Get the next line and check if it contains a given string and split the tag.
     *
     * @throws RuntimeException
     */
    protected function assumedNextTaggedLine(string $start, ?string &$tag): bool
    {
        return str_contains($this->nextTaggedLine($tag), $start);
    }

    /**
     * Get the next line and check if it contains a given string and split the tag.
     *
     * @throws RuntimeException
     */
    protected function assumedNextTaggedLineIgnoreUntagged(string $start, string &$tag): bool
    {
        $line = $this->nextTaggedLineIgnoreUntagged($tag);

        return str_contains($line, $start);
    }

    /**
     * RFC3501 - 2.2.2
     * Data transmitted by the server to the client and status responses
     * that do not indicate command completion are prefixed with the token
     * "*", and are called untagged responses.
     */
    protected function isUntaggedLine(string $line): bool
    {
        return str_starts_with($line, '* ');
    }

    /**
     * Split a given line into tokens. A token is either a scalar string or a
     * nested list produced by parenthesized IMAP data (e.g. FLAGS, ENVELOPE).
     *
     * The returned structure is a flat list at the top level; nested IMAP lists
     * become nested PHP arrays. Example:
     *   "* 1 FETCH (FLAGS (\Seen) UID 42)"
     *   → ['1', 'FETCH', [['FLAGS', ['\Seen'], 'UID', '42']]]
     *
     * @param string        $line   raw IMAP response line (without CRLF)
     * @param null|resource $stream if provided, literal string data ({N} octets) is
     *                              written to this stream instead of buffered in memory
     *
     * @return list<list<mixed>|string>
     *
     * @throws RuntimeException
     */
    protected function decodeLine(string $line, mixed $stream = null): array
    {
        $tokens = [];
        $stack = [];

        //  replace any trailing <NL> including spaces with a single space
        $line = rtrim($line).' ';
        while (($pos = strpos($line, ' ')) !== false) {
            $token = substr($line, 0, $pos);
            if ('' === $token) {
                $line = substr($line, $pos + 1);

                continue;
            }
            while ('(' === $token[0]) {
                $stack[] = $tokens;
                $tokens = [];
                $token = substr($token, 1);
            }
            if (('"' === $token[0]) && preg_match('%^\(*"((?:[^"\\\]|\\\.)*)"( |$)%', $line, $matches)) {
                $tokens[] = $matches[1];
                $line = substr($line, strlen($matches[0]));

                continue;
            }
            if ('{' === $token[0]) {
                $endPos = strpos($token, '}');
                $chars = substr($token, 1, $endPos - 1);
                if (is_numeric($chars)) {
                    $charsToRead = (int) $chars;
                    $token = (null === $stream) ? '' : 'STREAM CONTENT';
                    while ($charsToRead > 0) {
                        $chunk = fread($this->stream, min($charsToRead, 2048));
                        if (null === $stream) {
                            $token .= $chunk;
                        }
                        if (false === $chunk) {
                            throw new RuntimeException('empty response.');
                        }
                        $chunkSize = strlen($chunk);
                        if (null !== $this->logger && $chunkSize > 0) {
                            $this->logger->debug('>> {chunkSize} bytes read', ['chunkSize' => $chunkSize]);
                        }
                        $charsToRead -= $chunkSize;
                        if (is_resource($stream)) {
                            // store chunk to another file
                            $charsWritten = fwrite($stream, $chunk, $chunkSize);
                            if ($charsWritten !== $chunkSize) {
                                throw new RuntimeException('error writing to email file.');
                            }
                        }
                    }

                    $line = $this->nextLine();
                    $tokens[] = $token;
                    $line = trim($line).' ';

                    continue;
                }
            }
            if ($stack && ')' === $token[strlen($token) - 1]) {
                // closing braces are not separated by spaces, so we need to count them
                $braces = strlen($token);
                $token = rtrim($token, ')');
                // only count braces if more than one
                $braces -= strlen($token) + 1;
                // only add if token had more than just closing braces
                if ('' !== rtrim($token)) {
                    $tokens[] = rtrim($token);
                }
                $token = $tokens;
                $tokens = array_pop($stack);
                // special handling if more than one closing brace
                while ($braces-- > 0) {
                    $tokens[] = $token;
                    $token = $tokens;
                    $tokens = array_pop($stack);
                }
            }
            $tokens[] = $token;
            $line = substr($line, $pos + 1);
        }

        // maybe the server forgot to send some closing braces
        while ($stack) {
            $child = $tokens;
            $tokens = array_pop($stack);
            $tokens[] = $child;
        }

        return $tokens;
    }

    /**
     * @param null|resource $stream
     *
     * @throws RuntimeException
     */
    protected function fetchOneByUid(string $item, int $msgUid, mixed $stream = null): string
    {
        $itemList = $this->escapeList([$item]);
        $tag = '';
        $this->sendRequest($this->buildUIDCommand('FETCH', IMAP::ST_UID), [(string) $msgUid, $itemList], $tag);
        $data = '';
        $tokens = []; // define $tokens variable before first use
        while (!$this->readLine($tokens, $tag, false, $stream)) {
            // ignore other responses
            if ('FETCH' !== $tokens[1]) {
                continue;
            }

            // find array key of UID value; try the last elements, or search for it
            $count = count($tokens[2]);
            if ('UID' === $tokens[2][$count - 2]) {
                $uidKey = $count - 1;
            } elseif ('UID' === $tokens[2][0]) {
                $uidKey = 1;
            } else {
                $found = array_search('UID', $tokens[2], true);
                if (false === $found) {
                    continue;
                }

                $uidKey = $found + 1;
            }

            // ignore other messages
            if ((int) $tokens[2][$uidKey] !== $msgUid) {
                continue;
            }

            if ($tokens[2][0] === $item) {
                $data = $tokens[2][1];
            } elseif ($tokens[2][2] === $item) {
                $data = $tokens[2][3];
            } else {
                $expectedResponse = false;
                // maybe the server send another field we didn't wanted
                $count = count($tokens[2]);
                // we start with 2, because 0 was already checked
                for ($i = 2; $i < $count; $i += 2) {
                    if ($tokens[2][$i] === $item) {
                        $data = $tokens[2][$i + 1];
                        $expectedResponse = true;

                        break;
                    }
                }
                if (!$expectedResponse) {
                    continue;
                }
            }
        }

        return $data;
    }

    private function useUidCache(): bool
    {
        return !$this->enableUidCache
                || null === $this->uidCache
                || [] === $this->uidCache;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function resolveMessageId(array $tokens, int|string $uid, int $uidKey): int
    {
        return $uid ? (int) $tokens[2][$uidKey] : (int) $tokens[0];
    }

    /**
     * @param list<mixed> $tokens
     */
    private function resolveUidKey(array $tokens): ?int
    {
        $count = count($tokens);
        if ('UID' === $tokens[$count - 2]) {
            return $count - 1;
        }

        if ('UID' === $tokens[0]) {
            return 1;
        }

        $found = array_search('UID', $tokens, true);
        if (false === $found) {
            return null;
        }

        return $found + 1;
    }
}
