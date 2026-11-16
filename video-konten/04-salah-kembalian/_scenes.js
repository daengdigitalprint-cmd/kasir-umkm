/* ---------- batas panggung (dtk) — disetel ulang saat VO masuk ---------- */
/* disinkronkan ke vo.wav (35,04 dtk; awal kalimat: 0.21 3.93 6.89 8.64 11.91 14.51 ~17.65 20.34 22.64 ~26.2 28.78 31.61) */
const C=[0,3.8,6.75,8.5,11.75,14.35,17.6,20.2,22.5,26.1,28.6,31.45,35.2];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const KQ=i=>Math.min(1,(C[i+1]-C[i])/1.3);
const SHIRT=['#7943e5','#f97316','#57b01c','#FFC83D','#e2557a'];
const FACE={flat:'<path d="M40 68h20" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',happy:'<path d="M38 64q12 12 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',
  angry:'<path d="M38 72q12-10 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><path d="M30 36l14 6M70 36l-14 6" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',
  panic:'<ellipse cx="50" cy="70" rx="7" ry="9" fill="#3B1F12"/><path d="M84 30q4 10 0 14" fill="none" stroke="#5bb2ff" stroke-width="5" stroke-linecap="round"/>'};
const person=(par,i,{x=0,y=0,mood='flat',s=1}={})=>{const p=el('person',`<svg viewBox="0 0 100 180"><rect x="18" y="86" width="64" height="94" rx="30" fill="${SHIRT[i%5]}" stroke="#3B1F12" stroke-width="6"/><circle cx="50" cy="48" r="34" fill="#FFD9B0" stroke="#3B1F12" stroke-width="6"/><path d="M18 40q32-38 64 0q-10-26-32-26t-32 26z" fill="#3B1F12"/><circle cx="38" cy="50" r="4.5" fill="#3B1F12"/><circle cx="62" cy="50" r="4.5" fill="#3B1F12"/>${FACE[mood]}</svg>`);
  p.style.left=x+'px';p.style.top=y+'px';p.style.width=170*s+'px';p.style.height=306*s+'px';par.append(p);return p;};
const billN=(par,label,cls='')=>{const b=el('bill '+cls,`<i></i><b>${label}</b>`);par.append(b);return b;};
const bubble=(par,txt,x,y,cls='')=>{const b=el('bub '+cls,txt);b.style.left=x+'px';b.style.top=y+'px';par.append(b);return b;};
function phone(S,html,{from=1}={}){const ph=el('phone',html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);return ph;}
/* uang kertas & koin keluar dari bawah, menyebar jadi kipas; angka di bawah ikut menjumlah */
function payout(S,items,at,{gap=.2,y=820,spread=250}={}){const n=items.length;items.forEach(([l,c],i)=>{const isCoin=c==='coin';
  const b=isCoin?el('coin xl','500'):billN(S.inn,l,c);if(isCoin)S.inn.append(b);gsap.set(b,{opacity:0});
  const tx=isCoin?540:330+(i%2?50:-50), ty=isCoin?y+i*175+60:y+i*175;
  tl.set(b,{opacity:1,left:isCoin?540:330,top:1900,rotation:0,scale:.8},at+i*gap);
  tl.to(b,{left:tx,top:ty,rotation:i%2?6:-6,duration:.42,ease:'back.out(1.5)'},at+i*gap);});}

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — antrean penuh, gelembung "Saya dulu!": jam makan siang RAMAI */
{const S=stage(0,'dark');
  const pos=[[60,980],[250,940],[440,1000],[630,950],[820,990],[150,1300],[540,1320],[880,1310]];
  pos.forEach(([x,y],i)=>{const p=person(S.inn,i,{x,y,s:1.05});tl.fromTo(p,{opacity:0,y:300},{opacity:1,y:0,duration:.4,ease:'back.out(1.8)',immediateRender:false},.05+i*.06);BOBS.push({e:p,a:10,f:6,ph:i*1.1});});
  [['Saya dulu!',80,760],['Bungkus 2!',620,700],['Berapa?',360,620]].forEach(([t,x,y],i)=>{const b=bubble(S.inn,t,x,y);pop(b,.6+i*.25,{rot:i%2?12:-12});BOBS.push({e:b,a:12,f:3,ph:i*2});});
  cap(S,.1,'jam makan siang, warung','ramai',{kwAt:3.0});}

