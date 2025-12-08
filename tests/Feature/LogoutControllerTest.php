<?php

namespace Tests\Feature;

use Tests\TestCase;

class LogoutControllerTest extends TestCase
{
    public function test_logout_returns_fresh_csrf_token_cookie(): void
    {
        $session = $this->app['session.store'];
        $session->start();

        $originalToken = $session->token();

        $response = $this
            ->withCookie('XSRF-TOKEN', $originalToken)
            ->withHeader('X-CSRF-TOKEN', $originalToken)
            ->postJson(route('logout'));

        $response->assertOk()
            ->assertJson(['status' => 'ok']);

        $csrfCookie = collect($response->headers->getCookies())
            ->firstWhere('Name', 'XSRF-TOKEN');

        $this->assertNotNull($csrfCookie, 'CSRF cookie should be present');
        $this->assertNotSame($originalToken, $csrfCookie->getValue());
        $this->assertSame('lax', strtolower((string) $csrfCookie->getSameSite()));
        $this->assertFalse($csrfCookie->isHttpOnly());
    }
}
