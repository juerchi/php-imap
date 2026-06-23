<?php

/*
* File: Structure.php
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
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;

/**
 * Class Structure.
 */
final class Structure
{
    /**
     * Raw structure.
     */
    public mixed $raw = '';

    /**
     * Message type (if multipart or not).
     *
     * @var int
     */
    public $type = IMAP::MESSAGE_TYPE_TEXT;

    /**
     * All available parts.
     *
     * @var Part[]
     */
    public array $parts = [];

    private ?Header $header = null;

    /**
     * Config holder.
     */
    private array $config = [];

    /**
     * @throws MessageContentFetchingException
     * @throws InvalidMessageDateException
     */
    public function __construct(mixed $rawStructure, Header $header)
    {
        $this->raw = $rawStructure;
        $this->header = $header;
        $this->config = ClientManager::get('options');
        $this->parse();
    }

    /**
     * Determine the message content type.
     */
    public function findContentType(): void
    {
        $contentType = $this->header->get('contentType')->first();
        if ($contentType && 0 === stripos($contentType, 'multipart')) {
            $this->type = IMAP::MESSAGE_TYPE_MULTIPART;
        } else {
            $this->type = IMAP::MESSAGE_TYPE_TEXT;
        }
    }

    /**
     * Find all available parts.
     *
     * @throws MessageContentFetchingException
     * @throws InvalidMessageDateException
     */
    public function find_parts(): array
    {
        if (IMAP::MESSAGE_TYPE_MULTIPART === $this->type) {
            if (($boundary = $this->header->getBoundary()) === null) {
                throw new MessageContentFetchingException('no content found', 0);
            }

            return $this->detectParts($boundary, $this->raw);
        }

        return [
            new Part($this->raw, $this->header),
        ];
    }

    /**
     * Try to find a boundary if possible.
     *
     * @Depricated since version 2.4.4
     */
    public function getBoundary(): ?string
    {
        return $this->header->getBoundary();
    }

    /**
     * Parse the given raw structure.
     *
     * @throws MessageContentFetchingException
     * @throws InvalidMessageDateException
     */
    private function parse(): void
    {
        $this->findContentType();
        $this->parts = $this->find_parts();
    }

    /**
     * Find all available headers and return the leftover body segment.
     *
     * @return Part[]
     *
     * @throws InvalidMessageDateException
     */
    private function parsePart(string $context, int $partNumber = 0): array
    {
        $body = $context;
        while (($pos = strpos($body, "\r\n")) > 0) {
            $body = substr($body, $pos + 2);
        }
        $headers = substr($context, 0, -strlen($body));
        $body = substr($body, 0, -2);

        $headers = new Header($headers);
        $boundary = $headers->getBoundary();
        if (null !== $boundary) {
            return $this->detectParts($boundary, $body, $partNumber);
        }

        return [
            new Part($body, $headers, $partNumber),
        ];
    }

    /**
     * @throws InvalidMessageDateException
     */
    private function detectParts(string $boundary, string $context, int $partNumber = 0): array
    {
        $baseParts = explode($boundary, $context);
        $finalParts = [];
        foreach ($baseParts as $ctx) {
            $ctx = substr($ctx, 2);
            if ('--' !== $ctx && '' !== $ctx) {
                $parts = $this->parsePart($ctx, $partNumber);
                foreach ($parts as $part) {
                    $finalParts[] = $part;
                    $partNumber = $part->partNumber;
                }
                ++$partNumber;
            }
        }

        return $finalParts;
    }
}
