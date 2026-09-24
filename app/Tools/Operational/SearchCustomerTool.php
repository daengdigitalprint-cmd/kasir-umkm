<?php

namespace App\Tools\Operational;

use App\Models\Customer;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class SearchCustomerTool extends AiTool
{
    public function getName(): string
    {
        return 'search_customer';
    }

    public function getDescription(): string
    {
        return 'Mencari pelanggan berdasarkan nama, nomor telepon, atau email. Gunakan tool ini saat user ingin mencari data pelanggan.';
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Kata kunci pencarian (nama, telepon, atau email pelanggan)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah maksimal hasil (default: 10)',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return ['query'];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $this->validateArgs($args, $this->getRequiredParameters());

        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $limit = $args['limit'] ?? 10;

        $customers = Customer::where('store_id', $store->id)
            ->where(function ($q) use ($args) {
                $q->where('name', 'like', "%{$args['query']}%")
                    ->orWhere('phone', 'like', "%{$args['query']}%")
                    ->orWhere('email', 'like', "%{$args['query']}%");
            })
            ->limit($limit)
            ->get();

        if ($customers->isEmpty()) {
            return ToolResult::success(
                data: ['customers' => []],
                message: "Tidak ditemukan pelanggan dengan kata kunci \"{$args['query']}\""
            );
        }

        $customerList = $customers->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone ?? '-',
            'email' => $c->email ?? '-',
            'total_transactions' => $c->total_orders ?? 0,
            'total_spent' => $this->formatCurrency($c->total_spent ?? 0),
        ])->toArray();

        return ToolResult::success(
            data: ['customers' => $customerList, 'total' => $customers->count()],
            message: "Ditemukan {$customers->count()} pelanggan"
        );
    }
}
