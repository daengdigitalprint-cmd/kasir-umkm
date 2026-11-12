<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan - {{ $store->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 20px;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: bold;
            color: #2563eb;
            margin: 0 0 10px 0;
        }
        
        .header h2 {
            font-size: 18px;
            color: #666;
            margin: 0 0 5px 0;
        }
        
        .header p {
            font-size: 14px;
            color: #888;
            margin: 0;
        }
        
        .summary {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        
        .summary-row {
            display: table-row;
        }
        
        .summary-cell {
            display: table-cell;
            padding: 10px;
            border: 1px solid #ddd;
            background-color: #f8f9fa;
            font-weight: bold;
        }
        
        .summary-value {
            display: table-cell;
            padding: 10px;
            border: 1px solid #ddd;
            text-align: right;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section h3 {
            font-size: 16px;
            font-weight: bold;
            color: #2563eb;
            margin: 0 0 15px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .table th,
        .table td {
            padding: 8px 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        
        .table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .currency {
            font-weight: bold;
            color: #059669;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $store->name }}</h1>
        <h2>Laporan Penjualan</h2>
        <p>Periode: {{ \Carbon\Carbon::parse($dateRange['start_date'])->format('d F Y') }} - {{ \Carbon\Carbon::parse($dateRange['end_date'])->format('d F Y') }}</p>
        <p>Dibuat pada: {{ \Carbon\Carbon::now()->format('d F Y H:i') }}</p>
    </div>

    <!-- Summary -->
    <div class="section">
        <h3>Ringkasan Penjualan</h3>
        <div class="summary">
            <div class="summary-row">
                <div class="summary-cell">Total Penjualan</div>
                <div class="summary-value currency">Rp {{ number_format($summary['total_sales'], 0, ',', '.') }}</div>
            </div>
            <div class="summary-row">
                <div class="summary-cell">Total Transaksi</div>
                <div class="summary-value">{{ number_format($summary['total_transactions']) }} transaksi</div>
            </div>
            <div class="summary-row">
                <div class="summary-cell">Rata-rata Nilai Transaksi</div>
                <div class="summary-value currency">Rp {{ number_format($summary['average_order_value'], 0, ',', '.') }}</div>
            </div>
        </div>
    </div>

    <!-- Top Products -->
    @if($topProducts->count() > 0)
    <div class="section">
        <h3>Produk Terlaris</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nama Produk</th>
                    <th class="text-center">Terjual</th>
                    <th class="text-right">Harga</th>
                    <th class="text-right">Total Penjualan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topProducts as $index => $product)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $product->name }}</td>
                    <td class="text-center">{{ number_format((float) ($product->total_sold ?? 0), 4, ',', '.') }}</td>
                    <td class="text-right">Rp {{ number_format($product->price, 0, ',', '.') }}</td>
                    <td class="text-right currency">Rp {{ number_format($product->price * (float) ($product->total_sold ?? 0), 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Recent Transactions -->
    @if($recentTransactions->count() > 0)
    <div class="section">
        <h3>Transaksi Terbaru</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Pelanggan</th>
                    <th class="text-right">Total</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentTransactions as $index => $transaction)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ \Carbon\Carbon::parse($transaction->created_at)->format('d/m/Y H:i') }}</td>
                    <td>{{ $transaction->customer ? $transaction->customer->name : 'Pelanggan Umum' }}</td>
                    <td class="text-right currency">Rp {{ number_format($transaction->total_amount, 0, ',', '.') }}</td>
                    <td class="text-center">
                        @if($transaction->status === 'completed')
                            <span style="color: #059669; font-weight: bold;">Selesai</span>
                        @else
                            <span style="color: #dc2626; font-weight: bold;">{{ ucfirst($transaction->status) }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="footer">
        <p>Laporan ini dibuat secara otomatis oleh sistem Kasir UMKM by MaccaPOS</p>
        <p>© {{ date('Y') }} {{ $store->name }}. Semua hak dilindungi.</p>
    </div>
</body>
</html>
