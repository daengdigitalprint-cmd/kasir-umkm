/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (48,28 dtk) lewat transkripsi per kata ---------- */
/* kata pembuka: Jualan 0 · Kok bisa 3.96 · Contoh 7.86 · Modal 12.06 · Biaya 16.24 · Dibagi 23.46 · 120 ribu 27.24 · Di bawah 32.02 · Di Kasir 37.06 · Kasir 43.66 */
const C=[0,3.85,7.7,11.9,16.1,23.3,27.05,31.85,36.9,43.5,49.4];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const ease2=p=>1-Math.pow(1-clamp(p),2.4);
const rb=v=>Math.round(v).toLocaleString('id-ID');
const qmark=(par,x,y,size=150)=>{const q=el('qs','?');q.style.cssText=`left:${x}px;top:${y}px;width:${size}px;height:${size}px;font-size:${size*.72}px;line-height:${size*.86}px`;par.append(q);return q;};
function phone(S,html,{from=1,cls=''}={}){const ph=el('phone '+cls,html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);return ph;}
const BOWL=`<svg viewBox="0 0 120 90"><path d="M10 40h100q-4 40-50 44Q14 80 10 40z" fill="#fff" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><path d="M16 40q10-26 44-26t44 26z" fill="#f5a524" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><circle cx="44" cy="28" r="5" fill="#e2557a"/><circle cx="70" cy="24" r="5" fill="#57b01c"/><path d="M84 6l-20 30" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/></svg>`;
const bowl=(par,x,y,w=200)=>{const b=el('bowl',BOWL);b.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(b);return b;};

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — HOOK (0–3 dtk): 12 mangkuk bermunculan cepat, penghitung naik, lalu stempel RUGI menghantam.
   Gerak baru tiap ±0,15 dtk supaya mata tidak sempat lepas. */
{const S=stage(0,'dark');
  const bs=[];for(let i=0;i<12;i++){const c=i%4,r=Math.floor(i/4),b=bowl(S.inn,90+c*230,800+r*200,190);gsap.set(b,{opacity:0});bs.push(b);
    tl.fromTo(b,{opacity:0,scale:.2,y:-200,rotation:(i%2?1:-1)*30},{opacity:1,scale:1,y:0,rotation:(i%2?1:-1)*4,duration:.28,ease:'back.out(2.6)',immediateRender:false},.08+i*.13);}
  const n=num(S.inn,'porsiN',t=>Math.max(0,Math.min(12,Math.floor((t-.08)/.13)+1)),v=>v+' porsi');tl.fromTo(n,{opacity:0},{opacity:1,duration:.1},.08);
  const st=el('stamp','RUGI');S.inn.append(st);
  tl.fromTo(st,{opacity:0,scale:3.2,rotation:-30},{opacity:1,scale:1,rotation:-12,duration:.22,ease:'power4.in',immediateRender:false},2.95);
  tl.fromTo(S.inn,{x:0},{x:18,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},3.17);
  tl.to(bs,{filter:'grayscale(1) brightness(.75)',duration:.25},3.17);
  /* sambil menunggu kata ternyata, mangkuk bergoyang supaya layar tidak diam */
  tl.fromTo(bs,{rotation:0},{rotation:6,duration:.12,yoyo:true,repeat:7,stagger:.02,ease:'sine.inOut',immediateRender:false},1.75);
  cap(S,.04,'jualan 12 porsi sehari,','masih rugi?',{kwAt:2.84});}

/* 1 — kok bisa? belum tahu TITIK IMPAS: jungkat-jungkit miring */
{const S=stage(1,'cream'),sw=el('seesaw','<i class="beam"><b class="l">Untung</b><b class="r">Biaya</b></i><i class="piv"></i>');S.inn.append(sw);
  pop(sw,S.t0+.02,{from:.6,dur:.35});
  tl.fromTo($('.beam',sw),{rotation:0},{rotation:14,duration:.6,ease:'back.out(2)',immediateRender:false},S.t0+.4);
  tl.to($('.beam',sw),{rotation:0,duration:.6,ease:'elastic.out(1,.5)'},S.t0+2.65);
  const q=qmark(S.inn,800,600,150);pop(q,S.t0+.3,{rot:30});BOBS.push({e:q,a:14,f:3,ph:0});
  cap(S,S.t0+.08,'karena belum tahu','titik impas',{kwAt:2.22});}

