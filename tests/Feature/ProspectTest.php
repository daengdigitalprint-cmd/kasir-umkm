<?php

use App\Models\Prospect;
use App\Models\User;
use App\Services\Prospecting\OsmProspectSource;
use App\Services\Prospecting\ProspectCollector;
use App\Support\ProspectScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** Jawaban tiruan Nominatim + Overpass; tidak ada panggilan jaringan sungguhan. */
function palsukanPeta(array $elements): void
{
    Http::preventStrayRequests();
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            ['osm_type' => 'node', 'osm_id' => 1, 'category' => 'place', 'name' => 'Makassar'],
            ['osm_type' => 'relation', 'osm_id' => 7626001, 'category' => 'boundary', 'name' => 'Makassar'],
        ]),
        'overpass-api.de/*' => Http::response(['elements' => $elements]),
    ]);
}

function kedai(int $id, string $nama, array $tags = [], string $type = 'node'): array
{
    return ['type' => $type, 'id' => $id, 'lat' => -5.14, 'lon' => 119.42, 'tags' => ['amenity' => 'cafe', 'name' => $nama] + $tags];
}

it('menormalkan nomor telepon Indonesia', function (?string $mentah, ?string $hasil) {
    expect(OsmProspectSource::normalizePhone($mentah))->toBe($hasil);
})->with([
    ['0812-3456-7890', '6281234567890'],
    ['+62 812 3456 7890', '6281234567890'],
    ['0411 872345; 0812 3456 7890', '6281234567890'], // seluler diutamakan
    ['(0411) 872345', '62411872345'],
    ['12345', null],
    [null, null],
]);

it('mengenali jenis usaha dari kata kunci', function () {
    expect(OsmProspectSource::matchCategory('Kedai Kopi')['label'])->toBe('Kedai kopi')
        ->and(OsmProspectSource::matchCategory('toko bangunan')['label'])->toBe('Toko bangunan')
        ->and(OsmProspectSource::matchCategory('barbershop')['label'])->toBe('Barbershop & salon')
        ->and(OsmProspectSource::matchCategory('studio foto'))->toBeNull();
});

it('memberi skor lebih tinggi untuk usaha yang mudah dihubungi dan belum punya situs', function () {
    $mudah = ProspectScorer::score(['phone' => '6281234567890', 'phone_is_mobile' => true, 'instagram' => 'kopi.senja', 'website' => 'https://instagram.com/kopi.senja']);
    $punyaSitus = ProspectScorer::score(['phone' => '62411872345', 'website' => 'https://kopisenja.co.id']);
    $kosong = ProspectScorer::score([]);

    expect($mudah)->toBe(8)
        ->and($punyaSitus)->toBe(3)
        ->and($kosong)->toBe(2)
        ->and(ProspectScorer::tier($mudah))->toBe('high')
        ->and(ProspectScorer::tier($kosong))->toBe('low');
});

it('menyimpan hasil pencarian tanpa duplikat dan melewati jaringan besar', function () {
    palsukanPeta([
        kedai(1, 'Kopi Senja', ['phone' => '0812-3456-7890', 'contact:instagram' => 'https://www.instagram.com/kopi.senja/', 'addr:street' => 'Jl. Pengayoman']),
        kedai(2, 'Kopi Senja', ['phone' => '+62 812 3456 7890'], 'way'), // objek kedua untuk usaha yang sama
        kedai(3, 'Warkop Dg. Sija'),
        kedai(4, 'Starbucks', ['brand:wikidata' => 'Q37158']),
        kedai(5, 'Kopi Kenangan Pettarani'),
        ['type' => 'node', 'id' => 6, 'tags' => ['amenity' => 'cafe']], // tanpa nama
    ]);

    $hasil = app(ProspectCollector::class)->collect('kedai kopi', 'makassar');

    expect($hasil)->toMatchArray(['city' => 'Makassar', 'category' => 'Kedai kopi', 'found' => 3, 'added' => 2, 'duplicates' => 1, 'skipped_chains' => 2, 'with_phone' => 1])
        ->and(Prospect::count())->toBe(2);

    $senja = Prospect::where('name', 'Kopi Senja')->sole();
    expect($senja->phone)->toBe('6281234567890')
        ->and($senja->phone_is_mobile)->toBeTrue()
        ->and($senja->instagram)->toBe('kopi.senja')
        ->and($senja->address)->toBe('Jl. Pengayoman')
        ->and($senja->status)->toBe('new')
        ->and($senja->score)->toBeGreaterThan(Prospect::where('name', 'Warkop Dg. Sija')->sole()->score);
});

