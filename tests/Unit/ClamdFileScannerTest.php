<?php

namespace Tests\Unit;

use App\Domain\Uploads\ClamdFileScanner;
use App\Domain\Uploads\ScanStatus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The clamd adapter streams INSTREAM chunks and maps the daemon's reply;
 * anything unexpected or an unreachable daemon is an error, never "clean".
 */
class ClamdFileScannerTest extends TestCase
{
    public function test_reply_mapping(): void
    {
        $this->assertSame(ScanStatus::CLEAN, ClamdFileScanner::interpret('stream: OK'));
        $this->assertSame(ScanStatus::INFECTED, ClamdFileScanner::interpret('stream: Eicar-Test-Signature FOUND'));

        $this->expectException(RuntimeException::class);
        ClamdFileScanner::interpret('INSTREAM size limit exceeded. ERROR');
    }

    public function test_streams_instream_frames_and_reads_the_verdict(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('x/file.pdf', '%PDF-1.4 test');

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($server, $error);
        $address = 'tcp://'.stream_socket_get_name($server, false);
        $daemon = null;

        $scanner = new class($address, $server, $daemon) extends ClamdFileScanner
        {
            /**
             * @param  resource  $server
             * @param  resource|null  $daemon
             */
            public function __construct(private string $addr, private $server, private &$daemon)
            {
                parent::__construct($addr, 5);
            }

            protected function connect()
            {
                $client = stream_socket_client($this->addr);
                $this->daemon = stream_socket_accept($this->server);
                // The fake daemon answers up front and closes its write side.
                fwrite($this->daemon, "stream: Eicar-Test-Signature FOUND\0");
                stream_socket_shutdown($this->daemon, STREAM_SHUT_WR);

                return $client;
            }
        };

        $this->assertSame(ScanStatus::INFECTED, $scanner->scan('local', 'x/file.pdf'));

        $received = stream_get_contents($daemon);
        $this->assertSame("zINSTREAM\0".pack('N', 13).'%PDF-1.4 test'.pack('N', 0), $received);
    }

    public function test_unreachable_daemon_throws_instead_of_passing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('x/file.pdf', '%PDF-1.4 test');

        $this->expectException(RuntimeException::class);
        (new ClamdFileScanner('tcp://127.0.0.1:1', 1))->scan('local', 'x/file.pdf');
    }
}
