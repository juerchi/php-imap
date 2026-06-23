<?php

/*
* File:     Query.php
* Category: -
* Author:   M. Goldenbaum
* Created:  21.07.18 18:54
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Query;

use Symfony\Component\String\UnicodeString;
use Webklex\PHPIMAP\Exceptions\InvalidWhereQueryCriteriaException;
use Webklex\PHPIMAP\Exceptions\MessageSearchValidationException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;

/**
 * Class WhereQuery.
 *
 * @method WhereQuery all()
 * @method WhereQuery answered()
 * @method WhereQuery deleted()
 * @method WhereQuery new()
 * @method WhereQuery old()
 * @method WhereQuery recent()
 * @method WhereQuery seen()
 * @method WhereQuery unanswered()
 * @method WhereQuery undeleted()
 * @method WhereQuery unflagged()
 * @method WhereQuery unseen()
 * @method WhereQuery not()
 * @method WhereQuery unkeyword($value)
 * @method WhereQuery to($value)
 * @method WhereQuery text($value)
 * @method WhereQuery subject($value)
 * @method WhereQuery since($date)
 * @method WhereQuery on($date)
 * @method WhereQuery keyword($value)
 * @method WhereQuery from($value)
 * @method WhereQuery flagged()
 * @method WhereQuery cc($value)
 * @method WhereQuery body($value)
 * @method WhereQuery before($date)
 * @method WhereQuery bcc($value)
 * @method WhereQuery inReplyTo($value)
 * @method WhereQuery messageId($value)
 */
final class WhereQuery extends Query
{
    /**
     * @var list<string>
     */
    private const array AVAiLABLE_CRITERIA = [
        'OR', 'AND',
        'ALL', 'ANSWERED', 'BCC', 'BEFORE', 'BODY', 'CC', 'DELETED', 'FLAGGED', 'FROM', 'KEYWORD',
        'NEW', 'NOT', 'OLD', 'ON', 'RECENT', 'SEEN', 'SINCE', 'SUBJECT', 'TEXT', 'TO',
        'UNANSWERED', 'UNDELETED', 'UNFLAGGED', 'UNKEYWORD', 'UNSEEN', 'UID',
    ];

    /**
     * Magic method to allow alias usage of all "where" methods in an optional connection with "NOT".
     *
     * @throws InvalidWhereQueryCriteriaException
     * @throws MethodNotFoundException
     */
    public function __call(string $name, ?array $arguments = null): mixed
    {
        $that = $this;

        $name = new UnicodeString($name)->camel()->toString();
        if (0 === stripos($name, 'not')) {
            $that = $that->whereNot();
            $name = substr($name, 3);
        }

        if (!str_starts_with(strtolower($name), 'where')) {
            $method = 'where'.ucfirst($name);
        } else {
            $method = lcfirst($name);
        }

        if (true === method_exists($this, $method)) {
            return call_user_func_array([$that, $method], $arguments ?? []);
        }

        throw new MethodNotFoundException('Method '.self::class.'::'.$method.'() is not supported');
    }

