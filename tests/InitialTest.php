<?php
/*
* File: InitialTest.php
* Category: test
* Author: M.Goldenbaum
* Created: 02.01.21 04:42
* Updated: -
*
* Description:
*  -
*/

use PHPUnit\Framework\TestCase;
use \Webklex\PHPIMAP\ClientManager;

class InitialTest extends TestCase {
    protected $cm;

    protected function setUp(): void {
        parent::setUp();
        $this->cm = new ClientManager();
    }

    public function testConfigDefaultAccount() {
        $this->assertEquals("default", ClientManager::get("default"));
    }
}