<?php

/*
* File: AttachmentMask.php
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

class AttachmentMask extends Mask
{
    /** @var Attachment */
    protected object $parent;

    /**
     * Get the attachment content base64 encoded.
     */
    public function getContentBase64Encoded(): ?string
    {
        return base64_encode($this->parent->content);
    }

    /**
     * Get a base64 image src string.
     */
    public function getImageSrc(): ?string
    {
        return 'data:'.$this->parent->contentType.';base64,'.$this->getContentBase64Encoded();
    }
}
