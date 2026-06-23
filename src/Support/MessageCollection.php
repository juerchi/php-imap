<?php

declare(strict_types=1);

/*
* File:     MessageCollection.php
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
use Webklex\PHPIMAP\Message;

/**
 * Class MessageCollection.
 *
 * @template-extends ArrayCollection<string|int, Message>
 */
final class MessageCollection extends ArrayCollection {}
