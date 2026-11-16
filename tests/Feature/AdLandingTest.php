<?php

use App\Models\AdLandingSetting;
use App\Models\AdLead;
use App\Models\User;
use App\Support\AdLeadQualifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** Jawaban form lengkap; $ubah menimpa sebagian. */
function jawabanLead(array $ubah = []): array
{
    return array_merge([
        'business_type' => 'retail',
        'business_status' => 'running',
        'daily_transactions' => '20to50',
        'start_timeline' => 'this_week',
        'name' => 'Ibu Rahma',
        'business_name' => 'Toko Berkah',
        'whatsapp' => '0812-3456-7890',
        'city' => 'Makassar',
    ], $ubah);
}

it('menampilkan landing iklan tanpa diindeks mesin pencari', function () {
    config(['services.meta.pixel_id' => null]);

    $this->get('/mulai')
        ->assertOk()
        ->assertSee('noindex, nofollow', false)
        ->assertSee('Usaha Anda bergerak di bidang apa?')
        ->assertDontSee("fbq('init'", false);
});

it('menyesuaikan judul dan pilihan awal dengan jenis usaha dari iklan', function () {
    $this->get('/mulai?usaha=laundry')
        ->assertOk()
        ->assertSee('Nota laundry rapi,')
        ->assertSee('value="laundry" required checked', false);
});

it('memuat Pixel hanya bila ID-nya diatur', function () {
    config(['services.meta.pixel_id' => '1234567890']);

    $this->get('/mulai')->assertOk()->assertSee("fbq('init', \"1234567890\"", false);
});

it('menilai keseriusan lead dari jawabannya', function (string $status, string $timeline, string $tier) {
    expect(AdLeadQualifier::evaluate($status, '20to50', $timeline)['tier'])->toBe($tier);
})->with([
    'berjalan + minggu ini' => ['running', 'this_week', 'hot'],
    'berjalan + bulan ini' => ['running', 'this_month', 'warm'],
    'segera buka + minggu ini' => ['opening_soon', 'this_week', 'warm'],
    'masih rencana' => ['planning', 'this_week', 'cold'],
    'masih lihat-lihat' => ['running', 'browsing', 'cold'],
]);

it('menormalkan nomor WhatsApp Indonesia', function (string $input, ?string $hasil) {
    expect(AdLeadQualifier::normalizeWhatsapp($input))->toBe($hasil);
})->with([
    ['0812-3456-7890', '6281234567890'],
    ['+62 812 3456 7890', '6281234567890'],
    ['81234567890', '6281234567890'],
    ['021-555-1234', null],
    ['12345', null],
]);

it('menyimpan lead serius beserta asal iklannya', function () {
    $this->get('/mulai?utm_source=meta&utm_campaign=promo-nov&utm_content=video-01&fbclid=AbC123');

    $this->post('/mulai', jawabanLead())->assertRedirect('/mulai/terima-kasih');

    $lead = AdLead::sole();

    expect($lead->whatsapp)->toBe('6281234567890')
        ->and($lead->tier)->toBe('hot')
        ->and($lead->status)->toBe('new')
        ->and($lead->utm_campaign)->toBe('promo-nov')
        ->and($lead->utm_content)->toBe('video-01')
        ->and($lead->fbclid)->toBe('AbC123')
        ->and($lead->fbc)->toEndWith('.AbC123')
        ->and($lead->event_id)->not->toBeNull();

    $this->get('/mulai/terima-kasih')
        ->assertOk()
        ->assertSee('Chat kami di WhatsApp')
        ->assertSee('wa.me/'.AdLeadQualifier::WHATSAPP_NUMBER, false);
});

it('mengarahkan lead yang belum siap ke paket gratis, bukan ke tim', function () {
    $this->post('/mulai', jawabanLead(['business_status' => 'planning']))->assertRedirect('/mulai/terima-kasih');

    expect(AdLead::sole()->tier)->toBe('cold');

    $this->get('/mulai/terima-kasih')
        ->assertOk()
        ->assertSee('Buat akun gratis')
        ->assertDontSee('Chat kami di WhatsApp');
});

it('melaporkan Lead ke Pixel hanya untuk lead serius dan hanya sekali', function () {
    config(['services.meta.pixel_id' => '1234567890']);

    $this->post('/mulai', jawabanLead());
    $this->get('/mulai/terima-kasih')->assertSee("'track', 'Lead'", false);
    // Muat ulang: event tidak ditembak lagi.
    $this->get('/mulai/terima-kasih')->assertDontSee("'track', 'Lead'", false);

    $this->post('/mulai', jawabanLead(['whatsapp' => '081300000002', 'start_timeline' => 'browsing']));
    $this->get('/mulai/terima-kasih')
        ->assertDontSee("'track', 'Lead'", false)
        ->assertSee('LeadBelumSiap', false);
});

