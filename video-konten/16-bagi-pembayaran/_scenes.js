/* ---------- batas panggung (dtk) — disetel ulang saat VO masuk ---------- */
/* disinkronkan ke vo.wav (34,12 dtk; awal kalimat: 0.26 3.26 ~6.1 7.94 11.28 14.12 17.96 19.99 22.55 24.66 27.95 29.94) */
const C=[0,3.1,6.0,7.8,11.15,13.95,17.8,19.85,22.4,24.55,27.8,29.8,34.4];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const KQ=i=>Math.min(1,(C[i+1]-C[i])/1.3);
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const SHIRT=['#7943e5','#f97316','#57b01c','#FFC83D','#e2557a'];
const person=(par,i,{x=0,y=0,happy=false,s=1,bag=false}={})=>{const p=el('person',`<svg viewBox="0 0 100 180"><rect x="18" y="86" width="64" height="94" rx="30" fill="${SHIRT[i%5]}" stroke="#3B1F12" stroke-width="6"/><circle cx="50" cy="48" r="34" fill="#FFD9B0" stroke="#3B1F12" stroke-width="6"/><path d="M18 40q32-38 64 0q-10-26-32-26t-32 26z" fill="#3B1F12"/><circle cx="38" cy="50" r="4.5" fill="#3B1F12"/><circle cx="62" cy="50" r="4.5" fill="#3B1F12"/>${happy?'<path d="M38 64q12 12 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>':'<path d="M40 68h20" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>'}${bag?'<path d="M70 120h30l-4 48H74z" fill="#FFE3B8" stroke="#3B1F12" stroke-width="5" stroke-linejoin="round"/><path d="M78 120q7-14 14 0" fill="none" stroke="#3B1F12" stroke-width="5"/>':''}</svg>`);
  p.style.left=x+'px';p.style.top=y+'px';p.style.width=170*s+'px';p.style.height=306*s+'px';par.append(p);return p;};
const billN=(par,label,cls='')=>{const b=el('bill '+cls,`<i></i><b>${label}</b>`);par.append(b);return b;};
/* kode QR tiruan, deterministik */
function qrSVG(){let r='';const N=25,fin=(x,y)=>`<rect x="${x}" y="${y}" width="7" height="7" fill="#3B1F12"/><rect x="${x+1}" y="${y+1}" width="5" height="5" fill="#fff"/><rect x="${x+2}" y="${y+2}" width="3" height="3" fill="#3B1F12"/>`;
  for(let y=0;y<N;y++)for(let x=0;x<N;x++){const inF=(x<8&&y<8)||(x>N-9&&y<8)||(x<8&&y>N-9);if(!inF&&rnd()<.48)r+=`<rect x="${x}" y="${y}" width="1" height="1" fill="#3B1F12"/>`;}
  return `<svg viewBox="-1 -1 ${N+2} ${N+2}" shape-rendering="crispEdges"><rect x="-1" y="-1" width="${N+2}" height="${N+2}" fill="#fff"/>${r}${fin(0,0)}${fin(N-7,0)}${fin(0,N-7)}</svg>`;}
function phone(S,html,{from=1,cls=''}={}){const ph=el('phone '+cls,html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);return ph;}
const rowsIn=(els,at,st=.25)=>tl.fromTo(els,{opacity:0,x:-60},{opacity:1,x:0,duration:.3,ease:'power3.out',stagger:st,immediateRender:false},at);

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — struk belanja: total 150 RIBU */
{const S=stage(0,'dark'),pw=el('paperW'),p=el('paper','<h4>Toko Sembako</h4><div class="prow"><span>Beras 5 kg</span><span>75.000</span></div><div class="prow"><span>Minyak 2 L</span><span>40.000</span></div><div class="prow"><span>Gula 1 kg</span><span>18.000</span></div><div class="prow"><span>Telur 1 kg</span><span>17.000</span></div><div class="ptot"><small>Total belanja</small><b>Rp150.000</b></div>');
  pw.append(p);S.inn.append(pw,el('slot'));
  tl.fromTo(pw,{height:0},{height:860,duration:1.0,ease:'steps(11)',immediateRender:false},.1);
  tl.fromTo($$('.prow',p),{opacity:0,x:-30},{opacity:1,x:0,duration:.25,ease:'power3.out',stagger:.16,immediateRender:false},.5);
  tl.fromTo($('.ptot',p),{opacity:0,y:20},{opacity:1,y:0,duration:.35,ease:'back.out(2)',immediateRender:false},1.2);
  tl.fromTo($('.ptot b',p),{scale:1},{scale:1.14,duration:.14,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},1.6);
  cap(S,.1,'total belanja','150 ribu',{kwAt:1.1});}

