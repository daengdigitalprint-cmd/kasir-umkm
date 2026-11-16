/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (59,16 dtk; awal paragraf: 0 4.60 11.22 13.32 16.50 20.02 24.52 29.82 33.14 39.20 42.06 44.60 49.58 54.52) ---------- */
const C=[0,4.45,11.0,13.2,16.4,19.85,24.4,29.7,33.0,39.05,41.9,44.45,49.4,54.4,60.3];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[], TAPS=[];

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.04,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ---------- HP berisi tangkapan layar asli ----------
   Koordinat kolom/tombol dalam css px layar 390×844 (dari shots/meta.json & pengukuran potret). */
const SK=574/390, PH={left:225,top:20}, FOC={x:540,y:725};
const R={
  d_name:[51,297,288,52], d_email:[51,383,288,52], d_wa:[51,468,288,52], d_pw:[51,589,288,52], d_konf:[51,674,288,52], d_btn:[51,748,288,52],
  t_nama:[37,353,316,52], t_fnb:[38,554,151,85],
  dash_buat:[138,441,114,36], dash_pop:[16,298,358,248],
  p_nama:[41,367,308,36], p_kat:[41,449,260,40],
  p_harga:[41,110,308,36], p_untung:[41,152,190,20], p_modal:[41,216,308,36], p_stok:[41,298,308,36],
  l_total:[16,92,173,124], l_list:[16,92,358,262],
  k_kas:[44,328,306,36], k_buka:[44,381,98,35],
  pos_nasi:[313,400,36,32], pos_teh:[313,727,36,32], pos_bar:[12,692,366,56],
  c_nasi:[37,338,316,85], c_teh:[37,436,316,82], c_lanjut:[37,543,316,47],
  b_total:[29,151,332,44], b_50:[254,203,106,33], b_terima:[30,310,330,50], b_kembali:[30,370,330,44], b_selesai:[142,627,218,44],
  s_struk:[49,134,292,452], s_kembali:[61,481,268,16], s_cetak:[33,617,324,36],
};
const pt=([x,y,w,h])=>({x:PH.left+28+(x+w/2)*SK,y:PH.top+28+(y+h/2)*SK});
function view(S,src){const win=el('vw'),cam=el('cam');win.append(cam);S.inn.append(win);
  const sp=el('sp'),scr=el('scr'),img=el('','','img');img.src='shots/'+src+'.png';img.alt='';scr.append(img);sp.append(scr);
  sp.style.left=PH.left+'px';sp.style.top=PH.top+'px';cam.append(sp);gsap.set(cam,{x:0,y:0,scale:1});
  tl.fromTo(sp,{y:900,rotation:6},{y:0,rotation:0,duration:.55,ease:'back.out(1.2)',immediateRender:false},S.t0+.02);
  return {cam,scr,sp};}
/* kamera mendekat ke satu bagian layar (z = perbesaran) */
const focus=(V,r,z,at,dur=.6,ease='power3.inOut')=>{const c=pt(r);tl.to(V.cam,{x:FOC.x-c.x*z,y:FOC.y-c.y*z,scale:z,duration:dur,ease},at);};
const home=(V,at,dur=.5)=>tl.to(V.cam,{x:0,y:0,scale:1,duration:dur,ease:'power3.inOut'},at);
/* isi kolom "diketik": potret terisi tampil di kotak kolom, terbuka kiri → kanan */
function type(V,src,r,at,{dur=.5,steps=9}={}){const img=el('','','img');img.src='shots/'+src+'.png';img.alt='';V.scr.append(img);
  const [x,y,w,h]=r,t=y*SK-3,l=x*SK-3,b=1242-(y+h)*SK-3,rr=574-(x+w)*SK-3;
  const a=`inset(${t}px ${574-l}px ${b}px ${l}px)`;gsap.set(img,{clipPath:a});
  tl.fromTo(img,{clipPath:a},{clipPath:`inset(${t}px ${rr}px ${b}px ${l}px)`,duration:dur,ease:`steps(${steps})`,immediateRender:false},at);return img;}
