<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\TrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Program afiliasi: link → cookie → daftar → toko → pembayaran dikonfirmasi → komisi.
 */
class AffiliateProgramTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPlan $growup;

    protected function setUp(): void
    {
        parent::setUp();

        SubscriptionPlan::create(['name' => 'Starter', 'slug' => 'starter', 'price' => 0, 'duration_days' => 0, 'max_products' => 30, 'max_customers' => 50, 'is_active' => true, 'sort_order' => 1, 'billing_cycle' => 'monthly']);
        $this->growup = SubscriptionPlan::create(['name' => 'GrowUp', 'slug' => 'bisnis', 'price' => 99000, 'duration_days' => 30, 'is_active' => true, 'sort_order' => 2, 'billing_cycle' => 'monthly']);

        config([
            'affiliate.commission_percent' => 20,
            'affiliate.commission_months' => 12,
            'affiliate.hold_days' => 14,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function affiliate(array $userOverrides = []): Affiliate
    {
        return AffiliateService::enroll(User::factory()->create(['name' => 'Budi Santoso', 'phone' => '6281200000001', ...$userOverrides]));
    }

    /** Pemilik toko yang dirujuk afiliator, lengkap dengan grup usaha & tokonya. */
    private function referredStore(Affiliate $affiliate): array
    {
        $owner = User::factory()->create(['name' => 'Siti Aminah', 'role' => 'admin', 'phone' => '6281299999999']);
        $organization = Organization::create(['name' => 'Usaha Siti', 'slug' => 'usaha-siti-'.uniqid(), 'owner_user_id' => $owner->id]);
        $store = Store::create(['name' => 'Toko Siti', 'slug' => 'toko-siti-'.uniqid(), 'is_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta', 'organization_id' => $organization->id]);
        $owner->update(['store_id' => $store->id]);
        $owner->stores()->attach($store->id, ['role' => 'admin']);

        $referral = AffiliateReferral::create([
            'affiliate_id' => $affiliate->id,
            'user_id' => $owner->id,
            'referred_name' => $owner->name,
            'source' => 'link',
        ]);
        AffiliateService::linkOrganization($owner, $store);

        return [$owner, $store, $referral->refresh()];
    }

    private function pendingPayment(Store $store, User $payer, array $overrides = []): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'store_id' => $store->id,
            'user_id' => $payer->id,
            'subscription_plan_id' => $this->growup->id,
            'amount' => 99000,
            'duration_days' => 30,
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            ...$overrides,
        ]);
    }

    private function confirm(SubscriptionPayment $payment): void
    {
        $this->actingAs($this->superAdmin())->postJson("/superadmin/payments/{$payment->id}/confirm")->assertOk();
        auth()->logout();
    }

    private function registrationPayload(array $overrides = []): array
    {
        return [
            'name' => 'Siti Aminah',
            'email' => 'siti@example.com',
            'whatsapp' => '0812 9999 9999',
            'password' => 'password-rahasia-1',
            'password_confirmation' => 'password-rahasia-1',
            ...$overrides,
        ];
    }

    // ───────────────────────── Link & atribusi ─────────────────────────

    public function test_short_link_records_click_and_sets_cookie(): void
    {
        $affiliate = $this->affiliate();

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14)')->get('/r/'.strtolower($affiliate->code));

        $response->assertRedirect('/');
        $response->assertCookie(config('affiliate.cookie_name'), $affiliate->code);
        $this->assertSame(1, $affiliate->clicks()->count());

        // Klik kedua dari pengunjung yang sama di hari yang sama tidak dihitung lagi.
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14)')->get('/r/'.$affiliate->code);
        $this->assertSame(1, $affiliate->clicks()->count());
    }

    public function test_link_previews_are_not_counted_as_clicks(): void
    {
        $affiliate = $this->affiliate();

        $this->withHeader('User-Agent', 'WhatsApp/2.23.20')->get('/r/'.$affiliate->code)->assertRedirect('/');
        $this->assertSame(0, $affiliate->clicks()->count());
    }

    public function test_unknown_code_still_reaches_home_without_cookie(): void
    {
        $this->affiliate();

        $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/r/TIDAKADA1')
            ->assertRedirect('/')
            ->assertCookieMissing(config('affiliate.cookie_name'));
    }

    public function test_ref_query_parameter_on_any_page_is_captured(): void
    {
        $affiliate = $this->affiliate();

        $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/register?ref='.$affiliate->code)
            ->assertOk()
            ->assertCookie(config('affiliate.cookie_name'), $affiliate->code)
            // Kode langsung terpasang di form, walau cookie baru dikirim di respons ini.
            ->assertInertia(fn (Assert $page) => $page->component('auth/Register')->where('referralCode', $affiliate->code));

        $this->assertSame(1, $affiliate->clicks()->count());
    }

    public function test_register_form_shows_code_from_cookie_and_nothing_without_it(): void
    {
        $affiliate = $this->affiliate();

        $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('referralCode', null));

        $this->withCookie(config('affiliate.cookie_name'), $affiliate->code)->get('/register')
            ->assertInertia(fn (Assert $page) => $page->where('referralCode', $affiliate->code));
    }

    public function test_registration_with_cookie_is_attributed_to_affiliate(): void
    {
        $affiliate = $this->affiliate();

        $this->withCookie(config('affiliate.cookie_name'), $affiliate->code)
            ->post('/register', $this->registrationPayload())
            ->assertRedirect(route('stores.create'));

        $user = User::where('email', 'siti@example.com')->firstOrFail();
        $this->assertDatabaseHas('affiliate_referrals', [
            'affiliate_id' => $affiliate->id,
            'user_id' => $user->id,
            'source' => 'link',
            'organization_id' => null,
        ]);
    }

    public function test_typed_code_beats_cookie(): void
    {
        $fromCookie = $this->affiliate();
        $fromForm = $this->affiliate(['name' => 'Rina Wati', 'phone' => '6281200000002']);

        $this->withCookie(config('affiliate.cookie_name'), $fromCookie->code)
            ->post('/register', $this->registrationPayload(['referral_code' => strtolower($fromForm->code)]))
            ->assertRedirect(route('stores.create'));

        $this->assertDatabaseHas('affiliate_referrals', ['affiliate_id' => $fromForm->id, 'source' => 'code']);
        $this->assertSame(0, $fromCookie->referrals()->count());
    }

    public function test_unknown_typed_code_is_rejected(): void
    {
        $this->post('/register', $this->registrationPayload(['referral_code' => 'NGAWUR99']))
            ->assertSessionHasErrors('referral_code');

        $this->assertDatabaseMissing('users', ['email' => 'siti@example.com']);
    }

    public function test_registration_without_link_has_no_referral(): void
    {
        $this->post('/register', $this->registrationPayload())->assertRedirect(route('stores.create'));

        $this->assertSame(0, AffiliateReferral::count());
    }

    public function test_self_referral_by_same_whatsapp_is_ignored(): void
    {
        // Afiliator mendaftarkan akun kedua dengan nomor WhatsApp yang sama.
        $affiliate = $this->affiliate(['phone' => '6281299999999']);

        $this->withCookie(config('affiliate.cookie_name'), $affiliate->code)
            ->post('/register', $this->registrationPayload())
            ->assertRedirect(route('stores.create'));

        $this->assertSame(0, AffiliateReferral::count());
    }

    public function test_suspended_affiliate_link_does_nothing(): void
    {
        $affiliate = $this->affiliate();
        $affiliate->update(['status' => Affiliate::STATUS_SUSPENDED]);

        $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/r/'.$affiliate->code)
            ->assertRedirect('/')
            ->assertCookieMissing(config('affiliate.cookie_name'));

        $this->assertSame(0, $affiliate->clicks()->count());
    }

    public function test_first_store_links_referral_to_organization(): void
    {
        $affiliate = $this->affiliate();

        $this->withCookie(config('affiliate.cookie_name'), $affiliate->code)
            ->post('/register', $this->registrationPayload());

        $user = User::where('email', 'siti@example.com')->firstOrFail();

        $this->actingAs($user)->post('/stores', ['name' => 'Warung Siti', 'business_type' => 'general'])->assertRedirect();

        $store = Store::where('name', 'Warung Siti')->firstOrFail();
        $this->assertNotNull($store->organization_id);
        $this->assertDatabaseHas('affiliate_referrals', [
            'user_id' => $user->id,
            'organization_id' => $store->organization_id,
        ]);
    }

    // ───────────────────────── Komisi ─────────────────────────

    public function test_confirmed_payment_creates_held_commission(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store, $referral] = $this->referredStore($affiliate);

        $payment = $this->pendingPayment($store, $owner);

        // Belum dikonfirmasi: belum ada komisi.
        $this->assertSame(0, AffiliateCommission::count());

        $this->confirm($payment);

        $commission = AffiliateCommission::firstOrFail();
        $this->assertSame($affiliate->id, $commission->affiliate_id);
        $this->assertSame($payment->id, $commission->subscription_payment_id);
        $this->assertEquals(99000, (float) $commission->base_amount);
        $this->assertEquals(20, (float) $commission->percent);
        $this->assertEquals(19800, (float) $commission->amount);
        $this->assertSame('held', $commission->state());

        $referral->refresh();
        $this->assertNotNull($referral->first_paid_at);
        $this->assertEqualsWithDelta(now()->addMonthsNoOverflow(12)->timestamp, $referral->commission_until->timestamp, 5);

        // Setelah masa tahan, komisi siap dibayar.
        $this->travel(15)->days();
        $this->assertSame('ready', $commission->refresh()->state());
        $this->assertEquals(19800, AffiliateService::summary($affiliate)['ready']);
    }

    public function test_commission_uses_amount_after_voucher_and_covers_addons(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);

        $this->confirm($this->pendingPayment($store, $owner, ['amount' => 79000]));
        $this->assertEquals(15800, (float) AffiliateCommission::latest('id')->first()->amount);

        $addon = $this->pendingPayment($store, $owner, ['amount' => 85000, 'type' => 'outlet_addon', 'extra_outlets_count' => 1, 'subscription_plan_id' => null]);
        $this->confirm($addon);

        $commission = AffiliateCommission::where('subscription_payment_id', $addon->id)->firstOrFail();
        $this->assertSame('outlet_addon', $commission->payment_type);
        $this->assertEquals(17000, (float) $commission->amount);
    }

    public function test_renewals_earn_commission_only_within_window(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);

        $this->confirm($this->pendingPayment($store, $owner));

        $this->travel(6)->months();
        $this->confirm($this->pendingPayment($store, $owner));
        $this->assertSame(2, AffiliateCommission::count());

        // Lewat 12 bulan sejak pembayaran pertama: tidak ada komisi lagi.
        $this->travel(7)->months();
        $this->confirm($this->pendingPayment($store, $owner));
        $this->assertSame(2, AffiliateCommission::count());
    }

    public function test_commission_is_paid_once_per_store_when_not_recurring(): void
    {
        // Aturan bawaan: komisi sekali saja, dari paket pertama yang dibeli toko.
        config(['affiliate.commission_months' => 0]);
        $affiliate = $this->affiliate();
        [$owner, $store, $referral] = $this->referredStore($affiliate);

        // Paket pertama: tahunan → komisi dari harga paket itu.
        $this->confirm($this->pendingPayment($store, $owner, ['amount' => 1188000, 'duration_days' => 365]));
        $this->assertSame(1, AffiliateCommission::count());
        $this->assertEquals(237600, (float) AffiliateCommission::first()->amount);
        $this->assertNull($referral->refresh()->commission_until);
        $this->assertNotNull($referral->first_paid_at);

        // Perpanjangan dan add-on berikutnya tidak menghasilkan komisi lagi.
        $this->travel(1)->months();
        $this->confirm($this->pendingPayment($store, $owner));
        $this->confirm($this->pendingPayment($store, $owner, ['amount' => 85000, 'type' => 'outlet_addon', 'extra_outlets_count' => 1, 'subscription_plan_id' => null]));
        $this->travel(13)->months();
        $this->confirm($this->pendingPayment($store, $owner, ['amount' => 1188000, 'duration_days' => 365]));
        $this->assertSame(1, AffiliateCommission::count());

        $this->assertTrue(AffiliateService::rules()['once']);
    }

    public function test_monthly_first_package_also_earns_only_once(): void
    {
        config(['affiliate.commission_months' => 0]);
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);

        $this->confirm($this->pendingPayment($store, $owner)); // bulanan Rp99.000
        $this->travel(1)->months();
        $this->confirm($this->pendingPayment($store, $owner));
        $this->travel(1)->months();
        $this->confirm($this->pendingPayment($store, $owner));

        $this->assertSame(1, AffiliateCommission::count());
        $this->assertEquals(19800, AffiliateService::summary($affiliate)['held'] + AffiliateService::summary($affiliate)['ready']);
    }

    public function test_cancelled_first_commission_lets_next_valid_payment_earn(): void
    {
        config(['affiliate.commission_months' => 0]);
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);

        // Pembayaran pertama di-refund: komisinya dibatalkan.
        $this->confirm($this->pendingPayment($store, $owner));
        $this->actingAs($this->superAdmin())
            ->post('/superadmin/affiliate-commissions/'.AffiliateCommission::first()->id.'/cancel', ['reason' => 'Refund']);
        auth()->logout();

        // Pembayaran sah berikutnya tetap menghasilkan satu komisi.
        $this->confirm($this->pendingPayment($store, $owner, ['amount' => 1188000, 'duration_days' => 365]));
        $this->confirm($this->pendingPayment($store, $owner));

        $this->assertSame(1, AffiliateCommission::where('status', 'earned')->count());
        $this->assertEquals(237600, (float) AffiliateCommission::where('status', 'earned')->first()->amount);
    }

    public function test_payment_from_unreferred_store_earns_nothing(): void
    {
        $this->affiliate();
        $owner = User::factory()->create(['role' => 'admin']);
        $store = Store::create(['name' => 'Toko Biasa', 'slug' => 'toko-biasa', 'is_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta']);

        $this->confirm($this->pendingPayment($store, $owner));

        $this->assertSame(0, AffiliateCommission::count());
    }

    public function test_rejected_payment_earns_nothing(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);
        $payment = $this->pendingPayment($store, $owner);

        $this->actingAs($this->superAdmin())
            ->postJson("/superadmin/payments/{$payment->id}/reject", ['admin_notes' => 'Bukti tidak valid'])
            ->assertOk();

        $this->assertSame(0, AffiliateCommission::count());
    }

    public function test_affiliate_gets_no_commission_from_own_store_or_while_suspended(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);

        // Afiliator ternyata ikut mengelola toko itu.
        $affiliate->user->stores()->attach($store->id, ['role' => 'owner']);
        $this->confirm($this->pendingPayment($store, $owner));
        $this->assertSame(0, AffiliateCommission::count());

        $affiliate->user->stores()->detach($store->id);
        $affiliate->update(['status' => Affiliate::STATUS_SUSPENDED]);
        $this->confirm($this->pendingPayment($store, $owner));
        $this->assertSame(0, AffiliateCommission::count());
    }

    // ───────────────────────── Area afiliator ─────────────────────────

    public function test_guest_can_register_as_affiliate_without_store(): void
    {
        $this->post('/afiliasi/daftar', $this->registrationPayload(['agree' => true]))
            ->assertRedirect(route('affiliate.dashboard'));

        $user = User::where('email', 'siti@example.com')->firstOrFail();
        $this->assertNull($user->store_id);
        $affiliate = $user->affiliate;
        $this->assertNotNull($affiliate);
        $this->assertMatchesRegularExpression('/^SITI\d{3}$/', $affiliate->code);
        $this->assertTrue($user->isAffiliateOnly());

        // Dashboard toko mengalihkan afiliator tanpa toko ke area afiliasi.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('affiliate.dashboard'));
    }

    public function test_affiliate_registration_requires_agreement(): void
    {
        $this->post('/afiliasi/daftar', $this->registrationPayload())->assertSessionHasErrors('agree');
        $this->assertSame(0, Affiliate::count());
    }

    public function test_existing_user_can_join_with_same_account(): void
    {
        $user = User::factory()->create(['name' => 'Andi']);

        $this->actingAs($user)->get('/afiliasi/dashboard')->assertRedirect(route('affiliate.landing'));
        $this->actingAs($user)->post('/afiliasi/gabung', ['agree' => true])->assertRedirect(route('affiliate.dashboard'));

        $this->assertNotNull($user->affiliate()->first());
        // Gabung dua kali tidak membuat kode kedua.
        $this->actingAs($user)->post('/afiliasi/gabung', ['agree' => true]);
        $this->assertSame(1, Affiliate::where('user_id', $user->id)->count());
    }

    public function test_dashboard_shows_link_summary_and_masked_names(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);
        $this->confirm($this->pendingPayment($store, $owner));

        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Affiliate/Dashboard')
                ->where('affiliate.code', $affiliate->code)
                ->where('affiliate.link', url('/r/'.$affiliate->code))
                ->where('summary.signups', 1)
                ->where('summary.paying', 1)
                ->where('summary.held', 19800)
                ->where('referrals.0.name', 'Si** A.')
                ->where('referrals.0.stage', 'paid')
                ->where('commissions.0.state', 'held')
            );
    }

    public function test_affiliate_can_save_payout_account(): void
    {
        $affiliate = $this->affiliate();

        $this->actingAs($affiliate->user)->put('/afiliasi/rekening', [
            'payout_provider' => 'BCA',
            'payout_account_number' => '1234567890',
            'payout_account_holder' => 'Budi Santoso',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($affiliate->refresh()->hasPayoutAccount());

        $this->actingAs($affiliate->user)->put('/afiliasi/rekening', [
            'payout_provider' => 'BCA',
            'payout_account_number' => 'abc',
            'payout_account_holder' => 'Budi Santoso',
        ])->assertSessionHasErrors('payout_account_number');
    }

    // ───────────────────────── Super admin ─────────────────────────

    public function test_superadmin_page_is_protected_and_lists_affiliates(): void
    {
        $affiliate = $this->affiliate();

        $this->actingAs($affiliate->user)->get('/superadmin/affiliates')->assertForbidden();

        $this->actingAs($this->superAdmin())->get('/superadmin/affiliates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Affiliates/Index')
                ->where('stats.affiliates', 1)
                ->where('affiliates.data.0.code', $affiliate->code)
            );
    }

    public function test_superadmin_marks_ready_commissions_paid_but_not_held_ones(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);

        $this->confirm($this->pendingPayment($store, $owner));
        $this->travel(20)->days();
        $this->confirm($this->pendingPayment($store, $owner)); // masih ditahan

        $this->actingAs($this->superAdmin())
            ->post("/superadmin/affiliates/{$affiliate->id}/pay", ['note' => 'Transfer BCA 26 Nov'])
            ->assertSessionHas('success');

        $summary = AffiliateService::summary($affiliate);
        $this->assertEquals(19800, $summary['paid']);
        $this->assertEquals(19800, $summary['held']);
        $this->assertEquals(0, $summary['ready']);
        $this->assertDatabaseHas('affiliate_commissions', ['status' => 'paid', 'paid_note' => 'Transfer BCA 26 Nov']);
    }

    public function test_superadmin_can_cancel_unpaid_commission_only(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);
        $this->confirm($this->pendingPayment($store, $owner));
        $commission = AffiliateCommission::firstOrFail();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/superadmin/affiliate-commissions/{$commission->id}/cancel", [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post("/superadmin/affiliate-commissions/{$commission->id}/cancel", ['reason' => 'Pembayaran di-refund'])
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $commission->refresh()->state());
        $this->assertEquals(0, AffiliateService::summary($affiliate)['held']);

        // Sudah dibatalkan: tidak bisa diproses lagi.
        $this->actingAs($admin)->post("/superadmin/affiliate-commissions/{$commission->id}/cancel", ['reason' => 'lagi'])
            ->assertSessionHas('error');
    }

    public function test_superadmin_can_suspend_and_reactivate_affiliate(): void
    {
        $affiliate = $this->affiliate();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/superadmin/affiliates/{$affiliate->id}/toggle");
        $this->assertFalse($affiliate->refresh()->isActive());

        $this->actingAs($admin)->post("/superadmin/affiliates/{$affiliate->id}/toggle");
        $this->assertTrue($affiliate->refresh()->isActive());
    }

    // ───────────────────────── Tahap 2: trial untuk yang diajak ─────────────────────────

    public function test_referred_store_gets_longer_trial_and_others_keep_default(): void
    {
        config(['affiliate.referred_trial_days' => 30]);
        $affiliate = $this->affiliate();

        // Lewat link afiliasi: 30 hari.
        $this->withCookie(config('affiliate.cookie_name'), $affiliate->code)->post('/register', $this->registrationPayload());
        $referred = User::where('email', 'siti@example.com')->firstOrFail();
        $this->actingAs($referred)->post('/stores', ['name' => 'Warung Siti', 'business_type' => 'general'])->assertRedirect();

        $store = Store::where('name', 'Warung Siti')->firstOrFail();
        $this->assertTrue($store->pro_trial_active);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $store->pro_trial_end->timestamp, 5);
        $this->assertSame(30, (int) $store->subscription_days);
        $this->assertSame(30, TrialService::payload($store)['days_total']);
        $this->assertDatabaseHas('trial_notifications', ['store_id' => $store->id, 'title' => 'Semua fitur Pro aktif 30 hari']);

        // Tanpa link: tetap 14 hari. (Cookie bawaan klien tes dikosongkan: pengunjung lain.)
        auth()->logout();
        $this->defaultCookies = [];
        $this->post('/register', $this->registrationPayload(['email' => 'biasa@example.com', 'whatsapp' => '0813 1111 2222']));
        $plain = User::where('email', 'biasa@example.com')->firstOrFail();
        $this->actingAs($plain)->post('/stores', ['name' => 'Toko Biasa', 'business_type' => 'general'])->assertRedirect();

        $plainStore = Store::where('name', 'Toko Biasa')->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(TrialService::TRIAL_DAYS)->timestamp, $plainStore->pro_trial_end->timestamp, 5);
        $this->assertSame(TrialService::TRIAL_DAYS, TrialService::payload($plainStore)['days_total']);
    }

    public function test_longer_trial_can_be_switched_off(): void
    {
        config(['affiliate.referred_trial_days' => 0]);
        $affiliate = $this->affiliate();

        $this->withCookie(config('affiliate.cookie_name'), $affiliate->code)->post('/register', $this->registrationPayload());
        $referred = User::where('email', 'siti@example.com')->firstOrFail();
        $this->actingAs($referred)->post('/stores', ['name' => 'Warung Siti', 'business_type' => 'general']);

        $store = Store::where('name', 'Warung Siti')->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(TrialService::TRIAL_DAYS)->timestamp, $store->pro_trial_end->timestamp, 5);
        // Atribusinya tetap tercatat.
        $this->assertSame(1, $affiliate->referrals()->count());
    }

    public function test_register_form_tells_trial_benefit_of_referral_code(): void
    {
        config(['affiliate.referred_trial_days' => 30]);

        $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('referralTrialDays', 30));
    }

    // ───────────────────────── Tahap 2: pencairan ─────────────────────────

    /** Afiliator dengan rekening dan komisi Rp198.000 yang sudah bisa ditarik. */
    private function affiliateWithReadyCommission(): array
    {
        $affiliate = $this->affiliate();
        $affiliate->update(['payout_provider' => 'BCA', 'payout_account_number' => '1234567890', 'payout_account_holder' => 'Budi Santoso']);
        [$owner, $store] = $this->referredStore($affiliate);

        $this->confirm($this->pendingPayment($store, $owner, ['amount' => 990000, 'duration_days' => 365]));
        $this->travel(15)->days();

        return [$affiliate, $owner, $store];
    }

    public function test_affiliate_requests_payout_of_all_ready_commissions(): void
    {
        [$affiliate, $owner, $store] = $this->affiliateWithReadyCommission();
        // Komisi baru yang masih ditahan tidak ikut dicairkan.
        $this->confirm($this->pendingPayment($store, $owner));

        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan')->assertSessionHas('success');

        $payout = AffiliatePayout::firstOrFail();
        $this->assertSame('requested', $payout->status);
        $this->assertEquals(198000, (float) $payout->amount);
        $this->assertSame('1234567890', $payout->payout_account_number);
        $this->assertSame(1, $payout->commissions()->count());

        $summary = AffiliateService::summary($affiliate);
        $this->assertEquals(0, $summary['ready']);
        $this->assertEquals(198000, $summary['processing']);
        $this->assertEquals(19800, $summary['held']);

        // Selagi masih diproses, tidak bisa mengajukan lagi.
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan')->assertSessionHasErrors('payout');
        $this->assertSame(1, AffiliatePayout::count());

        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('openPayout.amount', 198000)
                ->where('payouts.0.status', 'requested')
                ->where('summary.processing', 198000)
            );
    }

    public function test_payout_request_needs_minimum_account_and_active_status(): void
    {
        // Di bawah minimum (Rp19.800 < Rp100.000).
        $small = $this->affiliate(['email' => 'kecil@example.com', 'phone' => '6281200000009']);
        $small->update(['payout_provider' => 'BCA', 'payout_account_number' => '111', 'payout_account_holder' => 'Kecil']);
        [$owner, $store] = $this->referredStore($small);
        $this->confirm($this->pendingPayment($store, $owner));
        $this->travel(15)->days();
        $this->actingAs($small->user)->post('/afiliasi/pencairan')->assertSessionHasErrors('payout');

        // Tanpa rekening.
        $small->update(['payout_account_number' => null]);
        config(['affiliate.min_payout' => 10000]);
        $this->actingAs($small->user)->post('/afiliasi/pencairan')->assertSessionHasErrors('payout');

        // Dibekukan.
        $small->update(['payout_account_number' => '111', 'status' => Affiliate::STATUS_SUSPENDED]);
        $this->actingAs($small->user)->post('/afiliasi/pencairan')->assertSessionHasErrors('payout');

        $this->assertSame(0, AffiliatePayout::count());

        // Syarat terpenuhi: berhasil.
        $small->update(['status' => Affiliate::STATUS_ACTIVE]);
        $this->actingAs($small->user)->post('/afiliasi/pencairan')->assertSessionHasNoErrors();
        $this->assertSame(1, AffiliatePayout::count());
    }

    public function test_superadmin_marks_requested_payout_paid_with_proof(): void
    {
        Storage::fake('public');
        [$affiliate] = $this->affiliateWithReadyCommission();
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');
        $payout = AffiliatePayout::firstOrFail();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/superadmin/affiliate-payouts/{$payout->id}/paid", [
            'note' => 'Transfer BCA ref 123',
            'proof' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertSessionHas('success');

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertSame($admin->id, $payout->processed_by);
        $this->assertNotNull($payout->proof_path);
        Storage::disk('public')->assertExists($payout->proof_path);

        $summary = AffiliateService::summary($affiliate);
        $this->assertEquals(198000, $summary['paid']);
        $this->assertEquals(0, $summary['processing']);
        $this->assertDatabaseHas('affiliate_commissions', ['affiliate_payout_id' => $payout->id, 'status' => 'paid', 'paid_note' => 'Transfer BCA ref 123']);

        // Tidak bisa diproses dua kali.
        $this->actingAs($admin)->post("/superadmin/affiliate-payouts/{$payout->id}/paid")->assertSessionHas('error');

        // Afiliator melihat status dan buktinya; tidak ada lagi permintaan terbuka.
        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('openPayout', null)
                ->where('payouts.0.status', 'paid')
                ->where('payouts.0.proof_url', '/storage/'.$payout->proof_path)
                ->where('commissions.0.state', 'paid')
            );
    }

    public function test_payout_proof_must_be_an_image(): void
    {
        Storage::fake('public');
        [$affiliate] = $this->affiliateWithReadyCommission();
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');
        $payout = AffiliatePayout::firstOrFail();

        $this->actingAs($this->superAdmin())->post("/superadmin/affiliate-payouts/{$payout->id}/paid", [
            'proof' => UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('proof');

        $this->assertSame('requested', $payout->refresh()->status);
    }

    public function test_superadmin_rejects_payout_and_commissions_become_ready_again(): void
    {
        [$affiliate] = $this->affiliateWithReadyCommission();
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');
        $payout = AffiliatePayout::firstOrFail();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/superadmin/affiliate-payouts/{$payout->id}/reject", [])->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post("/superadmin/affiliate-payouts/{$payout->id}/reject", ['reason' => 'Nama rekening tidak cocok'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $payout->refresh()->status);
        $summary = AffiliateService::summary($affiliate);
        $this->assertEquals(198000, $summary['ready']);
        $this->assertEquals(0, $summary['processing']);

        // Setelah ditolak, afiliator bisa mengajukan lagi.
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan')->assertSessionHasNoErrors();
        $this->assertSame(2, AffiliatePayout::count());
    }

    public function test_commission_in_open_payout_cannot_be_cancelled(): void
    {
        [$affiliate] = $this->affiliateWithReadyCommission();
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');
        $commission = AffiliateCommission::firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post("/superadmin/affiliate-commissions/{$commission->id}/cancel", ['reason' => 'Refund'])
            ->assertSessionHas('error');

        $this->assertSame('processing', $commission->refresh()->state());
    }

    public function test_direct_transfer_is_recorded_as_paid_payout(): void
    {
        [$affiliate] = $this->affiliateWithReadyCommission();

        $this->actingAs($this->superAdmin())
            ->post("/superadmin/affiliates/{$affiliate->id}/pay", ['note' => 'Transfer langsung'])
            ->assertSessionHas('success');

        $payout = AffiliatePayout::firstOrFail();
        $this->assertSame('paid', $payout->status);
        $this->assertNull($payout->requested_at);
        $this->assertEquals(198000, (float) $payout->amount);
        $this->assertDatabaseHas('affiliate_commissions', ['affiliate_payout_id' => $payout->id, 'status' => 'paid']);
    }

    public function test_superadmin_page_lists_pending_payouts_first(): void
    {
        [$affiliate] = $this->affiliateWithReadyCommission();
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');

        $this->actingAs($this->superAdmin())->get('/superadmin/affiliates')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.pending_payouts', 1)
                ->where('stats.pending_payouts_amount', 198000)
                ->where('payouts.data.0.status', 'requested')
                ->where('payouts.data.0.payout_account_number', '1234567890')
            );
    }

    // ───────────────────────── Tahap 2: materi promosi ─────────────────────────

    public function test_dashboard_offers_links_per_business_type(): void
    {
        $affiliate = $this->affiliate();

        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('promoTargets.0.key', 'umum')
                ->where('promoTargets.0.url', url('/r/'.$affiliate->code))
                ->where('promoTargets.1.key', 'aplikasi-kasir-cafe')
                ->where('promoTargets.1.url', url('/aplikasi-kasir-cafe').'?ref='.$affiliate->code)
            );

        // Link halaman jenis usaha benar-benar memasang cookie afiliator.
        auth()->logout();
        $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/aplikasi-kasir-cafe?ref='.$affiliate->code)
            ->assertOk()
            ->assertCookie(config('affiliate.cookie_name'), $affiliate->code);
    }

    public function test_superadmin_can_preview_program_page_without_being_redirected(): void
    {
        $this->actingAs($this->superAdmin())->get('/afiliasi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Affiliate/Landing')
                ->where('adminPreview', true)
                ->where('viewer', null) // tampilan pengunjung, tanpa form gabung
                ->where('rules.percent', 20)
            );

        // Pengunjung biasa tidak melihat tanda pratinjau.
        auth()->logout();
        $this->get('/afiliasi')->assertInertia(fn (Assert $page) => $page->where('adminPreview', false));
    }

    public function test_login_from_affiliate_button_lands_in_affiliate_area(): void
    {
        $affiliate = $this->affiliate();

        // Tombol "Masuk akun afiliasi" → halaman login khusus afiliasi.
        $this->get('/afiliasi/dashboard')->assertRedirect(route('login'));
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('affiliateLogin', true));

        $this->post('/login', ['email' => $affiliate->user->email, 'password' => 'password'])
            ->assertRedirect('/afiliasi/dashboard');
    }

    public function test_store_owner_logging_in_from_affiliate_button_is_not_sent_to_store_dashboard(): void
    {
        // Pemilik toko yang juga afiliator: biasanya ke dashboard owner, tapi di sini ke area afiliasi.
        $affiliate = $this->affiliate();
        $store = Store::create(['name' => 'Toko Budi', 'slug' => 'toko-budi', 'is_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta']);
        $affiliate->user->update(['store_id' => $store->id, 'role' => 'owner']);
        $affiliate->user->stores()->attach($store->id, ['role' => 'owner']);

        $this->get('/afiliasi/dashboard')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $affiliate->user->email, 'password' => 'password'])
            ->assertRedirect('/afiliasi/dashboard');
        auth()->logout();
        $this->flushSession();

        // Login biasa (bukan dari tombol afiliasi) tetap ke dashboard owner.
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('affiliateLogin', false));
        $this->post('/login', ['email' => $affiliate->user->email, 'password' => 'password'])
            ->assertRedirect(route('owner.dashboard'));
    }

    public function test_program_page_shows_monthly_and_yearly_commission_examples(): void
    {
        SubscriptionPlan::create(['name' => 'GrowUp (tahunan)', 'slug' => 'bisnis-tahunan', 'price' => 1188000, 'duration_days' => 365, 'is_active' => true, 'sort_order' => 5, 'billing_cycle' => 'yearly']);
        // Paket promo dan paket nonaktif tidak dipakai sebagai contoh.
        SubscriptionPlan::create(['name' => 'Promo', 'slug' => 'promo-onboarding-tahunan', 'price' => 792000, 'duration_days' => 365, 'is_active' => true, 'sort_order' => 3, 'billing_cycle' => 'yearly']);

        $this->get('/afiliasi')->assertInertia(fn (Assert $page) => $page
            ->has('examples', 2)
            ->where('examples.0.cycle', 'bulan')
            ->where('examples.0.label', 'Paket bulanan')
            ->where('examples.0.commission', 19800)
            ->where('examples.1.cycle', 'tahun')
            ->where('examples.1.label', 'Paket tahunan')
            ->where('examples.1.plan', 'GrowUp (tahunan)')
            ->where('examples.1.price', 1188000)
            ->where('examples.1.commission', 237600)
        );

        // Paket 3 dan 6 bulan (bila admin menambahkannya) ikut tampil, urut dari terpendek.
        SubscriptionPlan::create(['name' => 'GrowUp 6 bulan', 'slug' => 'bisnis-6-bulan', 'price' => 540000, 'duration_days' => 180, 'is_active' => true, 'sort_order' => 7, 'billing_cycle' => 'monthly']);
        SubscriptionPlan::create(['name' => 'GrowUp 3 bulan', 'slug' => 'bisnis-3-bulan', 'price' => 285000, 'duration_days' => 90, 'is_active' => true, 'sort_order' => 8, 'billing_cycle' => 'monthly']);

        $this->get('/afiliasi')->assertInertia(fn (Assert $page) => $page
            ->has('examples', 4)
            ->where('examples.1.label', 'Paket 3 bulan')
            ->where('examples.1.cycle', '3 bulan')
            ->where('examples.1.commission', 57000)
            ->where('examples.2.label', 'Paket 6 bulan')
            ->where('examples.3.label', 'Paket tahunan')
        );
    }

    // ───────────────────────── Tahap 3: aturan dari super admin ─────────────────────────

    public function test_superadmin_changes_rules_for_new_commissions_only(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);
        $this->confirm($this->pendingPayment($store, $owner)); // aturan bawaan: 20%, tahan 14 hari
        $admin = $this->superAdmin();

        $this->actingAs($affiliate->user)->put('/superadmin/affiliates/settings', ['commission_percent' => 50])->assertForbidden();

        $this->actingAs($admin)->put('/superadmin/affiliates/settings', [
            'commission_percent' => 30,
            'commission_months' => 6,
            'cookie_days' => 45,
            'hold_days' => 7,
            'min_payout' => 50000,
            'referred_trial_days' => 21,
        ])->assertSessionHas('success');
        auth()->logout();

        $rules = AffiliateService::rules();
        $this->assertEquals(30, $rules['percent']);
        $this->assertSame(7, $rules['hold_days']);
        $this->assertSame(50000, $rules['min_payout']);
        $this->assertSame(21, $rules['referred_trial_days']);

        $second = $this->pendingPayment($store, $owner);
        $this->confirm($second);

        $old = AffiliateCommission::oldest('id')->first();
        $new = AffiliateCommission::where('subscription_payment_id', $second->id)->firstOrFail();
        $this->assertEquals(19800, (float) $old->amount); // tidak ikut berubah
        $this->assertEquals(29700, (float) $new->amount);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $new->available_at->timestamp, 5);

        // Halaman program menampilkan angka baru.
        $this->get('/afiliasi')->assertInertia(fn (Assert $page) => $page->where('rules.percent', 30)->where('rules.months', 6));

        // Dikosongkan: kembali ke nilai bawaan.
        $this->actingAs($admin)->put('/superadmin/affiliates/settings', [])->assertSessionHas('success');
        $this->assertEquals(20, AffiliateService::rules()['percent']);
        $this->assertSame(14, AffiliateService::rules()['hold_days']);
    }

    public function test_rule_values_are_validated(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/superadmin/affiliates/settings', ['commission_percent' => 150, 'commission_months' => -1])
            ->assertSessionHasErrors(['commission_percent' => 'Persen komisi maksimal 100.', 'commission_months' => 'Lama komisi minimal 0.']);

        $this->assertEquals(20, AffiliateService::rules()['percent']);
    }

    public function test_superadmin_sets_special_rate_for_one_affiliate(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/superadmin/affiliates/{$affiliate->id}/rate", ['percent' => 35])->assertSessionHas('success');
        auth()->logout();
        $this->confirm($this->pendingPayment($store, $owner));
        $this->assertEquals(34650, (float) AffiliateCommission::latest('id')->first()->amount);

        $this->actingAs($admin)->post("/superadmin/affiliates/{$affiliate->id}/rate", ['percent' => null]);
        auth()->logout();
        $this->assertNull($affiliate->refresh()->commission_percent);
        $this->confirm($this->pendingPayment($store, $owner));
        $this->assertEquals(19800, (float) AffiliateCommission::latest('id')->first()->amount);

        $this->actingAs($admin)->post("/superadmin/affiliates/{$affiliate->id}/rate", ['percent' => 101])->assertSessionHasErrors('percent');
    }

    // ───────────────────────── Tahap 3: notifikasi ─────────────────────────

    public function test_affiliate_is_notified_about_signup_commission_and_payout(): void
    {
        $affiliate = $this->affiliate();
        $affiliate->update(['payout_provider' => 'BCA', 'payout_account_number' => '1234567890', 'payout_account_holder' => 'Budi Santoso']);

        // Pendaftar baru lewat link.
        $this->withCookie(config('affiliate.cookie_name'), $affiliate->code)->post('/register', $this->registrationPayload());
        $referred = User::where('email', 'siti@example.com')->firstOrFail();
        $this->actingAs($referred)->post('/stores', ['name' => 'Warung Siti', 'business_type' => 'general']);
        auth()->logout();
        $this->assertDatabaseHas('affiliate_notifications', ['affiliate_id' => $affiliate->id, 'type' => 'signup']);

        // Komisi baru.
        $store = Store::where('name', 'Warung Siti')->firstOrFail();
        $this->confirm($this->pendingPayment($store, $referred, ['amount' => 990000, 'duration_days' => 365]));
        $this->assertDatabaseHas('affiliate_notifications', ['affiliate_id' => $affiliate->id, 'type' => 'commission', 'title' => 'Komisi baru Rp198.000']);

        // Pencairan ditolak lalu dibayar.
        $this->travel(15)->days();
        $admin = $this->superAdmin();
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');
        $this->actingAs($admin)->post('/superadmin/affiliate-payouts/'.AffiliatePayout::latest('id')->first()->id.'/reject', ['reason' => 'Rekening salah']);
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');
        $this->actingAs($admin)->post('/superadmin/affiliate-payouts/'.AffiliatePayout::latest('id')->first()->id.'/paid');
        $this->assertDatabaseHas('affiliate_notifications', ['affiliate_id' => $affiliate->id, 'type' => 'payout_rejected']);
        $this->assertDatabaseHas('affiliate_notifications', ['affiliate_id' => $affiliate->id, 'type' => 'payout_paid', 'title' => 'Pencairan Rp198.000 sudah ditransfer']);

        // Tampil di dashboard (terbaru dulu), lalu hilang setelah ditandai dibaca.
        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')
            ->assertInertia(fn (Assert $page) => $page->has('notifications', 4)->where('notifications.0.type', 'payout_paid'));

        $this->actingAs($affiliate->user)->post('/afiliasi/notifikasi/baca')->assertRedirect();
        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')->assertInertia(fn (Assert $page) => $page->has('notifications', 0));
    }

    public function test_cancelled_commission_notifies_affiliate(): void
    {
        $affiliate = $this->affiliate();
        [$owner, $store] = $this->referredStore($affiliate);
        $this->confirm($this->pendingPayment($store, $owner));

        $this->actingAs($this->superAdmin())
            ->post('/superadmin/affiliate-commissions/'.AffiliateCommission::firstOrFail()->id.'/cancel', ['reason' => 'Pembayaran di-refund']);

        $this->assertDatabaseHas('affiliate_notifications', [
            'affiliate_id' => $affiliate->id,
            'type' => 'commission_cancelled',
            'title' => 'Komisi Rp19.800 dibatalkan',
        ]);
    }

    public function test_superadmin_sees_pending_payout_badge_count(): void
    {
        [$affiliate] = $this->affiliateWithReadyCommission();
        $this->actingAs($affiliate->user)->post('/afiliasi/pencairan');

        $this->actingAs($this->superAdmin())->get('/superadmin/affiliates')
            ->assertInertia(fn (Assert $page) => $page->where('pending_affiliate_payouts', 1));

        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('pending_affiliate_payouts', 0));
    }

    // ───────────────────────── Tahap 3: tukar komisi jadi langganan ─────────────────────────

    /** Beri afiliator toko sendiri (sebagai admin toko) supaya bisa menukar komisi. */
    private function giveOwnStore(Affiliate $affiliate): Store
    {
        $store = Store::create(['name' => 'Toko Budi', 'slug' => 'toko-budi-'.uniqid(), 'is_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta']);
        $affiliate->user->update(['store_id' => $store->id, 'role' => 'admin']);
        $affiliate->user->stores()->attach($store->id, ['role' => 'admin']);

        return $store;
    }

    public function test_store_owner_redeems_commission_for_own_subscription(): void
    {
        [$affiliate] = $this->affiliateWithReadyCommission(); // Rp198.000 bisa ditarik
        $ownStore = $this->giveOwnStore($affiliate);
        $before = AffiliateCommission::count();

        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('redeem.store_name', 'Toko Budi')
                ->where('redeem.plans.0.id', $this->growup->id)
                ->where('redeem.plans.0.price', 99000)
            );

        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => $this->growup->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        // Langganan toko langsung aktif 30 hari dengan paket GrowUp.
        $ownStore->refresh();
        $this->assertSame($this->growup->id, $ownStore->subscription_plan_id);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $ownStore->subscription_end->timestamp, 5);
        $this->assertTrue($ownStore->isSubscriptionActive());

        // Tercatat sebagai pembayaran terkonfirmasi tanpa uang masuk.
        $payment = SubscriptionPayment::where('store_id', $ownStore->id)->firstOrFail();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('affiliate_balance', $payment->payment_method);
        $this->assertEquals(99000, (float) $payment->amount);

        // Komisi Rp198.000 dipecah: Rp99.000 terpakai, Rp99.000 masih bisa ditarik.
        $summary = AffiliateService::summary($affiliate);
        $this->assertEquals(99000, $summary['ready']);
        $this->assertEquals(99000, $summary['paid']);
        $this->assertSame($before + 1, AffiliateCommission::count());
        $this->assertEquals(990000, (float) AffiliateCommission::sum('base_amount')); // dasar komisi tetap utuh

        $payout = AffiliatePayout::firstOrFail();
        $this->assertSame('subscription', $payout->type);
        $this->assertSame('paid', $payout->status);
        $this->assertSame($payment->id, $payout->subscription_payment_id);

        // Pembayaran dengan komisi tidak melahirkan komisi baru dan tidak dihitung sebagai pendapatan.
        $this->assertSame(0, AffiliateCommission::where('subscription_payment_id', $payment->id)->count());
        $this->actingAs($this->superAdmin())->get('/superadmin')
            ->assertInertia(fn (Assert $page) => $page->where('stats.total_revenue', fn ($value) => (float) $value === 990000.0));

        // Sisa komisi bisa dipakai sekali lagi, pas habis (tanpa pecahan baru).
        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => $this->growup->id])->assertSessionHasNoErrors();
        $this->assertEquals(0, AffiliateService::summary($affiliate)['ready']);
        $this->assertSame($before + 1, AffiliateCommission::count());
        $this->assertEqualsWithDelta(now()->addDays(60)->timestamp, $ownStore->refresh()->subscription_end->timestamp, 5);

        // Saldo habis: ditolak.
        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => $this->growup->id])->assertSessionHasErrors('redeem');
    }

    public function test_redeem_is_refused_without_store_with_pending_payment_or_for_promo_plan(): void
    {
        [$affiliate] = $this->affiliateWithReadyCommission();

        // Afiliator tanpa toko: tidak ada pilihan tukar, dan permintaan ditolak.
        $this->actingAs($affiliate->user)->get('/afiliasi/dashboard')->assertInertia(fn (Assert $page) => $page->where('redeem', null));
        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => $this->growup->id])->assertSessionHasErrors('redeem');

        $ownStore = $this->giveOwnStore($affiliate);

        // Paket promo dan paket gratis tidak bisa ditukar.
        $promo = SubscriptionPlan::create(['name' => 'Promo', 'slug' => 'promo-onboarding-tahunan', 'price' => 50000, 'duration_days' => 365, 'is_active' => true, 'sort_order' => 9, 'billing_cycle' => 'yearly']);
        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => $promo->id])->assertSessionHasErrors('redeem');
        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => SubscriptionPlan::where('slug', 'starter')->value('id')])->assertSessionHasErrors('redeem');

        // Masih ada pembayaran yang menunggu konfirmasi.
        $pending = $this->pendingPayment($ownStore, $affiliate->user);
        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => $this->growup->id])->assertSessionHasErrors('redeem');
        $pending->delete();

        // Dibekukan.
        $affiliate->update(['status' => Affiliate::STATUS_SUSPENDED]);
        $this->actingAs($affiliate->user)->post('/afiliasi/tukar-langganan', ['plan_id' => $this->growup->id])->assertSessionHasErrors('redeem');

        $this->assertSame(0, AffiliatePayout::count());
        $this->assertEquals(198000, AffiliateService::summary($affiliate)['ready']);
    }

    // ───────────────────────── Tahap 3: papan peringkat ─────────────────────────

    public function test_leaderboard_ranks_this_month_and_masks_other_names(): void
    {
        $budi = $this->affiliate();
        [$owner, $store] = $this->referredStore($budi);

        // Bulan lalu tidak dihitung.
        $this->travelTo(now()->startOfMonth()->subDays(3));
        $this->confirm($this->pendingPayment($store, $owner, ['amount' => 990000]));
        $this->travelBack();

        $this->assertSame([], AffiliateService::leaderboard($budi)['rows']);
        $this->assertNull(AffiliateService::leaderboard($budi)['me']['rank']);

        // Bulan ini: Rina (Rp198.000) di atas Budi (Rp19.800).
        $rina = $this->affiliate(['name' => 'Rina Wati', 'email' => 'rina@example.com', 'phone' => '6281200000002']);
        $rinaOwner = User::factory()->create(['name' => 'Joko', 'role' => 'admin', 'phone' => '6281377770000']);
        $rinaOrg = Organization::create(['name' => 'Usaha Joko', 'slug' => 'usaha-joko', 'owner_user_id' => $rinaOwner->id]);
        $rinaStore = Store::create(['name' => 'Toko Joko', 'slug' => 'toko-joko', 'is_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta', 'organization_id' => $rinaOrg->id]);
        AffiliateReferral::create(['affiliate_id' => $rina->id, 'user_id' => $rinaOwner->id, 'organization_id' => $rinaOrg->id, 'referred_name' => 'Joko', 'source' => 'link']);

        $this->confirm($this->pendingPayment($rinaStore, $rinaOwner, ['amount' => 990000]));
        $this->confirm($this->pendingPayment($store, $owner));

        $board = AffiliateService::leaderboard($budi);
        $this->assertCount(2, $board['rows']);
        $this->assertSame('Ri** W.', $board['rows'][0]['name']);
        $this->assertEquals(198000, $board['rows'][0]['earned']);
        $this->assertSame(1, $board['rows'][0]['paying']);
        $this->assertFalse($board['rows'][0]['is_me']);
        $this->assertSame('Budi Santoso', $board['rows'][1]['name']);
        $this->assertTrue($board['rows'][1]['is_me']);
        $this->assertSame(2, $board['me']['rank']);

        // Super admin melihat nama asli.
        $this->actingAs($this->superAdmin())->get('/superadmin/affiliates')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboard.rows.0.name', 'Rina Wati'));

        // Afiliator yang dibekukan keluar dari papan.
        $rina->update(['status' => Affiliate::STATUS_SUSPENDED]);
        $this->assertCount(1, AffiliateService::leaderboard($budi)['rows']);
    }
}
