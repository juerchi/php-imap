<?php

declare(strict_types=1);

namespace Webklex\PHPIMAP;

/**
 * Date Parser for imap header dates.
 *
 * Known bad and "invalid" formats:
 * ^ Datetime                                   ^ Problem                           ^ Cause
 * | Mon, 20 Nov 2017 20:31:31 +0800 (GMT+8:00) | Double timezone specification     | A Windows feature
 * | Thu, 8 Nov 2018 08:54:58 -0200 (-02)       |
 * |                                            | and invalid timezone (max 6 char) |
 * | 04 Jan 2018 10:12:47 UT                    | Missing letter "C"                | Unknown
 * | Thu, 31 May 2018 18:15:00 +0800 (added by) | Non-standard details added by the | Unknown
 * |                                            | mail server                       |
 * | Sat, 31 Aug 2013 20:08:23 +0580            | Invalid timezone                  | PHPMailer bug https://sourceforge.net/p/phpmailer/mailman/message/6132703/
 * | Mi., 23 Apr. 2025 09:48:37 +0200 (MESZ)    | Non-standard localized format     | Aqua Mail S/MIME implementation
 *
 * Please report any new invalid timestamps to [#45](https://github.com/Webklex/php-imap/issues)
 */
final readonly class DateParser
{
    /**
     * @throws \DateMalformedStringException
     *
     * @noinspection CallableParameterUseCaseInTypeContextInspection
     */
    public static function parse(string $date): \DateTimeImmutable
    {
        if (preg_match('/\+0580/', $date)) {
            $date = str_replace('+0580', '+0530', $date);
        }

        $date = trim(rtrim($date));

        try {
            if (str_contains($date, '&nbsp;')) {
                $date = str_replace('&nbsp;', ' ', $date);
            }
            if (str_contains($date, ' UT ')) {
                $date = str_replace(' UT ', ' UTC ', $date);
            }

            return new \DateTimeImmutable($date);
        } catch (\DateMalformedStringException) {
            // ignore -> do further processing
        }

        if (preg_match('/(\d{4}\.\d{1,2}\.\d{1,2}-\d{1,2}\.\d{1,2}.\d{1,2})+$/', $date) > 0) {
            $parsed = \DateTimeImmutable::createFromFormat('Y.m.d-H.i.s', $date);
            if (false !== $parsed) {
                return $parsed;
            }
        }
        if (preg_match('/(\d{2} [A-Z]{3} \d{4} \d{1,2}:\d{1,2}:\d{1,2} [+-]\d{1,4} \d{1,2}:\d{1,2}:\d{1,2} [+-]\d{1,4})+$/i', $date) > 0) {
            $parts = explode(' ', $date);
            array_splice($parts, -2);

            return new \DateTimeImmutable(implode(' ', $parts));
        }
        if (preg_match('/([A-Z]{2,4}, \d{1,2} [A-Z]{2,3} \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4})+$/i', $date) > 0) {
            $array = explode(',', $date);
            array_shift($array);

            $parsed = \DateTimeImmutable::createFromFormat('d M Y H:i:s O', trim(implode(',', $array)));
            if (false !== $parsed) {
                return $parsed;
            }
        }
        if (
            preg_match('/(\d{1,2} [A-Z]{2,3} \d{4} \d{1,2}:\d{1,2}:\d{1,2} UT)+$/i', $date) > 0
            || preg_match('/([A-Z]{2,3}, \d{1,2} [A-Z]{2,3} (\d{2}|\d{4}) \d{1,2}:\d{1,2}:\d{1,2} UT)+$/i', $date) > 0
        ) {
            return new \DateTimeImmutable($date.'C');
        }
        if (preg_match('/([A-Z]{2,3}, \d{1,2}, [A-Z]{2,3} \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4})+$/i', $date) > 0) {
            return new \DateTimeImmutable(str_replace(',', '', $date));
        }
        // match case for: Di., 15 Feb. 2022 06:52:44 +0100 (MEZ)/Di., 15 Feb. 2022 06:52:44 +0100 (MEZ) and Mi., 23 Apr. 2025 09:48:37 +0200 (MESZ)
        if (preg_match('/([A-Z]{2,3}\., \d{1,2} [A-Z]{2,3}\. \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4} \([A-Z]{3,4}\))(\/([A-Z]{2,3}\., \d{1,2} [A-Z]{2,3}\. \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4} \([A-Z]{3,4}\))+)?$/i', $date) > 0) {
            $dates = explode('/', $date);

            /** @var non-empty-list<string> $dates */
            $date = array_shift($dates);
            $array = explode(',', $date);
            array_shift($array);
            $date = trim(implode(',', $array));
            $array = explode(' ', $date);
            array_pop($array);
            $date = trim(implode(' ', $array));

            $parsed = \DateTimeImmutable::createFromFormat('d M. Y H:i:s O', $date);
            if (false !== $parsed) {
                return $parsed;
            }
        }
        // match case for: fr., 25 nov. 2022 06:27:14 +0100/fr., 25 nov. 2022 06:27:14 +0100
        if (preg_match('/([A-Z]{2,3}\., \d{1,2} [A-Z]{2,3}\. \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4})\/([A-Z]{2,3}\., \d{1,2} [A-Z]{2,3}\. \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4})+$/i', $date) > 0) {
            /** @var non-empty-list<string> $dates */
            $dates = explode('/', $date);
            $date = array_shift($dates);
            $array = explode(',', $date);
            array_shift($array);
            $date = trim(implode(',', $array));

            $parsed = \DateTimeImmutable::createFromFormat('d M. Y H:i:s O', $date);
            if (false !== $parsed) {
                return $parsed;
            }
        }
        if (
            preg_match('/([A-Z]{2,3}, \d{1,2} [A-Z]{2,3} \d{4} \d{1,2}:\d{1,2}:\d{1,2} \+\d{2,4} \(\+\d{1,2}\))+$/i', $date) > 0
            || preg_match('/([A-Z]{2,3}[,| ] \d{1,2} [A-Z]{2,3} \d{4} \d{1,2}:\d{1,2}:\d{1,2}.*)+$/i', $date) > 0
            || preg_match('/([A-Z]{2,3}, \d{1,2} [A-Z]{2,3} \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4} \(.*)\)+$/i', $date) > 0
            || preg_match('/([A-Z]{2,3}, {2}\d{1,2} [A-Z]{2,3} \d{4} \d{1,2}:\d{1,2}:\d{1,2} [-|+]\d{4} \(.*)\)+$/i', $date) > 0
            || preg_match('/(\d{1,2} [A-Z]{2,3} \d{2,4} \d{2}:\d{2}:\d{2} [A-Z]{2} -\d{2}:\d{2} \([A-Z]{2,3} -\d{2}:\d{2}\))+$/i', $date) > 0
        ) {
            $array = explode('(', $date);
            $array = array_reverse($array);
            $date = trim(array_pop($array));

            return new \DateTimeImmutable($date);
        }

        return new \DateTimeImmutable($date);
    }
}
