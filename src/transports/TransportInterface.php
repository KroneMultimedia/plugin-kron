<?php

namespace KMM\KRoN\transports;

interface TransportInterface
{
    public function send($message);

    public function consume();
}
