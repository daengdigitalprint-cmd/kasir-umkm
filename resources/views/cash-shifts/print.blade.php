@php
    $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $dt = fn ($d) => $d ? $d->copy()->timezone($timezone)->translatedFormat('d M Y, H.i') : '—';
    $diff = $shift->difference_amount !== null ? (float) $shift->difference_amount : null;
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Laporan shift {{ $shift->shift_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; font-family: ui-monospace, Menlo, Consolas, monospace; color: #0f172a; }
        .paper { width: 80mm; max-width: 100%; margin: 16px auto; background: #fff; padding: 14px 12px; font-size: 12px; line-height: 1.45; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 2px; }
        .center { text-align: center; }
        .muted { color: #475569; }
        hr { border: 0; border-top: 1px dashed #94a3b8; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .row span:last-child { text-align: right; white-space: nowrap; }
        .bold { font-weight: 700; }
        .actions { width: 80mm; max-width: 100%; margin: 0 auto 24px; display: flex; gap: 8px; font-family: system-ui, sans-serif; }
        .actions button, .actions a { flex: 1; padding: 10px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 14px; text-align: center; color: #0f172a; text-decoration: none; cursor: pointer; }
        .actions button { background: #6d28d9; color: #fff; border-color: #6d28d9; font-weight: 600; }
        .small { font-size: 10px; }
        .sigs { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; text-align: center; }
        .sig { display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 0; }
        .sig-img { width: 100%; max-width: 130px; height: 56px; object-fit: contain; }
        .sig-blank { height: 56px; }
        .sig-open { margin-top: 6px; padding: 6px 8px; border-radius: 8px; border: 1px solid #6d28d9; background: #f5f3ff; color: #5b21b6; font: 600 11px system-ui, sans-serif; cursor: pointer; }
        .pad-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .6); display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 50; font-family: system-ui, sans-serif; }
        .pad-backdrop[hidden] { display: none; }
        .pad-box { background: #fff; border-radius: 16px; padding: 16px; width: 100%; max-width: 480px; user-select: none; -webkit-user-select: none; }
        .pad-title { font-weight: 700; font-size: 16px; }
        .pad-hint { color: #64748b; font-size: 12px; margin: 4px 0 8px; }
        .pad-canvas { width: 100%; height: 200px; border: 2px dashed #cbd5e1; border-radius: 12px; touch-action: none; background: #fff; display: block; }
        .pad-error { color: #dc2626; font-size: 13px; margin: 6px 0 0; }
        .pad-actions { display: flex; gap: 8px; margin-top: 12px; }
        .pad-actions button { flex: 1; padding: 10px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 14px; cursor: pointer; }
        .pad-actions button.primary { background: #6d28d9; border-color: #6d28d9; color: #fff; font-weight: 600; }
        .pad-actions button:disabled { opacity: .6; }
        @media print {
            body { background: #fff; }
            .paper { margin: 0; width: auto; padding: 0; }
            .actions, .sig-open, .pad-backdrop { display: none !important; }
            @page { margin: 4mm; }
        }
    </style>
</head>
<body>
<div class="paper">
    <h1>{{ $store->name }}</h1>
    <div class="center muted">Laporan Tutup Shift</div>
    <hr>
    <div class="row"><span>No. shift</span><span>{{ $shift->shift_number }}</span></div>
    <div class="row"><span>Kasir</span><span>{{ $cashierName }}</span></div>
    <div class="row"><span>Buka</span><span>{{ $dt($shift->opened_at) }}</span></div>
    <div class="row"><span>Tutup</span><span>{{ $shift->closed_at ? $dt($shift->closed_at) : 'Masih berjalan' }}</span></div>
    <hr>
    <div class="row"><span>Jumlah transaksi</span><span>{{ $totals['transactions_count'] }}</span></div>
    @if ($canSeeCash)
        <div class="row bold"><span>Total penjualan</span><span>{{ $fmt($totals['sales_total']) }}</span></div>
    @endif
    @foreach ($payments as $p)
        <div class="row"><span>&nbsp;&nbsp;{{ $p['label'] }}</span><span>{{ $fmt($p['amount']) }}</span></div>
    @endforeach
    <hr>
    <div class="row"><span>Kas awal</span><span>{{ $fmt($shift->opening_cash) }}</span></div>
    @if ($canSeeCash)
        <div class="row"><span>Penjualan tunai</span><span>{{ $fmt($totals['sales_cash']) }}</span></div>
        <div class="row"><span>Kas masuk</span><span>{{ $fmt($totals['cash_in']) }}</span></div>
        <div class="row"><span>Kas keluar</span><span>- {{ $fmt($totals['cash_out']) }}</span></div>
        <div class="row bold"><span>Seharusnya di laci</span><span>{{ $fmt($shift->expected_cash ?? $totals['expected']) }}</span></div>
    @endif
    @if ($shift->closing_cash_counted !== null)
        <div class="row bold"><span>Uang dihitung</span><span>{{ $fmt($shift->closing_cash_counted) }}</span></div>
    @endif
    @if ($canSeeCash && $diff !== null)
        <div class="row bold">
            <span>Selisih</span>
            <span>{{ $diff == 0 ? 'Pas' : ($diff < 0 ? 'Kurang ' . $fmt(abs($diff)) : 'Lebih ' . $fmt($diff)) }}</span>
        </div>
    @endif
    @if ($shift->closing_notes)
        <hr>
        <div class="muted">Catatan:</div>
        <div>{{ $shift->closing_notes }}</div>
    @endif
    <hr>
    <div class="sigs">
        @foreach ([
            ['role' => 'cashier', 'title' => 'Kasir', 'img' => $shift->cashier_signature, 'name' => $shift->cashier_signed_name ?: $cashierName, 'at' => $shift->cashier_signed_at, 'can' => $canSignCashier],
            ['role' => 'manager', 'title' => 'Pengelola', 'img' => $shift->manager_signature, 'name' => $shift->manager_signed_name, 'at' => $shift->manager_signed_at, 'can' => $canSignManager],
        ] as $sig)
            <div class="sig">
                <div class="muted">{{ $sig['title'] }}</div>
                @if ($sig['img'])
                    <img class="sig-img" src="{{ $sig['img'] }}" alt="Tanda tangan {{ $sig['title'] }}">
                    <div class="bold">{{ $sig['name'] }}</div>
                    <div class="muted small">{{ $dt($sig['at']) }}</div>
                @else
                    <div class="sig-blank"></div>
                    <div>({{ $sig['role'] === 'cashier' ? $sig['name'] : '____________' }})</div>
                    @if ($sig['can'])
                        <button type="button" class="sig-open" data-role="{{ $sig['role'] }}"
                            data-title="Tanda tangan {{ $sig['title'] }}{{ $sig['role'] === 'manager' ? ' (' . $managerName . ')' : '' }}">
                            Tanda tangan di layar
                        </button>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
    @if ($signatureEnabled && ! $shift->closed_at)
        <div class="center muted small" style="margin-top:6px">Tanda tangan digital tersedia setelah shift ditutup.</div>
    @endif
    <div class="center muted" style="margin-top:12px">Dicetak {{ now()->timezone($timezone)->translatedFormat('d M Y, H.i') }}</div>
</div>

@if ($canSignCashier || $canSignManager)
    <div class="pad-backdrop" id="padModal" hidden>
        <div class="pad-box" role="dialog" aria-modal="true" aria-labelledby="padTitle">
            <div class="pad-title" id="padTitle">Tanda tangan</div>
            <p class="pad-hint">Gunakan jari atau mouse di kotak di bawah.</p>
            <canvas id="padCanvas" class="pad-canvas"></canvas>
            <p class="pad-error" id="padError" hidden></p>
            <div class="pad-actions">
                <button type="button" id="padCancel">Batal</button>
                <button type="button" id="padClear">Ulangi</button>
                <button type="button" id="padSave" class="primary">Simpan</button>
            </div>
            <p class="pad-hint">Tanda tangan yang sudah disimpan tidak bisa diubah.</p>
        </div>
    </div>
    <script>
    (function () {
        const modal = document.getElementById('padModal');
        const canvas = document.getElementById('padCanvas');
        const ctx = canvas.getContext('2d');
        const err = document.getElementById('padError');
        const saveBtn = document.getElementById('padSave');
        let role = null, drawing = false, strokes = 0, last = null;

        function resize() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
            strokes = 0;
        }
        function pos(e) {
            const r = canvas.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }
        canvas.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            canvas.setPointerCapture(e.pointerId);
            drawing = true;
            last = pos(e);
            ctx.beginPath();
            ctx.arc(last.x, last.y, 1, 0, Math.PI * 2);
            ctx.fillStyle = '#0f172a';
            ctx.fill();
        });
        canvas.addEventListener('pointermove', (e) => {
            if (!drawing) return;
            e.preventDefault();
            const p = pos(e);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
            strokes++;
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach((t) => canvas.addEventListener(t, () => { drawing = false; }));

        document.querySelectorAll('.sig-open').forEach((btn) => btn.addEventListener('click', () => {
            role = btn.dataset.role;
            document.getElementById('padTitle').textContent = btn.dataset.title;
            err.hidden = true;
            modal.hidden = false;
            resize();
        }));
        document.getElementById('padCancel').addEventListener('click', () => { modal.hidden = true; });
        document.getElementById('padClear').addEventListener('click', resize);
        saveBtn.addEventListener('click', async () => {
            if (strokes < 8) {
                err.textContent = 'Tanda tangan masih kosong atau terlalu pendek.';
                err.hidden = false;
                return;
            }
            saveBtn.disabled = true;
            saveBtn.textContent = 'Menyimpan…';
            try {
                const res = await fetch(@json(route('cash-shifts.sign', $shift)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ role, signature: canvas.toDataURL('image/png') }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'Gagal menyimpan tanda tangan.');
                window.location.reload();
            } catch (e) {
                err.textContent = e.message;
                err.hidden = false;
                saveBtn.disabled = false;
                saveBtn.textContent = 'Simpan';
            }
        });
    })();
    </script>
@endif
<div class="actions">
    <a href="{{ route('cash-shifts.index') }}">Kembali</a>
    <button type="button" onclick="window.print()">Cetak</button>
</div>
</body>
</html>
