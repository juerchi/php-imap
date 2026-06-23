<?php

/*
* File: MessageMask.php
* Category: Mask
* Author: M.Goldenbaum
* Created: 14.03.19 20:49
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Support\Masks;

use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

class MessageMask extends Mask
{
    /** @var Message */
    protected object $parent;

    /**
     * Get the message HTML body.
     */
    public function getHtmlBody(): ?string
    {
        $bodies = $this->parent;
        if (!isset($bodies['html'])) {
            return null;
        }

        if (is_object($bodies['html']) && property_exists($bodies['html'], 'content')) {
            return $bodies['html']->content;
        }

        return $bodies['html'];
    }

    /**
     * Get the Message HTML body filtered by an optional callback.
     */
    public function getCustomHTMLBody(?callable $callback = null): ?string
    {
        $body = $this->getHtmlBody();
        if (null === $body) {
            return null;
        }

        if (null !== $callback) {
            foreach ($this->parent->getAttachments() as $oAttachment) {
                $body = $callback($body, $oAttachment);
            }
        }

        return $body;
    }

    /**
     * Get the Message HTML body with embedded base64 images the resulting $body.
     */
    public function getHTMLBodyWithEmbeddedBase64Images(): ?string
    {
        return $this->getCustomHTMLBody(
            function ($body, Attachment $oAttachment) {
                if ($oAttachment->id) {
                    $body = str_replace('cid:'.$oAttachment->id, 'data:'.$oAttachment->getContentType().';base64, '.base64_encode($oAttachment->getContent()), $body);
                }

                return $body;
            }
        );
    }
}
