<?php


namespace App\Service;


class Logger
{
    /**
     * @var string
     */
    private $logPath;
    /**
     * @var bool
     */
    private $logToFile;
    /**
     * @var bool
     */
    private $logToConsole;

    /**
     * Logger constructor.
     * @param string $logPath
     * @param bool $logToFile
     * @param bool $logToConsole
     */
    public function __construct(string $logPath, bool $logToFile, bool $logToConsole)
    {
        $this->logPath = $logPath;
        $this->logToFile = $logToFile;
        $this->logToConsole = $logToConsole;
    }

    public function log(string $data, string $type): void
    {
        $printableLine = (new \DateTime())->format('d-m-Y H:i:s') . ' - [' . $type . '] ' . $data . PHP_EOL;
        if ($this->logToFile) {
            file_put_contents($this->logPath, $printableLine, FILE_APPEND);
        }
        if ($this->logToConsole) {
            echo $printableLine;
        }
    }
}