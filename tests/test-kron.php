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

      
        wp_schedule_single_event(time()+20, 'single_shot_event', []);
        exit;
        return;
        $time = strtotime('00:00:00');
        sleep(5);
      

        return;

        wp_schedule_event($time, 'daily', 'krn_delete_ngen_articles_preview_images1');
        wp_schedule_single_event($time, 'single_shot_event', []);
        wp_schedule_single_event($time, 'single_shot_event', []);
        if (! wp_next_scheduled('krn_delete_ngen_articles_preview_images')) {
            wp_schedule_event($time, 'daily', 'krn_delete_ngen_articles_preview_images');
        }
    }
}
