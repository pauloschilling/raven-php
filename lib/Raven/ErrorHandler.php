<?php

/*
 * This file is part of Raven.
 *
 * (c) Sentry Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Event handlers for exceptions and errors
 *
 * $client = new Raven_Client('http://public:secret/example.com/1');
 * $error_handler = new Raven_ErrorHandler($client);
 * $error_handler->registerExceptionHandler();
 * $error_handler->registerErrorHandler();
 * $error_handler->registerShutdownFunction();
 *
 * @package raven
 */

class Raven_ErrorHandler
{
    private $old_exception_handler;
    private $call_existing_exception_handler = false;
    private $old_error_handler;
    private $call_existing_error_handler = false;
    private $reservedMemory;
    private $send_errors_last = false;
    private $error_types = -1;
    private $error_types_shutdown;
    private $send_silented_errors = false;

    public function __construct($client, $send_errors_last = false)
    {
        $this->error_types_shutdown = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING | E_STRICT;
        $this->client = $client;
        register_shutdown_function(array($this, 'detectShutdown'));
        if ($send_errors_last) {
            $this->send_errors_last = true;
            $this->client->store_errors_for_bulk_send = true;
            register_shutdown_function(array($this->client, 'sendUnsentErrors'));
        }
    }

    public function handleException($e, $isError = false, $vars = null)
    {
        $e->event_id = $this->client->getIdent($this->client->captureException($e, null, null, $vars));

        if (!$isError && $this->call_existing_exception_handler && $this->old_exception_handler) {
            call_user_func($this->old_exception_handler, $e);
        }
    }

    public function handleError($code, $message, $file = '', $line = 0, $context=array())
    {
        if (!$this->send_silented_errors && (error_reporting() == 0)) {
            return;
        }

        if ($this->error_types & $code) {
            $e = new ErrorException($message, 0, $code, $file, $line);
            $this->handleException($e, true, $context);
        }

        if ($this->call_existing_error_handler && $this->old_error_handler) {
            call_user_func($this->old_error_handler, $code, $message, $file, $line, $context);
        }
    }

    public function handleFatalError()
    {
        if (null === $lastError = error_get_last()) {
            return;
        }

        unset($this->reservedMemory);

        if ($lastError['type'] & $this->error_types_shutdown) {
            $e = new ErrorException(
                @$lastError['message'], @$lastError['type'], @$lastError['type'],
                @$lastError['file'], @$lastError['line']
            );
            $this->handleException($e, true);
        }
    }

    public function registerExceptionHandler($call_existing_exception_handler = true)
    {
        $this->old_exception_handler = set_exception_handler(array($this, 'handleException'));
        $this->call_existing_exception_handler = $call_existing_exception_handler;
    }

    public function registerErrorHandler($call_existing_error_handler = true, $error_types = -1, $send_silented_errors = false)
    {
        $this->error_types = $error_types;
        $this->send_silented_errors = $send_silented_errors;
        $this->old_error_handler = set_error_handler(array($this, 'handleError'), error_reporting());
        $this->call_existing_error_handler = $call_existing_error_handler;
    }

    public function registerShutdownFunction($reservedMemorySize = 10, $error_types = null)
    {
        if ($error_types !== null) {
            $this->error_types_shutdown = $error_types;
        }

        register_shutdown_function(array($this, 'handleFatalError'));

        $this->reservedMemory = str_repeat('x', 1024 * $reservedMemorySize);
    }

    public function detectShutdown() {
        if (!defined('RAVEN_CLIENT_END_REACHED')) {
            define('RAVEN_CLIENT_END_REACHED', true);
        }
    }
}