/* ganti layar penuh (hasil ketukan) */
function swap(V,src,at,dur=.18){const img=el('','','img');img.src='shots/'+src+'.png';img.alt='';V.scr.append(img);gsap.set(img,{opacity:0});
  tl.fromTo(img,{opacity:0},{opacity:1,duration:dur,ease:'none',immediateRender:false},at);return img;}
/* tutup nilai kolom dengan warna latarnya, lalu buka seperti diketik */
function reveal(V,r,at,{dur=.45,cls=''}={}){const [x,y,w,h]=r,c=el('cov '+cls);c.style.cssText=`left:${x*SK}px;top:${y*SK}px;width:${w*SK}px;height:${h*SK}px`;V.scr.append(c);
  tl.fromTo(c,{scaleX:1},{scaleX:0,duration:dur,ease:'steps(8)',immediateRender:false},at);return c;}
function ring(V,r,at,until=null){const [x,y,w,h]=r,p=pt(r),g=el('ring');const W=w*SK+26,H=h*SK+26;
  g.style.cssText=`left:${p.x-W/2}px;top:${p.y-H/2}px;width:${W}px;height:${H}px`;V.cam.append(g);
  tl.fromTo(g,{opacity:0,scale:1.25},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},at);
  if(until!=null)tl.to(g,{opacity:0,duration:.2},until);return g;}
function tap(V,r,at){const p=pt(r),f=el('tap');f.style.left=p.x+'px';f.style.top=p.y+'px';V.cam.append(f);
  tl.fromTo(f,{opacity:0,scale:1.8},{opacity:1,scale:1,duration:.2,ease:'power2.out',immediateRender:false},at-.22);
  tl.fromTo(f,{scale:1},{scale:.78,duration:.08,yoyo:true,repeat:1,ease:'power1.inOut',immediateRender:false},at);
  tl.to(f,{opacity:0,duration:.2},at+.4);TAPS.push({f,at});return f;}
const stepN=(S,n,at)=>{const s=el('stepn',String(n));S.st.append(s);pop(s,at,{from:.2,rot:-30,dur:.45});BOBS.push({e:s,a:10,f:2.4,ph:n});};

/* ================= PANGGUNG =================
   Waktu aksi = detik absolut di vo.wav (dari transkripsi per kata), bukan offset kira-kira. */
/* 0 — tiga layar asli melayang: mulai jualan cuma 4 LANGKAH */
{const S=stage(0,'dark');
  [['02-daftar-terisi',270,-10],['12-pos-siap',540,0],['19-selesai',810,10]].forEach(([src,cx,rot],i)=>{
    const sp=el('sp'),scr=el('scr'),img=el('','','img');img.src='shots/'+src+'.png';img.alt='';scr.append(img);sp.append(scr);S.inn.append(sp);
    gsap.set(sp,{left:cx-315,top:1240-649,scale:.5,transformOrigin:'50% 50%',zIndex:i===1?2:1});
    tl.fromTo(sp,{opacity:0,y:1100,rotation:rot*3},{opacity:1,y:0,rotation:rot,duration:.6,ease:'back.out(1.4)',immediateRender:false},.12+i*.14);
    BOBS.push({e:sp,a:14,f:2.2,ph:i*2.1});});
  cap(S,.1,'mulai jualan cuma','4 langkah',{kwAt:2.65});}

/* 1 — LANGKAH 1: daftar, kolom terisi saat disebut */
{const S=stage(1,'cream'),V=view(S,'01-daftar-kosong');stepN(S,1,4.75);
  focus(V,R.d_email,1.32,6.6,.8);
  type(V,'02-daftar-terisi',R.d_name,7.2);type(V,'02-daftar-terisi',R.d_email,8.1);type(V,'02-daftar-terisi',R.d_wa,9.2);
  type(V,'02-daftar-terisi',R.d_pw,10.05,{dur:.35});type(V,'02-daftar-terisi',R.d_konf,10.4,{dur:.35});
  tl.to(V.cam,{y:'-=180',duration:1.4,ease:'sine.inOut'},9.0);
  cap(S,S.t0+.1,'langkah 1','daftar gratis',{kwAt:1.1});}

