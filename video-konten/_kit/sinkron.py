"""Hitung batas adegan dan waktu penanda dari naskah + rekaman VO.

pakai: python _kit/sinkron.py <folder-video>

Naskah ada di video.json, per adegan di kolom "vo". Tulis penanda [nama] tepat sebelum kata
yang jadi ketukan, contoh:  "Modalnya [modal]enam ribu delapan ratus."
Elemen lalu memakai "@modal" (atau "@modal+0.3") sebagai waktunya.

- Ada vo.wav/vo.mp3/vo.m4a  → whisper (small, bahasa Indonesia) per kata, hasilnya disimpan di
  _kata.json supaya sinkron ulang (misalnya setelah memindah penanda) tidak perlu transkrip lagi.
- Belum ada VO              → waktu diperkirakan dari jumlah kata (untuk pratinjau tanpa suara).
Hasil: <folder>/_waktu.json  {"C": [...batas adegan...], "cue": {"<adegan>:<nama>": detik}, "sumber": ...}
"""
import difflib
import io
import json
import os
import re
import sys

LEAD = 0.25      # adegan mulai sekian detik sebelum kata pertamanya
EKOR = 1.1       # sisa setelah kata terakhir
SATUAN = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas']


def terbilang(n):
    n = int(n)
    if n < 12:
        return SATUAN[n]
    if n < 20:
        return terbilang(n - 10) + ' belas'
    if n < 100:
        return terbilang(n // 10) + ' puluh ' + terbilang(n % 10)
    if n < 200:
        return 'seratus ' + terbilang(n - 100)
    if n < 1000:
        return terbilang(n // 100) + ' ratus ' + terbilang(n % 100)
    if n < 2000:
        return 'seribu ' + terbilang(n - 1000)
    if n < 10 ** 6:
        return terbilang(n // 1000) + ' ribu ' + terbilang(n % 1000)
    if n < 10 ** 9:
        return terbilang(n // 10 ** 6) + ' juta ' + terbilang(n % 10 ** 6)
    return str(n)


def normal(tok):
    """Satu token → daftar kata baku (angka dieja, huruf kecil, tanpa tanda baca)."""
    t = re.sub(r'rp(?=\d)', '', tok.lower())
    d = re.sub(r'[.,](?=\d{3}\b)', '', t)       # 6.800 → 6800
    d = re.sub(r'[^\w]', '', d)
    if d.isdigit():
        return terbilang(d).split()
    w = re.sub(r'[^\w]', '', t)
    return [w] if w else []


def naskah(data):
    """Kata naskah berurutan: [(kata_baku, adegan, [penanda...])]."""
    out = []
    for i, a in enumerate(data['adegan']):
        pend = []
        for tok in a.get('vo', '').split():
            while True:
                m = re.match(r'^\[(\w+)\](.*)$', tok)
                if not m:
                    break
                pend.append(m.group(1))
                tok = m.group(2)
            for w in normal(tok):
                out.append([w, i, pend])
                pend = []
        if pend:
            raise SystemExit(f'adegan {i}: penanda {pend} tidak diikuti kata')
    return out


def kata_vo(folder, prompt):
    cache = os.path.join(folder, '_kata.json')
    vo = next((os.path.join(folder, f) for f in ('vo.wav', 'vo.mp3', 'vo.m4a') if os.path.exists(os.path.join(folder, f))), None)
    if not vo:
        return None
    if os.path.exists(cache) and os.path.getmtime(cache) >= os.path.getmtime(vo):
        return json.load(io.open(cache, encoding='utf-8'))
    import warnings
    import whisper
    warnings.filterwarnings('ignore')
    res = whisper.load_model('small').transcribe(vo, language='id', word_timestamps=True, fp16=False, initial_prompt=prompt[:800])
    raw = [{'w': w['word'].strip(), 's': w['start'], 'e': w['end']} for seg in res['segments'] for w in seg.get('words', [])]
    json.dump(raw, io.open(cache, 'w', encoding='utf-8'), ensure_ascii=False)
    return raw


def pecah_vo(raw):
    """Gabung '6' + '.800' jadi 6800, lalu eja; tiap kata hasil ejaan dapat potongan waktu yang adil."""
    toks = []
    for r in raw:
        if toks and re.match(r'^[.,]\d', r['w']) and re.sub(r'[^\d]', '', toks[-1]['w']).isdigit():
            toks[-1] = {'w': toks[-1]['w'] + r['w'], 's': toks[-1]['s'], 'e': r['e']}
        else:
            toks.append(dict(r))
    out = []
    for r in toks:
        ws = normal(r['w'])
        for k, w in enumerate(ws):
            d = (r['e'] - r['s']) / max(1, len(ws))
            out.append((w, r['s'] + k * d, r['s'] + (k + 1) * d))
    return out


def sejajarkan(nk, vo):
    """Pasangkan kata naskah ke kata VO (difflib), kata yang tak cocok diinterpolasi."""
    a = [w for w, *_ in nk]
    b = [w for w, *_ in vo]
    st = [None] * len(a)
    en = [None] * len(a)
    for blk in difflib.SequenceMatcher(None, a, b, autojunk=False).get_matching_blocks():
        for k in range(blk.size):
            st[blk.a + k] = vo[blk.b + k][1]
            en[blk.a + k] = vo[blk.b + k][2]
    idx = [i for i, s in enumerate(st) if s is not None]
    if not idx:
        raise SystemExit('naskah dan rekaman tidak cocok sama sekali')
    for i in range(len(a)):
        if st[i] is not None:
            continue
        lo = max([j for j in idx if j < i], default=None)
        hi = min([j for j in idx if j > i], default=None)
        if lo is None:
            st[i] = max(0, st[hi] - 0.35 * (hi - i))
        elif hi is None:
            st[i] = en[lo] + 0.35 * (i - lo - 1)
        else:
            st[i] = en[lo] + (st[hi] - en[lo]) * (i - lo) / (hi - lo)
        en[i] = st[i] + 0.3
    cocok = len(idx) / len(a)
    return st, en, cocok


def perkiraan(nk, data):
    """Tanpa VO: 0,36 dtk per kata + jeda koma/titik, jeda 0,9 dtk antaradegan."""
    st, en, t, prev = [], [], 0.4, 0
    tanda = {}
    for a_i, a in enumerate(data['adegan']):
        for tok in a.get('vo', '').split():
            n = len(normal(re.sub(r'^(\[\w+\])+', '', tok)))
            tanda.setdefault(a_i, []).append((n, tok[-1] in ',.?!:'))
    for a_i in range(len(data['adegan'])):
        if a_i:
            t += 0.9
        for n, jeda in tanda.get(a_i, []):
            for _ in range(n):
                st.append(t)
                t += 0.36
                en.append(t)
            if jeda:
                t += 0.25
    return st, en


def main(folder):
    data = json.load(io.open(os.path.join(folder, 'video.json'), encoding='utf-8'))
    nk = naskah(data)
    prompt = ' '.join(re.sub(r'\[\w+\]', '', a.get('vo', '')) for a in data['adegan'])
    raw = kata_vo(folder, prompt)
    if raw:
        st, en, cocok = sejajarkan(nk, pecah_vo(raw))
        sumber = f'vo ({cocok:.0%} kata cocok)'
    else:
        st, en = perkiraan(nk, data)
        sumber = 'perkiraan (belum ada VO)'
    n = len(data['adegan'])
    first = [next(k for k, x in enumerate(nk) if x[1] == i) for i in range(n)]
    C = [0.0] + [round(float(max(st[first[i]] - LEAD, en[first[i] - 1] + 0.05)), 2) for i in range(1, n)]
    C.append(round(float(en[-1] + data.get('ekor', EKOR)), 2))
    cue = {}
    for k, (_, i, pend) in enumerate(nk):
        for p in pend:
            cue[f'{i}:{p}'] = round(float(st[k]), 2)
    json.dump({'C': C, 'cue': cue, 'sumber': sumber}, io.open(os.path.join(folder, '_waktu.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f'sinkron: {sumber} · durasi {C[-1]} dtk · batas adegan {C}')
    return C, cue


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
