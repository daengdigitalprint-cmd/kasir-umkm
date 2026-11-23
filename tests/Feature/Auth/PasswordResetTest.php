<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.store'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.store'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

test('setelah minta tautan reset, halaman menampilkan email tujuan tanpa flash status (tidak dobel popup)', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('reset_link_sent_to', $user->email)
        ->assertSessionMissing('status');

    Notification::assertSentToTimes($user, ResetPassword::class, 1);

    $this->withSession(['reset_link_sent_to' => $user->email])
        ->get(route('password.request'))
        ->assertInertia(fn ($page) => $page
            ->component('auth/ForgotPassword')
            ->where('sentTo', $user->email)
            ->where('resendSeconds', 60)
            ->where('flash.status', null));
});

test('email yang tidak terdaftar tetap mendapat tampilan terkirim yang sama', function () {
    Notification::fake();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'tidak-ada@contoh.test'])
        ->assertSessionHas('reset_link_sent_to', 'tidak-ada@contoh.test');

    Notification::assertNothingSent();
});
