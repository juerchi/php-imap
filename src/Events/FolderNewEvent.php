<?php

/*
* File:     FolderNewEvent.php
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
 * Class FolderNewEvent.
 */
class FolderNewEvent extends Event
{
    public function __construct(
        public readonly Folder $folder,
    ) {}
}
