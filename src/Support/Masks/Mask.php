<?php

/*
* File: Mask.php
* Category: Mask
* Author: M.Goldenbaum
* Created: 14.03.19 20:49
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Support\Masks;

use Symfony\Component\String\UnicodeString;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;

class Mask
{
    /**
     * Available attributes.
     */
    protected array $attributes = [];

    public function __construct(protected object $parent)
    {
        if (method_exists($this->parent, 'getAttributes')) {
            $this->attributes = array_merge($this->attributes, $this->parent->getAttributes());
        }

        $this->boot();
    }

    /**
     * Call dynamic attribute setter and getter methods and inherit the parent calls.
     *
     * @throws MethodNotFoundException
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (0 === stripos($method, 'get')) {
            $name = new UnicodeString(substr($method, 3))->snake()->toString();

            if (isset($this->attributes[$name])) {
                return $this->attributes[$name];
            }
        } elseif (0 === stripos($method, 'set')) {
            $name = new UnicodeString(substr($method, 3))->snake()->toString();

            if (isset($this->attributes[$name])) {
                $this->attributes[$name] = array_pop($arguments);

                return $this->attributes[$name];
            }
        }

        if (true === method_exists($this->parent, $method)) {
            return call_user_func_array([$this->parent, $method], $arguments);
        }

        throw new MethodNotFoundException('Method '.self::class.'::'.$method.'() is not supported');
    }

    public function __set(mixed $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __get(mixed $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function getParent(): object
    {
        return $this->parent;
    }

    /**
     * Get all available attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Boot method made to be used by any custom mask.
     */
    protected function boot(): void {}
}
