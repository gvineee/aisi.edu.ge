<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behind Cloudflare the visitor's address comes from X-Forwarded-For, but
 * only when the request really arrives from a trusted edge; the host (and
 * so the school) is never taken from a forwarded header.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/client', fn (Request $request) => ['ip' => $request->ip(), 'host' => $request->getHost(), 'secure' => $request->isSecure()]);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function client(string $remote, array $headers): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders($headers)->get('http://localhost/_test/client')->assertOk()->json();
    }

    public function test_the_visitor_address_is_read_through_a_cloudflare_edge(): void
    {
        config(['trustedproxy.proxies' => ['104.16.0.0/13']]);

        $seen = $this->client('104.22.177.23', ['X-Forwarded-For' => '203.0.113.7, 104.22.177.23', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'other-school.test']);

        $this->assertSame(['ip' => '203.0.113.7', 'host' => 'localhost', 'secure' => true], $seen);
    }

    public function test_forwarded_headers_from_anyone_else_are_ignored(): void
    {
        config(['trustedproxy.proxies' => ['104.16.0.0/13']]);

        $this->assertSame('198.51.100.9', $this->client('198.51.100.9', ['X-Forwarded-For' => '203.0.113.7'])['ip']);
    }

    public function test_nobody_is_trusted_by_default(): void
    {
        config(['trustedproxy.proxies' => []]);

        $this->assertSame('104.22.177.23', $this->client('104.22.177.23', ['X-Forwarded-For' => '203.0.113.7'])['ip']);
    }
}
