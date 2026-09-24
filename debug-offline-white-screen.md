# Debug Session: Offline White Screen

Status: [OPEN]
Session ID: offline-white-screen

## Symptom
Saat aplikasi idle atau tidak memiliki jaringan, layar menjadi putih. Mode offline diharapkan tetap menampilkan aplikasi.

## Hypotheses
1. Komponen utama hanya dirender saat koneksi online.
2. Service worker atau cache offline tidak terdaftar atau gagal mengambil asset.
3. Error JavaScript terjadi saat event perubahan jaringan memicu render ulang.
4. Backend/Inertia mengembalikan respons kosong atau error ketika jaringan tidak tersedia.
5. State offline tersedia, tetapi route awal tetap diarahkan ke halaman yang membutuhkan jaringan.

## Evidence

### Pre-fix
- Browser log menunjukkan error Vue saat render pada halaman Create: `TypeError: Cannot read properties of undefined (reading 'target')`.
- Reproduksi pengguna di mobile: setelah aplikasi idle, saat dibuka kembali tampilan putih.
- Instrumentasi bootstrap berhasil mencatat bootstrap dan navigasi normal pada `/pos` saat online.

### Post-fix
- Belum dikonfirmasi pada perangkat mobile.
- `npm run build` berhasil.
- Diagnostic TypeScript pada `resources/js/app.ts` tidak menemukan error.

## Conclusion
Akar masalah yang ditangani: error fatal saat kondisi offline tidak boleh memaksa `window.location.reload()`, karena reload tidak dapat mengambil dokumen Inertia. Error boundary kini menampilkan panel pemulihan dan menunggu koneksi pulih sebelum reload.

