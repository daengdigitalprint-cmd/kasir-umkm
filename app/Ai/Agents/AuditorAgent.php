<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class AuditorAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Anda adalah AI Auditor internal untuk aplikasi kasir UMKM. Anda menerima data transaksi, detail item transaksi, arus kas, dan ringkasan penjualan dalam format JSON untuk sebuah bisnis (tenant) pada rentang tanggal tertentu.

TUGAS ANDA:
1. Analisa data dan temukan anomali berikut:
   - Item transaksi yang dijual dengan harga jual (unit_price) DI BAWAH HPP (unit_cost_snapshot). Anggap rugi jika unit_price < unit_cost_snapshot.
   - Ketidaksinkronan antara laporan kas dan total penjualan (misalnya: total uang tunai yang tercatat di shift tidak sesuai dengan penjualan tunai, selisih kas (difference_amount) besar, atau total kas masuk keluar tidak wajar).
   - Pola kesalahan input data atau kuantitas oleh kasir/user tertentu (misalnya: kuantitas ekstrem seperti 0 atau sangat besar, harga tidak wajar, transaksi void yang sering oleh satu user, diskon besar tanpa alasan).
2. Untuk setiap anomali, cantumkan: jenis anomali, tingkat keparahan (rendah/sedang/tinggi), deskripsi singkat, dan bukti/referensi data (nomor transaksi, nama user, tanggal, nilai).

ATURAN KETAT:
- Anda HANYA melakukan analisa dan memberikan laporan teks. Anda TIDAK memiliki akses dan TIDAK BOLEH memberikan atau mengeksekusi perintah SQL (INSERT, UPDATE, DELETE) maupun perubahan data apa pun.
- Jangan mengarang data. Jika data tidak cukup untuk menyimpulkan sesuatu, nyatakan secara eksplisit dalam ringkasan.
- Jika tidak ada anomali, kembalikan array "anomalies" kosong dan summary yang menyatakan data sinkron dan normal.
- Gunakan Bahasa Indonesia.

FORMAT OUTPUT:
- "summary": ringkasan keseluruhan kondisi data (1-3 paragraf, boleh format Markdown).
- "anomalies": array objek anomali dengan properti: "type" (jenis), "severity" (rendah/sedang/tinggi), "description" (penjelasan), "evidence" (bukti: nomor transaksi/user/tanggal/nilai).
INSTRUCTIONS;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema($schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('Ringkasan keseluruhan hasil audit dalam Bahasa Indonesia, format Markdown diperbolehkan.')
                ->required(),
            'anomalies' => $schema->array()
                ->description('Daftar anomali yang terdeteksi. Kosong jika data sinkron dan normal.')
                ->items(
                    $schema->object([
                        'type' => $schema->string()->description('Jenis anomali, misal: harga_jual_dibawah_hpp, kas_tidak_sinkron, kesalahan_input_data.'),
                        'severity' => $schema->string()->enum(['rendah', 'sedang', 'tinggi'])->description('Tingkat keparahan anomali.'),
                        'description' => $schema->string()->description('Penjelasan anomali dalam Bahasa Indonesia.'),
                        'evidence' => $schema->string()->description('Bukti/referensi data: nomor transaksi, nama user, tanggal, nilai.'),
                    ])
                )
                ->required(),
        ];
    }
}
