/**
 * Naskah narasi, satu berkas audio per adegan.
 *
 * `seconds` adalah jatah tiap adegan, harus cocok dengan SCENES di
 * src/PromoKasir/theme.ts dibagi 30fps. Skrip voiceover memperingatkan
 * bila hasil rekaman melebihi jatahnya.
 */
export const NARRATION = [
  {
    name: "hook",
    seconds: 6,
    text: "Masih catat penjualan di buku tulis? Saatnya pindah ke kasir digital.",
  },
  {
    name: "pos",
    seconds: 12,
    text: "Dengan MaccaPOS, satu transaksi selesai dalam hitungan detik. Cari produk atau scan barcode, pilih pembayaran tunai, QRIS, atau e-wallet, dan struk langsung tercetak.",
  },
  {
    name: "stock",
    seconds: 10,
    text: "Produk jual dan bahan baku tersimpan di satu tempat. Stok berkurang otomatis setiap penjualan, dan Anda diperingatkan sebelum barang habis.",
  },
  {
    name: "dashboard",
    seconds: 10.5,
    text: "Omzet toko bisa Anda pantau dari mana saja. Penjualan hari ini, tren tiap bulan, sampai produk terlaris, semuanya terbaca dalam sekali lihat.",
  },
  {
    name: "reports",
    seconds: 11,
    text: "Laba rugi dihitung otomatis. Rekap per kasir, per shift, siap diekspor ke Excel dan PDF. Tidak ada lagi tebak-tebakan di akhir bulan.",
  },
  {
    name: "outro",
    seconds: 10.5,
    text: "MaccaPOS. Kasir Macca, usaha maju. Coba gratis hari ini di kasir U M K M titik web titik I D.",
  },
];
