<?php

declare(strict_types=1);

/*
* File:     AttachmentCollection.php
* Category: Collection
* Author:   M. Goldenbaum
* Created:  16.03.18 03:13
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Webklex\PHPIMAP\Attachment;

/**
 * Class AttachmentCollection.
 *
 * @template-extends ArrayCollection<string|int, Attachment>
 */
class AttachmentCollection extends ArrayCollection {}
