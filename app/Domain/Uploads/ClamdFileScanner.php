<?php

namespace App\Domain\Uploads;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Scans a private upload with a ClamAV daemon over its INSTREAM protocol
 * (`UPLOADS_SCANNER=clamd`, `CLAMD_ADDRESS=tcp://127.0.0.1:3310` or
 * `unix:///var/run/clamav/clamd.ctl`). The file is streamed in chunks, so
 * it never leaves the server. If the daemon is unreachable or answers
 * unexpectedly this throws: ScanUploadedFile retries, and the file stays
 * `pending` (never served) instead of being waved through.
 */
class ClamdFileScanner implements FileScanner
{
    private const CHUNK = 65536;

    public function __construct(
        private readonly string $address,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function scan(string $disk, string $path): string
    {
        $file = Storage::disk($disk)->readStream($path);

        if (! is_resource($file)) {
            throw new RuntimeException("Upload {$path} could not be opened for scanning.");
        }

        $socket = $this->connect();

        try {
            fwrite($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = (string) fread($file, self::CHUNK);

                if ($chunk === '') {
                    continue;
                }

                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
            fclose($file);
        }

        return self::interpret($reply);
    }

    /**
     * `stream: OK` → clean; `stream: <signature> FOUND` → infected;
     * anything else (e.g. size limit `INSTREAM size limit exceeded`) is an
     * error, never a pass.
     */
    public static function interpret(string $reply): string
    {
        if (str_ends_with($reply, ': OK')) {
            return ScanStatus::CLEAN;
        }

        if (str_ends_with($reply, ' FOUND')) {
            return ScanStatus::INFECTED;
        }

        throw new RuntimeException('Unexpected clamd reply: '.mb_substr($reply, 0, 200));
    }

    /**
     * @return resource
     */
    protected function connect()
    {
        $socket = @stream_socket_client($this->address, $errno, $error, $this->timeoutSeconds);

        if ($socket === false) {
            throw new RuntimeException("clamd unreachable at {$this->address}: {$error}");
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        return $socket;
    }
}
