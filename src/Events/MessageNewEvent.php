<?php

/*
* File:     MessageNewEvent.php
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
 * Class MessageNewEvent.
 */
class MessageNewEvent extends Event
{
    public function __construct(
        public readonly Message $message,
    ) {}
}
