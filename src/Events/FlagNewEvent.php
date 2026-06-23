<?php

/*
* File:     FlagNewEvent.php
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
 * Class FlagNewEvent.
 */
class FlagNewEvent extends Event
{
    public function __construct(
        public readonly Message $message,
        public readonly string $flag,
    ) {}
}