    /**
     * Register search parameters.
     *
     * @return $this
     *
     * @throws InvalidWhereQueryCriteriaException
     *
     * Examples:
     * $query->from("someone@email.tld")->seen();
     * $query->whereFrom("someone@email.tld")->whereSeen();
     * $query->where([["FROM" => "someone@email.tld"], ["SEEN"]]);
     * $query->where(["FROM" => "someone@email.tld"])->where(["SEEN"]);
     * $query->where(["FROM" => "someone@email.tld", "SEEN"]);
     * $query->where("FROM", "someone@email.tld")->where("SEEN");
     */
    public function where(mixed $criteria, mixed $value = null): self
    {
        if (is_array($criteria)) {
            foreach ($criteria as $key => $value) {
                if (is_numeric($key)) {
                    $this->where($value);
                } else {
                    $this->where($key, $value);
                }
            }
        } else {
            $this->push_search_criteria($criteria, $value);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function orWhere(?\Closure $closure = null): self
    {
        $this->query->add(['OR']);
        if (null !== $closure) {
            $closure($this);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function andWhere(?\Closure $closure = null): self
    {
        $this->query->add(['AND']);
        if (null !== $closure) {
            $closure($this);
        }

        return $this;
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereAll(): self
    {
        return $this->where('ALL');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereAnswered(): self
    {
        return $this->where('ANSWERED');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereBcc(string $value): self
    {
        return $this->where('BCC', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     * @throws MessageSearchValidationException
     */
    public function whereBefore(mixed $value): self
    {
        $date = $this->parse_date($value);

        return $this->where('BEFORE', $date);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereBody(string $value): self
    {
        return $this->where('BODY', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereCc(string $value): self
    {
        return $this->where('CC', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereDeleted(): self
    {
        return $this->where('DELETED');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereFlagged(): self
    {
        return $this->where('FLAGGED');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereFrom(string $value): self
    {
        return $this->where('FROM', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereKeyword(string $value): self
    {
        return $this->where('KEYWORD', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereNew(): self
    {
        return $this->where('NEW');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereNot(): self
    {
        return $this->where('NOT');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereOld(): self
    {
        return $this->where('OLD');
    }

    /**
     * @throws MessageSearchValidationException
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereOn(mixed $value): self
    {
        $date = $this->parse_date($value);

        return $this->where('ON', $date);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereRecent(): self
    {
        return $this->where('RECENT');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereSeen(): self
    {
        return $this->where('SEEN');
    }

    /**
     * @throws MessageSearchValidationException
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereSince(mixed $value): self
    {
        $date = $this->parse_date($value);

        return $this->where('SINCE', $date);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereSubject(string $value): self
    {
        return $this->where('SUBJECT', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereText(string $value): self
    {
        return $this->where('TEXT', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereTo(string $value): self
    {
        return $this->where('TO', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereUnkeyword(string $value): self
    {
        return $this->where('UNKEYWORD', $value);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereUnanswered(): self
    {
        return $this->where('UNANSWERED');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereUndeleted(): self
    {
        return $this->where('UNDELETED');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereUnflagged(): self
    {
        return $this->where('UNFLAGGED');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereUnseen(): self
    {
        return $this->where('UNSEEN');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereNoXSpam(): self
    {
        return $this->where('CUSTOM X-Spam-Flag NO');
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereIsXSpam(): self
    {
        return $this->where('CUSTOM X-Spam-Flag YES');
    }

    /**
     * Search for a specific header value.
     *
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereHeader(mixed $header, mixed $value): self
    {
        return $this->where("CUSTOM HEADER {$header} {$value}");
    }

    /**
     * Search for a specific message id.
     *
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereMessageId(mixed $messageId): self
    {
        return $this->whereHeader('Message-ID', $messageId);
    }

    /**
     * Search for a specific message id.
     *
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereInReplyTo(mixed $messageId): self
    {
        return $this->whereHeader('In-Reply-To', $messageId);
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereLanguage(mixed $countryCode): self
    {
        return $this->whereHeader('Content-Language', $countryCode);
    }

    /**
     * Get message be it UID.
     *
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereUid(int|string $uid): self
    {
        return $this->where('UID', $uid);
    }

    /**
     * Get messages by their UIDs.
     *
     * @param array<int, int> $uids
     *
     * @throws InvalidWhereQueryCriteriaException
     */
    public function whereUidIn(array $uids): self
    {
        $uids = implode(',', $uids);

        return $this->where('UID', $uids);
    }

    /**
     * Apply the callback if the given "value" is truthy.
     * Copied from @url https://github.com/laravel/framework/blob/8.x/src/Illuminate/Support/Traits/Conditionable.php.
     */
    public function when(mixed $value, callable $callback, mixed $default = null): mixed
    {
        if ($value) {
            return $callback($this, $value) ?: $this;
        }

        if ($default) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    /**
     * Apply the callback if the given "value" is falsy.
     * Copied from @url https://github.com/laravel/framework/blob/8.x/src/Illuminate/Support/Traits/Conditionable.php.
     */
    public function unless(mixed $value, callable $callback, mixed $default = null): mixed
    {
        if (!$value) {
            return $callback($this, $value) ?: $this;
        }
        if ($default) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    /**
     * @throws InvalidWhereQueryCriteriaException
     */
    protected function validate_criteria(mixed $criteria): string
    {
        $command = strtoupper($criteria);
        if (str_starts_with($command, 'CUSTOM ')) {
            return substr($criteria, 7);
        }
        if (!in_array($command, self::AVAiLABLE_CRITERIA, true)) {
            throw new InvalidWhereQueryCriteriaException("Invalid imap search criteria: {$command}");
        }

        return $criteria;
    }

    /**
     * Push a given search criteria and value pair to the search query.
     *
     * @throws InvalidWhereQueryCriteriaException
     */
    protected function push_search_criteria(string $criteria, mixed $value): void
    {
        $criteria = $this->validate_criteria($criteria);
        $value = $this->parse_value($value);

        if ('' === $value) {
            $this->query->add([$criteria]);
        } else {
            $this->query->add([$criteria, $value]);
        }
    }
}
