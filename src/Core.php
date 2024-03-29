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
        $this->checkTable();

        $this->add_filters();
        $this->climate = new \League\CLImate\CLImate();

        if (defined('WP_CLI') && WP_CLI) {
            $this->manager = new PubSubManager($this);
            $this->registerCLI();
        }

        //inject custom logger
        if (defined('WP_CLI') && WP_CLI) {
            $this->logger = new \WP_CLI\Loggers\Execution();
            $this->regular_logger = new \WP_CLI\Loggers\Regular(true);
        }

        $this->i18n = $i18n;
    }

    public function output($out)
    {
        if (defined('WP_CLI') && WP_CLI) {
            $d = date('d.m.Y H:i:s');
            $this->climate->to(['buffer'])->out("<dim><underline>{$d}</underline></dim> > " . $out);

            @ob_end_flush();
            echo $this->climate->output->get('buffer')->get();
            @ob_flush();

            $this->climate->output->get('buffer')->clean();
        }
    }

    public function krn_convert()
    {
        $o = get_option('cron');
        unset($o['version']);
        $count = 0;
        foreach ($o as $timestamp => $entry) {
            foreach ($entry as $hook => $event_by_args) {
                foreach ($event_by_args as $chk => $event) {
                    $new_event = (object) [
                    'hook' => $hook,
                    'timestamp' => $timestamp,
                    'schedule' => $event['schedule'],
                    'interval' => $event['interval'],
                    'args' => $event['args'],
                    ];
                    $this->pre_schedule_event(false, $new_event);
                    ++$count;
                }
            }
        }
        $this->output('Converted <bold><green>' . $count . '</bold></green> Jobs from standard cron');
    }

    public function _get_jobs()
    {
        $gmt_time = time(); // microtime( true );

        $total = $this->wpdb->get_results('SELECT count(1) as cnt from ' . $this->getTableName());

        $total = $total[0]->cnt;
        //FIXME paginate: https://wordpress.stackexchange.com/questions/190625/wordpress-get-pagination-on-wpdb-get-results/190632
        $results = $this->wpdb->get_results('SELECT * from ' . $this->getTableName() . ' where (`interval` = -1 and `timestamp` <= ' . $gmt_time . ') OR (`interval` > -1 and `timestamp`+`interval` <= ' . $gmt_time . ')');

        return (object)['total' => $total, 'jobs' => $results, 'count' => count($results)];
    }

    public function _work_jobs($jobs)
    {
        //$this->output("Working on <bold>{$jobs->count}</bold>/{$jobs->total} Jobs 🚧");
        foreach ($jobs->jobs as $cron) {
            $schedule = $cron->schedule;
            $hook = $cron->hook;
            $interval = $cron->interval;
            $args = unserialize($cron->args);
            $timestamp = $cron->timestamp;
            if ($schedule == '') {
                $schedule = false;
            }
            try {
                $this->run_hook($hook, $args, $timestamp, $schedule, $interval);
            } catch (\Throwable $exc) {
                $this->output("<red><bold>{$hook}</red></cyan> 💣 failed with execption:" . $exc->getMessage());
            }
        }
    }

    public function krn_work_jobs()
    {
        //wp_schedule_single_event(time()+10, 'single_shot_event', []);

        while (true) {
            $jobs = $this->_get_jobs();
            $this->_work_jobs($jobs);
            sleep(10);
        }
    }

    public function run_hook($hook, $args, $timestamp, $schedule, $interval)
    {
        //Capture output
        \WP_CLI::set_logger($this->logger);

        $this->debug('RUN: ' . $hook . ' ' . serialize($args));
        $curTime = microtime(true);

        ob_start();
        wp_unschedule_event($timestamp, $hook, $args);
        do_action_ref_array($hook, $args);
        if ($schedule) {
            wp_schedule_event(time() + $interval, $schedule, $hook, $args);
        }
        $cnt = ob_get_contents();
        $lines_echo = explode("\n", $cnt);
        $lines_stdout = explode("\n", $this->logger->stdout);
        $lines_stderr = explode("\n", $this->logger->stderr);
        $lines = array_merge($lines_stderr, $lines_stdout, $lines_echo);
        $lines = array_filter($lines, function ($el) {
            if ($el == '') {
                return false;
            }

            return true;
        });
        $this->logger->stdout = '';
        $this->logger->stderr = '';
        @ob_end_clean();
        $timeConsumed = round(microtime(true) - $curTime, 3) * 1000;
        $this->output("<cyan><bold>{$hook}</bold></cyan> done in {$timeConsumed} ms ✅");
        $this->output("\t<underline>Output</underline>: ");
        foreach ($lines as $line) {
            $this->output("\t " . $line);
        }
        \WP_CLI::set_logger($this->regular_logger);
    }

    public function registerCLI()
    {
        \WP_CLI::add_command('krn_kron', [$this, 'krn_work_jobs']);
        \WP_CLI::add_command('krn_kron_convert', [$this, 'krn_convert']);
        \WP_CLI::add_command('krn_kron_publisher', [$this->manager, 'publisher']);
        \WP_CLI::add_command('krn_kron_producer', [$this->manager, 'publisher']);
        \WP_CLI::add_command('krn_kron_consumer', [$this->manager, 'consumer']);
    }

    private function getTableName()
    {
        return $this->wpdb->prefix . 'kron_events';
    }

    private function checkTable()
    {
        $is_hit = false;
        $cache_key = sha1('krn_kron_table_v1');
        wp_cache_get($cache_key, 'kron', false, $is_hit);
        if ($is_hit) {
            return true;
        }

        //Check if Table exists
        $table_name = $this->getTableName();
        $charset_collate = $this->wpdb->get_charset_collate();
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
            ID int(12) NOT NULL AUTO_INCREMENT,
            `hook` text NOT NULL,
            `timestamp` int(12) NOT NULL,
            `schedule` text,
            `args` text,
            `interval` int(12),
            `argkey` text,
            PRIMARY KEY  (ID)
            )    $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        //Create It

        wp_cache_set($cache_key, 'SET', 'kron');
    }

    private function is_enabled()
    {
        $r = apply_filters('krn_kron_enabled', true);

        return $r;
    }

    private function add_filters()
    {
        add_filter('pre_schedule_event', [$this, 'pre_schedule_event'], -1, 2);
        add_filter('pre_unschedule_event', [$this, 'pre_unschedule_event'], 0, 4);
        add_filter('pre_clear_scheduled_hook', [$this, 'pre_clear_scheduled_hook'], 0, 3);
        add_filter('pre_unschedule_hook', [$this, 'pre_unschedule_hook'], 0, 2);
        add_filter('pre_get_scheduled_event', [$this, 'pre_get_scheduled_event'], 0, 4);
        add_filter('pre_get_ready_cron_jobs', [$this, 'pre_get_ready_cron_jobs'], 0, 1);

        //add_filter('option_cron', [$this, 'option_cron'], 0, 1);
        add_action('publish_future_post', function ($postId) {
            clean_post_cache($postId);
        }, -1, 1);
        add_filter('cron_schedules', function ($schedules) {
            $schedules['60s'] = ['interval' => 60, 'display' => 'Every Minute'];
            $schedules['1s'] = ['interval' => 1, 'display' => 'Every Second'];
            $schedules['10s'] = ['interval' => 10, 'display' => 'Every 10 Seconds'];
            $schedules['1h'] = ['interval' => 3600, 'display' => 'Every hour'];

            return $schedules;
        });
        add_action('ddd', function () {
            echo 'ASDF';
            \WP_CLI::log('WPCLI LOG output');
        });
        add_action('shaautdown', function () {
            if ($this->logger->stdout !== '') {
                echo $this->logger->stdout;
                echo $this->logger->stderr;
            }
        });
    }

    public function option_cron($v)
    {
        return [];
        //FIXME
        $results = $this->wpdb->get_results('SELECT * FROM `' . $this->getTableName() . '`');
        $ar = [];
        foreach ($results as $r) {
            $el = [];
            $el_child = [];
            $el_child[$r->argkey] = ['schedule' => $r->schedule, 'args' => unserialize($r->args)];
            $el[$r->hook] = $el_child;
            $ar[] = $el;
        }

        return $ar;
    }

    public function debug($msg)
    {
        if (getenv('KRN_KRON_DEBUG') && getenv('KRN_KRON_DEBUG') == 'TRUE') {
            $this->output('<magenta>DEBUG</magenta>:' . $msg);
        }
    }

    public function pre_schedule_event($pre = null, $event = null)
    {
        if (! $this->is_enabled()) {
            return false;
        }
        $this->debug('pre_schedule_event');
        $this->debug('Register Hook -> ' . $event->hook);
        if (isset($event->schedule) && $event->schedule != '') {
            $this->debug("\t Schedule -> " . $event->schedule);
        } else {
            $this->debug("\t Schedule -> ONCE");
        }
        //Check Duplicate
        $key = md5(serialize($event->args));
        $duplicate = false;

        if ($event->timestamp < time() + 10 * MINUTE_IN_SECONDS) {
            $min_timestamp = 0;
        } else {
            $min_timestamp = $event->timestamp - 10 * MINUTE_IN_SECONDS;
        }
        if ($event->timestamp < time()) {
            $max_timestamp = time() + 10 * MINUTE_IN_SECONDS;
        } else {
            $max_timestamp = $event->timestamp + 10 * MINUTE_IN_SECONDS;
        }
        $results = $this->wpdb->get_results($this->wpdb->prepare('SELECT * FROM `' . $this->getTableName() . '` WHERE `timestamp` >= %d and `timestamp` <= %d and `hook` = %s and `argkey` = %s ORDER BY `timestamp` DESC', $min_timestamp, $max_timestamp, $event->hook, $key));
        if (count($results) > 0) {
            $duplicate = true;
        }
        if ($duplicate) {
            $this->debug("\t\t stop DUPLICATE");

            return false;
        }

        $event = apply_filters('schedule_event', $event);
        if (! $event) {
            //a plugin denied this event
            $this->debug("\t\t stop PLUGIN");

            return false;
        }

        if (! isset($event->interval) || ! $event->interval) {
            $event->interval = -1;
        }
        if (! isset($event->schedule) || ! $event->schedule) {
            $event->schedule = '';
        }

        //Insert Event
        $data = ['timestamp' => $event->timestamp, 'hook' => $event->hook, 'schedule' => $event->schedule, 'args' => serialize($event->args), 'interval' => $event->interval, 'argkey' => md5(serialize($event->args))];
        $this->wpdb->insert($this->getTableName(), $data, ['%d', '%s', '%s', '%s', '%d', '%s']);

        return true;
    }

    /*
    public function pre_reschedule_event($pre, $event)
    {
        return false;
    }
     */

    public function pre_unschedule_event($pre, $timestamp, $hook, $args)
    {
        if (! $this->is_enabled()) {
            return false;
        }
        $this->debug('pre_unschedule_event');
        $stmt = 'DELETE FROM ' . $this->getTableName() . ' where hook = %s AND argkey= %s AND `timestamp` = %d';
        $sql = $this->wpdb->prepare($stmt, $hook, md5(serialize($args)), $timestamp);

        $this->wpdb->query($sql);

        $argserial = md5(serialize($args));
        $cache_key = 'pgse_' . $hook . '_' . $argserial;
        wp_cache_delete($cache_key, 'kron');

        return false;
    }

    public function pre_clear_scheduled_hook($pre, $hook, $args)
    {
        if (! $this->is_enabled()) {
            return false;
        }
        $this->debug('pre_clear_scheduled_hook -> ' . $hook);
        $sql = $this->wpdb->prepare('DELETE FROM ' . $this->getTableName() . ' where hook = %s AND argkey= %s', $hook, md5(serialize($args)));
        $this->wpdb->query($sql);

        $argserial = md5(serialize($args));
        $cache_key = 'pgse_' . $hook . '_' . $argserial;
        wp_cache_delete($cache_key, 'kron');

        return false;
    }

    public function pre_unschedule_hook($pre, $hook)
    {
        if (! $this->is_enabled()) {
            return false;
        }
        $this->debug('pre_unschedule_hook');
        $sql = $this->wpdb->prepare('DELETE FROM ' . $this->getTableName() . ' where hook = %s AND argkey= %s', $hook, md5(serialize($args)));
        $this->wpdb->query($sql);

        $argserial = md5(serialize($args));
        $cache_key = 'pgse_' . $hook . '_' . $argserial;
        wp_cache_delete($cache_key, 'kron');

        return false;
    }

    public function pre_get_scheduled_event($pre, $hook, $args, $timestamp)
    {
        if (! $this->is_enabled()) {
            return false;
        }
        $is_hit = false;
        $argserial = md5(serialize($args));
        $cache_key = 'pgse_' . $hook . '_' . $argserial;
        $evnt = wp_cache_get($cache_key, 'kron', false, $is_hit);

        if ($is_hit) {
            return $evnt;
        }

        $this->debug('pre_get_scheduled_event: ' . $hook);
        $results = $this->wpdb->get_results($this->wpdb->prepare('select * from ' . $this->getTableName() . ' where hook = %s AND argkey= %s limit 1', $hook, $argserial));
        if (count($results) > 0) {
            $e = $results[0];
            if (! $timestamp) {
                $timestamp = $e->timestamp + $e->interval;
            }

            $event = (object) [
            'hook' => $hook,
            'timestamp' => $timestamp,
            'schedule' => $e->schedule,
            'interval' => $e->interval,
            'args' => $args,
            ];

            wp_cache_set($cache_key, $event, 'kron');

            return $event;
        }

        return false;
    }

    public function pre_get_ready_cron_jobs()
    {
        $this->debug('pre_get_ready_cron_jobs');

        return [];
    }
}
