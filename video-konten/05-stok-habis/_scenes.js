/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (34,04 dtk; awal kalimat: 0 3.18 5.80 8.46 10.80 13.40 16.46 19.06 21.86 25.06 ~27.3 30.22) ---------- */
const C=[0,3.05,5.65,8.3,10.6,13.25,16.3,18.9,21.7,24.92,27.1,30.05,35.3];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const SHIRT=['#7943e5','#f97316','#57b01c','#FFC83D','#e2557a'];
const FACE={flat:'<path d="M40 68h20" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',happy:'<path d="M38 64q12 12 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',
  sad:'<path d="M38 72q12-10 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',
  angry:'<path d="M38 72q12-10 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><path d="M30 36l14 6M70 36l-14 6" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',
  think:'<path d="M40 68q6-4 12 0t10 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><path d="M30 34l14 2M70 30l-14 4" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>'};
const person=(par,i,{x=0,y=0,mood='flat',s=1}={})=>{const p=el('person',`<svg viewBox="0 0 100 180"><rect x="18" y="86" width="64" height="94" rx="30" fill="${SHIRT[i%5]}" stroke="#3B1F12" stroke-width="6"/><circle cx="50" cy="48" r="34" fill="#FFD9B0" stroke="#3B1F12" stroke-width="6"/><path d="M18 40q32-38 64 0q-10-26-32-26t-32 26z" fill="#3B1F12"/><circle cx="38" cy="50" r="4.5" fill="#3B1F12"/><circle cx="62" cy="50" r="4.5" fill="#3B1F12"/>${FACE[mood]}</svg>`);
  p.style.left=x+'px';p.style.top=y+'px';p.style.width=170*s+'px';p.style.height=306*s+'px';par.append(p);return p;};
const bubble=(par,txt,x,y,cls='')=>{const b=el('bub '+cls,txt);b.style.left=x+'px';b.style.top=y+'px';par.append(b);return b;};
function phone(S,html,{from=1,cls=''}={}){const ph=el('phone '+cls,html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);return ph;}
/* toples teh: isi (daun teh) bisa diatur 0..1 */
const JAR=(fill)=>`<svg viewBox="0 0 200 240"><rect x="58" y="14" width="84" height="30" rx="10" fill="#7943e5" stroke="#3B1F12" stroke-width="7"/><path d="M44 52h112q14 0 14 16v130q0 26-26 26H56q-26 0-26-26V68q0-16 14-16z" fill="#EAF6FF" stroke="#3B1F12" stroke-width="7"/>
  ${fill>0?`<path d="M38 ${220-150*fill}h124v-0 V198q0 18-18 18H56q-18 0-18-18z" fill="#8a5a2b"/><path d="M50 ${226-150*fill}l12-8 10 10 14-10 12 8 14-8 12 10 14-10 10 8" fill="none" stroke="#6b4220" stroke-width="5"/>`:''}
  <path d="M56 78v100" stroke="#fff" stroke-width="12" stroke-linecap="round" opacity=".8"/><rect x="62" y="120" width="76" height="46" rx="8" fill="#fff" stroke="#3B1F12" stroke-width="5"/><text x="100" y="152" text-anchor="middle" font-family="Outfit" font-weight="900" font-size="26" fill="#3B1F12">TEH</text></svg>`;
