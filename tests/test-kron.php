<?php
/**
* @covers KMM\Dummy\Core
*/
use KMM\KRoN\Core;
use phpmock\MockBuilder;


class TestKron extends \WP_UnitTestCase
{
    /**
    * @test
    */
    public function dummy()
    {
        $this->assertNull(null);
    }
}