/* 2 — tekan DAFTAR SEKARANG */
{const S=stage(2,'green'),V=view(S,'02-daftar-terisi');
  focus(V,R.d_btn,1.55,11.15,.55);ring(V,R.d_btn,11.75);tap(V,R.d_btn,12.3);
  cap(S,S.t0+.1,'lalu tekan','daftar sekarang',{kwAt:.72});}

/* 3 — beri nama TOKO ANDA + jenis usaha */
{const S=stage(3,'purple'),V=view(S,'03-toko-kosong');
  focus(V,R.t_nama,1.4,13.3,.5);type(V,'04-toko-terisi',R.t_nama,13.75,{dur:.6,steps:13});
  focus(V,R.t_fnb,1.35,14.6,.55);tap(V,R.t_fnb,15.3);swap(V,'04-toko-terisi',15.3);ring(V,R.t_fnb,15.4);
  cap(S,S.t0+.1,'beri nama','toko anda',{kwAt:.5});}

/* 4 — toko langsung AKTIF: tekan Buat Produk */
{const S=stage(4,'cream'),V=view(S,'05-dashboard');
  focus(V,R.dash_pop,1.12,16.5,.5);ring(V,R.dash_buat,17.6);tap(V,R.dash_buat,18.8);
  cap(S,S.t0+.1,'toko langsung','aktif',{kwAt:.62});}

/* 5 — LANGKAH 2: tambah produk (nama + kategori) */
{const S=stage(5,'green'),V=view(S,'06-produk-kosong');stepN(S,2,20.1);
  focus(V,R.p_nama,1.45,21.5,.6);type(V,'07-produk-nama',R.p_nama,22.3,{dur:.55,steps:11});
  focus(V,R.p_kat,1.45,22.9,.5);type(V,'07-produk-nama',R.p_kat,23.35,{dur:.3,steps:3});ring(V,R.p_kat,23.45);
  cap(S,S.t0+.1,'langkah 2','tambah produk',{kwAt:1.05});}

/* 6 — harga, modal, stok: UNTUNG TERHITUNG */
{const S=stage(6,'cream'),V=view(S,'07-produk-harga');
  focus(V,R.p_modal,1.38,24.5,.5);
  reveal(V,[44,113,300,30],24.85,{dur:.4});              // harga jual
  reveal(V,[44,219,300,30],25.9,{dur:.35});              // harga modal
  reveal(V,[41,150,200,24],26.3,{dur:.01,cls:'wh'});     // "Keuntungan …%" muncul setelah modal terisi
  reveal(V,[44,301,300,30],26.85,{dur:.2});              // stok awal
  focus(V,R.p_untung,1.75,27.15,.5);ring(V,R.p_untung,27.7);
  cap(S,S.t0+.1,'isi harga & stok,','untung terhitung',{kwAt:3.15});}

/* 7 — semua menu TERSIMPAN */
{const S=stage(7,'orange'),V=view(S,'08-daftar-produk');
  focus(V,R.l_list,1.1,29.8,.6);ring(V,R.l_total,30.9);
  cap(S,S.t0+.1,'semua menu','tersimpan',{kwAt:1.8});}

/* 8 — LANGKAH 3: buka kasir, isi kas awal */
{const S=stage(8,'dark'),V=view(S,'10-buka-kasir');stepN(S,3,33.25);
  focus(V,R.k_kas,1.45,35.3,.6);type(V,'11-kas-awal',R.k_kas,36.0,{dur:.55,steps:7});ring(V,R.k_kas,36.6,37.5);
  focus(V,R.k_buka,1.6,37.4,.5);tap(V,R.k_buka,38.2);
  cap(S,S.t0+.1,'langkah 3','buka kasir',{kwAt:1.35});}

