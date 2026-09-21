<?php
// tests/Feature/Security/CorsPolicyTest.php
//
// The API does not invite every website on the internet to call it.
//
// allowed_origins was '*'. Bearer tokens mean a random site still holds no
// credential, so this was never the worst thing in the system — but it gave
// away a free layer of protection for no benefit, and a wildcard here is the
// sort of thing that quietly becomes load-bearing once somebody turns
// supports_credentials on without reading the rest of the file.
//
// The two clients that matter are unaffected: the dashboard is served from the
// same origin as the API, and the mobile app is native, so neither is subject
// to CORS at all. This only decides which OTHER sites a browser will let talk
// to it.

namespace Tests\Feature\Security;

use Tests\TestCase;

class CorsPolicyTest extends TestCase
{
    public function test_the_wildcard_is_gone(): void
    {
        $this->assertNotContains(
            '*',
            config('cors.allowed_origins'),
            'the API is open to every website again'
        );
    }

    public function test_a_stranger_is_not_told_it_may_call_this_api(): void
    {
        // The header's absence is the refusal: a browser that gets no
        // Access-Control-Allow-Origin back will not hand the response to the
        // page that asked for it.
        $response = $this->call(
            'OPTIONS',
            '/api/v1/health',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://not-the-rhu.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]
        );

        $this->assertNotSame(
            'https://not-the-rhu.example.com',
            $response->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_configured_origin_is_allowed(): void
    {
        $allowed = config('cors.allowed_origins')[0] ?? null;

        $this->assertNotNull($allowed, 'no origin is allowed at all, which would break local development');

        $response = $this->call(
            'OPTIONS',
            '/api/v1/health',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => $allowed,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ]
        );

        $this->assertSame($allowed, $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_credentials_are_not_sent_cross_origin(): void
    {
        // Cookies plus a permissive origin list is the pairing that actually
        // hurts. This system authenticates with bearer tokens and should never
        // need credentialed cross-origin requests.
        $this->assertFalse((bool) config('cors.supports_credentials'));
    }
}
