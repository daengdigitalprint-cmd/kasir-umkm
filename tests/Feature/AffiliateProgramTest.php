<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
