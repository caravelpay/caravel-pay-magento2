<?php
declare(strict_types=1);

namespace Caravel\Pay\Model;

use CaravelPay\Connector\Logger;
use Psr\Log\LoggerInterface;

/** Connector log lines to var/log/caravel_pay.log (see etc/di.xml). */
class PsrLogger implements Logger
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function info($message)
    {
        $this->logger->info('Caravel Pay: ' . $message);
    }

    public function warning($message)
    {
        $this->logger->warning('Caravel Pay: ' . $message);
    }

    public function error($message)
    {
        $this->logger->error('Caravel Pay: ' . $message);
    }
}
