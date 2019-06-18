<?php

namespace KMM\KRoN;

use KMM\KRoN\CronJobMessage;
use KMM\KRoN\CronJobMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Handler\HandlersLocator;

use Symfony\Component\Messenger\Transport\AmqpExt\AmqpTransport;
use Symfony\Component\Messenger\Transport\AmqpExt\Connection;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Transport\AmqpExt\AmqpReceiver;

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


        if (defined('WP_CLI') && WP_CLI) {
            $this->registerCLI();
        }

        $this->i18n = $i18n;
    }

    public function krn_kron_demo()
    {
        //wp_schedule_single_event(time()+10, 'single_shot_event', []);

        
        $conn  = new Connection(["host" => "mq"], ["name" => "kron"],["krn_queue1" => "krn_queue1"]);
        $envelope = new Envelope(new CronJobMessage(["a" => 1]));
        $bus = new MessageBus([new CronJobMessageHandler()]);
        $transport = new AmqpTransport($conn);
        $transport->send($envelope);
        $transport->send($envelope);
        $transport->send($envelope);
				
				$worker = new Worker([new AmqpReceiver($conn)], $bus);
				$worker->run();
/*
				foreach($transport->get() as $msg) {
						var_dump($msg);
						$transport->ack($msg);
				}
        exit;
*/
exit;
        $timeStats = strtotime("00:00:00");

        if (! wp_next_scheduled('krn_demo')) {
            wp_schedule_event($timeStats, 'daily', 'krn_demo');
        }

        while (true) {
            $gmt_time = time();// microtime( true );

            //FIXME paginate: https://wordpress.stackexchange.com/questions/190625/wordpress-get-pagination-on-wpdb-get-results/190632
            $results = $this->wpdb->get_results("SELECT * from " . $this->getTableName() . " where (`interval` = -1 and `timestamp` <= " . $gmt_time . ") OR (`interval` > -1 and `timestamp`+`interval` <= " . $gmt_time . ")");
            foreach ($results as $cron) {
                $this->debug("loop:" . $cron->hook . " =" . $cron->timestamp . " = " . $gmt_time);
                $schedule = $cron->schedule;
                $hook = $cron->hook;
                $interval = $cron->interval;
                $args = unserialize($cron->args);
                $timestamp = $cron->timestamp;
                if ($schedule == "") {
                    $schedule = false;
                }

                $this->run_hook($hook, $args, $timestamp, $schedule, $interval);
            }

            sleep(1);
        }
    }
    public function run_hook($hook, $args, $timestamp, $schedule, $interval)
    {
        $this->debug("RUN: " . $hook . " " . serialize($args));
        wp_unschedule_event($timestamp, $hook, $args);
        do_action_ref_array($hook, $args);
        if ($schedule) {
            wp_schedule_event(time()+$interval, $schedule, $hook, $args);
        }
    }
    public function registerCLI()
    {
        \WP_CLI::add_command('krn_kron', [$this, 'krn_kron_demo']);
    }
    private function getTableName()
    {
        return $this->wpdb->prefix . "kron_events";
    }
    private function checkTable()
    {
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
    }
    private function add_filters()
    {
        add_filter('pre_schedule_event', [$this, "pre_schedule_event"], -1, 2);
        add_filter('pre_unschedule_event', [$this, "pre_unschedule_event"], 0, 4);
        add_filter('pre_clear_scheduled_hook', [$this, "pre_clear_scheduled_hook"], 0, 3);
        add_filter('pre_unschedule_hook', [$this, "pre_unschedule_hook"], 0, 2);
        add_filter('pre_get_scheduled_event', [$this, "pre_get_scheduled_event"], 0, 4);
        add_filter('pre_get_ready_cron_jobs', [$this, "pre_get_ready_cron_jobs"], 0, 1);

        add_filter('option_cron', [$this, 'option_cron'], 0, 1);

        add_filter('cron_schedules', function ($schedules) {
            $schedules['60s'] = ["interval" => 60, "display" => "Every Minute"];
            $schedules['1s'] = ["interval" => 1, "display" => "Every Second"];
            $schedules['10s'] = ["interval" => 10, "display" => "Every 10 Seconds"];
            return $schedules;
        });
    }
    public function option_cron($v)
    {
        return [];
        //FIXME
        $results = $this->wpdb->get_results("SELECT * FROM `".$this->getTableName()."`");
        $ar = [];
        foreach ($results as $r) {
            $el = [];
            $el_child = [];
            $el_child[$r->argkey] = ["schedule" => $r->schedule, "args" => unserialize($r->args)];
            $el[$r->hook] = $el_child;
            $ar[] = $el;
        }
        return $ar;
    }
    public function debug($msg)
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log(date("d.m.Y H:i:s # ") . $msg);
        } else {
        }
    }
    public function pre_schedule_event($pre = null, $event = null)
    {
        $this->debug("pre_schedule_event");
        $this->debug("Register Hook -> " .  $event->hook);
        if (isset($event->schedule) && $event->schedule != "") {
            $this->debug("\t Schedule -> " .  $event->schedule);
        } else {
            $this->debug("\t Schedule -> ONCE");
        }
        //Check Duplicate
        $key       = md5(serialize($event->args));
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
        $results = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM `".$this->getTableName()."` WHERE `timestamp` >= %d and `timestamp` <= %d and `hook` = %s and `argkey` = %s ORDER BY `timestamp` DESC", $min_timestamp, $max_timestamp, $event->hook, $key));
        if (count($results) > 0) {
            $duplicate = true;
        }
        if ($duplicate) {
            $this->debug("\t\t stop DUPLICATE");
            return false;
        }

        $event = apply_filters('schedule_event', $event);
        if (!$event) {
            //a plugin denied this event
            $this->debug("\t\t stop PLUGIN");
            return false;
        }

        if (!isset($event->interval) || !$event->interval) {
            $event->interval = -1;
        }
        if (!isset($event->schedule) || !$event->schedule) {
            $event->schedule = "";
        }
 
        //Insert Event
        $data = ['timestamp' => $event->timestamp, 'hook' => $event->hook, 'schedule' => $event->schedule, 'args' => serialize($event->args), 'interval' => $event->interval, 'argkey'=>md5(serialize($event->args))];
        $this->wpdb->insert($this->getTableName(), $data, array('%d', '%s', '%s', '%s', '%d', '%s'));
        

        
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
        $this->debug("pre_unschedule_event");
        $stmt = "DELETE FROM " . $this->getTableName() . " where hook = %s AND argkey= %s AND `timestamp` = %d";
        $sql = $this->wpdb->prepare($stmt, $hook, md5(serialize($args)), $timestamp);

        $this->wpdb->query($sql);
        \WP_CLI::log($sql);
        return false;
    }

    public function pre_clear_scheduled_hook($pre, $hook, $args)
    {
        $this->debug("pre_clear_scheduled_hook -> ", $hook);
        $sql = $this->wpdb->prepare("DELETE FROM " . $this->getTableName() . " where hook = %s AND argkey= %s", $hook, md5(serialize($args)));
        $this->wpdb->query($sql);
        return false;
    }

    public function pre_unschedule_hook($pre, $hook)
    {
        $this->debug("pre_unschedule_hook");
        $sql = $this->wpdb->prepare("DELETE FROM " . $this->getTableName() . " where hook = %s AND argkey= %s", $hook, md5(serialize($args)));
        $this->wpdb->query($sql);
        return false;
    }
    public function pre_get_scheduled_event($pre, $hook, $args, $timestamp)
    {
        $this->debug("pre_get_scheduled_event: " . $hook);
        $results = $this->wpdb->get_results($this->wpdb->prepare("select * from " . $this->getTableName() . " where hook = %s AND argkey= %s limit 1", $hook, md5(serialize($args))));
        if (count($results) > 0) {
            $e = $results[0];
            if (!$timestamp) {
                $timestamp = $e->timestamp + $e->interval;
            }

            $event = (object) array(
            'hook'      => $hook,
            'timestamp' => $timestamp,
            'schedule'  => $e->schedule,
            'interval'  => $e->interval,
            'args'      => $args,
            );
            //$this->debug("GOT: " . print_r($event, true));
            return $event;
        }
        return false;
    }

    public function pre_get_ready_cron_jobs()
    {
        $this->debug("pre_get_ready_cron_jobs");
        return [];
    }
}