const GLASS=`<svg viewBox="0 0 120 160"><path d="M14 14h92l-10 132q-1 8-9 8H33q-8 0-9-8z" fill="#FFB866" stroke="#3B1F12" stroke-width="7" stroke-linejoin="round"/><path d="M18 46h84" stroke="#3B1F12" stroke-width="5"/><rect x="36" y="58" width="22" height="22" rx="4" fill="#fff" opacity=".85" transform="rotate(12 47 69)"/><rect x="62" y="74" width="20" height="20" rx="4" fill="#fff" opacity=".85" transform="rotate(-10 72 84)"/><path d="M76 4l-8 60" stroke="#e2557a" stroke-width="8" stroke-linecap="round"/></svg>`;

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — pembeli datang: ES TEH SATU */
{const S=stage(0,'dark'),p=person(S.inn,1,{x:400,y:880,s:1.6});
  tl.fromTo(p,{opacity:0,x:700},{opacity:1,x:0,duration:.55,ease:'back.out(1.3)',immediateRender:false},.1);
  const b=bubble(S.inn,'Es teh satu, Bu!',330,640);pop(b,.6,{rot:-10});
  const g=el('glassI',GLASS);g.style.cssText='left:120px;top:1180px';S.inn.append(g);pop(g,.9,{rot:-20});
  BOBS.push({e:p,a:10,f:4,ph:0},{e:b,a:12,f:3,ph:1},{e:g,a:16,f:2.6,ph:2});
  cap(S,.1,'pembeli datang,','es teh satu',{kwAt:.7});}

/* 1 — toples dibuka: tehnya HABIS */
{const S=stage(1,'cream'),j=el('jar',JAR(0));S.inn.append(j);
  pop(j,S.t0+.02,{from:.6,rot:-8,dur:.4});
  tl.fromTo(j,{rotation:0},{rotation:8,duration:.07,yoyo:true,repeat:7,ease:'none',immediateRender:false},S.t0+.7);
  const q=el('q','?');q.style.cssText='left:720px;top:620px;font-size:240px';S.inn.append(q);pop(q,S.t0+1.0,{rot:30});BOBS.push({e:q,a:16,f:3,ph:0});
  const ex=el('emp','kosong');S.inn.append(ex);pop(ex,S.t0+1.2,{rot:-12});
  cap(S,S.t0+.1,'pas dicek, tehnya','habis',{kwAt:.9});}

/* 2 — pembeli pergi: REZEKI LEPAS */
{const S=stage(2,'orange'),p=person(S.inn,1,{x:400,y:880,mood:'sad',s:1.6});
  gsap.set(p,{opacity:1});tl.to(p,{x:900,rotation:6,duration:1.4,ease:'power2.in'},S.t0+.6);
  const c=el('coin xl','Rp');S.inn.append(c);gsap.set(c,{left:540,top:1500});
  tl.fromTo(c,{opacity:0,scale:.4},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},S.t0+.2);
  tl.to(c,{y:-900,x:420,rotation:540,duration:1.4,ease:'power2.in'},S.t0+.9);
  const t=el('tag','Rp5.000');t.style.cssText='left:330px;top:640px;background:#fff;color:#c2410c';S.inn.append(t);pop(t,S.t0+.3,{rot:-8});
  tl.to(t,{opacity:0,y:-120,duration:.4},S.t0+1.6);
  cap(S,S.t0+.1,'pembeli pergi,','rezeki lepas',{kwAt:.9});}

/* 3 — kalender: seminggu bisa 3 KALI */
{const S=stage(3,'dark'),days=['Sen','Sel','Rab','Kam','Jum','Sab','Min'],bad=[1,3,5];
  const k=el('cal wk','<div class="ch">Minggu ini</div><div class="cg">'+days.map(d=>`<div class="cd">${d}<em>✕</em></div>`).join('')+'</div>');S.inn.append(k);
  pop(k,S.t0+.02,{from:.6,rot:-6,dur:.4});
  const cds=$$('.cd',k);bad.forEach((d,i)=>{const e=$('em',cds[d]);tl.fromTo(e,{opacity:0,scale:2.4,rotation:-30},{opacity:1,scale:1,rotation:0,duration:.3,ease:'back.out(2.6)',immediateRender:false},S.t0+.35+i*.3);
    tl.fromTo(cds[d],{backgroundColor:'#F4EFFF'},{backgroundColor:'#FFE1D6',duration:.2,immediateRender:false},S.t0+.35+i*.3);});
  const n=num(S.inn,'mega2',t=>Math.max(0,Math.min(3,Math.floor((t-S.t0-.35)/.3)+1)),v=>v+'× habis');tl.fromTo(n,{opacity:0},{opacity:1,duration:.2},S.t0+.35);
  cap(S,S.t0+.1,'seminggu bisa','3 kali',{kwAt:1.05});}

