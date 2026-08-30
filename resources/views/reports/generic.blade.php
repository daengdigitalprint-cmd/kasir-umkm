@php
    /**
     * Template PDF generik untuk semua laporan.
     *
     * Variabel:
     *  - $title        : judul laporan (string)
     *  - $storeName    : nama toko (string)
     *  - $meta         : array baris meta di kop (mis. Periode, Per tanggal) [['label'=>..,'value'=>..], ...]
     *  - $sections     : array section. Tiap section:
     *      ['type' => 'keyvalue', 'title' => '...', 'rows' => [['label'=>..,'value'=>..,'strong'=>bool], ...]]
     *      ['type' => 'table', 'title' => '...',
     *          'headings' => ['No','Nama', ...],
     *          'aligns'   => ['left','right', ...],   // opsional, per kolom
     *          'rows'     => [[..,..], ...],
     *          'footer'   => [..,..]                  // opsional baris total
     *      ]
     *      ['type' => 'note', 'title' => '...', 'text' => '...']
     *  - $generatedAt  : string waktu cetak
     */
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} - {{ $storeName }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #1f2937;
            margin: 0;
            padding: 22px;
        }
        .header {
            border-bottom: 2px solid #7c3aed;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .header .store { font-size: 18px; font-weight: bold; color: #111827; }
        .header .doc-title { font-size: 15px; color: #7c3aed; font-weight: bold; margin-top: 2px; }
        .header .meta { margin-top: 6px; font-size: 11px; color: #4b5563; }
        .header .meta span { display: inline-block; margin-right: 16px; }

        .section { margin-bottom: 20px; }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #111827;
            margin: 0 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        table { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 6px 10px; border: 1px solid #e5e7eb; }
        table.kv td.label { background: #f8fafc; font-weight: bold; width: 45%; }
        table.kv td.value { text-align: right; }
        table.kv tr.strong td { background: #eef2ff; font-weight: bold; }

        table.data th, table.data td {
            padding: 6px 8px;
            border: 1px solid #e5e7eb;
            font-size: 10.5px;
        }
        table.data th { background: #f1f5f9; font-weight: bold; text-align: left; }
        table.data tr:nth-child(even) td { background: #fafafa; }
        table.data tfoot td { background: #eef2ff; font-weight: bold; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .note-box { background: #f8fafc; border: 1px solid #e5e7eb; padding: 10px; font-size: 10.5px; color: #374151; }

        .footer {
            margin-top: 28px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="store">{{ $storeName }}</div>
        <div class="doc-title">{{ $title }}</div>
        <div class="meta">
            @foreach($meta as $m)
                <span><strong>{{ $m['label'] }}:</strong> {{ $m['value'] }}</span>
            @endforeach
        </div>
    </div>

    @foreach($sections as $section)
        <div class="section">
            @if(!empty($section['title']))
                <div class="section-title">{{ $section['title'] }}</div>
            @endif

            @if(($section['type'] ?? 'table') === 'keyvalue')
                <table class="kv">
                    @foreach($section['rows'] as $row)
                        <tr class="{{ !empty($row['strong']) ? 'strong' : '' }}">
                            <td class="label">{{ $row['label'] }}</td>
                            <td class="value">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @elseif(($section['type'] ?? 'table') === 'note')
                <div class="note-box">{!! nl2br(e($section['text'] ?? '')) !!}</div>
            @else
                @php $aligns = $section['aligns'] ?? []; @endphp
                <table class="data">
                    <thead>
                        <tr>
                            @foreach($section['headings'] as $i => $h)
                                <th class="{{ ($aligns[$i] ?? 'left') === 'right' ? 'text-right' : (($aligns[$i] ?? 'left') === 'center' ? 'text-center' : '') }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($section['rows'] as $row)
                            <tr>
                                @foreach($row as $i => $cell)
                                    <td class="{{ ($aligns[$i] ?? 'left') === 'right' ? 'text-right' : (($aligns[$i] ?? 'left') === 'center' ? 'text-center' : '') }}">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td class="text-center" colspan="{{ count($section['headings']) }}" style="padding:14px;color:#9ca3af;">Tidak ada data.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(!empty($section['footer']))
                        <tfoot>
                            <tr>
                                @foreach($section['footer'] as $i => $cell)
                                    <td class="{{ ($aligns[$i] ?? 'left') === 'right' ? 'text-right' : (($aligns[$i] ?? 'left') === 'center' ? 'text-center' : '') }}">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            @endif
        </div>
    @endforeach

    <div class="footer">
        <p>Dicetak otomatis oleh sistem UMKM Kasir pada {{ $generatedAt }}</p>
        <p>&copy; {{ date('Y') }} {{ $storeName }}</p>
    </div>
</body>
</html>
