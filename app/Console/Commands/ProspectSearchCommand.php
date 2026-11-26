<?php

namespace App\Console\Commands;

use App\Models\Prospect;
use App\Services\Prospecting\ProspectCollector;
use App\Support\ProspectScorer;
use Illuminate\Console\Command;
use RuntimeException;

class ProspectSearchCommand extends Command
{
    protected $signature = 'prospek:cari
        {jenis : Jenis usaha, mis. "kedai kopi", "laundry", "barbershop"}
        {kota : Nama kota/kabupaten, mis. "Makassar"}';

    protected $description = 'Kumpulkan prospek (usaha + kontak publik) dari OpenStreetMap untuk satu jenis usaha di satu kota';

    public function handle(ProspectCollector $collector): int
    {
        $this->info("Mencari \"{$this->argument('jenis')}\" di {$this->argument('kota')} …");

        try {
            $result = $collector->collect((string) $this->argument('jenis'), (string) $this->argument('kota'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%s · %s: %d usaha ditemukan, %d baru, %d diperbarui, %d duplikat dilewati, %d jaringan besar dilewati, %d punya nomor telepon.',
            $result['category'],
            $result['city'],
            $result['found'],
            $result['added'],
            $result['updated'],
            $result['duplicates'],
            $result['skipped_chains'],
            $result['with_phone'],
        ));

        $top = Prospect::where('city', $result['city'])
            ->where('category', $result['category'])
            ->orderByDesc('score')
            ->orderBy('name')
            ->limit(15)
            ->get();

        $this->table(
            ['Skor', 'Prioritas', 'Usaha', 'Telepon', 'Instagram', 'Alamat'],
            $top->map(fn (Prospect $p) => [
                $p->score,
                ProspectScorer::tierLabel($p->score),
                mb_strimwidth($p->name, 0, 34, '…'),
                $p->phoneLocal() ?? '—',
                $p->instagram ? '@'.$p->instagram : '—',
                mb_strimwidth((string) $p->address, 0, 36, '…') ?: '—',
            ])->all(),
        );

        $this->line('Daftar lengkap, status tindak lanjut, dan ekspor CSV: /superadmin/prospects');

        return self::SUCCESS;
    }
}