/* 9 — tinggal TAP MENU: Nasi Goreng, Es Teh ×2, keranjang muncul */
{const S=stage(9,'green'),V=view(S,'12-pos-siap');
  focus(V,[16,360,358,420],1.12,39.1,.5);
  tap(V,R.pos_nasi,40.1);ring(V,[16,345,358,100],40.15,40.6);
  tap(V,R.pos_teh,40.55);tap(V,R.pos_teh,40.9);ring(V,[16,670,358,100],40.6,41.3);
  const bar=swap(V,'14-keranjang-isi',41.2,.01);
  const [bx,by,bw,bh]=R.pos_bar,t=by*SK-6,b=1242-(by+bh)*SK-6;
  gsap.set(bar,{clipPath:`inset(1242px 0px ${b}px 0px)`});
  tl.fromTo(bar,{clipPath:`inset(1242px 0px ${b}px 0px)`},{clipPath:`inset(${t}px 0px ${b}px 0px)`,duration:.3,ease:'back.out(1.6)',immediateRender:false},41.2);
  ring(V,R.pos_bar,41.45);
  cap(S,S.t0+.1,'sekarang tinggal','tap menu',{kwAt:1.4});}

/* 10 — cek keranjang, LANJUT BAYAR */
{const S=stage(10,'cream'),V=view(S,'15-keranjang-buka');
  focus(V,R.c_teh,1.28,42.0,.5);ring(V,R.c_nasi,42.3,43.0);ring(V,R.c_teh,42.45,43.0);
  focus(V,R.c_lanjut,1.4,43.0,.45);tap(V,R.c_lanjut,43.5);
  cap(S,S.t0+.1,'cek keranjang,','lanjut bayar',{kwAt:1.3});}

/* 11 — LANGKAH 4: terima uang, kembalian otomatis */
{const S=stage(11,'purple'),V=view(S,'17-pilih-tunai');stepN(S,4,44.7);
  focus(V,R.b_50,1.25,45.2,.5);tap(V,R.b_50,46.8);swap(V,'18-uang-diterima',46.85);
  focus(V,R.b_kembali,1.5,47.3,.5);ring(V,R.b_kembali,47.85,48.9);
  focus(V,R.b_selesai,1.45,48.75,.4);tap(V,R.b_selesai,49.2);
  cap(S,S.t0+.1,'langkah 4','terima uang',{kwAt:1.0});}

/* 12 — struk: transaksi BERHASIL */
{const S=stage(12,'green'),V=view(S,'19-selesai');
  focus(V,R.s_struk,1.18,49.5,.55);tl.to(V.cam,{y:'-=160',duration:1.4,ease:'sine.inOut'},50.1);
  ring(V,R.s_kembali,50.5,51.3);focus(V,R.s_cetak,1.35,51.3,.5);ring(V,R.s_cetak,51.85);
  cap(S,S.t0+.1,'transaksi pertama','berhasil',{kwAt:.85});}

/* 13 — maskot + logo + alamat */
const RISE=[];
{const S=stage(13,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';
  const url=el('url','<span>kasirumkm.web.id</span>');S.inn.append(lg,fox,url);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.65,ease:'back.out(1.5)',immediateRender:false},54.48);tl.set(fox,{opacity:1},54.48);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.6,ease:'back.out(1.8)',immediateRender:false},54.85);
  pop(url,56.85,{from:.4,rot:-6});
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  TAPS.forEach(o=>{const p=clamp((t-o.at)/.45);o.f.style.setProperty('--ripple',p>0&&p<1?String(1-p):'0');o.f.style.setProperty('--rs',String(.6+p*.9));});
  RISE.forEach(o=>{if(t<C[13])return;const y=2100-((o.y0+(t-C[13])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