/* 1 — dompet: tunainya cuma 80 RIBU */
{const S=stage(1,'cream'),w=el('wallet','<i class="flap"></i>');S.inn.append(w);
  pop(w,S.t0+.02,{from:.6,rot:-8,dur:.4});
  [['50.000',''],['20.000','b2'],['10.000','b3']].forEach(([l,c],i)=>{const b=billN(S.inn,l,c);gsap.set(b,{opacity:0});
    tl.set(b,{opacity:1,left:330,top:1180,rotation:0,scale:.9},S.t0+.35+i*.18);
    tl.to(b,{left:330+(i-1)*300,top:760+Math.abs(i-1)*50,rotation:(i-1)*12,scale:.8,duration:.45,ease:'back.out(1.6)'},S.t0+.35+i*.18);});
  const n=num(S.inn,'mega2',t=>{const k=Math.floor((t-S.t0-.55)/.18);return [0,50000,70000,80000][Math.max(0,Math.min(3,k+1))];});
  tl.fromTo(n,{opacity:0},{opacity:1,duration:.3},S.t0+.5);
  cap(S,S.t0+.1,'tunainya cuma','80 ribu',{kwAt:1.8});}

/* 2 — ponsel QR: sisanya BAYAR QRIS */
{const S=stage(2,'green'),ph=phone(S,`<div class="hd">QRIS</div><div class="qr">${qrSVG()}</div><div class="qamt">Rp70.000</div>`);
  tl.fromTo($('.qr',ph),{opacity:0,scale:.4},{opacity:1,scale:1,duration:.4,ease:'back.out(2)',immediateRender:false},S.t0+.5);
  tl.fromTo($('.qamt',ph),{opacity:0,y:20},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},S.t0+.8);
  const scan=el('scan');$('.qr',ph).append(scan);
  tl.fromTo(scan,{top:'0%'},{top:'96%',duration:.6,yoyo:true,repeat:2,ease:'sine.inOut',immediateRender:false},S.t0+.9);
  cap(S,S.t0+.1,'sisanya mau','bayar qris',{kwAt:.35});}

/* 3 — kasir bingung: TUNAI ATAU QRIS? */
{const S=stage(3,'dark'),k=person(S.inn,0,{x:404,y:820,s:1.6});
  const a=el('opt','Tunai?'),b=el('opt oq','QRIS?');a.style.cssText='left:90px;top:700px';b.style.cssText='left:690px;top:640px';S.inn.append(a,b);
  pop(k,S.t0+.02,{from:.7});pop(a,S.t0+.4,{rot:-16});pop(b,S.t0+.6,{rot:16});
  BOBS.push({e:a,a:18,f:3.2,ph:0},{e:b,a:18,f:3.2,ph:3});
  tl.fromTo(k,{rotation:0},{rotation:6,duration:.25,yoyo:true,repeat:5,ease:'sine.inOut',immediateRender:false},S.t0+.7);
  [[330,560,-14],[760,880,14]].forEach(([x,y,r],i)=>{const q=el('q','?');q.style.left=x+'px';q.style.top=y+'px';q.style.fontSize='200px';S.inn.append(q);pop(q,S.t0+.9+i*.15,{rot:r*3});BOBS.push({e:q,a:14,f:2.6,ph:i*2});});
  cap(S,S.t0+.1,'dicatat sebagai apa?','tunai atau qris?',{kwAt:1.3});}

