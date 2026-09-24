<?php

namespace App\Tools\Audit;

use App\Models\AiAuditLog;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class ActionLogTool extends AiTool
{
    public function getName(): string
    {
        return 'get_action_log';
    }

    public function getDescription(): string
    {
        return 'Menampilkan riwayat aksi AI yang sudah dieksekusi di toko ini: perubahan produk, stok, purchase order, hingga konfirmasi yang ditolak user. Gunakan saat user bertanya "riwayat aksi ai", "apa saja yang kamu ubah", "aksi apa saja yang sudah dilakukan", atau "konfirmasi apa yang saya tolak".';
    }

    public function getParameters(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah aksi terakhir yang ditampilkan (default: 10, maksimum: 50)',
            ],
            'include_reads' => [
                'type' => 'boolean',
                'description' => 'Termasuk juga aksi baca (cek stok, laporan, dll). Default hanya aksi yang mengubah data.',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return [];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
        $includeReads = (bool) ($args['include_reads'] ?? false);
        $writeTools = (array) config('ai.confirmation_tools', []);

        $query = AiAuditLog::query()->where('store_id', $store->id);

        if (! $includeReads) {
            $query->where(function ($q) use ($writeTools) {
                $q->whereIn('tool_name', $writeTools)
                    ->orWhere('action', 'confirmation_rejected');
            });
        }

        $logs = $query->latest()->limit($limit)->get();

        if ($logs->isEmpty()) {
            return ToolResult::success(
                data: ['actions' => [], 'total' => 0],
                message: 'Belum ada aksi AI yang tercatat di toko ini.'
            );
        }

        $actions = $logs->map(fn (AiAuditLog $log) => [
            'time' => $log->created_at->format('d/m/Y H:i'),
            'action' => $log->action,
            'tool' => $log->tool_name,
            'status' => $log->status,
            'parameters' => $log->parameters,
            'summary' => $this->summarize($log),
        ])->values();

        $lines = $actions->map(fn (array $a, int $index) => sprintf(
            '%d. [%s] **%s** — %s — %s',
            $index + 1,
            $a['time'],
            $a['tool'] ?? $a['action'],
            $this->statusLabel($a['status']),
            $a['summary'],
        ))->implode("\n");

        $total = AiAuditLog::query()->where('store_id', $store->id)->count();

        return ToolResult::success(
            data: ['actions' => $actions->all(), 'total' => $total],
            message: "Riwayat aksi AI terakhir ({$logs->count()} dari {$total} total):\n\n{$lines}"
        );
    }

    private function summarize(AiAuditLog $log): string
    {
        if ($log->action === 'confirmation_rejected') {
            $params = $this->compactParams($log->parameters);

            return 'Konfirmasi ditolak user'.($params !== '' ? " untuk {$params}" : '');
        }

        $resultMessage = $log->result['message'] ?? '';
        if ($resultMessage !== '') {
            return \Illuminate\Support\Str::limit($resultMessage, 120);
        }

        return $this->compactParams($log->parameters) ?: '-';
    }

    private function compactParams(?array $parameters): string
    {
        if (! $parameters) {
            return '';
        }

        return \Illuminate\Support\Str::limit(
            json_encode($parameters, JSON_UNESCAPED_UNICODE),
            100
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => 'sukses',
            'failed' => 'gagal',
            'cancelled' => 'ditolak',
            default => $status,
        };
    }
}
