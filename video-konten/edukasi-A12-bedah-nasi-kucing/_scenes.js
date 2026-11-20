/* dibuat otomatis oleh _kit/buat.py dari video.json — jangan diedit, ubah video.json */
const DATA={"judul": "Bedah Menu #4: nasi kucing", "brief": "  A12: nasi kucing Rp3.000, modal Rp1.500, beras Rp13.700/kg (Bapanas 12 Nov 2026); rahasia porsi: sekilo beras jadi 25 bungkus; salah hitung Rp1.000 = dua pertiga untung; keranjang kasir.", "adegan": [{"bg": "dark", "vo": "Nasi kucing tiga ribu. Modalnya cuma [modal]seribu lima ratus. [beras]Padahal beras sekarang [harga]tiga belas ribu tujuh ratus sekilo. [kok]Kok bisa?", "isi": [{"jenis": "objek", "svg": "nasi-kucing", "x": 60, "y": 640, "w": 360}, {"jenis": "label", "teks": "Rp3.000", "x": 80, "y": 980, "at": 0.5, "rot": -12}, {"jenis": "stempel", "kecil": "modal sebungkus", "besar": "Rp1.500", "kecilAtas": true, "kelas": "smod", "at": 3.96, "hilang": 5.5}, {"jenis": "objek", "svg": "beras", "x": 700, "y": 520, "w": 270, "at": 5.8, "rot": 10}, {"jenis": "label", "teks": "Rp13.700/kg", "x": 540, "y": 1110, "at": 7.16, "rot": 8, "ukuran": 70}, {"jenis": "tanya", "x": 470, "y": 700, "at": 9.46}], "cap": [{"kecil": "nasi kucing Rp3.000, modalnya", "kunci": "rp1.500", "at": 0.04, "kw": 3.96, "keluar": 5.55}, {"kecil": "padahal beras Rp13.700 sekilo.", "kunci": "kok bisa?", "at": 5.65, "kw": 9.46}]}, {"bg": "cream", "vo": "Bedah Menu nomor empat. Kita hitung pakai [harga]harga beras Bapanas, November dua ribu dua puluh enam.", "isi": [{"jenis": "objek", "svg": "nasi-kucing", "x": 340, "y": 600, "w": 400, "at": 0.02, "rot": -8, "dari": 0.5}, {"jenis": "seri", "sub": "#4 nasi kucing"}, {"jenis": "kaca"}, {"jenis": "pil", "teks": "harga Bapanas · Nov 2026", "y": 1200, "at": 2.99}]}, {"bg": "orange", "vo": "[nasi]Nasinya dari empat puluh gram beras, lima ratus lima puluh. [teri]Teri lima gram, empat ratus. [sambal]Sambal dua ratus. [kertas]Kertas dan karet seratus lima puluh. [gas]Gas dan minyak dua ratus. [total]Total seribu lima ratus.", "isi": [{"jenis": "struk", "judul": "Modal 1 bungkus", "baris": [["Nasi (40 g beras)", "550", 0.25], ["Teri 5 g", "400", 4.35], ["Sambal", "200", 6.99], ["Kertas & karet", "150", 8.57], ["Gas & minyak", "200", 11.63]], "total": {"label": "Modal", "nilai": 1500, "at": 13.69}}], "cap": [{"kecil": "modal sebungkus", "kunci": "rp1.500", "kw": 14.09}]}, {"bg": "dark", "vo": "Rahasianya di porsi. [kilo]Sekilo beras jadi [n]dua puluh lima bungkus. [jual]Dijual semua, [hasil]tujuh puluh lima ribu.", "isi": [{"jenis": "objek", "svg": "beras", "x": 70, "y": 560, "w": 240, "at": 2.26, "rot": -8}, {"jenis": "label", "teks": "1 kg", "x": 110, "y": 860, "at": 2.56, "rot": -8}, {"jenis": "grid", "svg": "nasi-kucing", "n": 25, "kolom": 5, "x0": 370, "y0": 540, "dx": 125, "dy": 100, "w": 110, "at": 3.0, "hitung": "bungkus", "hitungKelas": "sc2", "hitungHilang": 4.9}, {"jenis": "persamaan", "bagian": ["25", "×", "3.000"], "kelas": "sm seq", "at": 5.0}, {"jenis": "angka", "ke": 75000, "kelas": "smeg", "at": 6.12}], "cap": [{"kecil": "sekilo beras jadi", "kunci": "25 bungkus", "kw": 3.4}]}, {"bg": "purple", "vo": "Untung sebungkus [untung]seribu lima ratus. [separuh]Separuh dari harga jualnya.", "isi": [{"jenis": "objek", "svg": "nasi-kucing", "x": 300, "y": 560, "w": 420}, {"jenis": "stempel", "besar": "Rp1.500", "kecil": "untung sebungkus", "kelas": "sunt", "at": 1.09, "getar": false}, {"jenis": "bulat", "teks": "50%", "x": 760, "y": 1110, "at": 2.43}], "cap": [{"kecil": "untung sebungkus", "kunci": "separuh harga", "kw": 2.43}]}, {"bg": "cream", "vo": "Tapi jualannya kecil-kecil. Pembeli ambil [nasi]dua nasi, [gor]dua gorengan, [teh]es teh. [salah]Salah hitung seribu saja, [hilang]dua pertiga untung sebungkus hilang.", "isi": [{"jenis": "chip", "teks": "🍙 2 nasi kucing", "x": 150, "y": 540, "at": 3.69, "rot": -6}, {"jenis": "chip", "teks": "🍤 2 gorengan", "x": 330, "y": 690, "at": 4.55, "rot": 6}, {"jenis": "chip", "teks": "🧋 es teh", "x": 200, "y": 840, "at": 5.65, "rot": -4}, {"jenis": "stempel", "besar": "−Rp1.000", "kecil": "salah hitung", "kecilAtas": true, "y": 1040, "at": 6.05}, {"jenis": "kartu", "judul": "untung sebungkus", "dari": 1500, "ke": 500, "y": 1380, "at": 6.65, "ubahAt": 8.39, "catatan": "hilang dua pertiga"}], "cap": [{"kecil": "salah hitung seribu,", "kunci": "untung ikut hilang", "kw": 8.39}]}, {"bg": "purple", "vo": "Di Kasir UMKM, cukup [ketuk]ketuk tiap menu. [total]Totalnya langsung terhitung, tanpa hitung di kepala.", "isi": [{"jenis": "ponsel", "judul": "Keranjang", "kelas": "pendek", "baris": [["Nasi kucing ×2", "Rp6.000", 1.93], ["Gorengan ×2", "Rp3.000", 2.43], ["Es teh", "Rp3.000", 2.93]], "ringkasan": [["Total", ""]], "dari": 0, "langkah": [[1.93, 6000], [2.43, 9000], [2.93, 12000]]}], "cap": [{"kecil": "ketuk tiap menu,", "kunci": "total langsung jadi", "kw": 3.09}]}, {"bg": "purple", "vo": "Mau menu lain dibedah? [follow]Follow Kasir UMKM.", "isi": [{"jenis": "follow", "ketuk": 1.51}], "cap": [{"kecil": "mau menu lain dibedah?", "kunci": "follow kasir umkm", "kw": 1.51}]}]};
const WAKTU={"C": [0.0, 10.59, 17.23, 33.76, 41.51, 45.81, 57.33, 63.45, 67.7]};
/* ===================================================================================
   PUSTAKA KOMPONEN ADEGAN — kerangka v2 (dipakai _kit/buat.py)
   Membaca DATA (video.json yang waktunya sudah dihitung) dan WAKTU (_waktu.json).
   Semua waktu di dalam adegan RELATIF terhadap awal adegan (S.t0), dalam detik.
   Jenis elemen: objek label stempel struk batang chip tanya seri kaca pil grid persamaan
                 angka kartu bulat ponsel follow getar kode   (lihat _kit/PANDUAN.md)
   =================================================================================== */
