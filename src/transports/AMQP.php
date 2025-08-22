<?php

namespace KMM\KRoN\transports;

use Enqueue\AmqpBunny\AmqpConnectionFactory;

class AMQP implements TransportInterface
{
    public function __construct()
    {
        $dsn = null;
        if (defined('KRN_KRON_AMQP_DSN')) {
            $dsn = KRN_KRON_AMQP_DSN;
        }
        if (getenv('KRN_KRON_AMQP_DSN')) {
            $dsn = getenv('KRN_KRON_AMQP_DSN');
        }

        $this->amqp = (new AmqpConnectionFactory($dsn))->createContext();

        $this->queue = $this->amqp->createQueue('KRON');
        $this->amqp->declareQueue($this->queue);
    }

    public function init($manager, $core)
    {
        $this->core = $core;
        $this->manager = $manager;
        $this->core->output('using AMQP Publisher/Consumer 🐰');
    }

    public function send($message)
    {
        $msg = $this->amqp->createMessage(json_encode($message));
        $this->amqp->createProducer()->send($this->queue, $msg);
    }

    public function receiveMessage($message, $consumer)
    {
        try {
            // Check database connection before processing job
            if (method_exists($this->core, 'checkDatabaseConnection')) {
                $this->core->checkDatabaseConnection();
            }

            $job = json_decode($message->getBody());
            $this->manager->handle($job);
            $consumer->acknowledge($message);
        } catch (\Exception $e) {
            $this->core->output('<red>Error processing message: ' . $e->getMessage() . '</red>');
            // Reject the message so it can be requeued or sent to dead letter queue
            $consumer->reject($message, false);
        }

        return true;
    }

    public function consume()
    {
        $this->consumer = $this->amqp->createConsumer($this->queue);
        $this->subscriptionConsumer = $this->amqp->createSubscriptionConsumer();
        $this->subscriptionConsumer->subscribe($this->consumer, [$this, 'receiveMessage']);

        $this->subscriptionConsumer->consume();
    }
}
