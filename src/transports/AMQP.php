<?php
namespace KMM\KRoN\transports;

use KMM\KRoN\transports\TransportInterface;

use Enqueue\AmqpExt\AmqpConnectionFactory;
use Interop\Queue\Message;
use Interop\Queue\Consumer;

class AMQP implements TransportInterface
{
    public function __construct($manager, $core)
    {
        $dsn = null;
        if (defined("KRN_KRON_AMQP_DSN")) {
            $dsn = KRN_KRON_AMQP_DSN;
        }
        if (getenv('KRN_KRON_AMQP_DSN')) {
            $dsn = getenv('KRN_KRON_AMQP_DSN');
        }

        $this->amqp =  (new AmqpConnectionFactory($dsn))->createContext();

        $this->queue = $this->amqp->createQueue('KRON');
        $this->amqp->declareQueue($this->queue);
        $this->core = $core;
        $this->manager = $manager;
        $this->core->output("using AMQP Publisher/Consumer 🐰");
    }
    public function send($message)
    {
        $msg = $this->amqp->createMessage(json_encode($message));
        $this->amqp->createProducer()->send($this->queue, $msg);
    }
    public function receiveMessage($message, $consumer) {
        $job = json_decode($message->getBody());
        $this->manager->handle($job);
        $consumer->acknowledge($message);


        return true;
    }
    public function consume()
    {
      $this->consumer = $this->amqp->createConsumer($this->queue);
      $this->subscriptionConsumer = $this->amqp->createSubscriptionConsumer();
      $this->subscriptionConsumer->subscribe($this->consumer, [$this, "receiveMessage"]);

      $this->subscriptionConsumer->consume();

    }
}
