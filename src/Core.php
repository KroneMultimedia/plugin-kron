<?php

namespace KMM\KRoN;

class Core
{
    private $plugin_dir;

    public function __construct($i18n)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->plugin_dir = plugin_dir_url(__FILE__) . '../';
        $this->add_filters();
        $this->i18n = $i18n;
    }

    private function add_filters()
    {
    }
}
