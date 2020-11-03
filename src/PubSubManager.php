<?php

namespace KMM\KRoN;

use KMM\KRoN\transports\TransportInterface;

class PubSubManager
{
    public function __construct($core)
    {
        $this->core = $core;
        //add_filter('krn_kron_get_publisher', [$this, 'get_publisher'], 10, 1);
        //add_filter('krn_kron_get_consumer', [$this, 'get_consumer'], 10, 1);
    }

    public function consumer()
    {
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
        $this->publisher->init($this, $this->core);
        $this->publisher = $publisher;
        while (true) {
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
            sleep(10);
        }
    }
}
