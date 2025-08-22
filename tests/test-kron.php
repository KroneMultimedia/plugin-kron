<?php
/**
 * Simple unit tests for Kron plugin
 */

use PHPUnit\Framework\TestCase;

class TestKron extends TestCase
{
    /**
     * @test
     */
    public function dummy()
    {
        $this->assertEquals(1, 1);
    }

    /**
     * @test
     */
    public function testBasicPHPFunctionality()
    {
        $this->assertTrue(true);
        $this->assertFalse(false);
        $this->assertSame('test', 'test');
    }
}
