<?php

/*
* File:     FolderMovedEvent.php
* Category: Event
* Author:   M. Goldenbaum
* Created:  25.11.20 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Events;

use Webklex\PHPIMAP\Folder;

/**
 * Class FolderMovedEvent.
 */
class FolderMovedEvent extends Event
{
    public function __construct(
        public readonly Folder $oldFolder,
        public readonly Folder $newFolder,
    ) {}
}
