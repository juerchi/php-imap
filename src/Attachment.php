<?php

/*
* File:     Attachment.php
* Category: -
* Author:   M. Goldenbaum
* Created:  16.03.18 19:37
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\String\UnicodeString;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Support\MimeHeader;

/**
 * Class Attachment.
 *
 * @property int    $partNumber
 * @property int    $size
 * @property string $content
 * @property string $type
 * @property string $contentType
 * @property string $id
 * @property string $name
 * @property string $disposition
 * @property string $imgSrc
 *
 * @method int         getPartNumber()
 * @method int         setPartNumber(integer $partNumber)
 * @method string      getContent()
 * @method string      setContent(string $content)
 * @method string      getType()
 * @method string      setType(string $type)
 * @method string      getContentType()
 * @method string      setContentType(string $contentType)
 * @method null|string getId()
 * @method string      setId(string $id)
 * @method string      getSize()
 * @method string      setSize(integer $size)
 * @method null|string getName()
 * @method string      getDisposition()
 * @method string      setDisposition(string $disposition)
 * @method string      getImgSrc()
 * @method string      setImgSrc(string $imgSrc)
 */
final class Attachment
{
    private Message $oMessage;

    private Part $part;

    /**
     * Attribute holder.
     */
    public private(set) array $attributes = [
        'content' => null,
        'type' => null,
        'partNumber' => 0,
        'contentType' => null,
        'id' => null,
        'name' => null,
        'disposition' => null,
        'imgSrc' => null,
        'size' => null,
    ];

    /**
     * Default mask.
     */
    private string $mask;

    public function __construct(Message $oMessage, Part $part)
    {
        $this->oMessage = $oMessage;
        $this->part = $part;
        $this->partNumber = $part->partNumber;
        $this->mask = $this->oMessage->getClient()->getDefaultAttachmentMask();

        $this->findType();
        $this->fetch();
    }

    /**
     * Call dynamic attribute setter and getter methods.
     *
     * @throws MethodNotFoundException
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (0 === stripos($method, 'get')) {
            $name = new UnicodeString(substr($method, 3))->camel()->toString();

            return $this->attributes[$name] ?? null;
        }

        if (0 === stripos($method, 'set')) {
            $name = new UnicodeString(substr($method, 3))->camel()->toString();
            $this->attributes[$name] = array_pop($arguments);

            return $this->attributes[$name];
        }

        throw new MethodNotFoundException('Method '.self::class.'::'.$method.'() is not supported');
    }

    public function __set(mixed $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(mixed $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __get(mixed $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Set the attachment name and try to decode it.
     */
    public function setName(?string $name): void
    {
        if (null !== $name) {
            $this->name = MimeHeader::decode($name);
        }
    }

    /**
     * Get the attachment mime type.
     */
    public function getMimeType(): ?string
    {
        return new \finfo()->buffer($this->getContent(), FILEINFO_MIME_TYPE) ?: null;
    }

    /**
     * Try to guess the attachment file extension.
     */
    public function getExtension(): ?string
    {
        $mimeType = $this->getMimeType();

        return null === $mimeType ? null : array_first(MimeTypes::getDefault()->getExtensions($mimeType));
    }

    public function getMessage(): Message
    {
        return $this->oMessage;
    }

    /**
     * Set the default mask.
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
     * Get the used default mask.
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
     * Determine the structure type.
     */
    private function findType(): void
    {
        $this->type = match ($this->part->type) {
            IMAP::ATTACHMENT_TYPE_MESSAGE => 'message',
            IMAP::ATTACHMENT_TYPE_APPLICATION => 'application',
            IMAP::ATTACHMENT_TYPE_AUDIO => 'audio',
            IMAP::ATTACHMENT_TYPE_IMAGE => 'image',
            IMAP::ATTACHMENT_TYPE_VIDEO => 'video',
            IMAP::ATTACHMENT_TYPE_MODEL => 'model',
            IMAP::ATTACHMENT_TYPE_TEXT => 'text',
            IMAP::ATTACHMENT_TYPE_MULTIPART => 'multipart',
            default => 'other',
        };
    }

    /**
     * Fetch the given attachment.
     */
    private function fetch(): void
    {
        $content = $this->part->content;

        $this->contentType = $this->part->contentType;
        $this->content = $this->oMessage->decodeString($content, $this->part->encoding);

        if (($id = $this->part->id) !== null) {
            $this->id = str_replace(['<', '>'], '', $id);
        } else {
            $this->id = hash('sha256', (string) microtime(true));
        }

        $this->size = $this->part->bytes;
        $this->disposition = $this->part->disposition;

        if (($filename = $this->part->filename) !== null) {
            $this->setName($filename);
        } elseif (($name = $this->part->name) !== null) {
            $this->setName($name);
        } else {
            $this->setName('undefined');
        }

        if (IMAP::ATTACHMENT_TYPE_MESSAGE === $this->part->type) {
            if ($this->part->ifdescription) {
                $this->setName($this->part->description);
            } else {
                $this->setName($this->part->subtype);
            }
        }
    }
}