const C=WAKTU.C;
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const ease2=p=>1-Math.pow(1-clamp(p),2.4);
const rb=v=>Math.round(v).toLocaleString('id-ID');

function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {i,st,inn,t0,t1};}

/* ---------- gambar objek (SVG) — tambah di sini untuk objek baru ---------- */
const KOPI=(kafe)=>`<svg viewBox="0 0 200 330"><rect x="114" y="0" width="20" height="160" rx="10" fill="${kafe?'#fff':'#a0ea71'}" stroke="#3B1F12" stroke-width="6" transform="rotate(10 124 80)"/>
<path d="M21 72h158l-5 70H26z" fill="#5A2E14"/><path d="M26 142h148l-8 104H34z" fill="#C98F5A"/><path d="M34 246h132l-4 50H38z" fill="#7A3B12"/>
<rect x="52" y="96" width="38" height="38" rx="8" fill="#fff" opacity=".55" transform="rotate(-12 71 115)"/><rect x="104" y="112" width="34" height="34" rx="8" fill="#fff" opacity=".5" transform="rotate(14 121 129)"/><rect x="74" y="160" width="36" height="36" rx="8" fill="#fff" opacity=".4"/>
${kafe?'<path d="M30 150h140l-6 70H36z" fill="#2E7D4F" stroke="#3B1F12" stroke-width="6"/><circle cx="100" cy="185" r="24" fill="#fff" stroke="#3B1F12" stroke-width="5"/><path d="M88 185q12-16 24 0q-12 16-24 0z" fill="#2E7D4F"/>':'<circle cx="100" cy="200" r="30" fill="#7943e5" stroke="#3B1F12" stroke-width="5"/><path d="M86 200h28M100 186v28" stroke="#fff" stroke-width="7" stroke-linecap="round"/>'}
<path d="M30 80L44 290" stroke="#fff" stroke-width="10" stroke-linecap="round" opacity=".5"/>
<path d="M20 60h160l-18 240H38z" fill="none" stroke="#3B1F12" stroke-width="9" stroke-linejoin="round"/>
${kafe?'<path d="M12 62q88-60 176 0z" fill="rgba(255,255,255,.7)" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/>':'<rect x="12" y="50" width="176" height="18" rx="8" fill="#fff" stroke="#3B1F12" stroke-width="7"/>'}</svg>`;
const SVG={
  'kopi':KOPI(false),
  'kopi-kafe':KOPI(true),
  'susu':`<svg viewBox="0 0 140 230"><path d="M20 66l30-40h40l30 40v150H20z" fill="#fff" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/><path d="M52 26V8h36v18" fill="#7DC4F5" stroke="#3B1F12" stroke-width="7" stroke-linejoin="round"/><path d="M24 116h92v64H24z" fill="#7DC4F5"/><text x="70" y="160" font-family="Outfit" font-weight="900" font-size="34" text-anchor="middle" fill="#fff">SUSU</text><path d="M20 66h100" stroke="#3B1F12" stroke-width="7"/><path d="M20 66l30-40h40l30 40v150H20z" fill="none" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/></svg>`,
  'gerobak':`<svg viewBox="0 0 260 210"><rect x="34" y="14" width="170" height="34" rx="10" fill="#7943e5" stroke="#3B1F12" stroke-width="7"/><path d="M56 48v46M182 48v46" stroke="#3B1F12" stroke-width="8"/><rect x="20" y="94" width="196" height="76" rx="14" fill="#FFC83D" stroke="#3B1F12" stroke-width="7"/><path d="M60 118h116" stroke="#3B1F12" stroke-width="6" stroke-linecap="round" opacity=".5"/><circle cx="62" cy="180" r="22" fill="#fff" stroke="#3B1F12" stroke-width="7"/><circle cx="174" cy="180" r="22" fill="#fff" stroke="#3B1F12" stroke-width="7"/><path d="M216 112h34" stroke="#3B1F12" stroke-width="9" stroke-linecap="round"/></svg>`,
  'mie':`<svg viewBox="0 0 120 96"><path d="M8 44h104q-4 44-52 48Q12 88 8 44z" fill="#fff" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><path d="M14 44q8-24 46-24t46 24z" fill="#D9341F" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><path d="M24 38q6-10 12 0t12 0 12 0 12 0 12 0 12 0" stroke="#FFD27A" stroke-width="6" fill="none" stroke-linecap="round"/><path d="M30 30q6-8 12 0t12 0 12 0 12 0" stroke="#FFE7A3" stroke-width="5" fill="none" stroke-linecap="round"/><path d="M78 24l12-10" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><path d="M84 22l14-12" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><circle cx="44" cy="26" r="4" fill="#57b01c"/><circle cx="66" cy="23" r="4" fill="#57b01c"/></svg>`,
  'cabai':`<svg viewBox="0 0 60 100"><path d="M30 18q-4-12 8-16" stroke="#2d6b10" stroke-width="6" fill="none" stroke-linecap="round"/><path d="M22 20q8-6 18 0q8 30-4 62q-6 14-14 16q-4-24 0-78z" fill="#E4291B" stroke="#3B1F12" stroke-width="5" stroke-linejoin="round"/><path d="M28 30q2 20-2 40" stroke="#FF8A7A" stroke-width="5" fill="none" stroke-linecap="round"/></svg>`,
  'nasi-kucing':`<svg viewBox="0 0 200 170"><path d="M70 44q30-40 60 0" fill="#fff" stroke="#3B1F12" stroke-width="7"/><path d="M18 152L60 42q40-26 80 0l42 110z" fill="#E9D3A6" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/><path d="M60 42l40 110M140 42l-40 110" stroke="#3B1F12" stroke-width="5" opacity=".3"/><path d="M44 92q56 16 112 0" stroke="#E4291B" stroke-width="10" fill="none" stroke-linecap="round"/><path d="M30 150q70 12 140 0" stroke="#C9AE7A" stroke-width="8" fill="none" opacity=".7"/></svg>`,
  'beras':`<svg viewBox="0 0 200 240"><path d="M40 40q60-30 120 0l14 180q-74 20-148 0z" fill="#F4EBD6" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/><path d="M50 40q50 22 100 0" fill="none" stroke="#3B1F12" stroke-width="6"/><path d="M70 30l-10-22M100 26V2M130 30l10-22" stroke="#3B1F12" stroke-width="6" stroke-linecap="round"/><rect x="50" y="110" width="100" height="56" rx="10" fill="#57b01c" stroke="#3B1F12" stroke-width="6"/><text x="100" y="149" font-family="Outfit" font-weight="900" font-size="30" text-anchor="middle" fill="#fff">BERAS</text></svg>`,
  'telur':`<svg viewBox="0 0 120 100"><path d="M10 52q4-40 50-42t50 42q-2 38-50 40T10 52z" fill="#fff" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><circle cx="60" cy="54" r="22" fill="#F7B21B" stroke="#3B1F12" stroke-width="5"/><circle cx="52" cy="46" r="6" fill="#FFE29A"/></svg>`,
};
const obj=(par,name,x,y,w)=>{if(!SVG[name])throw new Error('objek tidak dikenal: '+name);const b=el('skop',SVG[name]);b.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(b);return b;};
const at=(S,v,d=.05)=>S.t0+(v??d);
const add=(S,cls,html,kelas)=>{const n=el(cls+(kelas?' '+kelas:''),html);S.inn.append(n);gsap.set(n,{opacity:0});return n;};
const stamp=(n,t,rot=-4)=>tl.fromTo(n,{opacity:0,scale:3,rotation:rot-14},{opacity:1,scale:1,rotation:rot,duration:.25,ease:'power4.in',immediateRender:false},t);
const shake=(S,t)=>tl.fromTo(S.inn,{x:0},{x:16,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},t);
const pulse=(n,t,s=1.12)=>tl.fromTo(n,{scale:1},{scale:s,duration:.14,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},t);

