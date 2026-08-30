<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Produk milik toko yang sama dengan konteks pengguna, atau outlet lain yang
     * pengguna ikuti di store_user (staf gudang/manajer multi-outlet).
     * Perbandingan memakai int agar aman untuk string/int dari driver DB.
     */
    private function canAccessProductStore(User $user, Product $product): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $productStoreId = (int) $product->store_id;

        if ($user->store_id !== null && (int) $user->store_id === $productStoreId) {
            return true;
        }

        return $user->canAccessInventory()
            && $user->isMemberOfStore($productStoreId);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->store_id !== null || $user->stores()->exists();
    }

    public function view(User $user, Product $product): bool
    {
        return $this->canAccessProductStore($user, $product);
    }

    public function create(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->store_id !== null || $user->stores()->exists();
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canAccessProductStore($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->canAccessProductStore($user, $product);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Product $product): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }
}
