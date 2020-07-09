<?php

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zend-log for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Log;

use DateTime;
use ErrorException;
use Traversable;
use Zend\Log\Processor\ProcessorInterface;
use Zend\Log\Processor\PsrPlaceholder;
use Zend\Log\Writer\Stream;
use Zend\Log\Writer\WriterInterface;
use Zend\ServiceManager\AbstractPluginManager;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ArrayUtils;
use Zend\Stdlib\SplPriorityQueue;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Logging messages with a stack of backends
 */
class Logger implements PsrLoggerInterface
{

    use LoggerTrait;

    /**
     * Map native PHP errors to priority
     *
     * @var array
     */
    public static $errorPriorityMap = [
        E_NOTICE => LogLevel::NOTICE,
        E_USER_NOTICE => LogLevel::NOTICE,
        E_WARNING => LogLevel::WARNING,
        E_CORE_WARNING => LogLevel::WARNING,
        E_USER_WARNING => LogLevel::WARNING,
        E_ERROR => LogLevel::ERROR,
        E_USER_ERROR => LogLevel::ERROR,
        E_CORE_ERROR => LogLevel::ERROR,
        E_RECOVERABLE_ERROR => LogLevel::ERROR,
        E_PARSE => LogLevel::ERROR,
        E_COMPILE_ERROR => LogLevel::ERROR,
        E_COMPILE_WARNING => LogLevel::ERROR,
        E_STRICT => LogLevel::DEBUG,
        E_DEPRECATED => LogLevel::DEBUG,
        E_USER_DEPRECATED => LogLevel::DEBUG,
    ];

    /**
     * Registered error handler
     *
     * @var bool
     */
    protected static $registeredErrorHandler = false;

    /**
     * Registered shutdown error handler
     *
     * @var bool
     */
    protected static $registeredFatalErrorShutdownFunction = false;

    /**
     * Registered exception handler
     *
     * @var bool
     */
    protected static $registeredExceptionHandler = false;

    /**
     * List of priority code => priority (short) name
     *
     * @var array
     */
    protected $priorities = [
        0 => LogLevel::EMERGENCY,
        1 => LogLevel::ALERT,
        2 => LogLevel::CRITICAL,
        3 => LogLevel::ERROR,
        4 => LogLevel::WARNING,
        5 => LogLevel::NOTICE,
        6 => LogLevel::INFO,
        7 => LogLevel::DEBUG,
    ];

    /**
     * Writers
     *
     * @var SplPriorityQueue
     */
    protected $writers;

    /**
     * Processors
     *
     * @var SplPriorityQueue
     */
    protected $processors;

    /**
     * Writer writerPlugins
     *
     * @var WriterPluginManager
     */
    protected $writerPlugins;

    /**
     * Processor writerPlugins
     *
     * @var ProcessorPluginManager
     */
    protected $processorPlugins;

    /**
     * @var Stream
     */
    protected $stdoutWriter;

    /**
     * Constructor
     *
     * Set options for a logger. Accepted options are:
     * - writers: array of writers to add to this logger
     * - exceptionhandler: if true register this logger as exceptionhandler
     * - errorhandler: if true register this logger as errorhandler
     *
     * @param array|Traversable $options
     * @return Logger
     * @throws Exception\InvalidArgumentException
     */
    public function __construct($options = null)
    {
        $this->stdoutWriter = new Stream('php://stdout');
        $this->writers = new SplPriorityQueue();
        $this->processors = new SplPriorityQueue();

        if ($options instanceof Traversable) {
            $options = ArrayUtils::iteratorToArray($options);
        }

        if (!$options) {
            return;
        }

        if (!is_array($options)) {
            throw new Exception\InvalidArgumentException(
                'Options must be an array or an object implementing \Traversable '
            );
        }

        // Inject writer plugin manager, if available
        if (isset($options['writer_plugin_manager']) && $options['writer_plugin_manager'] instanceof AbstractPluginManager
        ) {
            $this->setWriterPluginManager($options['writer_plugin_manager']);
        }

        // Inject processor plugin manager, if available
        if (isset($options['processor_plugin_manager']) && $options['processor_plugin_manager'] instanceof AbstractPluginManager
        ) {
            $this->setProcessorPluginManager($options['processor_plugin_manager']);
        }

        if (isset($options['writers']) && is_array($options['writers'])) {
            foreach ($options['writers'] as $writer) {
                if (!isset($writer['name'])) {
                    throw new Exception\InvalidArgumentException('Options must contain a name for the writer');
                }

                $priority = (isset($writer['priority'])) ? $writer['priority'] : null;
                $writerOptions = (isset($writer['options'])) ? $writer['options'] : null;

                $this->addWriter($writer['name'], $priority, $writerOptions);
            }
        }

        if (isset($options['processors']) && is_array($options['processors'])) {
            foreach ($options['processors'] as $processor) {
                if (!isset($processor['name'])) {
                    throw new Exception\InvalidArgumentException('Options must contain a name for the processor');
                }

                $priority = (isset($processor['priority'])) ? $processor['priority'] : null;
                $processorOptions = (isset($processor['options'])) ? $processor['options'] : null;

                $this->addProcessor($processor['name'], $priority, $processorOptions);
            }
        }

        if (isset($options['exceptionhandler']) && $options['exceptionhandler'] === true) {
            static::registerExceptionHandler($this);
        }

        if (isset($options['errorhandler']) && $options['errorhandler'] === true) {
            static::registerErrorHandler($this);
        }

        if (isset($options['fatal_error_shutdownfunction']) && $options['fatal_error_shutdownfunction'] === true) {
            static::registerFatalErrorShutdownFunction($this);
        }
    }