/* 4 — hitungan: sebulan hilang RP300 RIBU */
{const S=stage(4,'purple'),e=el('eqm','<div class="er"><span>12 pembeli</span><i>×</i><span>Rp25.000</span></div><div class="eb"></div>');S.inn.append(e);
  pop(e,S.t0+.02,{from:.6,dur:.4});
  tl.fromTo($$('.er span, .er i',e),{opacity:0,y:30},{opacity:1,y:0,duration:.25,stagger:.12,ease:'back.out(2)',immediateRender:false},S.t0+.3);
  num($('.eb',e),'',t=>clamp((t-S.t0-.6)/.7)*300000,v=>'−'+fmt(v));
  tl.fromTo($('.eb',e),{opacity:0},{opacity:1,duration:.2,immediateRender:false},S.t0+.55);
  tl.fromTo(e,{scale:1},{scale:1.06,duration:.12,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},S.t0+1.35);
  cap(S,S.t0+.1,'sebulan bisa hilang','rp300 ribu',{kwAt:1.1});}

/* 5 — pikiran: stok cuma DIINGAT-INGAT */
{const S=stage(5,'cream'),p=person(S.inn,0,{x:400,y:1180,mood:'think',s:1.6}),th=el('think','<i class="c1"></i><i class="c2"></i><div class="tq">kayaknya</div><div class="tv">masih ada…</div>');S.inn.append(th);
  tl.fromTo(p,{opacity:0,y:300},{opacity:1,y:0,duration:.45,ease:'back.out(1.6)',immediateRender:false},S.t0+.05);
  pop(th,S.t0+.4,{from:.4,rot:-6,dur:.45});
  tl.fromTo(p,{rotation:-5},{rotation:5,duration:.5,yoyo:true,repeat:3,ease:'sine.inOut',immediateRender:false},S.t0+.6);
  cap(S,S.t0+.1,'stoknya cuma','diingat-ingat',{kwAt:1.75});}

/* 6 — ponsel: daftar produk dengan stok — catat di KASIR UMKM */
{const S=stage(6,'green'),ph=phone(S,'<div class="hd">Produk</div>'+[['Es Teh Manis','12 pcs'],['Gula Pasir','3 kg'],['Gelas Plastik','40 pcs'],['Kopi Susu','20 pcs']].map(([a,b])=>`<div class="row"><span>${a}</span><em>Stok: ${b}</em></div>`).join(''));
  tl.fromTo($$('.row',ph),{opacity:0,x:-60},{opacity:1,x:0,duration:.3,ease:'power3.out',stagger:.18,immediateRender:false},S.t0+.5);
  cap(S,S.t0+.1,'catat stoknya di','kasir umkm',{kwAt:1.0});}

/* 7 — terjual: stok BERKURANG SENDIRI */
{const S=stage(7,'cream'),ph=phone(S,'<div class="hd">Kasir</div><div class="tile1"><span>Es Teh Manis</span><b>5.000</b><i class="pl">+</i></div><div class="stk"><small>Stok</small><b></b></div>');
  const fin=el('finger');S.inn.append(fin);gsap.set(fin,{opacity:0});
  [0,1,2].forEach(j=>{const t=S.t0+.8+j*.55;tl.to(fin,{opacity:1,x:745,y:885,duration:.12,ease:'power2.out'},t-.12);
    tl.fromTo($('.tile1',ph),{scale:1},{scale:.92,duration:.07,yoyo:true,repeat:1,immediateRender:false},t);
    const g=el('glassF',GLASS);S.inn.append(g);gsap.set(g,{opacity:0});
    tl.fromTo(g,{opacity:1,x:690,y:820,scale:.5,rotation:0},{x:120+j*90,y:1640,scale:.7,rotation:-20+j*20,duration:.5,ease:'power2.out',immediateRender:false},t+.05);});
  tl.to(fin,{opacity:0,duration:.2},S.t0+2.1);
  num($('.stk b',ph),'',t=>12-Math.max(0,Math.min(3,Math.floor((t-S.t0-.8)/.55)+1)),v=>v+' pcs');
  cap(S,S.t0+.1,'setiap terjual, stok','berkurang sendiri',{kwAt:1.55});}

