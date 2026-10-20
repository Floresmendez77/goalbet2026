<?php
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit("Acceso denegado.\n");
}


class Logger
{
    private int $creados = 0;
    private int $actualizados = 0;
    private int $omitidos = 0;
    private int $errores = 0;
    private $fileHandle;

    public function __construct(string $logDir = __DIR__ . '/logs')
    {
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $file = $logDir . '/seed_' . date('Y-m-d') . '.log';
        $this->fileHandle = fopen($file, 'a');
    }

    public function created(string $msg): void
    {
        $this->creados++;
        $this->write('CREADO', $msg);
    }

    public function updated(string $msg): void
    {
        $this->actualizados++;
        $this->write('ACTUALIZADO', $msg);
    }

    public function skipped(string $msg): void
    {
        $this->omitidos++;
        $this->write('OMITIDO', $msg);
    }

    public function error(string $msg): void
    {
        $this->errores++;
        $this->write('ERROR', $msg);
    }

    public function info(string $msg): void
    {
        $this->write('INFO', $msg);
    }

    private function write(string $level, string $msg): void
    {
        $line = sprintf('[%s] %-11s %s', date('H:i:s'), $level, $msg);
        echo $line . PHP_EOL;
        if ($this->fileHandle) {
            fwrite($this->fileHandle, $line . PHP_EOL);
        }
    }

    public function resumen(): void
    {
        $linea = sprintf(
            '[RESUMEN] creados: %d | actualizados: %d | omitidos: %d | errores: %d',
            $this->creados,
            $this->actualizados,
            $this->omitidos,
            $this->errores
        );
        echo $linea . PHP_EOL;
        if ($this->fileHandle) {
            fwrite($this->fileHandle, $linea . PHP_EOL);
            fclose($this->fileHandle);
        }
    }
}
