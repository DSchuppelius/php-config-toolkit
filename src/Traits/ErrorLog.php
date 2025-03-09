<?php
/*
 * Created on   : Sun Mar 09 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ErrorLog.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

namespace ConfigToolkit\Traits;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

trait ErrorLog {
    protected ?LoggerInterface $logger = null;

    private static array $logLevelMap = [
        LogLevel::EMERGENCY => LOG_EMERG,
        LogLevel::ALERT     => LOG_ALERT,
        LogLevel::CRITICAL  => LOG_CRIT,
        LogLevel::ERROR     => LOG_ERR,
        LogLevel::WARNING   => LOG_WARNING,
        LogLevel::NOTICE    => LOG_NOTICE,
        LogLevel::INFO      => LOG_INFO,
        LogLevel::DEBUG     => LOG_DEBUG,
    ];

    /**
     * Setzt einen PSR-3 kompatiblen Logger
     */
    public function setLogger(LoggerInterface $logger): void {
        $this->logger = $logger;
    }

    /**
     * Allgemeine Logging-Funktion mit PSR-3 Kompatibilität.
     */
    private function logMessage(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        } else {
            $logString = "[" . date('Y-m-d H:i:s') . "] [" . ucfirst($level) . "] $message";
            if (ini_get('error_log')) {
                error_log($logString);
            } elseif (function_exists('syslog')) {
                syslog(self::$logLevelMap[$level] ?? LOG_INFO, $message);
            } else {
                $tempDir = sys_get_temp_dir();
                $logFile = $tempDir . DIRECTORY_SEPARATOR . "php-config-toolkit.log";
                file_put_contents($logFile, $logString . PHP_EOL, FILE_APPEND);
            }
        }
    }

    protected function logDebug(string $message, array $context = []): void {
        $this->logMessage(LogLevel::DEBUG, $message, $context);
    }

    protected function logInfo(string $message, array $context = []): void {
        $this->logMessage(LogLevel::INFO, $message, $context);
    }

    protected function logNotice(string $message, array $context = []): void {
        $this->logMessage(LogLevel::NOTICE, $message, $context);
    }

    protected function logWarning(string $message, array $context = []): void {
        $this->logMessage(LogLevel::WARNING, $message, $context);
    }

    protected function logError(string $message, array $context = []): void {
        $this->logMessage(LogLevel::ERROR, $message, $context);
    }

    protected function logCritical(string $message, array $context = []): void {
        $this->logMessage(LogLevel::CRITICAL, $message, $context);
    }

    protected function logAlert(string $message, array $context = []): void {
        $this->logMessage(LogLevel::ALERT, $message, $context);
    }

    protected function logEmergency(string $message, array $context = []): void {
        $this->logMessage(LogLevel::EMERGENCY, $message, $context);
    }
}
