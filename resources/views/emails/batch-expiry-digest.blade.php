<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peringatan batch</title>
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #1e293b; max-width: 640px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 1.25rem; margin: 0 0 8px;">Peringatan batch &amp; kedaluwarsa</h1>
    <p style="margin: 0 0 16px; color: #64748b;">
        <strong>{{ $store->name }}</strong> — batch berikut memiliki tanggal kedaluwarsa dalam
        <strong>{{ $warnDays }} hari</strong> atau sudah lewat (sisa stok &gt; 0), sama seperti di dashboard Anda.
    </p>
    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
        <thead>
            <tr style="background: #f1f5f9; text-align: left;">
                <th style="padding: 8px; border: 1px solid #e2e8f0;">Produk</th>
                <th style="padding: 8px; border: 1px solid #e2e8f0;">Kode batch</th>
                <th style="padding: 8px; border: 1px solid #e2e8f0;">Sisa</th>
                <th style="padding: 8px; border: 1px solid #e2e8f0;">ED</th>
                <th style="padding: 8px; border: 1px solid #e2e8f0;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($batches as $b)
                <tr>
                    <td style="padding: 8px; border: 1px solid #e2e8f0;">{{ $b['product_name'] }}</td>
                    <td style="padding: 8px; border: 1px solid #e2e8f0;">{{ $b['batch_code'] ?: '—' }}</td>
                    <td style="padding: 8px; border: 1px solid #e2e8f0;">{{ $b['quantity_remaining'] }}</td>
                    <td style="padding: 8px; border: 1px solid #e2e8f0;">{{ $b['expiry_date'] }}</td>
                    <td style="padding: 8px; border: 1px solid #e2e8f0;">
                        {{ $b['expiry_status'] === 'expired' ? 'Kadaluarsa' : 'Perlu perhatian' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p style="margin-top: 24px; font-size: 0.75rem; color: #94a3b8;">
        Email ini dikirim otomatis karena Anda mengaktifkan pemberitahuan batch di pengaturan toko.
        Sesuaikan jendela peringatan (hari) di menu Pengaturan &gt; Profil &gt; Toko.
    </p>
</body>
</html>
