# Debug Session: websocket-connection
- **Status**: [OPEN]
- **Issue**: Browser mencoba membuat koneksi WebSocket ke `wss://umkm-kasir.test/app/...` dan gagal.

## Evidence
| ID | Hypothesis | Evidence |
| --- | --- | --- |
| A | Variabel Vite masih mengaktifkan Pusher/Reverb. | Dikonfirmasi: `.env` memiliki `VITE_REVERB_APP_KEY`, host `umkm-kasir.test`, port `443`, dan skema `https`. |
| B | Frontend membuat Echo tanpa kebutuhan fitur realtime. | Dikonfirmasi: `resources/js/app.ts` selalu membuat `new Echo(...)` ketika key tersedia; hanya halaman Kitchen yang membaca `window.Echo`. |
| C | Konfigurasi broadcasting backend tidak sesuai. | Tidak menjadi pemicu error browser; browser gagal sebelum event broadcast backend diperlukan. |
| D | Server WebSocket lokal tidak berjalan. | Dikonfirmasi dari error koneksi `wss://umkm-kasir.test/app/...` yang berulang dan tidak adanya proses Reverb yang dijalankan. |

## Fix
Echo kini hanya diinisialisasi jika `VITE_REVERB_ENABLED=true`. Variabel ini tidak disetel sehingga koneksi WebSocket tidak dibuat. Build frontend berhasil.

## Verification
Muat ulang halaman secara paksa lalu pastikan tidak ada error WebSocket baru di console.
