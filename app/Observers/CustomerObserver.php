<?php

namespace App\Observers;

use App\Models\Customer;
use App\Support\CacheKeys;

/**
 * Invalidate cache laporan saat data pelanggan berubah (jumlah pelanggan baru,
 * pelanggan aktif di KPI/dashboard tidak boleh basi).
 */
class CustomerObserver
{
    public function created(Customer $customer): void
    {
        $this->flush($customer);
    }

    public function updated(Customer $customer): void
    {
        // Perubahan yang memengaruhi laporan: status aktif / toko
        if ($customer->wasChanged(['store_id', 'is_active'])) {
            $this->flush($customer);
        }
    }

    public function deleted(Customer $customer): void
    {
        $this->flush($customer);
    }

    private function flush(Customer $customer): void
    {
        if ($customer->store_id) {
            CacheKeys::forgetStoreReports((int) $customer->store_id);
        }
    }
}