it('mengirim Lead serius ke Conversions API dengan data ter-hash', function () {
    config(['services.meta.pixel_id' => '1234567890', 'services.meta.capi_token' => 'token-uji']);
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

    $this->post('/mulai', jawabanLead());

    $lead = AdLead::sole();

    Http::assertSent(function ($request) use ($lead) {
        $event = $request['data'][0];

        return str_contains($request->url(), '/1234567890/events')
            && $event['event_name'] === 'Lead'
            && $event['event_id'] === $lead->event_id
            && $event['user_data']['ph'] === [hash('sha256', '6281234567890')]
            && ! str_contains(json_encode($event), '6281234567890');
    });
});

it('tidak mengirim lead yang belum siap ke Conversions API', function () {
    config(['services.meta.pixel_id' => '1234567890', 'services.meta.capi_token' => 'token-uji']);
    Http::fake();

    $this->post('/mulai', jawabanLead(['start_timeline' => 'browsing']));

    Http::assertNothingSent();
});

it('memperbarui lead yang sama saat form diisi ulang, tanpa menghapus tindak lanjut', function () {
    $this->post('/mulai', jawabanLead(['start_timeline' => 'browsing']));
    AdLead::sole()->update(['status' => 'contacted', 'notes' => 'Sudah ditelepon']);

    $this->post('/mulai', jawabanLead(['whatsapp' => '+62 812 3456 7890']));

    $lead = AdLead::sole();

    expect($lead->tier)->toBe('hot')
        ->and($lead->submissions)->toBe(2)
        ->and($lead->status)->toBe('contacted')
        ->and($lead->notes)->toBe('Sudah ditelepon');
});

it('menolak jawaban tidak lengkap atau nomor yang tidak valid', function () {
    $this->from('/mulai')->post('/mulai', jawabanLead(['whatsapp' => '12345', 'start_timeline' => 'besok']))
        ->assertRedirect('/mulai')
        ->assertSessionHasErrors(['whatsapp', 'start_timeline']);

    expect(AdLead::count())->toBe(0);
});

it('mengabaikan kiriman bot yang mengisi kolom jebakan', function () {
    $this->post('/mulai', jawabanLead(['website' => 'http://spam.example']))->assertRedirect('/mulai');

    expect(AdLead::count())->toBe(0);
});

it('mengembalikan pengunjung tanpa lead dari halaman terima kasih ke landing', function () {
    $this->get('/mulai/terima-kasih')->assertRedirect('/mulai');
});

it('hanya super admin yang bisa melihat dan menindaklanjuti lead', function () {
    $this->post('/mulai', jawabanLead());
    $lead = AdLead::sole();

    $this->get('/superadmin/leads')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    $this->get('/superadmin/leads')->assertForbidden();

    $this->actingAs(User::factory()->create(['is_super_admin' => true, 'role' => 'admin', 'email_verified_at' => now()]));

    $this->get('/superadmin/leads?tier=qualified')->assertInertia(
        fn ($page) => $page->component('SuperAdmin/Leads/Index')
            ->where('stats.qualified', 1)
            ->where('leads.data.0.business_name', 'Toko Berkah')
            ->where('leads.data.0.tier_label', 'Siap mulai')
    );

    $this->patch("/superadmin/leads/{$lead->id}", ['status' => 'contacted', 'notes' => 'Minta demo Sabtu'])->assertRedirect();

    expect($lead->fresh()->status)->toBe('contacted')
        ->and($lead->fresh()->notes)->toBe('Minta demo Sabtu')
        ->and($lead->fresh()->contacted_at)->not->toBeNull();
});

it('mengekspor lead ke CSV dan menetralkan rumus spreadsheet', function () {
    $this->post('/mulai', jawabanLead(['business_name' => '=HYPERLINK("http://x")']));

    $this->actingAs(User::factory()->create(['is_super_admin' => true, 'role' => 'admin', 'email_verified_at' => now()]));

    $csv = $this->get('/superadmin/leads/export')->assertOk()->streamedContent();

    expect($csv)->toContain('Ibu Rahma')
        ->toContain("'=HYPERLINK")
        ->toContain('6281234567890');
});

/* ───────────── Pengaturan dari panel Super Admin ───────────── */

function superAdminLead(): User
{
    return User::factory()->create(['is_super_admin' => true, 'role' => 'admin', 'email_verified_at' => now()]);
}

it('memakai ID Pixel yang diatur di panel admin, mendahului .env', function () {
    config(['services.meta.pixel_id' => '1111111111']);
    AdLandingSetting::create(['meta_pixel_id' => '987654321012345']);

    $this->get('/mulai')
        ->assertOk()
        ->assertSee("fbq('init', \"987654321012345\"", false)
        ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
        ->assertDontSee('1111111111', false);
});

