<?php

declare(strict_types=1);

namespace Webklex\PHPIMAP\Support;

/**
 * Decoder for RFC 2047 encoded words.
 */
final class MimeHeader
{
    /**
     * A run of one or more encoded words, separated by whitespace only.
     */
    private const string ENCODED_WORDS = '/=\?[^?\s]+\?[qb]\?[^?\s]*\?=(?:\s+=\?[^?\s]+\?[qb]\?[^?\s]*\?=)*/i';

    /**
     * Decode all encoded words (any charset, Q and B encoding) of a header value to UTF-8.
     *
     * Only the encoded words are touched: mb_decode_mimeheader() replaces raw 8-bit characters in the surrounding
     * text with "?", which would corrupt values such as "=?UTF-8?Q?J=C3=BCrgen?= Müller".
     */
    public static function decode(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }

        return preg_replace_callback(
            self::ENCODED_WORDS,
            static fn (array $match): string => mb_decode_mimeheader($match[0]),
            $value,
        ) ?? $value;
    }
}
