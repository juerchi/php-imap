<?php

/*
* File: HasEvents.php
* Category: -
* Author: M.Goldenbaum
* Created: 21.09.20 22:46
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Traits;

use Webklex\PHPIMAP\Events\Event;
use Webklex\PHPIMAP\Exceptions\EventNotFoundException;

/**
 * Trait HasEvents.
 *
 * @phpstan-type EventMap array<string, array<string, class-string<Event>|Event>>
 */
trait HasEvents
{
    /**
     * Event holder.
     *
     * @var EventMap
     */
    protected array $events = [];

    /**
     * Set a specific event.
     */
    public function setEvent(string $section, string $event, mixed $class): void
    {
        if (isset($this->events[$section])) {
            $this->events[$section][$event] = $class;
        }
    }

    /**
     * Set all events.
     *
     * @param EventMap $events
     */
    public function setEvents(array $events): void
    {
        $this->events = $events;
    }

    /**
     * Get a specific event callback.
     *
     * @phpstan-return class-string<Event>|Event
     *
     * @throws EventNotFoundException
     */
    public function getEvent(string $section, string $event): string|Event
    {
        return $this->events[$section][$event]
            ?? throw new EventNotFoundException('event not found');
    }

    /**
     * Get all events.
     *
     * @return EventMap
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Dispatch a specific event.
     * @throws EventNotFoundException
     */
    public function dispatch(string $section, string $event, mixed ...$args): void
    {
        $event = $this->getEvent($section, $event);
        $event::dispatch(...$args);
    }
}