/* 4 — catatan berantakan: laporan BERANTAKAN */
{const S=stage(4,'orange'),n=el('nota','<div class="nh">Kas hari ini</div><div class="nl"><span>Tunai</span><span>150.000</span></div><div class="nl"><span>QRIS</span><span>70.000</span></div><div class="nl"><span>Total</span><span>220.000 ??</span></div>');
  S.inn.append(n);gsap.set(n,{y:40});pop(n,S.t0+.02,{from:.7,rot:-10,dur:.4});
  const ls=$$('.nl',n);
  ls.forEach((l,i)=>{const s=el('strike');l.style.position='relative';l.append(s);tl.fromTo(s,{scaleX:0},{scaleX:1,duration:.2,ease:'power3.out',immediateRender:false},S.t0+.6+i*.18);});
  const ex=el('qm','!');ex.style.cssText='left:820px;top:560px';S.inn.append(ex);pop(ex,S.t0+1.0,{rot:30});BOBS.push({e:ex,a:14,f:4,ph:1});
  tl.fromTo(n,{rotation:-3},{rotation:3,duration:.05,yoyo:true,repeat:7,ease:'none',immediateRender:false},S.t0+1.1);
  cap(S,S.t0+.1,'salah catat, laporan','berantakan',{kwAt:1.5});}

/* 5 — ponsel kasir: tombol BAGI PEMBAYARAN */
{const S=stage(5,'purple'),ph=phone(S,'<div class="hd">Pembayaran</div><div class="tot0"><small>Total</small><b>Rp150.000</b></div><div class="mbtn">Tunai</div><div class="mbtn split">Bagi Pembayaran</div>');
  rowsIn($$('.mbtn',ph),S.t0+.5,.2);
  const fin=el('finger');S.inn.append(fin);gsap.set(fin,{opacity:0,x:540,y:1500});
  tl.to(fin,{opacity:1,x:560,y:1300,duration:.35,ease:'power2.out'},S.t0+1.8);
  tl.fromTo($('.split',ph),{scale:1},{scale:.92,duration:.08,yoyo:true,repeat:1,immediateRender:false},S.t0+2.2);
  tl.to($('.split',ph),{backgroundColor:'#7943e5',color:'#fff',duration:.15},S.t0+2.25);
  tl.to(fin,{opacity:0,duration:.25},S.t0+2.9);
  cap(S,S.t0+.1,'di kasir umkm ada tombol','bagi pembayaran',{kwAt:2.1});}

/* 6 — isi QRIS 70.000: sisa tunai terhitung */
{const S=stage(6,'green'),ph=phone(S,'<div class="hd">Bagi Pembayaran</div><div class="sline"><span class="sm1">QRIS ▾</span><span class="sm2">Rp <b class="typ"></b></span></div><div class="row"><span>Total non-tunai</span><span class="nt1"></span></div><div class="tot"><span>Sisa dibayar tunai</span><span class="nt2"></span></div>');
  tl.fromTo($('.sline',ph),{opacity:0,x:-60},{opacity:1,x:0,duration:.3,ease:'power3.out',immediateRender:false},S.t0+.4);
  const typ=['7','70','70.0','70.00','70.000'];
  NUMS.push({e:$('.typ',ph),f:t=>Math.max(-1,Math.min(4,Math.floor((t-S.t0-.75)/.12))),fm:v=>v<0?'':typ[v]});
  const nt=t=>clamp((t-S.t0-.75)/.6)>=1?70000:0;
  NUMS.push({e:$('.nt1',ph),f:nt,fm:fmt},{e:$('.nt2',ph),f:t=>150000-nt(t),fm:fmt});
  rowsIn([$('.row',ph),$('.tot',ph)],S.t0+.6,.2);
  tl.fromTo($('.tot',ph),{scale:1},{scale:1.08,duration:.14,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},S.t0+1.4);
  cap(S,S.t0+.1,'isi nominal','qris 70 ribu',{kwAt:.5});}

