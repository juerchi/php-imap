<?php

declare(strict_types=1);

/*
* File:     FolderCollection.php
* Category: Collection
* Author:   M. Goldenbaum
* Created:  18.03.18 02:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Webklex\PHPIMAP\Folder;

/**
 * Class FolderCollection.
 *
 * @template-extends ArrayCollection<int, Folder>
 */
final class FolderCollection extends ArrayCollection {}