    /**
     * Shutdown all writers
     *
     * @return void
     */
    public function __destruct()
    {
        foreach ($this->writers as $writer) {
            try {
                $writer->shutdown();
            } catch (\Exception $e) {

            }
        }
    }

    /**
     * Get writer plugin manager
     *
     * @return WriterPluginManager
     */
    public function getWriterPluginManager()
    {
        if (null === $this->writerPlugins) {
            $this->setWriterPluginManager(new WriterPluginManager(new ServiceManager()));
        }
        return $this->writerPlugins;
    }

    /**
     * Set writer plugin manager
     *
     * @param WriterPluginManager $writerPlugins
     *
     * @return Logger
     */
    public function setWriterPluginManager(WriterPluginManager $writerPlugins)
    {
        $this->writerPlugins = $writerPlugins;
        return $this;
    }

    /**
     * Get writer instance
     *
     * @param string $name
     * @param array|null $options
     * @return Writer\WriterInterface
     */
    public function writerPlugin($name, array $options = null)
    {
        return $this->getWriterPluginManager()->get($name, $options);
    }

    /**
     * Add a writer to a logger
     *
     * @param string|Writer\WriterInterface $writer
     * @param int $priority
     * @param array|null $options
     * @return Logger
     * @throws Exception\InvalidArgumentException
     */
    public function addWriter($writer, $priority = 1, array $options = null)
    {
        if (is_string($writer)) {
            $writer = $this->writerPlugin($writer, $options);
        } elseif (!$writer instanceof Writer\WriterInterface) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Writer must implement %s\Writer\WriterInterface; received "%s"', __NAMESPACE__, is_object($writer) ? get_class($writer) : gettype($writer)
            ));
        }
        $this->writers->insert($writer, $priority);

        return $this;
    }

    /**
     * Get writers
     *
     * @return SplPriorityQueue
     */
    public function getWriters()
    {
        return $this->writers;
    }

    /**
     * Set the writers
     *
     * @param SplPriorityQueue $writers
     * @return Logger
     * @throws Exception\InvalidArgumentException
     */
    public function setWriters(SplPriorityQueue $writers)
    {
        foreach ($writers->toArray() as $writer) {
            if (!$writer instanceof Writer\WriterInterface) {
                throw new Exception\InvalidArgumentException('Writers must be a SplPriorityQueue of Zend\Log\Writer');
            }
        }
        $this->writers = $writers;
        return $this;
    }

    /**
     * Get processor plugin manager
     *
     * @return ProcessorPluginManager
     */
    public function getProcessorPluginManager()
    {
        if (null === $this->processorPlugins) {
            $this->setProcessorPluginManager(new ProcessorPluginManager(new ServiceManager()));
        }
        return $this->processorPlugins;
    }

    /**
     * Set processor plugin manager
     *
     * @param string|ProcessorPluginManager $plugins
     * @return Logger
     * @throws Exception\InvalidArgumentException
     */
    public function setProcessorPluginManager($plugins)
    {
        if (is_string($plugins)) {
            $plugins = new $plugins;
        }
        if (!$plugins instanceof ProcessorPluginManager) {
            throw new Exception\InvalidArgumentException(sprintf(
                'processor plugin manager must extend %s\ProcessorPluginManager; received %s', __NAMESPACE__, is_object($plugins) ? get_class($plugins) : gettype($plugins)
            ));
        }

        $this->processorPlugins = $plugins;
        return $this;
    }

    /**
     * Get processor instance
     *
     * @param string $name
     * @param array|null $options
     * @return Processor\ProcessorInterface
     */
    public function processorPlugin($name, array $options = null)
    {
        return $this->getProcessorPluginManager()->get($name, $options);
    }

    /**
     * Add a processor to a logger
     *
     * @param string|Processor\ProcessorInterface $processor
     * @param int $priority
     * @param array|null $options
     * @return Logger
     * @throws Exception\InvalidArgumentException
     */
    public function addProcessor($processor, $priority = 1, array $options = null)
    {
        if (is_string($processor)) {
            $processor = $this->processorPlugin($processor, $options);
        } elseif (!$processor instanceof Processor\ProcessorInterface) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Processor must implement Zend\Log\ProcessorInterface; received "%s"', is_object($processor) ? get_class($processor) : gettype($processor)
            ));
        }
        $this->processors->insert($processor, $priority);

        return $this;
    }

    /**
     * Get processors
     *
     * @return SplPriorityQueue
     */
    public function getProcessors()
    {
        return $this->processors;
    }

    /**
     * @param $level
     * @param $message
     * @param array $context
     * @return array
     */
    protected function createEvent($level, $message, array $context = array())
    {
        if (!array_key_exists($level, $this->priorities) && !in_array($level, $this->priorities)) {
            throw new InvalidArgumentException(sprintf(
                '$level must be one of PSR-3 log levels; received %s', var_export($level, 1)
            ));
        }

        $priority = is_int($level) ? $level : array_flip($this->priorities)[$level];

        if (is_object($message) && !method_exists($message, '__toString')) {
            throw new Exception\InvalidArgumentException(
                '$message must implement magic __toString() method'
            );
        }

        if (is_array($message)) {
            $message = var_export($message, true);
        }

        $timestamp = new DateTime();

        $event = [
            'timestamp' => $timestamp,
            'priority' => (int)$priority,
            'level' => $this->priorities[$priority],
            'message' => (string)$message,
            'context' => $context,
        ];

        $processorPsrPlaceholderExist = false;

        /* @var $processor ProcessorInterface */
        foreach ($this->processors->toArray() as $processor) {
            $event = $processor->process($event);
            $processorPsrPlaceholderExist = is_a($processor, PsrPlaceholder::class) ? true : $processorPsrPlaceholderExist;
        }

        if (!$processorPsrPlaceholderExist) {
            $processorPsrPlaceholder = new PsrPlaceholder();
            $event = $processorPsrPlaceholder->process($event);
        }

        return $event;
    }

    /**
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return $this
     */
    public function log($level, $message, array $context = array())
    {
        $event = $this->createEvent($level, $message, $context);

        if ($this->writers->count() === 0) {
            trigger_error('No log writer was specified.', E_USER_WARNING);
            return $this;
        }

        $missedWriterEvents = [];
        $executedWriters = [];

        /* @var $writer WriterInterface */
        foreach ($this->writers->toArray() as $writer) {
            try {
                $writer->write($event);
                $executedWriters[] = $writer;
            } catch (\Throwable $e) {
                $missedWriterEvents[] = $this->createEvent(
                    LogLevel::ALERT,
                    'Writer ' . get_class($writer) . ' failed to write log message',
                    ['exception' => $e]
                );
            }
        }

        if (empty($executedWriters)) {
            $this->stdoutWrite($this->createEvent(LogLevel::ALERT, 'No log writer was executed.', $missedWriterEvents));
        }

        // Process case when a write failed to log
        if (!empty($missedWriterEvents) && !empty($executedWriters)) {
            foreach ($missedWriterEvents as $event) {
                foreach ($executedWriters as $executedWriter) {
                    try {
                        $executedWriter->write($event);
                    } catch (\Throwable $e) {
                        $this->stdoutWrite($this->createEvent(LogLevel::ALERT, 'Writer ' . get_class($executedWriter) . ' failed to write log message', ['exception' => $e, 'event' => $event]));
                        continue 1;
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Register logging system as an error handler to log PHP errors
     *
     * @link http://www.php.net/manual/function.set-error-handler.php
     * @param Logger $logger
     * @param bool $continueNativeHandler
     * @return mixed  Returns result of set_error_handler
     * @throws Exception\InvalidArgumentException if logger is null
     */
    public static function registerErrorHandler(Logger $logger, $continueNativeHandler = false)
    {
        // Only register once per instance
        if (static::$registeredErrorHandler) {
            return false;
        }

        $errorPriorityMap = static::$errorPriorityMap;

        $previous = set_error_handler(
            function ($level, $message, $file, $line) use ($logger, $errorPriorityMap, $continueNativeHandler) {
                $iniLevel = error_reporting();

                if ($iniLevel & $level) {
                    if (isset($errorPriorityMap[$level])) {
                        $priority = $errorPriorityMap[$level];
                    } else {
                        $priority = LogLevel::INFO;
                    }
                    $logger->log($priority, $message, [
                        'errno' => $level,
                        'file' => $file,
                        'line' => $line,
                    ]);
                }

                return !$continueNativeHandler;
            }
        );

        static::$registeredErrorHandler = true;
        return $previous;
    }

    /**
     * Unregister error handler
     *
     */
    public static function unregisterErrorHandler()
    {
        restore_error_handler();
        static::$registeredErrorHandler = false;
    }

    /**
     * Register a shutdown handler to log fatal errors
     *
     * @link http://www.php.net/manual/function.register-shutdown-function.php
     * @param Logger $logger
     * @return bool
     */
    public static function registerFatalErrorShutdownFunction(Logger $logger)
    {
        // Only register once per instance
        if (static::$registeredFatalErrorShutdownFunction) {
            return false;
        }

        $errorPriorityMap = static::$errorPriorityMap;

        register_shutdown_function(function () use ($logger, $errorPriorityMap) {
            $error = error_get_last();

            $isFatalError = !in_array(
                $error['type'],
                [
                    E_ERROR,
                    E_PARSE,
                    E_CORE_ERROR,
                    E_CORE_WARNING,
                    E_COMPILE_ERROR,
                    E_COMPILE_WARNING
                ],
                true
            );

            if (null === $error || $isFatalError) {
                return;
            }

            $logger->log(
                $errorPriorityMap[$error['type']], $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                ]
            );
        });

        static::$registeredFatalErrorShutdownFunction = true;

        return true;
    }

    /**
     * Register logging system as an exception handler to log PHP exceptions
     *
     * @link http://www.php.net/manual/en/function.set-exception-handler.php
     * @param Logger $logger
     * @return bool
     * @throws Exception\InvalidArgumentException if logger is null
     */
    public static function registerExceptionHandler(Logger $logger)
    {
        // Only register once per instance
        if (static::$registeredExceptionHandler) {
            return false;
        }

        if ($logger === null) {
            throw new Exception\InvalidArgumentException('Invalid Logger specified');
        }

        $errorPriorityMap = static::$errorPriorityMap;

        set_exception_handler(function ($exception) use ($logger, $errorPriorityMap) {
            $logMessages = [];

            do {
                $priority = LogLevel::ERROR;
                if ($exception instanceof ErrorException && isset($errorPriorityMap[$exception->getSeverity()])) {
                    $priority = $errorPriorityMap[$exception->getSeverity()];
                }

                $context = [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTrace(),
                ];
                if (isset($exception->xdebug_message)) {
                    $context['xdebug'] = $exception->xdebug_message;
                }

                $logMessages[] = [
                    'priority' => $priority,
                    'message' => $exception->getMessage(),
                    'context' => $context,
                ];
                $exception = $exception->getPrevious();
            } while ($exception);

            foreach (array_reverse($logMessages) as $logMessage) {
                $logger->log($logMessage['priority'], $logMessage['message'], $logMessage['context']);
            }
        });

        static::$registeredExceptionHandler = true;
        return true;
    }

    /**
     * Unregister exception handler
     */
    public static function unregisterExceptionHandler()
    {
        restore_exception_handler();
        static::$registeredExceptionHandler = false;
    }

    /**
     * @param array $event
     */
    private function stdoutWrite(array $event)
    {
        try {
            $this->stdoutWriter->write($event);
        } catch (\Throwable $e) {
            // skip any exceptions
        }
    }

}
