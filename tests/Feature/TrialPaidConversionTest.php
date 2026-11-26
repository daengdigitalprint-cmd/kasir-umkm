<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\TrialActivationLog;
use App\Models\User;
use App\Models\Voucher;
use App\Services\TrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Toko yang sudah membayar (atau diperpanjang) selagi trial Pro masih berjalan
 * tidak boleh ikut diturunkan ke paket Gratis ketika tanggal trialnya lewat.
 */
class TrialPaidConversionTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPlan $growup;

    protected function setUp(): void
    {
        parent::setUp();

        SubscriptionPlan::create(['name' => 'Starter', 'slug' => 'starter', 'price' => 0, 'duration_days' => 0, 'max_products' => 30, 'max_customers' => 50, 'is_active' => true, 'sort_order' => 1, 'billing_cycle' => 'monthly']);
        $this->growup = SubscriptionPlan::create(['name' => 'GrowUp', 'slug' => 'bisnis', 'price' => 99000, 'duration_days' => 30, 'is_active' => true, 'sort_order' => 2, 'billing_cycle' => 'monthly']);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** Toko baru yang trial Pro-nya sedang berjalan. */
    private function trialStore(): Store
    {
        $store = Store::create(['name' => 'Toko Trial', 'slug' => 'toko-trial-'.uniqid(), 'is_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta']);
        $this->assertTrue(TrialService::startForNewStore($store, User::factory()->create(['role' => 'admin'])));

        return $store->refresh();
    }

    private function pendingPayment(Store $store, array $overrides = []): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'store_id' => $store->id,
            'user_id' => User::factory()->create(['role' => 'admin', 'store_id' => $store->id])->id,
            'subscription_plan_id' => $this->growup->id,
            'amount' => 99000,
            'duration_days' => 30,
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            ...$overrides,
        ]);
    }

    public function test_store_that_paid_during_trial_keeps_paid_plan_after_trial_date(): void
    {
        $store = $this->trialStore();
        $trialEnd = $store->pro_trial_end->copy();

        $this->travel(5)->days();
        $payment = $this->pendingPayment($store);
        $this->actingAs($this->superAdmin())->postJson("/superadmin/payments/{$payment->id}/confirm")->assertOk();

        $store->refresh();
        $paidUntil = $store->subscription_end->copy();
        // Sisa hari trial tidak hangus: 30 hari dihitung dari akhir trial.
        $this->assertEqualsWithDelta($trialEnd->copy()->addDays(30)->timestamp, $paidUntil->timestamp, 5);

        // Sehari setelah tanggal trial lewat, perintah terjadwal trial:expire berjalan.
        $this->travelTo($trialEnd->copy()->addDay());
        TrialService::expireExpiredTrials();

        $store->refresh();
        $this->assertSame('bisnis', $store->subscriptionPlan->slug);
        $this->assertNotNull($store->subscription_end);
        $this->assertEqualsWithDelta($paidUntil->timestamp, $store->subscription_end->timestamp, 5);
        $this->assertFalse($store->pro_trial_active);
        $this->assertTrue($store->isSubscriptionActive());
        $this->assertDatabaseMissing('trial_activation_logs', ['store_id' => $store->id, 'action' => 'expired']);
        $this->assertDatabaseMissing('trial_notifications', ['store_id' => $store->id, 'type' => 'trial_expired']);
    }

    public function test_confirming_subscription_payment_ends_trial_status_and_logs_it(): void
    {
        $store = $this->trialStore();
        $admin = $this->superAdmin();

        $this->travel(5)->days();
        $payment = $this->pendingPayment($store);
        $this->actingAs($admin)->postJson("/superadmin/payments/{$payment->id}/confirm")->assertOk();

        $store->refresh();
        $this->assertFalse($store->pro_trial_active);
        $this->assertTrue($store->pro_trial_used);
        $this->assertSame('bisnis', $store->subscriptionPlan->slug);

        $log = TrialActivationLog::where('store_id', $store->id)->where('action', 'converted')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('bisnis', $log->new_plan_slug);

        // Pesan trial yang belum dibaca tidak relevan lagi, dan tidak ada pengingat "trial berakhir".
        $payload = TrialService::payload($store);
        $this->assertFalse($payload['active']);
        $this->assertNull($payload['notice']);

        $this->travelTo($store->pro_trial_end->copy()->subHours(10));
        $this->assertSame(0, TrialService::sendReminders());
    }

    public function test_store_already_marked_trial_but_paid_is_not_downgraded_by_expiry(): void
    {
        // Data lama: pembayaran dikonfirmasi sebelum perbaikan, jadi pro_trial_active masih true.
        $store = $this->trialStore();
        $trialEnd = $store->pro_trial_end->copy();
        $paidUntil = $trialEnd->copy()->addDays(30);
        $store->update(['subscription_end' => $paidUntil, 'subscription_days' => 44]);
        $this->pendingPayment($store)->update(['status' => 'confirmed', 'confirmed_at' => now()->addDays(5)]);

        // Pengingat "besok pindah ke paket Gratis" tidak dikirim ke toko yang sudah membayar.
        $this->travelTo($trialEnd->copy()->subHours(10));
        $this->assertSame(0, TrialService::sendReminders());

        $this->travelTo($trialEnd->copy()->addDay());
        $this->assertSame(1, TrialService::expireExpiredTrials());

        $store->refresh();
        $this->assertFalse($store->pro_trial_active);
        $this->assertSame('bisnis', $store->subscriptionPlan->slug);
        $this->assertEqualsWithDelta($paidUntil->timestamp, $store->subscription_end->timestamp, 5);
        $this->assertDatabaseHas('trial_activation_logs', ['store_id' => $store->id, 'action' => 'converted']);
        $this->assertDatabaseMissing('trial_notifications', ['store_id' => $store->id, 'type' => 'trial_expired']);

        // Tidak diproses dua kali.
        $this->assertSame(0, TrialService::expireExpiredTrials());
    }

    public function test_addon_payment_during_trial_does_not_end_trial_status(): void
    {
        $store = $this->trialStore();

        $payment = $this->pendingPayment($store, ['type' => 'attendance_payroll_addon', 'duration_days' => 30]);
        $this->actingAs($this->superAdmin())->postJson("/superadmin/payments/{$payment->id}/confirm")->assertOk();

        $this->assertTrue($store->refresh()->pro_trial_active);
    }

    public function test_admin_extension_during_trial_survives_trial_date(): void
    {
        $store = $this->trialStore();
        $trialEnd = $store->pro_trial_end->copy();

        $this->actingAs($this->superAdmin())
            ->postJson("/superadmin/stores/{$store->slug}/extend", ['days' => 30])
            ->assertOk();

        $this->assertFalse($store->refresh()->pro_trial_active);
        $this->assertDatabaseHas('trial_activation_logs', ['store_id' => $store->id, 'action' => 'converted']);

        // Tidak ada peringatan "besok pindah ke paket Gratis": toko ini tidak akan diturunkan.
        $this->travelTo($trialEnd->copy()->subHours(10));
        $this->assertSame(0, TrialService::sendReminders());
        $this->assertDatabaseMissing('trial_notifications', ['store_id' => $store->id, 'type' => 'trial_expiring_soon']);

        $this->travelTo($trialEnd->copy()->addDay());
        TrialService::expireExpiredTrials();

        $store->refresh();
        $this->assertSame('bisnis', $store->subscriptionPlan->slug);
        $this->assertEqualsWithDelta($trialEnd->copy()->addDays(30)->timestamp, $store->subscription_end->timestamp, 5);
        $this->assertFalse($store->pro_trial_active);
    }

    public function test_voucher_used_during_trial_survives_trial_date(): void
    {
        $store = $this->trialStore();
        $trialEnd = $store->pro_trial_end->copy();
        $voucher = Voucher::create(['code' => 'UJI30', 'name' => 'Voucher uji', 'days' => 30, 'price' => 0, 'is_active' => true]);

        $this->actingAs($this->superAdmin())
            ->post("/superadmin/stores/{$store->slug}/use-voucher", ['voucher_code' => $voucher->code])
            ->assertRedirect();

        $this->travelTo($trialEnd->copy()->addDay());
        TrialService::expireExpiredTrials();

        $store->refresh();
        $this->assertSame('bisnis', $store->subscriptionPlan->slug);
        $this->assertEqualsWithDelta($trialEnd->copy()->addDays(30)->timestamp, $store->subscription_end->timestamp, 5);
        $this->assertFalse($store->pro_trial_active);
    }

    public function test_unpaid_trial_still_downgrades_to_free_plan(): void
    {
        $store = $this->trialStore();

        $this->travelTo($store->pro_trial_end->copy()->addDay());
        $this->assertSame(1, TrialService::expireExpiredTrials());

        $store->refresh();
        $this->assertSame('starter', $store->subscriptionPlan->slug);
        $this->assertNull($store->subscription_end);
        $this->assertFalse($store->pro_trial_active);
        $this->assertDatabaseHas('trial_activation_logs', ['store_id' => $store->id, 'action' => 'expired']);
    }
}
