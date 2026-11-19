<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Staff forget their password, and a session ends after two idle hours. On a
 * device of their own they may choose to stay signed in; on the shared computer
 * in the staff room they must not be, so it is never the default.
 */
class StaySignedInTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_sign_in_screen_offers_to_stay_signed_in_and_says_who_resets_a_password(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="remember"', false)
            ->assertDontSee('name="remember" value="1" checked', false)
            ->assertSee('Stay signed in')
            ->assertSee('The headmaster can reset it for you.');
    }

    #[Test]
    public function a_plain_sign_in_is_not_remembered(): void
    {
        $response = $this->post(route('login.attempt'), ['id' => (string) $this->headmaster->id, 'password' => 'secret']);

        $this->assertAuthenticatedAs($this->headmaster);
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
        $this->assertNull($this->headmaster->fresh()->remember_token);
    }

    #[Test]
    public function ticking_the_box_keeps_the_device_signed_in_until_they_sign_out(): void
    {
        $response = $this->post(route('login.attempt'), [
            'id' => (string) $this->headmaster->id, 'password' => 'secret', 'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($this->headmaster);
        $response->assertCookie(Auth::guard()->getRecallerName());
        $token = $this->headmaster->fresh()->remember_token;
        $this->assertNotNull($token);

        // Signing out ends it: the remembered token is replaced, so the cookie
        // left on the device no longer opens the account.
        $this->get(route('logout'));
        $this->assertGuest();
        $this->assertNotSame($token, $this->headmaster->fresh()->remember_token);
    }
}