/* 8 — mendekati stok minimum: masuk STOK RENDAH */
{const S=stage(8,'orange'),gz=el('gauge','<div class="gf"></div><div class="gm"><span>Stok minimum 5</span></div><div class="gv"></div>'),cd=el('srcard','<small>Stok Rendah</small><b>1</b><i>Es Teh Manis</i>');
  S.inn.append(gz,cd);pop(gz,S.t0+.02,{from:.6,dur:.4});
  tl.fromTo($('.gf',gz),{height:'60%'},{height:'20%',duration:1.3,ease:'power2.inOut',immediateRender:false},S.t0+.4);
  num($('.gv',gz),'',t=>Math.round(9-5*clamp((t-S.t0-.4)/1.3)),v=>v+' pcs');
  tl.fromTo($('.gf',gz),{backgroundColor:'#57b01c'},{backgroundColor:'#E0430E',duration:.3,immediateRender:false},S.t0+1.4);
  pop(cd,S.t0+2.0,{from:.3,rot:12,dur:.45});
  tl.fromTo(cd,{rotation:0},{rotation:4,duration:.08,yoyo:true,repeat:5,ease:'none',immediateRender:false},S.t0+2.3);
  cap(S,S.t0+.1,'mau habis? masuk','stok rendah',{kwAt:2.25});}

/* 9 — belanja: sebelum KEHABISAN */
{const S=stage(9,'green'),gz=el('gauge g2','<div class="gf"></div><div class="gm"><span>Stok minimum 5</span></div><div class="gv"></div>');S.inn.append(gz);
  gsap.set($('.gf',gz),{backgroundColor:'#57b01c'});pop(gz,S.t0+.02,{from:.7,dur:.35});
  for(let i=0;i<3;i++){const bx=el('box','<i></i>TEH');S.inn.append(bx);gsap.set(bx,{opacity:0});
    tl.fromTo(bx,{opacity:1,x:50+i*20,y:-300,rotation:-20+i*15},{y:1250-i*130,rotation:-4+i*4,duration:.5,ease:'bounce.out',immediateRender:false},S.t0+.4+i*.3);}
  tl.fromTo($('.gf',gz),{height:'20%'},{height:'90%',duration:1.0,ease:'back.out(1.2)',immediateRender:false},S.t0+.6);
  num($('.gv',gz),'',t=>Math.round(4+26*clamp((t-S.t0-.6)/1.0)),v=>v+' pcs');
  cap(S,S.t0+.1,'belanja sebelum','kehabisan',{kwAt:1.5});}

/* 10 — pembeli datang lagi: barang SELALU ADA */
{const S=stage(10,'cream'),p=person(S.inn,1,{x:520,y:900,mood:'happy',s:1.6});
  tl.fromTo(p,{opacity:0,x:600},{opacity:1,x:0,duration:.5,ease:'back.out(1.4)',immediateRender:false},S.t0+.05);
  const g=el('glassI',GLASS);g.style.cssText='left:250px;top:1080px;width:260px';S.inn.append(g);
  tl.fromTo(g,{opacity:0,x:-500,rotation:-30},{opacity:1,x:0,rotation:-6,duration:.45,ease:'back.out(1.6)',immediateRender:false},S.t0+.5);
  [[300,760,-20],[820,700,20],[700,560,0]].forEach(([x,y,r],i)=>{const h=el('heart','♥');h.style.left=x+'px';h.style.top=y+'px';S.inn.append(h);pop(h,S.t0+.9+i*.15,{rot:r});BOBS.push({e:h,a:14,f:3.4,ph:i});});
  BOBS.push({e:p,a:12,f:5,ph:0});
  cap(S,S.t0+.1,'pembeli datang, barang','selalu ada',{kwAt:1.95});}

/* 11 — maskot + logo */
const RISE=[];
{const S=stage(11,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';S.inn.append(lg,fox);
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
