<?php

namespace App\Console\Commands;

use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\TransactionController;
use App\Models\CashTransaction;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteUserTransactions extends Command
{
    protected $signature = 'app:delete-user-transactions
        {email : Email user pemilik transaksi}
        {--from= : Tanggal awal, format YYYY-MM-DD}
        {--to= : Tanggal akhir, format YYYY-MM-DD}
        {--force : Jalankan penghapusan permanen}
        {--dry-run : Hanya tampilkan jumlah data tanpa menghapus}';

    protected $description = 'Menghapus transaksi user pada rentang tanggal tertentu';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $from = (string) ($this->option('from') ?: now()->startOfMonth()->toDateString());
        $to = (string) ($this->option('to') ?: now()->endOfMonth()->toDateString());

        if ($from > $to) {
            $this->error('Tanggal awal tidak boleh melewati tanggal akhir.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->error("User {$email} tidak ditemukan.");

            return self::FAILURE;
        }

        $counts = $this->counts($user, $from, $to);
        $this->table(['Jenis', 'Jumlah'], collect($counts)
            ->map(fn (int $count, string $type): array => [$type, $count])
            ->values()
            ->all());

        if (! $this->option('force') || $this->option('dry-run')) {
            $this->warn('Tidak ada data yang dihapus. Gunakan --force tanpa --dry-run untuk menghapus permanen.');

            return self::SUCCESS;
        }

        $this->delete($user, $from, $to);
        $this->info('Penghapusan selesai.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function counts(User $user, string $from, string $to): array
    {
        $until = date('Y-m-d', strtotime($to.' +1 day'));

        return [
            'Penjualan' => Transaction::query()->where('user_id', $user->id)->where('created_at', '>=', $from)->where('created_at', '<', $until)->count(),
            'Pembelian' => Purchase::query()->where('user_id', $user->id)->where('purchased_at', '>=', $from)->where('purchased_at', '<', $until)->count(),
            'Arus kas' => CashTransaction::query()->where('user_id', $user->id)->where('created_at', '>=', $from)->where('created_at', '<', $until)->count(),
            'Pembayaran pembelian' => PurchasePayment::query()->where('user_id', $user->id)->whereBetween('payment_date', [$from, $to])->count(),
            'Pembayaran piutang' => ReceivablePayment::query()->where('user_id', $user->id)->where('received_at', '>=', $from)->where('received_at', '<', $until)->count(),
            'Retur penjualan' => SalesReturn::query()->where('user_id', $user->id)->where('returned_at', '>=', $from)->where('returned_at', '<', $until)->count(),
        ];
    }

    private function delete(User $user, string $from, string $to): void
    {
        $store = $user->store;
        abort_unless($store, 422, 'User tidak memiliki toko.');

        $actor = User::query()
            ->where('store_id', $store->id)
            ->where(function ($query): void {
                $query->whereIn('role', ['owner', 'admin'])->orWhere('is_super_admin', true);
            })
            ->first();
        abort_unless($actor, 422, 'Tidak ada owner/admin untuk menjalankan rollback.');

        Auth::login($actor);
        $request = Request::create('/', 'DELETE');
        $transactionController = app(TransactionController::class);
        $purchaseController = app(PurchaseController::class);
        $until = date('Y-m-d', strtotime($to.' +1 day'));

        $originalSettings = $store->settings;
        $store->settings = array_merge($originalSettings ?? [], ['max_backdate_days' => 36500]);
        $store->save();

        try {
            DB::transaction(function () use ($user, $from, $until, $transactionController, $purchaseController, $request): void {
                Transaction::query()
                    ->where('user_id', $user->id)
                    ->where('created_at', '>=', $from)
                    ->where('created_at', '<', $until)
                    ->orderBy('id')
                    ->get()
                    ->each(function (Transaction $transaction) use ($transactionController, $request): void {
                        $response = $transactionController->destroy($request, $transaction);
                        if ($response->getStatusCode() >= 400) {
                            throw new \RuntimeException($response->getContent());
                        }
                    });

                Purchase::query()
                    ->where('user_id', $user->id)
                    ->where('purchased_at', '>=', $from)
                    ->where('purchased_at', '<', $until)
                    ->orderBy('id')
                    ->get()
                    ->each(function (Purchase $purchase) use ($purchaseController): void {
                        $response = $purchaseController->destroy($purchase);
                        if ($response->getSession()->get('error')) {
                            throw new \RuntimeException($response->getSession()->get('error'));
                        }
                    });

                CashTransaction::query()->where('user_id', $user->id)->where('created_at', '>=', $from)->where('created_at', '<', $until)->delete();
                PurchasePayment::query()->where('user_id', $user->id)->whereBetween('payment_date', [$from, date('Y-m-d', strtotime($until.' -1 day'))])->delete();
                ReceivablePayment::query()->where('user_id', $user->id)->where('received_at', '>=', $from)->where('received_at', '<', $until)->delete();
                SalesReturn::query()->where('user_id', $user->id)->where('returned_at', '>=', $from)->where('returned_at', '<', $until)->delete();
            });
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $store->settings = $originalSettings;
            $store->save();
        }
    }
}
