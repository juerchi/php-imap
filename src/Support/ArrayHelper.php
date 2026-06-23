<?php

declare(strict_types=1);

namespace Webklex\PHPIMAP\Support;

final class ArrayHelper
{
    /**
     * Merge arrays recursively and distinctly.
     *
     * Merges any number of arrays recursively, replacing entries with string keys
     * with values from latter arrays. Numeric entries are appended, not replaced,
     * but only if they are unique.
     *
     * @see http://www.php.net/manual/en/function.array-merge-recursive.php#96201
     *
     * @author Mark Roduner <mark.roduner@gmail.com>
     */
    public static function mergeRecursiveDistinct(mixed ...$arrays): mixed
    {
        $base = array_shift($arrays);

        $isAssoc = static fn (array $arr): bool => [] !== $arr && !array_is_list($arr);

        if (!is_array($base)) {
            $base = empty($base) ? [] : [$base];
        }

        foreach ($arrays as $append) {
            if (!is_array($append)) {
                $append = [$append];
            }

            foreach ($append as $key => $value) {
                if (!array_key_exists($key, $base) && !is_numeric($key)) {
                    $base[$key] = $value;

                    continue;
                }

                if (
                    (is_array($value) && $isAssoc($value))
                    || (is_array($base[$key]) && $isAssoc($base[$key]))
                ) {
                    $base[$key] = self::mergeRecursiveDistinct($base[$key], $value);
                } elseif (is_numeric($key)) {
                    if (!in_array($value, $base, true)) {
                        $base[] = $value;
                    }
                } else {
                    $base[$key] = $value;
                }
            }
        }

        return $base;
    }
}
