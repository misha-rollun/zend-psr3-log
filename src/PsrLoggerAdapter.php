<?php

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zend-log for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Log;

use Psr\Log\AbstractLogger as PsrAbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * PSR-3 logger adapter for Zend\Log\LoggerInterface
 *
 * Decorates a LoggerInterface to allow it to be used anywhere a PSR-3 logger
 * is expected.
 */
class PsrLoggerAdapter extends PsrAbstractLogger
{

    /**
     * Zend\Log logger
     *
     * @var PsrLoggerInterface
     */
    protected $logger;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     */
    public function __construct(PsrLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Returns composed LoggerInterface instance.
     *
     * @return PsrLoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     * @return null
     * @throws InvalidArgumentException if log level is not recognized
     */
    public function log($level, $message, array $context = [])
    {
        $this->logger->log($level, $message, $context);
    }

}