/* 2 — harga jual RP15 RIBU */
{const S=stage(2,'orange'),b=bowl(S.inn,340,820,400),tg=el('ptag','<i></i><b>Rp15.000</b>');S.inn.append(tg);gsap.set(b,{opacity:0});
  pop(b,S.t0+.02,{from:.5,rot:-10,dur:.4});pop(tg,9.8,{from:.4,rot:14});BOBS.push({e:b,a:10,f:2.4,ph:0});
  cap(S,S.t0+.08,'contoh nasi goreng, harga','rp15 ribu',{kwAt:2.77});}

/* 3 — modal Rp7.000 → untung per porsi RP8 RIBU */
{const S=stage(3,'green'),eq=el('eqm','<div class="er"><span>15.000</span><i>−</i><span class="md">7.000</span></div><div class="ex">modal bahan</div><div class="eb">= 8.000</div>');S.inn.append(eq);
  pop(eq,S.t0+.02,{from:.6,dur:.35});
  $$('.er > *',eq).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:30},{opacity:1,y:0,duration:.25,ease:'back.out(2)',immediateRender:false},[12.0,12.35,12.65][i]);});
  gsap.set($('.ex',eq),{opacity:0});tl.fromTo($('.ex',eq),{opacity:0},{opacity:1,duration:.2,immediateRender:false},12.3);
  gsap.set($('.eb',eq),{opacity:0});tl.fromTo($('.eb',eq),{opacity:0,scale:1.6},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},14.95);
  cap(S,S.t0+.08,'untung per porsi','rp8 ribu',{kwAt:2.98});}

/* 4 — biaya tetap sebulan RP3,6 JUTA */
{const S=stage(4,'purple'),L=[['🏠','Sewa','1.500.000'],['⚡','Listrik & gas','600.000'],['👤','Gaji karyawan','1.500.000']];
  const cs=L.map(([ic,a,b],i)=>{const c=el('costc',`<em>${ic}</em><span>${a}</span><b>${b}</b>`);c.style.top=(640+i*190)+'px';S.inn.append(c);gsap.set(c,{opacity:0});
    tl.fromTo(c,{opacity:0,x:-700,rotation:-8},{opacity:1,x:0,rotation:(i-1)*2,duration:.4,ease:'back.out(1.6)',immediateRender:false},[17.7,18.5,19.95][i]);return c;});
  const tot=el('costT','<span>Total</span><b></b>');S.inn.append(tot);gsap.set(tot,{opacity:0});
  num($('b',tot),'',t=>Math.round(3600000*ease2((t-20.95)/1.3)/50000)*50000,v=>rb(v));
  tl.fromTo(tot,{opacity:0,scale:1.4},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},20.85);
  cap(S,S.t0+.08,'biaya tetap sebulan','rp3,6 juta',{kwAt:5.32});}

/* 5 — dibagi 30 hari = RP120 RIBU sehari */
{const S=stage(5,'cream'),k=el('cal m30','<div class="ch">30 hari</div><div class="cg">'+Array.from({length:30},(_,i)=>`<div class="cd">${i+1}</div>`).join('')+'</div>');S.inn.append(k);
  pop(k,S.t0+.02,{from:.6,rot:-6,dur:.35});
  tl.fromTo($$('.cd',k),{backgroundColor:'#F4EFFF'},{backgroundColor:'#FFE3B8',duration:.08,stagger:.03,immediateRender:false},S.t0+.4);
  const n=num(S.inn,'mega2',t=>Math.round(120000*ease2((t-25.1)/.55)/1000)*1000,v=>'Rp'+rb(v)+'/hari');tl.fromTo(n,{opacity:0},{opacity:1,duration:.2},25.05);
  cap(S,S.t0+.08,'dibagi 30 hari, jadi','rp120 ribu',{kwAt:1.72});}

