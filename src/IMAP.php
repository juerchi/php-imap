<?php

/*
* File: IMAP.php
* Category: -
* Author: M.Goldenbaum
* Created: 14.03.19 18:22
* Updated: -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

/**
 * Class IMAP.
 *
 * Independent imap const holder
 */
class IMAP
{
    /**
     * Message const.
     */
    public const int MESSAGE_TYPE_TEXT = 0;
    public const int MESSAGE_TYPE_MULTIPART = 1;

    public const int MESSAGE_ENC_7BIT = 0;
    public const int MESSAGE_ENC_8BIT = 1;
    public const int MESSAGE_ENC_BINARY = 2;
    public const int MESSAGE_ENC_BASE64 = 3;
    public const int MESSAGE_ENC_QUOTED_PRINTABLE = 4;
    public const int MESSAGE_ENC_OTHER = 5;

    public const int MESSAGE_PRIORITY_UNKNOWN = 0;
    public const int MESSAGE_PRIORITY_HIGHEST = 1;
    public const int MESSAGE_PRIORITY_HIGH = 2;
    public const int MESSAGE_PRIORITY_NORMAL = 3;
    public const int MESSAGE_PRIORITY_LOW = 4;
    public const int MESSAGE_PRIORITY_LOWEST = 5;

    /**
     * Attachment const.
     */
    public const int ATTACHMENT_TYPE_TEXT = 0;
    public const int ATTACHMENT_TYPE_MULTIPART = 1;
    public const int ATTACHMENT_TYPE_MESSAGE = 2;
    public const int ATTACHMENT_TYPE_APPLICATION = 3;
    public const int ATTACHMENT_TYPE_AUDIO = 4;
    public const int ATTACHMENT_TYPE_IMAGE = 5;
    public const int ATTACHMENT_TYPE_VIDEO = 6;
    public const int ATTACHMENT_TYPE_MODEL = 7;
    public const int ATTACHMENT_TYPE_OTHER = 8;

    /**
     * Client const.
     */
    public const int CLIENT_OPENTIMEOUT = 1;
    public const int CLIENT_READTIMEOUT = 2;
    public const int CLIENT_WRITETIMEOUT = 3;
    public const int CLIENT_CLOSETIMEOUT = 4;

    /**
     * Generic imap const.
     */
    public const int NIL = 0;
    public const int IMAP_OPENTIMEOUT = 1;
    public const int IMAP_READTIMEOUT = 2;
    public const int IMAP_WRITETIMEOUT = 3;
    public const int IMAP_CLOSETIMEOUT = 4;
    public const int OP_DEBUG = 1;

    /**
     * Open mailbox read-only.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int OP_READONLY = 2;

    /**
     * Don't use or update a .newsrc for news
     * (NNTP only).
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int OP_ANONYMOUS = 4;
    public const int OP_SHORTCACHE = 8;
    public const int OP_SILENT = 16;
    public const int OP_PROTOTYPE = 32;

    /**
     * For IMAP and NNTP
     * names, open a connection but don't open a mailbox.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int OP_HALFOPEN = 64;
    public const int OP_EXPUNGE = 128;
    public const int OP_SECURE = 256;

    /**
     * silently expunge the mailbox before closing when
     * calling <b>imap_close</b>.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int CL_EXPUNGE = 32768;

    /**
     * The parameter is a UID.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int FT_UID = 1;

    /**
     * Do not set the \Seen flag if not already set.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int FT_PEEK = 2;
    public const int FT_NOT = 4;

    /**
     * The return string is in internal format, will not canonicalize to CRLF.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int FT_INTERNAL = 8;
    public const int FT_PREFETCHTEXT = 32;

    /**
     * The sequence argument contains UIDs instead of sequence numbers.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int ST_UID = 1;
    public const int ST_SILENT = 2;
    public const int ST_MSGN = 3;
    public const int ST_SET = 4;

    /**
     * the sequence numbers contain UIDS.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int CP_UID = 1;

    /**
     * Delete the messages from the current mailbox after copying
     * with <b>imap_mail_copy</b>.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int CP_MOVE = 2;

    /**
     * Return UIDs instead of sequence numbers.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SE_UID = 1;
    public const int SE_FREE = 2;

    /**
     * Don't prefetch searched messages.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SE_NOPREFETCH = 4;
    public const int SO_FREE = 8;
    public const int SO_NOSERVER = 16;
    public const int SA_MESSAGES = 1;
    public const int SA_RECENT = 2;
    public const int SA_UNSEEN = 4;
    public const int SA_UIDNEXT = 8;
    public const int SA_UIDVALIDITY = 16;
    public const int SA_ALL = 31;

    /**
     * This mailbox has no "children" (there are no
     * mailboxes below this one).
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int LATT_NOINFERIORS = 1;

    /**
     * This is only a container, not a mailbox - you
     * cannot open it.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int LATT_NOSELECT = 2;

    /**
     * This mailbox is marked. Only used by UW-IMAPD.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int LATT_MARKED = 4;

    /**
     * This mailbox is not marked. Only used by
     * UW-IMAPD.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int LATT_UNMARKED = 8;
    public const int LATT_REFERRAL = 16;
    public const int LATT_HASCHILDREN = 32;
    public const int LATT_HASNOCHILDREN = 64;

    /**
     * Sort criteria for <b>imap_sort</b>:
     * message Date
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SORTDATE = 0;

    /**
     * Sort criteria for <b>imap_sort</b>:
     * arrival date
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SORTARRIVAL = 1;

    /**
     * Sort criteria for <b>imap_sort</b>:
     * mailbox in first From address
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SORTFROM = 2;

    /**
     * Sort criteria for <b>imap_sort</b>:
     * message subject
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SORTSUBJECT = 3;

    /**
     * Sort criteria for <b>imap_sort</b>:
     * mailbox in first To address
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SORTTO = 4;

    /**
     * Sort criteria for <b>imap_sort</b>:
     * mailbox in the first cc address
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SORTCC = 5;

    /**
     * Sort criteria for <b>imap_sort</b>:
     * size of a message in octets
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int SORTSIZE = 6;
    public const int TYPETEXT = 0;
    public const int TYPEMULTIPART = 1;
    public const int TYPEMESSAGE = 2;
    public const int TYPEAPPLICATION = 3;
    public const int TYPEAUDIO = 4;
    public const int TYPEIMAGE = 5;
    public const int TYPEVIDEO = 6;
    public const int TYPEMODEL = 7;
    public const int TYPEOTHER = 8;
    public const int ENC7BIT = 0;
    public const int ENC8BIT = 1;
    public const int ENCBINARY = 2;
    public const int ENCBASE64 = 3;
    public const int ENCQUOTEDPRINTABLE = 4;
    public const int ENCOTHER = 5;

    /**
     * Garbage collector, clear message cache elements.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int IMAP_GC_ELT = 1;

    /**
     * Garbage collector, clear envelopes, and bodies.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int IMAP_GC_ENV = 2;

    /**
     * Garbage collector, clear texts.
     *
     * @see http://php.net/manual/en/imap.constants.php
     */
    public const int IMAP_GC_TEXTS = 4;
}
