<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBatchStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_status_constants_are_defined_and_available_is_default(): void
    {
        $this->assertSame('available', ProductBatch::STATUS_AVAILABLE);
        $this->assertContains(ProductBatch::STATUS_QUARANTINED, ProductBatch::STATUSES);
        $this->assertContains(ProductBatch::STATUS_EXPIRED, ProductBatch::STATUSES);
        $this->assertContains(ProductBatch::STATUS_DESTROYED, ProductBatch::STATUSES);
        $this->assertContains(ProductBatch::STATUS_RETURNED, ProductBatch::STATUSES);
    }

    public function test_pharmacy_inventory_user_can_quarantine_and_release_without_changing_quantity(): void
    {
        [$store, $user, $batch] = $this->makeBatch();
        $quantity = $batch->quantity_remaining;

        $this->actingAs($user)->post(route('product-batches.quarantine', $batch), ['reason' => 'Kemasan rusak'])
            ->assertSessionHas('success');
        $batch->refresh();
        $this->assertSame(ProductBatch::STATUS_QUARANTINED, $batch->status);
        $this->assertSame($quantity, $batch->quantity_remaining);
        $this->assertSame('Kemasan rusak', $batch->quarantine_reason);
        $this->assertSame($user->id, $batch->quarantined_by);
        $this->assertDatabaseHas('stock_movements', ['type' => 'batch_quarantine', 'quantity_change' => 0, 'notes' => 'Kemasan rusak']);

        $this->actingAs($user)->post(route('product-batches.release', $batch))->assertSessionHas('success');
        $batch->refresh();
        $this->assertSame(ProductBatch::STATUS_AVAILABLE, $batch->status);
        $this->assertSame($quantity, $batch->quantity_remaining);
        $this->assertNotNull($batch->released_at);
        $this->assertDatabaseHas('stock_movements', ['type' => 'batch_release', 'quantity_change' => 0]);
    }

    public function test_quarantine_requires_reason_and_only_pharmacy_owner_can_access(): void
    {
        [$store, $user, $batch] = $this->makeBatch();
        $this->actingAs($user)->post(route('product-batches.quarantine', $batch), [])->assertSessionHasErrors('reason');

        $other = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
        $this->actingAs($other)->post(route('product-batches.quarantine', $batch), ['reason' => 'x'])->assertRedirect(route('dashboard'));

        $nonPharmacy = Store::create(['name' => 'Toko', 'slug' => uniqid(), 'business_type' => 'retail', 'is_active' => true, 'is_subscription_active' => true]);
        $nonUser = User::factory()->create(['store_id' => $nonPharmacy->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $nonBatch = ProductBatch::create(['store_id' => $nonPharmacy->id, 'product_id' => Product::create(['store_id' => $nonPharmacy->id, 'name' => 'P', 'price' => 1000])->id, 'quantity_remaining' => 1, 'status' => ProductBatch::STATUS_AVAILABLE]);
        $this->actingAs($nonUser)->post(route('product-batches.quarantine', $nonBatch), ['reason' => 'x'])->assertNotFound();
    }

    public function test_release_expired_batch_is_rejected(): void
    {
        [$store, $user, $batch] = $this->makeBatch(['status' => ProductBatch::STATUS_QUARANTINED, 'expiry_date' => today()->subDay()]);
        $this->actingAs($user)->post(route('product-batches.release', $batch))->assertStatus(422);
    }

    public function test_destroy_supports_partial_and_full_batch_with_damage_movement(): void
    {
        [$store, $user, $batch] = $this->makeBatch();
        $product = $batch->product;
        $this->actingAs($user)->post(route('product-batches.destroy', $batch), ['quantity' => 2, 'reason' => 'Rusak'])->assertSessionHas('success');
        $this->assertSame('3.0000', $batch->fresh()->quantity_remaining);
        $this->assertSame('8.0000', $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['type' => StockMovement::TYPE_DAMAGE, 'quantity_change' => '-2.0000']);

        $this->actingAs($user)->post(route('product-batches.destroy', $batch), ['quantity' => 3, 'reason' => 'Musnah'])->assertSessionHas('success');
        $this->assertSame(ProductBatch::STATUS_DESTROYED, $batch->fresh()->status);
        $this->assertSame('5.0000', $product->fresh()->stock_quantity);
    }

    public function test_destroy_rejects_overbalance_and_wrong_store(): void
    {
        [$store, $user, $batch] = $this->makeBatch();
        $this->actingAs($user)->post(route('product-batches.destroy', $batch), ['quantity' => 6, 'reason' => 'Rusak'])->assertStatus(422);
        $other = User::factory()->create(['store_id' => Store::create(['name' => 'Lain', 'slug' => uniqid(), 'business_type' => 'pharmacy', 'is_active' => true, 'is_subscription_active' => true])->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($other)->post(route('product-batches.destroy', $batch), ['quantity' => 1, 'reason' => 'Rusak'])->assertRedirect(route('dashboard'));
    }

    private function makeBatch(array $overrides = []): array
    {
        $store = Store::create(['name' => 'Apotek', 'slug' => uniqid(), 'business_type' => 'pharmacy', 'is_active' => true, 'is_subscription_active' => true]);
        $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $product = Product::create(['store_id' => $store->id, 'name' => 'Obat', 'price' => 1000, 'stock_quantity' => 10]);
        $batch = ProductBatch::create(array_merge(['store_id' => $store->id, 'product_id' => $product->id, 'quantity_remaining' => 5, 'expiry_date' => today()->addMonth(), 'status' => ProductBatch::STATUS_AVAILABLE], $overrides));
        return [$store, $user, $batch];
    }
}
