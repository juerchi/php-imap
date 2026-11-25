<?php

/*
* File: Header.php
* Category: -
* Author: M.Goldenbaum
* Created: 17.09.20 20:38
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Support\AddressListParser;
use Webklex\PHPIMAP\Support\MimeHeader;

final class Header
{
    /**
     * Raw header.
     */
    public string $raw = '';

    /**
     * Attribute holder.
     *
     * @var Attribute[]
     */
    public private(set) array $attributes = [];

    /**
     * Fallback Encoding.
     */
    public string $fallbackEncoding = 'UTF-8';

    private array $config;

    /**
     * Header constructor.
     *
     * @throws InvalidMessageDateException
     */
    public function __construct(string $rawHeader)
    {
        $this->raw = $rawHeader;
        $this->config = ClientManager::get('options');
        $this->parse();
    }

    /**
     * Call dynamic attribute setter and getter methods.
     *
     * @throws MethodNotFoundException
     */
    public function __call(string $method, array $arguments): Attribute
    {
        if (str_starts_with($method, 'get')) {
            $name = preg_replace('/(.)(?=[A-Z])/u', '$1_', strtolower(substr($method, 3)));
            if (\array_key_exists($name, $this->attributes)) {
                return $this->attributes[$name];
            }
        }

        throw new MethodNotFoundException('Method '.self::class.'::'.$method.'() is not supported');
    }

    public function __get(string $name): Attribute
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value, true);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /**
     * Get a specific header attribute.
     */
    public function get(string $name): Attribute
    {
        $name = $this->normalizeName($name);

        return $this->attributes[$name] ?? new Attribute($name);
    }

    public function has(string $name): bool
    {
        $name = $this->normalizeName($name);

        return isset($this->attributes[$name]);
    }

    /**
     * Set a specific attribute.
     */
    public function set(string $name, mixed $value, bool $strict = false): Attribute
    {
        if (isset($this->attributes[$name]) && !$strict) {
            $this->attributes[$name]->add($value, strict: true);
        } else {
            $this->attributes[$name] = new Attribute($name, $value);
        }

        return $this->attributes[$name];
    }

    /**
     * Perform a regex match all on the raw header and return the first result.
     */
    public function find(string $pattern): ?string
    {
        if (preg_match_all($pattern, $this->raw, $matches)) {
            return $matches[1][0] ?? null;
        }

        return null;
    }

    /**
     * Try to find a boundary if possible.
     */
    public function getBoundary(): ?string
    {
        $regex = $this->config['boundary'] ?? '/boundary=(.*?(?=;)|(.*))/i';
        $boundary = $this->find($regex);
        if (null === $boundary) {
            return null;
        }

        return $this->clearBoundaryString($boundary);
    }

    /**
     * Parse mail headers from a string.
     *
     * Pure PHP counterpart of imap_rfc822_parse_headers().
     */
    public function rfc822_parse_headers(string $rawHeaders): object
    {
        $headers = [];
        $lines = explode("\r\n", preg_replace("/\r\n\\s/", ' ', preg_replace('/\r\n|\r|\n/', "\r\n", $rawHeaders)));
        $prevHeader = null;
        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }
            if ("\n" === $line[0]) {
                $line = substr($line, 1);
            }
            if ("\t" === $line[0]) {
                $line = substr($line, 1);
                $line = trim(rtrim($line));
                if (null !== $prevHeader) {
                    $headers[$prevHeader][] = $line;
                }
            } elseif (' ' === $line[0]) {
                $line = substr($line, 1);
                $line = trim(rtrim($line));
                if (null !== $prevHeader) {
                    if (!isset($headers[$prevHeader])) {
                        $headers[$prevHeader] = '';
                    }
                    if (is_array($headers[$prevHeader])) {
                        $headers[$prevHeader][] = $line;
                    } else {
                        $headers[$prevHeader] .= $line;
                    }
                }
            } elseif (($pos = strpos($line, ':')) > 0) {
                $key = strtolower(trim(rtrim(substr($line, 0, $pos))));
                $key = str_replace('-', '_', $key);

                $value = trim(rtrim(substr($line, $pos + 1)));
                if (isset($headers[$key])) {
                    $headers[$key][] = $value;
                } else {
                    $headers[$key] = [$value];
                }
                $prevHeader = $key;
            }
        }

        foreach ($headers as $key => $values) {
            $value = null;

            switch ($key) {
                case 'from':
                case 'to':
                case 'cc':
                case 'bcc':
                case 'reply_to':
                case 'sender':
                    $value = $this->decodeAddresses($values);
                    $headers[$key.'address'] = implode(', ', $values);

                    break;

                case 'subject':
                    $value = $values[0];

                    break;

                default:
                    if (is_array($values)) {
                        foreach ($values as $k => $v) {
                            if ('' === $v) {
                                unset($values[$k]);
                            }
                        }
                        $availableValues = count($values);
                        if (1 === $availableValues) {
                            $value = array_pop($values);
                        } elseif (2 === $availableValues) {
                            $value = implode(' ', $values);
                        } elseif ($availableValues > 2) {
                            $value = array_values($values);
                        } else {
                            $value = '';
                        }
                    }

                    break;
            }
            $headers[$key] = $value;
        }

        // like c-client, Reply-To and Sender default to From
        if ([] !== ($headers['from'] ?? [])) {
            foreach (['reply_to', 'sender'] as $key) {
                if (!isset($headers[$key])) {
                    $headers[$key] = array_map(static fn (object $address): object => clone $address, $headers['from']);
                    $headers[$key.'address'] = $headers['fromaddress'];
                }
            }
        }

        return (object) $headers;
    }

    /**
     * Decode MIME header elements.
     *
     * Every encoded word (any charset, Q and B encoding) is resolved using MimeHeader::decode().
     *
     * @param string $text The MIME text
     *
     * @return array the decoded elements are returned in an array of objects, where each
     *               object has two properties, charset and text
     */
    public function mime_header_decode(string $text): array
    {
        // mb_decode_mimeheader() resolves every encoded word (any charset, Q and B) and returns UTF-8
        return [
            (object) [
                'charset' => 'UTF-8',
                'text' => MimeHeader::decode($text),
            ],
        ];
    }

    /**
     * Convert the encoding.
     */
    public function convertEncoding(string $str, string $from = 'ISO-8859-2', string $to = 'UTF-8'): mixed
    {
        $from = EncodingAliases::get($from, $this->fallbackEncoding);
        $to = EncodingAliases::get($to, $this->fallbackEncoding);

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
        // convertEncoding() function basically means convertToUtf8(), so when we convert ASCII string into UTF-8, it gets broken.
        if ('us-ascii' === strtolower($from) && 'UTF-8' === $to) {
            return $str;
        }

        try {
            if (function_exists('iconv') && 'UTF-7' !== $from && 'UTF-7' !== $to) {
                return iconv($from, $to, $str);
            }

            if (!$from) {
                return mb_convert_encoding($str, $to);
            }

            return mb_convert_encoding($str, $to, $from);
        } catch (\Exception) {
            if (str_contains($from, '-')) {
                $from = str_replace('-', '', $from);

                return $this->convertEncoding($str, $from, $to);
            }

            return $str;
        }
    }

    /**
     * Get the encoding of a given object.
     */
    public function getEncoding(object|string $structure): string
    {
        if (is_object($structure) && property_exists($structure, 'parameters')) {
            foreach ($structure->parameters as $parameter) {
                if ('charset' === strtolower($parameter->attribute)) {
                    return EncodingAliases::get($parameter->value, $this->fallbackEncoding);
                }
            }
        } elseif (is_object($structure) && property_exists($structure, 'charset')) {
            return EncodingAliases::get($structure->charset, $this->fallbackEncoding);
        } elseif (is_string($structure)) {
            $result = mb_detect_encoding($structure);

            return false !== $result ? $result : $this->fallbackEncoding;
        }

        return $this->fallbackEncoding;
    }

    /**
     * Remove all unwanted chars from a given boundary.
     */
    private function clearBoundaryString(string $str): string
    {
        return str_replace(['"', '\r', '\n', "\n", "\r", ';', '\\s'], '', $str);
    }

    /**
     * Parse the raw headers.
     *
     * @throws InvalidMessageDateException
     */
    private function parse(): void
    {
        $header = $this->rfc822_parse_headers($this->raw);

        $this->extractAddresses($header);

        if (property_exists($header, 'subject')) {
            $this->set('subject', $this->decode($header->subject));
        }
        if (property_exists($header, 'references')) {
            $this->set('references', $this->decode($header->references));
        }
        if (property_exists($header, 'messageId')) {
            $this->set('messageId', str_replace(['<', '>'], '', $header->message_id));
        }

        $this->parseDate($header);
        foreach ($header as $key => $value) {
            $key = strtolower(trim(rtrim($key)));
            if (!isset($this->attributes[$key])) {
                $this->set($key, $value);
            }
        }

        $this->extractHeaderExtensions();
        $this->findPriority();
    }

    /**
     * Check if a given pair of strings has been decoded.
     */
    private function notDecoded(string $encoded, string $decoded): bool
    {
        return str_starts_with($decoded, '=?')
            && strlen($decoded) - 2 === strpos($decoded, '?=')
            && str_contains($encoded, $decoded);
    }

    /**
     * Try to decode a specific header.
     */
    private function decode(array|string|null $value): mixed
    {
        if (is_array($value)) {
            return $this->decodeArray($value);
        }

        $originalValue = $value;
        $decoder = $this->config['decoder']['message'];

        if (null !== $value) {
            if (str_contains($value, '=?')) {
                $value = 'iconv' === $decoder ? iconv_mime_decode($value) : MimeHeader::decode($value);
            }

            if ($this->notDecoded($originalValue, $value)) {
                $value = $this->convertEncoding($originalValue, $this->getEncoding($originalValue));
            }
        }

        return $value;
    }

    /**
     * Decode a given array.
     */
    private function decodeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = $this->decode($value);
        }

        return $values;
    }

    /**
     * Try to extract the priority from a given raw header string.
     */
    private function findPriority(): void
    {
        $priority = (int) (string) $this->get('x_priority');

        $priority = match ($priority) {
            IMAP::MESSAGE_PRIORITY_HIGHEST,
            IMAP::MESSAGE_PRIORITY_HIGH,
            IMAP::MESSAGE_PRIORITY_NORMAL,
            IMAP::MESSAGE_PRIORITY_LOW,
            IMAP::MESSAGE_PRIORITY_LOWEST => $priority,
            default => IMAP::MESSAGE_PRIORITY_UNKNOWN,
        };

        $this->set('priority', $priority);
    }

    /**
     * Extract a given part as an address array from a given header.
     *
     * @param iterable<string> $values
     */
    private function decodeAddresses(iterable $values): array
    {
        $addresses = [];

        foreach ($values as $address) {
            array_push($addresses, ...AddressListParser::parse($address));
        }

        return $addresses;
    }

    /**
     * Extract a given part as an address array from a given header.
     */
    private function extractAddresses(object $header): void
    {
        foreach (['from', 'to', 'cc', 'bcc', 'reply_to', 'sender'] as $key) {
            if (property_exists($header, $key)) {
                $this->set($key, $this->parseAddresses($header->{$key}));
            }
        }
    }

    /**
     * Parse Addresses.
     *
     * @return Address[]
     */
    private function parseAddresses(mixed $list): array
    {
        $addresses = [];

        if (!is_iterable($list)) {
            return $addresses;
        }

        foreach ($list as $item) {
            $address = (object) $item;
            if (!property_exists($address, 'mailbox')) {
                $address->mailbox = false;
            }
            if (!property_exists($address, 'host')) {
                $address->host = false;
            }
            if (!property_exists($address, 'personal')) {
                $address->personal = false;
            } else {
                $personalParts = $this->mime_header_decode($address->personal);
                $address->personal = '';
                foreach ($personalParts as $p) {
                    $address->personal .= $this->convertEncoding($p->text, $this->getEncoding($p));
                }

                if (str_starts_with($address->personal, "'")) {
                    $address->personal = str_replace("'", '', $address->personal);
                }
            }

            if ('.SYNTAX-ERROR.' === $address->host) {
                $address->host = '';
            }
            if ('UNEXPECTED_DATA_AFTER_ADDRESS' === $address->mailbox) {
                $address->mailbox = '';
            }

            $address->mail = ($address->mailbox && $address->host) ? $address->mailbox.'@'.$address->host : false;
            $address->full = ($address->personal) ? $address->personal.' <'.$address->mail.'>' : $address->mail;

            $addresses[] = new Address($address);
        }

        return $addresses;
    }

    /**
     * Search and extract potential header extensions.
     */
    private function extractHeaderExtensions(): void
    {
        foreach ($this->attributes as $key => $value) {
            $value = (string) $value;
            // Only parse strings and don't parse any attributes like the user-agent
            if (
                !\in_array($key, ['user-agent', 'subject'], true)
                && ($pos = strpos($value, ';')) !== false
            ) {
                $original = substr($value, 0, $pos);
                $this->set($key, trim(rtrim($original)), true);

                // Get all potential extensions
                $extensions = explode(';', substr($value, $pos + 1));
                foreach ($extensions as $extension) {
                    if (($pos = strpos($extension, '=')) !== false) {
                        $key = substr($extension, 0, $pos);
                        $key = strtolower(trim(rtrim($key)));

                        if (!isset($this->attributes[$key])) {
                            $value = substr($extension, $pos + 1);
                            $value = str_replace('"', '', $value);
                            $value = trim(rtrim($value));

                            $this->set($key, $value);
                        }
                    }
                }
            }
        }
    }

    /**
     * Exception handling for invalid dates.
     *
     * @throws InvalidMessageDateException
     */
    private function parseDate(object $header): void
    {
        if (property_exists($header, 'date')) {
            $date = $header->date;

            try {
                $parsedDate = DateParser::parse($date);
            } catch (\DateMalformedStringException $e) {
                if (!isset($this->config['fallback_date'])) {
                    throw new InvalidMessageDateException(
                        'Invalid message date. ID:'.$this->get('messageId').' Date:'.$header->date.'/'.$date,
                        1100,
                        $e
                    );
                }

                $parsedDate = new \DateTimeImmutable($this->config['fallback_date']);
            }
            $this->set('date', $parsedDate);
        }
    }

    private function normalizeName(string $name): string
    {
        return str_replace(['-', ' '], '_', strtolower($name));
    }
}
