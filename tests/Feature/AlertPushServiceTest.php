<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use App\Notifications\AiAlertPushNotification;
use App\Services\Alerts\AlertPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    Http::fake();
});

function createPushStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Push',
        'slug' => 'toko-push-'.uniqid(),
        'is_active' => true,
    ]);
}

function defaultPushConfig(): void
{
    config([
        'alerts.push.enabled' => true,
        'alerts.push.mail_enabled' => true,
        'alerts.push.telegram.enabled' => false,
        'alerts.push.whatsapp.enabled' => false,
    ]);
}

it('mengirim notifikasi email ketika push mail aktif', function () {
    defaultPushConfig();
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner']);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Roti tinggal 2 pcs.',
        'data' => ['sku' => 'ROTI'],
    ]);

    Notification::assertSentTo($user, AiAlertPushNotification::class);
});

it('melewati email jika push mail dimatikan', function () {
    defaultPushConfig();
    config(['alerts.push.mail_enabled' => false]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner']);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Roti tinggal 2 pcs.',
        'data' => [],
    ]);

    Notification::assertNothingSent();
});

it('melewati email jika alamat tidak valid', function () {
    defaultPushConfig();
    $store = createPushStore();
    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'owner',
        'email' => 'bukan-email',
    ]);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Roti tinggal 2 pcs.',
        'data' => [],
    ]);

    Notification::assertNothingSent();
});

it('melewati semua channel ketika push global dimatikan', function () {
    config(['alerts.push.enabled' => false]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner']);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Roti tinggal 2 pcs.',
        'data' => [],
    ]);

    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('mengirim pesan telegram ke chat id yang dikonfigurasi', function () {
    defaultPushConfig();
    config([
        'alerts.push.telegram.enabled' => true,
        'alerts.push.telegram.bot_token' => 'token-abc',
        'alerts.push.telegram.chat_ids' => '111, 222',
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner']);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'churn_risk',
        'title' => 'Pelanggan hampir churn',
        'message' => 'Bu Sari tidak belanja 40 hari.',
        'data' => [],
    ]);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org/bottoken-abc/sendMessage')
        && $request['chat_id'] === '111'
        && str_contains((string) $request['text'], 'hampir churn'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org/bottoken-abc/sendMessage')
        && $request['chat_id'] === '222');
});

it('tidak mengirim telegram tanpa bot token', function () {
    defaultPushConfig();
    config([
        'alerts.push.telegram.enabled' => true,
        'alerts.push.telegram.bot_token' => null,
        'alerts.push.telegram.chat_ids' => '111',
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner']);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Sisa 2.',
        'data' => [],
    ]);

    Http::assertNothingSent();
});

it('mengirim payload go-wa dengan to dan message ke nomor user', function () {
    defaultPushConfig();
    config([
        'alerts.push.whatsapp.enabled' => true,
        'alerts.push.whatsapp.webhook_url' => null,
        'alerts.push.whatsapp.token' => null,
        'whatsapp.base_url' => 'http://localhost:3000',
        'whatsapp.token' => 'wa-token',
        'whatsapp.recipients' => '',
        'whatsapp.timeout' => 10,
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner', 'phone' => '6281234567890']);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'transaction_anomaly',
        'title' => 'Anomali kasir',
        'message' => 'Diskon 35% pada TRX-1.',
        'data' => ['transaction_number' => 'TRX-1'],
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:3000/api/send'
        && $request->hasHeader('Authorization', 'Bearer wa-token')
        && $request['to'] === '6281234567890@s.whatsapp.net'
        && str_contains((string) $request['message'], 'Anomali kasir'));
});

it('mengirim ke fallback recipients jika user tanpa phone', function () {
    defaultPushConfig();
    config([
        'alerts.push.whatsapp.enabled' => true,
        'whatsapp.base_url' => 'http://localhost:3000',
        'whatsapp.token' => null,
        'whatsapp.recipients' => '628990001111, 628990002222',
        'alerts.push.whatsapp.recipients' => null,
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner', 'phone' => null]);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Sisa 2.',
        'data' => [],
    ]);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request['to'] === '628990001111@s.whatsapp.net');
    Http::assertSent(fn ($request): bool => $request['to'] === '628990002222@s.whatsapp.net');
});

it('menormalisasi nomor dengan strip dan JID utuh', function () {
    defaultPushConfig();
    config([
        'alerts.push.whatsapp.enabled' => true,
        'whatsapp.base_url' => 'http://localhost:3000',
        'whatsapp.recipients' => '62812-3456-789, 628999000111@s.whatsapp.net',
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner', 'phone' => null]);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Sisa 2.',
        'data' => [],
    ]);

    Http::assertSent(fn ($request): bool => $request['to'] === '628123456789@s.whatsapp.net');
    Http::assertSent(fn ($request): bool => $request['to'] === '628999000111@s.whatsapp.net');
});

it('tidak mengirim whatsapp jika tidak ada nomor penerima', function () {
    defaultPushConfig();
    config([
        'alerts.push.whatsapp.enabled' => true,
        'whatsapp.base_url' => 'http://localhost:3000',
        'whatsapp.recipients' => '',
        'alerts.push.whatsapp.recipients' => '',
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner', 'phone' => null]);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Sisa 2.',
        'data' => [],
    ]);

    Http::assertNothingSent();
});

it('tidak mengirim whatsapp tanpa base url / webhook url', function () {
    defaultPushConfig();
    config([
        'alerts.push.whatsapp.enabled' => true,
        'alerts.push.whatsapp.webhook_url' => null,
        'whatsapp.base_url' => '',
        'whatsapp.recipients' => '6281234567890',
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner', 'phone' => '6281234567890']);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Sisa 2.',
        'data' => [],
    ]);

    Http::assertNothingSent();
});

it('fallback ke webhook_url jika base_url kosong', function () {
    defaultPushConfig();
    config([
        'alerts.push.whatsapp.enabled' => true,
        'alerts.push.whatsapp.webhook_url' => 'https://wa-gateway.test',
        'whatsapp.base_url' => '',
        'whatsapp.recipients' => '6281234567890',
    ]);
    $store = createPushStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'owner', 'phone' => null]);

    app(AlertPushService::class)->push($user, $store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => 'Sisa 2.',
        'data' => [],
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'wa-gateway.test')
        && $request['to'] === '6281234567890@s.whatsapp.net');
});