/* 1 — struk: total 37.500 */
{const S=stage(1,'cream'),pw=el('paperW'),p=el('paper','<h4>Warung Bu Ani</h4><div class="prow"><span>Nasi ayam</span><span>20.000</span></div><div class="prow"><span>Es jeruk</span><span>7.500</span></div><div class="prow"><span>Kerupuk 2</span><span>4.000</span></div><div class="prow"><span>Sambal extra</span><span>6.000</span></div><div class="ptot"><small>Total</small><b>Rp37.500</b></div>');
  pw.append(p);S.inn.append(pw,el('slot'));
  tl.fromTo(pw,{height:0},{height:860,duration:.9,ease:'steps(10)',immediateRender:false},S.t0+.1);
  tl.fromTo($$('.prow',p),{opacity:0,x:-30},{opacity:1,x:0,duration:.25,ease:'power3.out',stagger:.14,immediateRender:false},S.t0+.45);
  tl.fromTo($('.ptot',p),{opacity:0,y:20},{opacity:1,y:0,duration:.35,ease:'back.out(2)',immediateRender:false},S.t0+1.1);
  tl.fromTo($('.ptot b',p),{scale:1},{scale:1.14,duration:.14,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},S.t0+1.5);
  cap(S,S.t0+.1,'total belanja','37.500',{kwAt:1.2});}

/* 2 — selembar 100 ribu disodorkan */
{const S=stage(2,'green'),b=billN(S.inn,'100.000','b4');gsap.set(b,{opacity:0});
    tl.set(b,{opacity:1,left:330,top:1900,rotation:20,scale:1.5},S.t0+.05);
  tl.to(b,{top:960,rotation:-6,duration:.55,ease:'back.out(1.4)'},S.t0+.05);
  BOBS.push({e:b,a:10,f:3,ph:0});
  cap(S,S.t0+.1,'pembeli bayar','100 ribu',{kwAt:.7});}

/* 3 — kasir panik menghitung di kepala; angka di gelembung pikiran berputar */
{const S=stage(3,'dark'),k=person(S.inn,0,{x:404,y:1080,mood:'panic',s:1.6}),th=el('think','<i class="c1"></i><i class="c2"></i><div class="tq">100.000 − 37.500</div><div class="tv"></div>');S.inn.append(th);
  pop(k,S.t0+.02,{from:.7});pop(th,S.t0+.3,{from:.3,rot:-8});
  const SPIN=['?','52.500','67.500','57.500','72.500','62.000','?','72.500'];
  NUMS.push({e:$('.tv',th),f:t=>Math.max(0,Math.min(SPIN.length-1,Math.floor((t-S.t0-1.0)/.2))),fm:v=>'= '+SPIN[v]});
  tl.fromTo(k,{rotation:0},{rotation:5,duration:.12,yoyo:true,repeat:11,ease:'sine.inOut',immediateRender:false},S.t0+.4);
  cap(S,S.t0+.1,'kembalian dihitung','di kepala',{kwAt:1.7});}

/* 4 — dikasih 72.500 */
{const S=stage(4,'orange');
  payout(S,[['50.000',''],['20.000','b2'],['2.000','b3'],['500','coin']],S.t0+.15,{gap:.18,spread:220,y:760});
  const n=el('mega2');S.inn.append(n);NUMS.push({e:n,f:t=>{const k=Math.floor((t-S.t0-.35)/.18);return [0,50000,70000,72000,72500][Math.max(0,Math.min(4,k+1))];},fm:fmt});
  tl.fromTo(n,{opacity:0},{opacity:1,duration:.3},S.t0+.3);
  cap(S,S.t0+.1,'kembalian dikasih','72.500',{kwAt:.8});}

/* 5 — padahal harusnya 62.500: hitungan benar ditulis, angka lama dicoret */
{const S=stage(5,'cream'),eq=el('eqc','<div class="el">100.000</div><div class="el">− 37.500</div><div class="er"><s>72.500</s><b>62.500</b></div>');S.inn.append(eq);
  pop(eq,S.t0+.02,{from:.6,rot:-6});
  tl.fromTo($('.er s',eq),{'--k':0},{'--k':1,duration:.3,ease:'power3.out',immediateRender:false},S.t0+.7);
  tl.fromTo($('.er b',eq),{opacity:0,scale:2,rotation:-20},{opacity:1,scale:1,rotation:-6,duration:.4,ease:'back.out(2.5)',immediateRender:false},S.t0+1.0);
  cap(S,S.t0+.1,'padahal harusnya','62.500',{kwAt:1.4});}

/* 6 — selisih merah bergetar: RUGI 10 RIBU */
let negEl;
{const S=stage(6,'dark'),b=billN(S.inn,'10.000','b3');gsap.set(b,{opacity:0});
  tl.set(b,{opacity:1,left:330,top:1250,rotation:0,scale:1.2},S.t0+.2);
  tl.to(b,{left:900,top:-300,rotation:60,duration:.9,ease:'power2.in'},S.t0+.3);
  negEl=el('neg','−Rp10.000');S.inn.append(negEl);
  tl.fromTo(negEl,{opacity:0,scale:2.4,filter:'blur(18px)'},{opacity:1,scale:1,filter:'blur(0px)',duration:.5,ease:'expo.out',immediateRender:false},S.t0+.1);
  cap(S,S.t0+.1,'kelebihan, warung','rugi 10 ribu',{kwAt:1.4});}

