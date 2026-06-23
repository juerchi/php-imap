<?php

/*
* File:     MessageMovedEvent.php
* Category: Event
* Author:   M. Goldenbaum
* Created:  25.11.20 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Events;

use Webklex\PHPIMAP\Message;

/**
 * Class MessageMovedEvent.
 */
class MessageMovedEvent extends Event
{
    public function __construct(
        public readonly Message $oldMessage,
        public readonly Message $newMessage,
    ) {}
}