/* 7 — sisa tunai 80 ribu otomatis, kembalian Rp0 */
{const S=stage(7,'cream'),ph=phone(S,'<div class="hd">Bagi Pembayaran</div><div class="row"><span>QRIS</span><span>Rp70.000</span></div><div class="row hl2"><span>Sisa dibayar tunai</span><span>Rp80.000</span></div><div class="row"><span>Uang diterima</span><span>Rp80.000</span></div><div class="tot"><span>Kembalian</span><span>Rp0</span></div>',{from:-1});
  rowsIn([...$$('.row',ph),$('.tot',ph)],S.t0+.45,.22);
  tl.fromTo($('.hl2',ph),{scale:1},{scale:1.08,duration:.14,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},S.t0+.75);
  cap(S,S.t0+.1,'sisa tunai dihitung','otomatis',{kwAt:1.6});}

/* 8 — scan, bayar, LUNAS */
{const S=stage(8,'purple'),ph=phone(S,`<div class="hd">Transaksi</div><div class="qr sm">${qrSVG()}</div><div class="paid"><span>✓</span>Lunas</div><div class="pl"><b>Tunai</b> Rp80.000 · <b>QRIS</b> Rp70.000</div>`);
  tl.fromTo($('.paid',ph),{opacity:0,scale:.3,rotation:-20},{opacity:1,scale:1,rotation:-6,duration:.45,ease:'back.out(2.6)',immediateRender:false},S.t0+1.45);
  tl.fromTo($('.pl',ph),{opacity:0,y:20},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},S.t0+1.65);
  cap(S,S.t0+.1,'scan, bayar,','lunas',{kwAt:1.4});}

/* 9 — laporan: tunai & non-tunai tetap RAPI */
{const S=stage(9,'green'),r=el('rep','<div class="rh">Laporan hari ini</div><div class="bars"><div class="bc"><i class="b1"></i><span>Tunai</span><b>Rp80.000</b></div><div class="bc"><i class="b2"></i><span>Non-Tunai</span><b>Rp70.000</b></div></div>');
  S.inn.append(r);pop(r,S.t0+.02,{from:.6,rot:-6,dur:.45});
  tl.fromTo($('.b1',r),{height:0},{height:440,duration:.6,ease:'power3.out',immediateRender:false},S.t0+.45);
  tl.fromTo($('.b2',r),{height:0},{height:385,duration:.6,ease:'power3.out',immediateRender:false},S.t0+.6);
  tl.fromTo($$('.bc b',r),{opacity:0,y:20},{opacity:1,y:0,duration:.3,stagger:.15,ease:'back.out(2)',immediateRender:false},S.t0+1.0);
  cap(S,S.t0+.1,'laporan tetap','rapi',{kwAt:2.1});}

/* 10 — pembeli senang, KASIR TENANG */
{const S=stage(10,'cream'),a=person(S.inn,3,{x:150,y:820,happy:true,s:1.6,bag:true}),b=person(S.inn,0,{x:660,y:820,happy:true,s:1.6});
  pop(a,S.t0+.02,{from:.6});pop(b,S.t0+.15,{from:.6});BOBS.push({e:a,a:16,f:5,ph:0},{e:b,a:16,f:5,ph:2});
  [[200,700],[480,620],[760,700]].forEach(([x,y],i)=>{const h=el('heart','♥');h.style.left=x+'px';h.style.top=y+'px';S.inn.append(h);pop(h,S.t0+.5+i*.12,{rot:i%2?20:-20});BOBS.push({e:h,a:14,f:4,ph:i});});
  cap(S,S.t0+.1,'pembeli senang,','kasir tenang',{kwAt:1.0});}

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
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  RISE.forEach(o=>{if(t<C[11])return;const y=2100-((o.y0+(t-C[11])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