/* 7 — kalau kurang: pembeli kesal */
{const S=stage(7,'orange'),p=person(S.inn,1,{x:404,y:1000,mood:'angry',s:1.6}),b=bubble(S.inn,'Kok kurang?!',540,760,'red');
  pop(p,S.t0+.02,{from:.7});pop(b,S.t0+.45,{rot:14});BOBS.push({e:b,a:12,f:4,ph:0});
  tl.fromTo(p,{x:0},{x:14,duration:.05,yoyo:true,repeat:9,ease:'none',immediateRender:false},S.t0+.6);
  cap(S,S.t0+.1,'kalau kurang,','pembeli kesal',{kwAt:1.0});}

/* 8 — ponsel kasir: isi UANG DITERIMA */
{const S=stage(8,'green'),ph=phone(S,'<div class="hd">Pembayaran</div><div class="tot0"><small>Total</small><b>Rp37.500</b></div><div class="lbl">Uang diterima</div><div class="inp">Rp <b class="typ"></b><i class="caret"></i></div><div class="chips"><span>Uang pas</span><span>50.000</span><span>100.000</span></div>');
  const typ=['1','10','100','100.0','100.00','100.000'];
  NUMS.push({e:$('.typ',ph),f:t=>Math.max(-1,Math.min(5,Math.floor((t-S.t0-1.9)/.12))),fm:v=>v<0?'':typ[v]});
  tl.fromTo($$('.chips span',ph),{opacity:0,y:20},{opacity:1,y:0,duration:.25,stagger:.08,immediateRender:false},S.t0+.5);
  tl.to($$('.chips span',ph)[2],{backgroundColor:'#7943e5',color:'#fff',duration:.12},S.t0+2.55);
  cap(S,S.t0+.1,'di kasir umkm tinggal isi','uang diterima',{kwAt:2.6});}

/* 9 — kembalian langsung muncul besar */
{const S=stage(9,'cream'),ph=phone(S,'<div class="hd">Pembayaran</div><div class="row"><span>Total</span><span>Rp37.500</span></div><div class="row"><span>Uang diterima</span><span>Rp100.000</span></div><div class="kbig"><small>Kembalian</small><b>Rp62.500</b></div>',{from:-1});
  tl.fromTo($$('.row',ph),{opacity:0,x:-60},{opacity:1,x:0,duration:.3,stagger:.18,ease:'power3.out',immediateRender:false},S.t0+.4);
  tl.fromTo($('.kbig',ph),{opacity:0,scale:.4},{opacity:1,scale:1,duration:.5,ease:'back.out(2.4)',immediateRender:false},S.t0+.85);
  tl.fromTo($('.kbig b',ph),{scale:1},{scale:1.12,duration:.14,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},S.t0+1.4);
  cap(S,S.t0+.1,'kembaliannya','langsung muncul',{kwAt:.85});}

/* 10 — uang sesuai layar, centang: PASTI PAS */
{const S=stage(10,'green');
  payout(S,[['50.000',''],['10.000','b3'],['2.000','b2'],['500','coin']],S.t0+.1,{gap:.16,spread:220,y:760});
  const ok=el('okbig','✓ Rp62.500');S.inn.append(ok);
  tl.fromTo(ok,{opacity:0,scale:.4,rotation:-14},{opacity:1,scale:1,rotation:-4,duration:.45,ease:'back.out(2.4)',immediateRender:false},S.t0+1.6);
  cap(S,S.t0+.1,'kasih sesuai layar,','pasti pas',{kwAt:1.7});}

/* 11 — maskot + logo */
const RISE=[];
{const S=stage(11,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';S.inn.append(lg,fox);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.65,ease:'back.out(1.5)',immediateRender:false},S.t0+.08);tl.set(fox,{opacity:1},S.t0+.08);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.6,ease:'back.out(1.8)',immediateRender:false},S.t0+.5);
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  const sh=clamp((t-C[6]-.6)/.2)*clamp((C[7]-t)/.2);
  negEl.style.marginLeft=(Math.sin(t*43)*9*sh)+'px'; negEl.style.marginTop=(Math.cos(t*37)*6*sh)+'px';
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  $$('.caret').forEach(c=>c.style.opacity=(Math.floor(t*2.5)%2)?0:1);
  RISE.forEach(o=>{if(t<C[11])return;const y=2100-((o.y0+(t-C[11])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
