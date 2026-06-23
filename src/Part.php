<?php

declare(strict_types=1);

/*
* File: Part.php
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

/**
 * Class Part.
 */
final class Part
{
    /**
     * Raw part.
     */
    public string $raw = '';

    /**
     * Part type.
     */
    public int $type = IMAP::MESSAGE_TYPE_TEXT;

    /**
     * Part content.
     */
    public string $content = '';

    /**
     * Part subtype.
     */
    public ?string $subtype = null;

    /**
     * Part charset - if available.
     */
    public string $charset = 'utf-8';

    /**
     * Part encoding method.
     */
    public int $encoding = IMAP::MESSAGE_ENC_OTHER;

    /**
     * Alias to check if the part is an attachment.
     */
    public bool $ifdisposition = false;

    /**
     * Indicates if the part is an attachment.
     */
    public ?string $disposition = null;

    /**
     * Alias to check if the part has a description.
     */
    public bool $ifdescription = false;

    /**
     * Part description if available.
     */
    public ?string $description = null;

    /**
     * Part filename if available.
     */
    public ?string $filename = null;

    /**
     * Part name if available.
     */
    public ?string $name = null;

    /**
     * Part id if available.
     */
    public ?string $id = null;

    /**
     * The part number of the current part.
     */
    public int $partNumber = 0;

    /**
     * Part length in bytes.
     */
    public int $bytes;

    /**
     * Part content type.
     */
    public ?string $contentType = null;

    private ?Header $header = null;

    /**
     * @throws InvalidMessageDateException
     */
    public function __construct(string $rawPart, ?Header $header = null, int $partNumber = 0)
    {
        $this->raw = $rawPart;
        $this->header = $header;
        $this->partNumber = $partNumber;
        $this->parse();
    }

    /**
     * Check if the current part represents an attachment.
     */
    public function isAttachment(): bool
    {
        $validDisposition = in_array(
            strtolower($this->disposition ?? ''),
            ClientManager::get('options.dispositions'),
            true
        );

        if (
            IMAP::MESSAGE_TYPE_TEXT === $this->type
            && (!$this->ifdisposition || empty($this->disposition) || !$validDisposition)) {
            if ((null === $this->subtype || in_array(
                strtolower($this->subtype),
                ['plain', 'html'],
                true
            )) && null === $this->filename && null === $this->name) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse the raw parts.
     *
     * @throws InvalidMessageDateException
     */
    private function parse(): void
    {
        if (null === $this->header) {
            $body = $this->findHeaders();
        } else {
            $body = $this->raw;
        }

        $this->parseDisposition();
        $this->parseDescription();
        $this->parseEncoding();

        $this->charset = $this->header->get('charset')->first() ?? 'utf-8';
        $this->name = (string) $this->header->get('name');
        $this->filename = (string) $this->header->get('filename');

        if (!$this->header->get('id')->isEmpty()) {
            $this->id = (string) $this->header->get('id');
        } elseif (!$this->header->get('x_attachment_id')->isEmpty()) {
            $this->id = (string) $this->header->get('x_attachment_id');
        } elseif (!$this->header->get('content_id')->isEmpty()) {
            $this->id = strtr(
                (string) $this->header->get('content_id'),
                ['<' => '', '>' => '']
            );
        }

        $contentTypes = $this->header->get('contentType')->all();
        if ([] !== $contentTypes) {
            $this->subtype = $this->parseSubtype($contentTypes);
            $contentType = $contentTypes[0];
            $parts = explode(';', $contentType);
            $this->contentType = trim($parts[0]);
        }

        $this->content = trim(rtrim($body));
        $this->bytes = strlen($this->content);
    }

    /**
     * Find all available headers and return the leftover body segment.
     *
     * @throws InvalidMessageDateException
     */
    private function findHeaders(): string
    {
        $body = $this->raw;
        while (($pos = strpos($body, "\r\n")) > 0) {
            $body = substr($body, $pos + 2);
        }
        $headers = substr($this->raw, 0, -strlen($body));
        $body = substr($body, 0, -2);

        $this->header = new Header($headers);

        return $body;
    }

    /**
     * Try to parse the subtype if any is present.
     *
     * @param string|string[] $contentType
     */
    private function parseSubtype(array|string $contentType): ?string
    {
        if (is_array($contentType)) {
            foreach ($contentType as $part) {
                $pos = strpos($part, '/');
                if (false !== $pos) {
                    return $this->parseSubtype($part);
                }
            }

            return null;
        }
        $pos = strpos($contentType, '/');
        if (false !== $pos) {
            return substr($contentType, $pos + 1);
        }

        return null;
    }

    /**
     * Try to parse the disposition if any is present.
     */
    private function parseDisposition(): void
    {
        $contentDisposition = $this->header->get('content_disposition')->first();
        if (null !== $contentDisposition) {
            $this->ifdisposition = true;
            $this->disposition = (is_array($contentDisposition))
                    ? implode(' ', $contentDisposition)
                    : explode(';', $contentDisposition)[0];
        }
    }

    /**
     * Try to parse the description if any is present.
     */
    private function parseDescription(): void
    {
        $contentDescription = $this->header->get('content_description')->first();
        if (null !== $contentDescription) {
            $this->ifdescription = true;
            $this->description = $contentDescription;
        }
    }

    /**
     * Try to parse the encoding if any is present.
     */
    private function parseEncoding(): void
    {
        $encoding = $this->header->get('content_transfer_encoding')->first();
        if (null !== $encoding) {
            $this->encoding = match (strtolower($encoding)) {
                'quoted-printable' => IMAP::MESSAGE_ENC_QUOTED_PRINTABLE,
                'base64' => IMAP::MESSAGE_ENC_BASE64,
                '7bit' => IMAP::MESSAGE_ENC_7BIT,
                '8bit' => IMAP::MESSAGE_ENC_8BIT,
                'binary' => IMAP::MESSAGE_ENC_BINARY,
                default => IMAP::MESSAGE_ENC_OTHER,
            };
        }
    }
}
