<?php

/*
* File:     Event.php
* Category: Event
* Author:   M. Goldenbaum
* Created:  25.11.20 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Events;

/**
 * Class Event.
 */
abstract class Event
{
    /**
     * Dispatch the event with the given arguments.
     */
    public static function dispatch(mixed ...$args): static
    {
        // @phpstan-ignore new.static (intentional: subclasses are always concrete)
        return new static(...$args);
    }
}
