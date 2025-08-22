<?php

namespace KMM\KRoN;

use KMM\KRoN\transports\AMQP;
use KMM\KRoN\transports\TransportInterface;

class PubSubManager
{
    public function __construct($core)
    {
        $this->core = $core;
        $dev_mode = false;
        if (defined('KRN_RABBIT_DEMO') || getenv('KRN_RABBIT_DEMO')) {
            $dev_mode = true;
        }

        $this->sleepTime = 10;
        if (defined('KRN_KRON_SLEEP_TIME')) {
            $this->sleepTime = KRN_KRON_SLEEP_TIME;
        }
        if (getenv('KRN_KRON_SLEEP_TIME')) {
            $this->sleepTime = getenv('KRN_KRON_SLEEP_TIME');
        }

        if ($dev_mode) {
            $amqp = new AMQP();
            //Share instance of prod and cons
            add_filter('krn_kron_get_publisher', function () use ($amqp) {
                return $amqp;
            }, 10, 1);
            add_filter('krn_kron_get_consumer', function () use ($amqp) {
                return $amqp;
            }, 10, 1);
        }
    }

    public function consumer()
    {
        // Mark that Kron consumer is now active
        $this->core->setKronConsumerActive(true);

        $consumer = apply_filters('krn_kron_get_consumer', false);
        if (! $consumer) {
            $this->core->output("No Consumer set! please register the 'krn_kron_get_consumer' filter and return a publisher");
            exit;
        }
        if (! $consumer instanceof TransportInterface) {
            $this->core->output("the provided result of the filter 'krn_kron_get_consumer' does not implement the TransportInterface");
            exit;
        }
        $this->consumer = $consumer;
        $this->consumer->init($this, $this->core);
        $this->consumer->consume();
    }

    public function handle($job)
    {
        $workload = (object)['total' => 1, 'jobs' => [$job], 'count' => 1];
        $this->core->output('Handling incomming job: <cyan>' . $job->hook . '</cyan> 🛬');
        $this->core->_work_jobs($workload);
    }

    public function publisher()
    {
        // Mark that Kron consumer is now active
        $this->core->setKronConsumerActive(true);

        $jobs = $this->core->_get_jobs();
        $publisher = apply_filters('krn_kron_get_publisher', false);
        if (! $publisher) {
            $this->core->output("No Publisher set! please register the 'krn_kron_get_publisher' filter and return a publisher");
            exit;
        }
        if (! $publisher instanceof TransportInterface) {
            $this->core->output("the provided result of the filter 'krn_kron_get_publisher' does not implement the TransportInterface");
            exit;
        }
        $this->publisher = $publisher;
        $this->publisher->init($this, $this->core);
        while (true) {
            try {
                // Check database connection before processing jobs
                if (method_exists($this->core, 'checkDatabaseConnection')) {
                    $this->core->checkDatabaseConnection();
                }

                $jobs = $this->core->_get_jobs();
                $this->core->output("Working on <bold>{$jobs->count}</bold>/{$jobs->total} Jobs 🚧");
                foreach ($jobs->jobs as $cron) {
                    //Send it to Message Queue
                    $this->core->output('dispatching: <cyan>' . $cron->hook . '</cyan> 🛫');
                    $this->publisher->send($cron);
                    //remove it from DB table
                    //Re-Scheduling logic is done late once job is processed
                    wp_unschedule_event($cron->timestamp, $cron->hook, unserialize($cron->args));
                }
            } catch (\Exception $e) {
                $this->core->output('<red>Critical error in publisher: ' . $e->getMessage() . '</red>');
                $this->core->output('<yellow>Waiting 30 seconds before retrying...</yellow>');
                sleep(30);
                continue;
            }
            sleep($this->sleepTime);
        }
    }
}
