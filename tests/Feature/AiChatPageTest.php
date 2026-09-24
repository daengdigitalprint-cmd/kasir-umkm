<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guest diarahkan ke halaman login', function () {
    $this->get(route('ai.chat.page'))->assertRedirect(route('login'));
});

test('user login bisa membuka halaman chat layar penuh', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->get(route('ai.chat.page'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Ai/Chat'));
});
