<?php

namespace KMM\KRoN\transports;

interface TransportInterface
{
    public function init($manager, $core);

    public function send($message);

    public function consume();
}