it('menyimpan ID Pixel dari panel admin, termasuk bila kode Pixel utuh ditempel', function () {
    $this->actingAs(superAdminLead());

    $kodeUtuh = "<script>!function(f,b,e,v,n,t,s){}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');\n fbq('init', '123456789012345');\n fbq('track', 'PageView');</script>";

    $this->put('/superadmin/leads/settings', ['meta_pixel_id' => $kodeUtuh, 'offer_text' => ' Gratis coba 7 hari '])->assertRedirect();

    expect(AdLandingSetting::pixelId())->toBe('123456789012345')
        ->and(AdLandingSetting::offerText())->toBe('Gratis coba 7 hari');

    $this->get('/mulai')->assertSee("fbq('init', \"123456789012345\"", false)->assertSee('Gratis coba 7 hari');
});

it('menolak ID Pixel yang tidak dikenali', function () {
    $this->actingAs(superAdminLead());

    $this->put('/superadmin/leads/settings', ['meta_pixel_id' => 'pixel-saya'])->assertSessionHasErrors('meta_pixel_id');

    expect(AdLandingSetting::pixelId())->toBeNull();
});

it('menyimpan token Conversions API terenkripsi dan tidak mengirimnya balik ke browser', function () {
    $this->actingAs(superAdminLead());

    $this->put('/superadmin/leads/settings', ['meta_pixel_id' => '123456789012345', 'meta_capi_token' => 'EAAB-rahasia-uji']);

    expect(AdLandingSetting::capiToken())->toBe('EAAB-rahasia-uji')
        ->and(DB::table('ad_landing_settings')->value('meta_capi_token'))->not->toContain('EAAB-rahasia-uji');

    $this->get('/superadmin/leads')
        ->assertInertia(fn ($page) => $page
            ->where('settings.has_capi_token', true)
            ->where('settings.capi_active', true)
            ->where('settings.active_pixel_id', '123456789012345')
            ->missing('settings.meta_capi_token'))
        ->assertDontSee('EAAB-rahasia-uji', false);

    // Menyimpan ulang tanpa mengisi token: token lama tetap ada.
    $this->put('/superadmin/leads/settings', ['meta_pixel_id' => '123456789012345', 'meta_capi_token' => '']);
    expect(AdLandingSetting::capiToken())->toBe('EAAB-rahasia-uji');

    $this->put('/superadmin/leads/settings', ['meta_pixel_id' => '123456789012345', 'clear_capi_token' => true]);
    expect(AdLandingSetting::capiToken())->toBeNull();
});

it('mengirim Lead ke Conversions API memakai pengaturan dari panel admin', function () {
    AdLandingSetting::create(['meta_pixel_id' => '555000111222333', 'meta_capi_token' => 'token-admin', 'meta_test_event_code' => 'TEST99']);
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

    $this->post('/mulai', jawabanLead());

    Http::assertSent(fn ($request) => str_contains($request->url(), '/555000111222333/events')
        && $request['access_token'] === 'token-admin'
        && $request['test_event_code'] === 'TEST99');
});

it('hanya super admin yang bisa mengubah pengaturan Pixel', function () {
    $this->put('/superadmin/leads/settings', ['meta_pixel_id' => '123456789012345'])->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    $this->put('/superadmin/leads/settings', ['meta_pixel_id' => '123456789012345'])->assertForbidden();

    expect(AdLandingSetting::pixelId())->toBeNull();
});

/* ───────────── Perbaikan konversi ───────────── */

it('menerima form tanpa kota karena kota tidak memengaruhi penilaian', function () {
    $this->post('/mulai', jawabanLead(['city' => '']))->assertRedirect('/mulai/terima-kasih');

    expect(AdLead::sole()->city)->toBeNull();
});

it('mengirim SubmitApplication untuk semua pengisi form', function () {
    AdLandingSetting::create(['meta_pixel_id' => '123456789012345']);

    $this->post('/mulai', jawabanLead(['start_timeline' => 'browsing']));

    $this->get('/mulai/terima-kasih')
        ->assertSee("'track', 'SubmitApplication'", false)
        ->assertDontSee("'track', 'Lead'", false);
});

it('tidak menampilkan angka bukti sosial selama datanya masih kecil', function () {
    $this->get('/mulai')->assertOk()->assertDontSee('class="proof"', false)->assertDontSee('outlet terdaftar');
});

it('menampilkan video tutorial yang baru diunduh saat diputar', function () {
    $this->get('/mulai')
        ->assertOk()
        ->assertSee('videos/tutorial-daftar-jualan.mp4', false)
        ->assertSee('preload="none"', false)
        ->assertSee("'VideoPlay'", false);
})->skip(fn () => ! is_file(public_path('videos/tutorial-daftar-jualan.mp4')), 'berkas video belum ada');
