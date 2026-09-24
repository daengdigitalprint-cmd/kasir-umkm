<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guest tidak bisa mengakses riwayat percakapan', function () {
    $this->get(route('ai.conversations'))->assertRedirect(route('login'));
});

test('menampilkan daftar percakapan milik user login', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $conversation = AiConversation::create([
        'user_id' => $user->id,
        'store_id' => $user->store_id,
        'title' => 'Laporan penjualan',
    ]);

    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Diskusi hasil analisa toko',
    ]);

    $response = $this->actingAs($user)->getJson(route('ai.conversations'));

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('conversations.0.title', 'Laporan penjualan')
        ->assertJsonPath('conversations.0.summary', 'Diskusi hasil analisa toko')
        ->assertJsonStructure(['conversations' => [['id', 'title', 'summary', 'last_message_at']]]);
});

test('tidak menampilkan percakapan milik user lain', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);

    AiConversation::create([
        'user_id' => $other->id,
        'store_id' => $other->store_id,
        'title' => 'Percakapan orang lain',
    ]);

    $response = $this->actingAs($user)->getJson(route('ai.conversations'));

    $response->assertSuccessful()->assertJsonPath('conversations', []);
});

test('mengambil pesan percakapan menampilkan chat user dan assistant', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $conversation = AiConversation::create([
        'user_id' => $user->id,
        'store_id' => $user->store_id,
        'title' => 'Percakapan Lama',
    ]);

    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'system',
        'content' => 'System prompt rahasia',
    ]);
    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Berapa penjualan hari ini?',
    ]);
    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Penjualan hari ini Rp 500.000',
        'feedback' => 'positive',
    ]);

    $response = $this->actingAs($user)->getJson(route('ai.conversations.messages', $conversation));

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('conversation.id', $conversation->id)
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.0.content', 'Berapa penjualan hari ini?')
        ->assertJsonPath('messages.1.role', 'assistant')
        ->assertJsonPath('messages.1.feedback', 'positive')
        ->assertJsonStructure(['messages' => [['id', 'role', 'content', 'feedback', 'timestamp']]]);
});

test('tidak bisa mengambil pesan percakapan milik user lain', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);

    $conversation = AiConversation::create([
        'user_id' => $other->id,
        'store_id' => $other->store_id,
        'title' => 'Privat',
    ]);

    $this->actingAs($user)
        ->getJson(route('ai.conversations.messages', $conversation))
        ->assertForbidden();
});

test('menghapus percakapan dari riwayat', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $conversation = AiConversation::create([
        'user_id' => $user->id,
        'store_id' => $user->store_id,
        'title' => 'Hapus Saya',
    ]);

    $this->actingAs($user)
        ->deleteJson(route('ai.conversations.delete', $conversation))
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('ai_conversations', ['id' => $conversation->id]);
});

test('tidak bisa menghapus percakapan milik user lain', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);

    $conversation = AiConversation::create([
        'user_id' => $other->id,
        'store_id' => $other->store_id,
        'title' => 'Orang Lain',
    ]);

    $this->actingAs($user)
        ->deleteJson(route('ai.conversations.delete', $conversation))
        ->assertForbidden();

    $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->id]);
});