/* 6 — MOMEN: 120.000 ÷ 8.000 = 15 PORSI */
{const S=stage(6,'dark'),eq=el('bigeq','<span class="q1">120.000</span><i>÷</i><span class="q2">8.000</span>'),res=el('res15','<b>15</b><span>porsi</span>');S.inn.append(eq,res);
  $$('.bigeq > *',eq).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:60,scale:.6},{opacity:1,y:0,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},[27.2,28.35,28.75][i]);});
  tl.set(eq,{opacity:1},S.t0+.2);
  tl.fromTo(res,{opacity:0,scale:3,rotation:-20},{opacity:1,scale:1,rotation:-4,duration:.35,ease:'back.out(2.2)',immediateRender:false},30.35);
  tl.fromTo(res,{scale:1},{scale:1.12,duration:.12,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},30.75);
  const SPK=[[150,1000],[880,980],[110,1250],[920,1260],[170,1500],[860,1520],[330,1640],[690,1650],[420,880],[620,890]];
  for(let i=0;i<10;i++){const sp=el('spk','✦');sp.style.left=SPK[i][0]+'px';sp.style.top=SPK[i][1]+'px';S.inn.append(sp);pop(sp,30.45+i*.04,{from:.2,rot:90});BOBS.push({e:sp,a:12,f:4,ph:i});}
  cap(S,S.t0+.08,'titik impasnya','15 porsi',{kwAt:3.22});}

/* 7 — di bawah 15 RUGI, lewat 15 BARU UNTUNG: penggaris porsi dengan penanda bergerak */
{const S=stage(7,'green'),bar=el('ruler','<div class="zr">rugi</div><div class="zu">untung</div><div class="mk15">15</div>'),pin=el('pin','<b></b>');S.inn.append(bar,pin);
  pop(bar,S.t0+.02,{from:.7,dur:.35});
  const X=p=>110+p*(860/25);
  const P=t=>t<34.6?15*clamp((t-32.0)/2.6):15+10*clamp((t-34.6)/1.4);
  num($('b',pin),'',t=>Math.round(P(t)),v=>v);
  tl.fromTo(pin,{x:X(0)},{x:X(15),duration:2.6,ease:'none',immediateRender:false},32.0);tl.to(pin,{x:X(25),duration:1.4,ease:'none'},34.6);tl.set(pin,{opacity:1},32.0);
  tl.fromTo($('b',pin),{backgroundColor:'#E0430E'},{backgroundColor:'#2d8a12',duration:.15,immediateRender:false},34.6);
  cap(S,S.t0+.08,'di bawah 15 porsi','rugi',{kwAt:1.57,out:34.45});
  cap(S,34.55,'lewat dari itu,','baru untung',{kwAt:1.05});}

/* 8 — di aplikasi: harga modal & biaya usaha tercatat (istilah dari halaman asli) */
{const S=stage(8,'purple'),ph=phone(S,'<div class="hd">Nasi Goreng</div><div class="fl"><small>Harga Jual</small><b>15.000</b></div><div class="fk">Keuntungan 114.29% dari modal</div><div class="fl"><small>Harga modal</small><b>7.000</b></div><div class="fl biaya"><small>Biaya Usaha / bulan</small><b>3.600.000</b></div>');
  $$('.fl, .fk',ph).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,x:-50},{opacity:1,x:0,duration:.3,ease:'power3.out',immediateRender:false},[37.5,37.75,38.4,39.35][i]);});
  cap(S,S.t0+.08,'di kasir umkm, semua','tercatat',{kwAt:3.0,out:40.8});
  cap(S,40.88,'angka ini','gampang dihitung',{kwAt:1.1});}

/* 9 — maskot + logo */
const RISE=[];
{const S=stage(9,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';
  const cta=el('cta','<span>Tahu target harianmu</span>');S.inn.append(lg,fox,cta);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},S.t0+.05);tl.set(fox,{opacity:1},S.t0+.05);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.55,ease:'back.out(1.8)',immediateRender:false},S.t0+.3);
  pop(cta,45.25,{from:.4,rot:-6});
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  RISE.forEach(o=>{if(t<C[9])return;const y=2100-((o.y0+(t-C[9])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
