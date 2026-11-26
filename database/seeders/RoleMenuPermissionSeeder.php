<?php

namespace Database\Seeders;

use App\Models\RoleMenuPermission;
use Illuminate\Database\Seeder;

class RoleMenuPermissionSeeder extends Seeder
{
    /**
     * Default menu access per role.
     * Menu keys harus cocok dengan array navigationSource di MainMenu.vue.
     */
    public function run(): void
    {
        $defaults = [
            'admin' => [
                'dashboard', 'pos', 'pos_transactions', 'deliveries', 'cash_shifts', 'cash_transactions',
                'kitchen_queue', 'queues', 'tables', 'table_orders', 'laundry_orders',
                'stock_overview', 'products', 'product_categories', 'stock_movements',
                'stock_adjustment', 'stock_opnames', 'recipes', 'productions',
                'stock_transfers', 'stock_requests', 'shopping_list', 'purchases', 'purchase_returns', 'pre_orders',
                'discounts', 'upsell', 'customers', 'receivables', 'loyalty', 'reports', 'expenses',
                'activity_logs', 'manage_stores', 'manage_products', 'manage_staff', 'settings',
                'attendance', 'payroll',
            ],
            'manager' => [
                'dashboard', 'pos', 'pos_transactions', 'deliveries', 'cash_shifts', 'cash_transactions',
                'kitchen_queue', 'queues', 'tables', 'table_orders', 'laundry_orders',
                'stock_overview', 'products', 'product_categories', 'stock_movements',
                'stock_adjustment', 'stock_opnames', 'recipes', 'productions',
                'stock_transfers', 'stock_requests', 'shopping_list', 'purchases', 'purchase_returns', 'pre_orders',
                'discounts', 'upsell', 'customers', 'receivables', 'loyalty', 'reports', 'expenses', 'settings',
                'attendance', 'payroll',
            ],
            'cashier' => [
                'dashboard', 'pos', 'pos_transactions', 'deliveries', 'cash_shifts', 'cash_transactions',
                'kitchen_queue', 'queues', 'tables', 'table_orders', 'laundry_orders',
                'stock_overview', 'stock_adjustment', 'productions', 'settings',
                'attendance',
            ],
            'warehouse' => [
                'dashboard', 'stock_overview', 'products', 'product_categories',
                'stock_movements', 'stock_adjustment', 'stock_opnames',
                'recipes', 'productions', 'stock_transfers', 'stock_requests',
                'shopping_list', 'purchases', 'purchase_returns', 'pre_orders', 'settings',
                'attendance',
            ],
            'kitchen' => [
                'dashboard', 'kitchen_queue', 'settings', 'attendance',
            ],
            'barber' => [
                'dashboard', 'queues', 'pos_transactions', 'attendance', 'settings',
            ],
            // Karyawan biasa: hanya Absensi (absen, riwayat, pengajuan, slip gaji sendiri).
            'staff' => [
                'attendance',
            ],
            'owner' => [
                'dashboard', 'products', 'product_categories',
                'recipes', 'productions', 'stock_transfers', 'stock_requests',
                'shopping_list', 'purchases', 'purchase_returns', 'pre_orders', 'reports', 'expenses',
                'activity_logs', 'kpi', 'settings',
                'attendance', 'payroll',
                // Menu manajemen pemilik usaha (lihat migrasi open_management_menus_for_owner).
                'customers', 'receivables', 'discounts', 'loyalty', 'upsell',
                'manage_products', 'manage_staff', 'manage_stores',
                'stock_opnames', 'stock_movements',
            ],
        ];

        foreach ($defaults as $role => $menus) {
            foreach ($menus as $menuKey) {
                RoleMenuPermission::updateOrCreate(
                    ['role' => $role, 'menu_key' => $menuKey],
                );
            }
        }
    }
}