it('tidak menimpa status dan catatan saat pencarian diulang', function () {
    palsukanPeta([kedai(1, 'Kopi Senja', ['phone' => '0812-3456-7890'])]);
    $collector = app(ProspectCollector::class);
    $collector->collect('kedai kopi', 'Makassar');

    Prospect::sole()->update(['status' => 'interested', 'notes' => 'Minta demo Sabtu']);

    $hasil = $collector->collect('kedai kopi', 'Makassar');

    expect($hasil)->toMatchArray(['added' => 0, 'updated' => 1])
        ->and(Prospect::sole()->status)->toBe('interested')
        ->and(Prospect::sole()->notes)->toBe('Minta demo Sabtu');
});

it('hanya superadmin yang bisa membuka dan memakai halaman prospek', function () {
    $this->get('/superadmin/prospects')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    $this->get('/superadmin/prospects')->assertForbidden();
    $this->post('/superadmin/prospects/search', ['keyword' => 'kedai kopi', 'city' => 'Makassar'])->assertForbidden();
});

it('superadmin mencari, menindaklanjuti, mengekspor, dan menghapus prospek', function () {
    palsukanPeta([kedai(1, '=Kopi Senja', ['phone' => '0812-3456-7890']), kedai(3, 'Warkop Dg. Sija')]);
    $this->actingAs(User::factory()->create(['is_super_admin' => true, 'role' => 'admin', 'email_verified_at' => now()]));

    $this->post('/superadmin/prospects/search', ['keyword' => 'kedai kopi', 'city' => 'Makassar'])
        ->assertRedirect(route('superadmin.prospects.index', ['city' => 'Makassar', 'category' => 'Kedai kopi']))
        ->assertSessionHas('prospect_search_result');

    $this->get('/superadmin/prospects?contact=mobile')->assertInertia(
        fn ($page) => $page->component('SuperAdmin/Prospects/Index')
            ->has('prospects.data', 1)
            ->where('prospects.data.0.phone_local', '081234567890')
            ->where('stats.total', 2)
            ->where('stats.to_contact', 1)
    );

    $prospek = Prospect::whereNotNull('phone')->sole();
    $this->patch("/superadmin/prospects/{$prospek->id}", ['status' => 'not_interested', 'notes' => 'Sudah pakai kasir lain'])->assertRedirect();
    expect($prospek->fresh()->status)->toBe('not_interested')
        ->and($prospek->fresh()->contacted_at)->not->toBeNull();

    $this->patch("/superadmin/prospects/{$prospek->id}", ['status' => 'dikirimi_spam'])->assertSessionHasErrors('status');

    $csv = $this->get('/superadmin/prospects/export')->assertOk()->streamedContent();
    expect($csv)->toContain('Tidak tertarik')->toContain("'=Kopi Senja")->toContain('Warkop Dg. Sija');

    $this->delete("/superadmin/prospects/{$prospek->id}")->assertRedirect();
    expect(Prospect::count())->toBe(1);
});

it('menolak kota yang tidak dikenal dengan pesan yang jelas', function () {
    Http::preventStrayRequests();
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
    $this->actingAs(User::factory()->create(['is_super_admin' => true, 'role' => 'admin', 'email_verified_at' => now()]));

    $this->post('/superadmin/prospects/search', ['keyword' => 'kedai kopi', 'city' => 'Kota Antah Berantah'])
        ->assertSessionHasErrors('city');

    expect(Prospect::count())->toBe(0);
});