/* ---------- jenis elemen ---------- */
const K={
  /* objek: gambar dari SVG {svg,x,y,w,at,rot,goyang} */
  objek(S,e){const b=obj(S.inn,e.svg,e.x,e.y,e.w??300);gsap.set(b,{opacity:0});pop(b,at(S,e.at),{from:e.dari??.4,rot:e.rot??-10,dur:.4});
    if(e.goyang!==0)BOBS.push({e:b,a:e.goyang??8,f:2.3,ph:S.i*.9+e.x/300});return b;},
  /* label: label harga putih {teks,x,y,at,rot,ukuran} */
  label(S,e){const g=add(S,'stag',e.teks,e.kelas);g.style.left=e.x+'px';g.style.top=e.y+'px';if(e.ukuran)g.style.fontSize=e.ukuran+'px';
    pop(g,at(S,e.at),{from:.3,rot:e.rot??-10});return g;},
  /* stempel: kartu emas jatuh {besar,kecil,kecilAtas,y,at,getar,miring} */
  stempel(S,e){const n=add(S,'sjt',e.kecilAtas?`<small>${e.kecil}</small><b>${e.besar}</b>`:`<b>${e.besar}</b><small>${e.kecil??''}</small>`,e.kelas);
    if(e.y!=null)n.style.top=e.y+'px';stamp(n,at(S,e.at),e.miring??-4);if(e.getar!==false)shake(S,at(S,e.at)+.25);return n;},
  /* struk: {judul, baris:[[nama,nilai,at]], total:{label,nilai,at}} */
  struk(S,e){const rc=add(S,'hrc',`<h5>${e.judul}</h5>`+e.baris.map(([a,b])=>`<div class="r"><span>${a}</span><b>${b}</b></div>`).join('')+(e.total?`<div class="tt"><span>${e.total.label}</span><b></b></div>`:''),e.kelas);
    pop(rc,at(S,e.at,.02),{from:.7,dur:.35});
    $$('.r',rc).forEach((r,i)=>{const ti=S.t0+e.baris[i][2];gsap.set(r,{opacity:0});tl.fromTo(r,{opacity:0,x:-60},{opacity:1,x:0,duration:.25,ease:'power3.out',immediateRender:false},ti);
      tl.fromTo(r,{backgroundColor:'#FFE3B8'},{backgroundColor:'rgba(255,227,184,0)',duration:.8,immediateRender:false},ti);});
    if(e.total){const tt=$('.tt',rc),t=S.t0+e.total.at;gsap.set(tt,{opacity:0});tl.fromTo(tt,{opacity:0,scale:1.3},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t);
      num($('b',tt),'',x=>Math.round(e.total.nilai*ease2((x-t-.05)/.8)/100)*100,v=>'Rp'+rb(v));}
    return rc;},
  /* batang: diagram batang biaya {baris:[[nama,nilai,sorot?]], at, sorot(at)} */
  batang(S,e){const mx=Math.max(...e.baris.map(r=>r[1]));
    const bs=add(S,'sbars',e.baris.map(([a,v,h])=>`<div class="g ${h?'hot':''}"><span>${a}</span><i><em></em></i><b>${rb(v)}</b></div>`).join(''),e.kelas);
    const ems=$$('em',bs),t=at(S,e.at,.2);gsap.set(ems,{width:'0%'});
    tl.fromTo(bs,{opacity:0,y:40},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},t);
    ems.forEach((m,i)=>tl.fromTo(m,{width:'0%'},{width:(e.baris[i][1]/mx*100)+'%',duration:.45,ease:'power2.out',immediateRender:false},t+.1+i*.1));
    if(e.sorot!=null)$$('.hot',bs).forEach(h=>pulse(h,S.t0+e.sorot,1.06));return bs;},
  /* chip: kartu kecil {teks,x,y,at,coret(at)} */
  chip(S,e){const c=add(S,'schip',e.teks+'<s></s>',e.kelas);c.style.top=e.y+'px';if(e.x!=null)c.style.left=e.x+'px';pop(c,at(S,e.at),{from:.4,rot:e.rot??8});
    if(e.coret!=null){tl.fromTo($('s',c),{scaleX:0},{scaleX:1,duration:.25,ease:'power2.out',immediateRender:false},S.t0+e.coret);tl.to(c,{opacity:.5,duration:.3},S.t0+e.coret+.2);}return c;},
  /* tanya: tanda tanya kuning {x,y,at,teks} */
  tanya(S,e){const q=add(S,'sq',e.teks??'?',e.kelas);q.style.cssText=`left:${e.x}px;top:${e.y}px`;pop(q,at(S,e.at),{from:.2,rot:-20});pulse(q,at(S,e.at)+.5,1.15);return q;},
  /* seri: judul seri {judul,sub,at} */
  seri(S,e){const n=add(S,'sseri',`<b>${e.judul??'BEDAH MENU'}</b><span>${e.sub}</span>`,e.kelas);pop(n,at(S,e.at,.4),{from:.4,rot:-10});return n;},
  /* kaca: kaca pembesar menyapu {at} */
  kaca(S,e){const mg=add(S,'smag','<i></i>',e.kelas);tl.fromTo(mg,{opacity:1,x:-500,y:60,rotation:-20},{x:120,y:-80,rotation:10,duration:1.2,ease:'sine.inOut',immediateRender:false},at(S,e.at,.1));return mg;},
  /* pil: label lonjong ungu {teks,y,at} */
  pil(S,e){const d=add(S,'sdiv',`<span>${e.teks}</span>`,e.kelas);if(e.y!=null)d.style.top=e.y+'px';pop(d,at(S,e.at),{from:.4,rot:6});return d;},
  /* grid: banyak objek kecil + penghitung {svg,n,kolom,x0,y0,dx,dy,w,at,hitung,hitungKelas,hitungHilang} */
  grid(S,e){const G=[],t=at(S,e.at,.3),dur=e.n*.016+.2;for(let i=0;i<e.n;i++){const c=obj(S.inn,e.svg,e.x0+(i%e.kolom)*e.dx,e.y0+Math.floor(i/e.kolom)*e.dy,e.w);gsap.set(c,{opacity:0});G.push(c);}
    tl.fromTo(G,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.18,stagger:.016,ease:'back.out(2.4)',immediateRender:false},t);
    if(e.hitung){const cnt=num(S.inn,'scnt'+(e.hitungKelas?' '+e.hitungKelas:''),x=>Math.max(0,Math.min(e.n,Math.round(e.n*clamp((x-t)/dur)))),v=>v+' '+e.hitung);
      tl.fromTo(cnt,{opacity:0},{opacity:1,duration:.1,immediateRender:false},t);if(e.hitungHilang!=null)tl.to(cnt,{opacity:0,y:-30,duration:.2},S.t0+e.hitungHilang);}
    return G[0];},
  /* persamaan: angka besar berurutan {bagian:['84','×','1.200'],at,jarak} */
  persamaan(S,e){const eq=el('bigeq'+(e.kelas?' '+e.kelas:''),e.bagian.map((b,i)=>i%2?`<i>${b}</i>`:`<span>${b}</span>`).join(''));S.inn.append(eq);const t=at(S,e.at);
    $$('span, i',eq).forEach((x,i)=>{gsap.set(x,{opacity:0});tl.fromTo(x,{opacity:0,y:60,scale:.6},{opacity:1,y:0,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+i*(e.jarak??.15));});
    tl.set(eq,{opacity:1},t);return eq;},
  /* angka: angka raksasa menghitung naik {ke,awalan,akhiran,at} */
  angka(S,e){const t=at(S,e.at),n=num(S.inn,'mega2'+(e.kelas?' '+e.kelas:''),x=>Math.round(e.ke*ease2((x-t-.05)/.6)/(e.bulat??100))*(e.bulat??100),v=>(e.awalan??'Rp')+rb(v)+(e.akhiran??''));
    tl.fromTo(n,{opacity:0,scale:1.5},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},t);return n;},
  /* kartu: kartu emas dengan angka yang berubah {judul,dari,ke,at,ubahAt,catatan} */
  kartu(S,e){const u=add(S,'sper',`<span>${e.judul}</span><b></b><small>&nbsp;</small>`,e.kelas);if(e.y!=null)u.style.top=e.y+'px';
    tl.fromTo(u,{opacity:0,y:60,rotation:6},{opacity:1,y:0,rotation:-3,duration:.35,ease:'back.out(2)',immediateRender:false},at(S,e.at));
    const tu=S.t0+(e.ubahAt??999);num($('b',u),'',x=>e.dari+Math.round((e.ke-e.dari)*ease2((x-tu)/.6)/100)*100,v=>'Rp'+rb(v));
    if(e.catatan)tl.set($('small',u),{innerHTML:e.catatan},tu+.55);return u;},
  /* bulat: lingkaran merah berisi teks pendek {teks,x,y,at} */
  bulat(S,e){const pc=add(S,'spct',e.teks,e.kelas);if(e.x!=null)pc.style.left=e.x+'px';if(e.y!=null)pc.style.top=e.y+'px';
    tl.fromTo(pc,{opacity:0,scale:3,rotation:-30},{opacity:1,scale:1,rotation:-10,duration:.3,ease:'back.out(2.2)',immediateRender:false},at(S,e.at));return pc;},
  /* ponsel: halaman aplikasi berisi baris + ringkasan angka berubah
     {judul,sub,baris:[[a,b,at?]] (at = baris muncul bertahap), langkah:[[at,nilai]] (ringkasan naik bertahap),sorot:{baris,badge,at},ringkasan:[[label,akhiran]],dari,ke,ubahAt} */
  ponsel(S,e){const ph=el('phone hph rcp'+(e.kelas?' '+e.kelas:''),`<div class="hd">${e.judul}</div>`+(e.sub?`<div class="snm">${e.sub}</div>`:'')
      +e.baris.map(([a,b],i)=>`<div class="row" data-i="${i}"><span>${a}</span><b>${b}</b></div>`).join('')
      +(e.ringkasan?`<div class="rcs">${e.ringkasan.map(([l],i)=>`<div><span>${l}</span><b class="x${i}"></b></div>`).join('')}</div>`:''));
    S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});const t=at(S,e.at,.02);
    e.baris.forEach((b,i)=>{if(b[2]==null)return;const r=$(`.row[data-i="${i}"]`,ph);gsap.set(r,{opacity:0});
      tl.fromTo(r,{opacity:0,x:-40},{opacity:1,x:0,duration:.3,ease:'power3.out',immediateRender:false},S.t0+b[2]);
      tl.fromTo(r,{backgroundColor:'#DCFCE7'},{backgroundColor:'#f1f5f9',duration:1,immediateRender:false},S.t0+b[2]);});
    tl.fromTo(ph,{opacity:1,y:1300,rotation:8},{y:0,rotation:-2,duration:.55,ease:'back.out(1.3)',immediateRender:false},t);tl.set(ph,{opacity:1},t);
    if(e.sorot){const mk=$(`.row[data-i="${e.sorot.baris}"]`,ph),ts=S.t0+e.sorot.at;
      tl.fromTo(mk,{backgroundColor:'#f1f5f9'},{backgroundColor:'#FEE2E2',duration:.3,immediateRender:false},ts);
      if(e.sorot.badge){const bd=el('sbd',e.sorot.badge);mk.append(bd);gsap.set(bd,{opacity:0});pop(bd,ts+.05,{from:.3,rot:-8});}}
    if(e.ringkasan){const tu=S.t0+(e.ubahAt??999),v=e.langkah?(x=>e.langkah.reduce((n,[lt,lv])=>x>=S.t0+lt?lv:n,e.dari)):(x=>e.dari+Math.round((e.ke-e.dari)*ease2((x-tu)/.6)/100)*100);
      e.ringkasan.forEach(([,akh],i)=>num($('.x'+i,ph),'',v,x=>'Rp'+rb(x)+(akh??'')));
      tl.fromTo($('.rcs',ph),{backgroundColor:'#f8fafc'},{backgroundColor:'#FEF3C7',duration:.3,yoyo:true,repeat:1,immediateRender:false},tu);}
    return ph;},
  /* follow: kartu profil @kasir_umkm, jari mengetuk Ikuti → Mengikuti, maskot, hati {ketuk,bio} */
  follow(S,e){const t=S.t0,k=t+(e.ketuk??1.6);
    const pf=add(S,'sprof',`<div class="top"><div class="av"><img src="assets/avatar.png" alt=""></div><div class="nm"><b>Kasir UMKM</b><span>@kasir_umkm</span></div></div><div class="bio">${e.bio??'Tips hitung untung &amp; bedah menu untuk UMKM'}</div><div class="btn"><span class="f">Ikuti</span><span class="d">Mengikuti</span></div>`);
    tl.fromTo(pf,{opacity:1,y:-900,rotation:-6},{y:0,rotation:-2,duration:.55,ease:'back.out(1.5)',immediateRender:false},t+.05);
    const btn=$('.btn',pf),d=$('.d',pf),f=$('.f',pf);gsap.set(d,{opacity:0});
    const hand=add(S,'shand','👆');
    tl.fromTo(hand,{opacity:1,x:300,y:500},{x:0,y:0,duration:.5,ease:'power3.out',immediateRender:false},k-.45);
    tl.fromTo(hand,{scale:1},{scale:.82,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},k);
    tl.fromTo(btn,{scale:1},{scale:.92,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},k);
    tl.set(f,{opacity:0},k+.1);tl.set(d,{opacity:1},k+.1);tl.set(btn,{backgroundColor:'#EFEFEF',borderColor:'#DBDBDB'},k+.1);
    const rg=el('sring');btn.append(rg);tl.fromTo(rg,{opacity:.9,scale:.6},{opacity:0,scale:1.8,duration:.5,ease:'power2.out',immediateRender:false},k+.1);
    tl.to(hand,{opacity:0,y:200,duration:.3},k+.9);
    const fox=el('fox sfox','','img');fox.src='assets/maskot.webp';fox.alt='';S.inn.append(fox);
    tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},t+.4);tl.set(fox,{opacity:1},t+.4);BOBS.push({e:fox,a:14,f:2.6,ph:0});
    for(let i=0;i<6;i++){const h=add(S,'sheart','♥');h.style.left=(560+i*60)+'px';
      tl.fromTo(h,{opacity:1,y:0,scale:.4},{opacity:0,y:-380-i*30,scale:1.3,x:(i%2?1:-1)*80,duration:1.2,ease:'power1.out',immediateRender:false},k+.15+i*.08);}
    return pf;},
  /* getar: guncang panggung {at} */
  getar(S,e){shake(S,at(S,e.at));return null;},
  /* kode: jalan keluar untuk animasi khusus — {js:"..."} dijalankan dengan S dan t (awal adegan) */
  kode(S,e){return new Function('S','t',e.js)(S,S.t0);},
};

/* ================= RAKIT SEMUA ADEGAN ================= */
DATA.adegan.forEach((a,i)=>{const S=stage(i,a.bg||'dark');
  (a.isi||[]).forEach(e=>{if(!K[e.jenis])throw new Error('jenis elemen tidak dikenal: '+e.jenis);const n=K[e.jenis](S,e);
    if(n&&e.hilang!=null)tl.to(n,{opacity:0,y:40,duration:.25,ease:'power2.in'},S.t0+e.hilang);});
  (a.cap||[]).forEach(c=>{const t=c.at??.08;cap(S,S.t0+t,c.kecil,c.kunci,{kwAt:(c.kw??t+.3)-t,out:c.keluar!=null?S.t0+c.keluar:null});});});
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
}
