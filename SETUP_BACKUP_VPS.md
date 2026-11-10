# Panduan Backup Otomatis ke Google Drive (VPS)

Salin perintah dari file ini ke terminal VPS. Tempel per blok, lalu jalankan.

---

## 1. Install rclone tanpa sudo

```bash
cd ~
url='https://github.com/rclone/rclone/releases/download/v1.75.1/rclone-v1.75.1-linux-amd64.zip'
clean=$(echo "$url" | tr -cd 'a-zA-Z0-9.:/_-')
echo "$clean"
curl -L -O "$clean"
```

Cek hasilnya — harus sekitar 30 MB dan `head` menampilkan `PK`:

```bash
ls -lh rclone-v1.75.1-linux-amd64.zip
head -c 4 rclone-v1.75.1-linux-amd64.zip
```

Ekstrak dan pasang:

```bash
python3 -m zipfile -e rclone-v1.75.1-linux-amd64.zip .
mkdir -p $HOME/.local/bin
cp $HOME/rclone-v1.75.1-linux-amd64/rclone $HOME/.local/bin/
chmod +x $HOME/.local/bin/rclone
rm -rf rclone-v1.75.1-linux-amd64.zip rclone-v1.75.1-linux-amd64
$HOME/.local/bin/rclone version
```

Tambahkan ke PATH (sekali saja):

```bash
echo 'export PATH="$HOME/.local/bin:$PATH"' >> $HOME/.bashrc
source $HOME/.bashrc
rclone version
```

> Catatan: URL lama `downloads.rclone.org/current/...` sudah 404 Not Found — gunakan URL GitHub di atas.
> Jika ukuran file kecil/HTML, jalankan ulang blok pertama. Jika `python3` tidak ada: minta penyedia VPS install `python3-minimal`.
>
> **Fallback (GitHub lambat dari VPS):** file ZIP valid sudah diunduh di PC Windows
> `C:\Users\melis\rclone-v1.75.1-linux-amd64.zip` — kirim ke VPS dari PowerShell Windows:
>
> ```powershell
> scp "C:\Users\melis\rclone-v1.75.1-linux-amd64.zip" kasirumkm@43.157.224.164:~/
> ```
>
> Lalu di VPS lanjut dari blok `python3 -m zipfile` di atas.

---

## 2. Buat Client ID Google (sekali saja)

rclone versi terbaru tidak lagi menyertakan kredensial bawaan, jadi buat milik sendiri:

1. Buka https://console.cloud.google.com/ → **New Project** → Create.
2. **APIs & Services → Library** → cari **Google Drive API** → **Enable**.
3. **APIs & Services → Credentials → Create Credentials → OAuth client ID**
   - Kalau diminta consent screen: **External** → isi app name & email → Save → lewati.
   - **Application type**: **Desktop app** → nama bebas → **Create**.
4. Simpan **Client ID** (`xxx.apps.googleusercontent.com`) dan **Client secret** (`GOCSPX-...`).

## 3. Hubungkan ke Google Drive

```bash
rclone config
```

| Prompt | Jawaban |
|---|---|
| `e/n/d/r/c/s/q` | `n` |
| `name>` | `gdrive` |
| `Storage` | ketik `drive` lalu Enter (atau nomor pilihannya) |
| `client_id>` | **paste Client ID dari Google Cloud Console** |
| `client_secret>` | **paste Client secret** |
| `scope` | `1` |
| `root_folder_id` / `service_account_file` | Enter kosong |
| `Edit advanced config?` | `n` |
| `Use auto config?` | **`n`** (VPS tanpa browser) |
| `Configure as team drive?` | `n` |
| `y/e/d/I/q` | `y` |

Rclone akan menampilkan URL — **salin URL itu ke browser di HP/laptop**,
login Google → Izinkan → muncul **kode verifikasi** → paste ke terminal VPS → Enter.

Verifikasi:

```bash
rclone listremotes
```

Harus muncul: `gdrive:`

---

## 4. Isi `.env` di VPS

Temukan dulu path binary rclone (hasilnya bisa berbeda tiap VPS):

```bash
which rclone
```

```bash
cd ~/www/kasirumkm.web.id
nano .env
```

Tambahkan baris ini (sesuaikan `RCLONE_BINARY` dengan hasil `which rclone`):

```env
RCLONE_BINARY=/home/kasirumkm/.local/bin/rclone
RCLONE_REMOTE=gdrive:Backup/kasirumkm
RCLONE_RETENTION_DAYS=30
```

Simpan: `Ctrl+O` → Enter → `Ctrl+X`.

---

## 5. Cek mysqldump

```bash
which mysqldump
```

Jika kosong, minta akses sudo ke penyedia VPS, atau install client mysql.
Alternatif — set path manual di `config/database.php`:

```php
'mysql' => [
    'db_dump_path' => '/usr/bin/mysqldump',
],
```

---

## 6. Uji backup manual

```bash
php artisan backup:drive
```

Harus muncul:

```
Backup lokal selesai.
Terunggah: 2026-10-07-02-42-29.zip
```

Lalu cek folder Google Drive — file ZIP sekitar 1 MB harus sudah ada.

---

## 7. Daftarkan cron scheduler

```bash
crontab -e
```

Tambahkan satu baris (sesuaikan path, cek dengan `pwd`):

```cron
* * * * * cd /home/kasirumkm/www/kasirumkm.web.id && php artisan schedule:run >> /dev/null 2>&1
```

Verifikasi jadwal terdaftar:

```bash
php artisan schedule:list
```

Harus tampil:

```
02:30  daily  backup:drive --cleanup
```

---

## Troubleshooting

| Masalah | Solusi |
|---|---|
| `rclone tidak ditemukan` | Pastikan `RCLONE_BINARY` di `.env` memakai path absolut |
| `Permission denied (publickey)` | Pakai `ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no user@ip` |
| `is not in the sudoers file` | User tidak punya sudo — install manual ke `$HOME/.local/bin` |
| Unduhan 27 KB (HTML Not Found) | URL sudah salah/berubah — pakai URL GitHub di langkah 1 |
| `remote not found` | Nama remote di `.env` harus sama persis dengan saat `rclone config` |
| Backup lama menumpuk | Sudah ditangani otomatis: lokal 7 hari, Google Drive 30 hari |

---

## Perintah berguna

```bash
php artisan backup:drive            # backup + upload
php artisan backup:drive --cleanup  # + hapus salinan Drive lama
php artisan backup:list             # riwayat backup lokal
php artisan backup:run              # backup lokal saja
php artisan backup:clean            # bersihkan backup lokal lama
php artisan schedule:list           # lihat jadwal
rclone ls gdrive:Backup/kasirumkm   # lihat isi folder Drive
```
