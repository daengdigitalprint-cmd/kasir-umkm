/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (66,24 dtk) lewat whisper per kata ---------- */
/* Semua waktu di dalam panggung RELATIF terhadap S.t0, jadi cukup ubah array C saat sinkron. */
const C=[0,9.5,11.4,23.85,29.95,36.95,39.5,48.9,53.6,60.8,67.3];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const ease2=p=>1-Math.pow(1-clamp(p),2.4);
const rb=v=>Math.round(v).toLocaleString('id-ID');
function phone(S,html,{from=1,cls=''}={}){const ph=el('phone '+cls,html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);return ph;}

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ---------- objek: mangkuk mie pedas, cabai, telur, struk ---------- */
const MIE=`<svg viewBox="0 0 120 96"><path d="M8 44h104q-4 44-52 48Q12 88 8 44z" fill="#fff" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><path d="M14 44q8-24 46-24t46 24z" fill="#D9341F" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><path d="M24 38q6-10 12 0t12 0 12 0 12 0 12 0 12 0" stroke="#FFD27A" stroke-width="6" fill="none" stroke-linecap="round"/><path d="M30 30q6-8 12 0t12 0 12 0 12 0" stroke="#FFE7A3" stroke-width="5" fill="none" stroke-linecap="round"/><path d="M78 24l12-10" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><path d="M84 22l14-12" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><circle cx="44" cy="26" r="4" fill="#57b01c"/><circle cx="66" cy="23" r="4" fill="#57b01c"/><path d="M38 10q4 8 0 14M60 4q4 8 0 14M82 8q4 8 0 14" stroke="#fff" stroke-width="4" fill="none" stroke-linecap="round" opacity=".7"/></svg>`;
const CABAI=`<svg viewBox="0 0 60 100"><path d="M30 18q-4-12 8-16" stroke="#2d6b10" stroke-width="6" fill="none" stroke-linecap="round"/><path d="M22 20q8-6 18 0q8 30-4 62q-6 14-14 16q-4-24 0-78z" fill="#E4291B" stroke="#3B1F12" stroke-width="5" stroke-linejoin="round"/><path d="M28 30q2 20-2 40" stroke="#FF8A7A" stroke-width="5" fill="none" stroke-linecap="round"/></svg>`;
const TELUR=`<svg viewBox="0 0 120 100"><path d="M10 52q4-40 50-42t50 42q-2 38-50 40T10 52z" fill="#fff" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><circle cx="60" cy="54" r="22" fill="#F7B21B" stroke="#3B1F12" stroke-width="5"/><circle cx="52" cy="46" r="6" fill="#FFE29A"/></svg>`;
const mie=(par,x,y,w=200)=>{const b=el('sbowl',MIE);b.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(b);return b;};
const cabai=(par,x,y,w=90)=>{const c=el('scabai',CABAI);c.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(c);return c;};
const telur=(par,x,y,w=200)=>{const e=el('stelur',TELUR);e.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(e);return e;};
function receipt(par,title,rows,tot,cls=''){const rc=el('hrc '+cls,`<h5>${title}</h5>`+rows.map(([a,b])=>`<div class="r"><span>${a}</span><b>${b}</b></div>`).join('')+(tot?`<div class="tt"><span>${tot}</span><b></b></div>`:''));par.append(rc);return rc;}

/* ================= PANGGUNG ================= */
/* 0 — HOOK: cabai berjatuhan tiap 0,11 dtk (level pedas naik ke 5), mangkuk mie + label Rp12.000,
   untung cuma Rp3.500 menciut, lalu gelembung "Mau tambah telur?" dan stempel +Rp1,2 juta/bulan. */
{const S=stage(0,'dark'),t=S.t0;
  const cs=[];for(let i=0;i<12;i++){const c=cabai(S.inn,70+((i*173)%880),420+((i*97)%260),80+(i%3)*14);gsap.set(c,{opacity:0});cs.push(c);
    tl.fromTo(c,{opacity:1,y:-900,rotation:(i%2?1:-1)*80},{y:0,rotation:(i%2?1:-1)*18,duration:.32,ease:'power2.in',immediateRender:false},t+.08+i*.11);}
  const lv=num(S.inn,'slvl',x=>Math.max(1,Math.min(5,1+Math.floor(4*clamp((x-t-.08)/1.3)))),v=>'level '+v+' 🔥');tl.fromTo(lv,{opacity:0},{opacity:1,duration:.1,immediateRender:false},t+.08);
  const b=mie(S.inn,240,820,600);gsap.set(b,{opacity:0});pop(b,t+.35,{from:.4,rot:-10,dur:.4});BOBS.push({e:b,a:8,f:2.2,ph:0});
  const tg=el('stag','Rp12.000');S.inn.append(tg);gsap.set(tg,{opacity:0});pop(tg,t+.9,{from:.3,rot:-14});
  const un=el('sun','<small>untung</small><b>Rp3.500</b>');S.inn.append(un);gsap.set(un,{opacity:0});pop(un,t+2.85,{from:.5,rot:8});
  tl.to(un,{scale:.78,duration:.3,ease:'power2.inOut'},t+3.5);
  const bb=el('sbub','Mau tambah telur?');S.inn.append(bb);gsap.set(bb,{opacity:0});pop(bb,t+5.3,{from:.3,rot:-6});
  const st=el('sjt','<b>+Rp1,2 juta</b><small>sebulan</small>');S.inn.append(st);gsap.set(st,{opacity:0});
  tl.fromTo(st,{opacity:0,scale:3,rotation:-20},{opacity:1,scale:1,rotation:-6,duration:.25,ease:'power4.in',immediateRender:false},t+7.75);
  tl.fromTo(S.inn,{x:0},{x:18,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},t+8.0);
  tl.to([lv,un],{opacity:0,y:30,duration:.25,ease:'power2.in'},t+4.9);
  cap(S,t+.04,'untungnya cuma Rp3.500,','satu pertanyaan?',{kwAt:5.3});}

/* 1 — judul seri: BEDAH MENU #2, kaca pembesar menyapu mangkuk */
{const S=stage(1,'cream'),t=S.t0,b=mie(S.inn,240,780,600);gsap.set(b,{opacity:0});pop(b,t+.02,{from:.5,rot:-8,dur:.35});
  const tg=el('sseri','<b>BEDAH MENU</b><span>#2 mie pedas</span>');S.inn.append(tg);gsap.set(tg,{opacity:0});pop(tg,t+.4,{from:.4,rot:-10});
  const mg=el('smag','<i></i>');S.inn.append(mg);gsap.set(mg,{opacity:0});
  tl.fromTo(mg,{opacity:1,x:-500,y:100,rotation:-20},{x:120,y:-40,rotation:10,duration:1.2,ease:'sine.inOut',immediateRender:false},t+.1);
  BOBS.push({e:b,a:10,f:2.4,ph:0});}

/* 2 — modal semangkuk: mie, ayam & bumbu, sayur & bawang, kemasan, gas → Rp8.500 */
{const S=stage(2,'orange'),t=S.t0,rc=receipt(S.inn,'Modal 1 mangkuk',[['Mie','2.500'],['Ayam & bumbu','3.500'],['Sayur & bawang','1.000'],['Kemasan','1.000'],['Gas','500']],'Modal','m5');
  pop(rc,t+.02,{from:.7,dur:.35});
  $$('.r',rc).forEach((r,i)=>{gsap.set(r,{opacity:0});tl.fromTo(r,{opacity:0,x:-60},{opacity:1,x:0,duration:.25,ease:'power3.out',immediateRender:false},t+[1.1,2.7,5.4,7.4,8.75][i]);
    tl.fromTo(r,{backgroundColor:'#FFE3B8'},{backgroundColor:'rgba(255,227,184,0)',duration:.8,immediateRender:false},t+[1.1,2.7,5.4,7.4,8.75][i]);});
  const tt=$('.tt',rc);gsap.set(tt,{opacity:0});tl.fromTo(tt,{opacity:0,scale:1.3},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+10.25);
  num($('b',tt),'',x=>Math.round(8500*ease2((x-t-10.3)/.8)/100)*100,v=>'Rp'+rb(v));
  cap(S,t+.08,'modal semangkuk','rp8.500',{kwAt:10.5});}

/* 3 — untung per mangkuk Rp3.500, kurang dari sepertiga harga */
{const S=stage(3,'green'),t=S.t0,b=mie(S.inn,300,600,480);gsap.set(b,{opacity:0});pop(b,t+.05,{from:.5,rot:6,dur:.35});BOBS.push({e:b,a:8,f:2.4,ph:1});
  const u=el('sper','<span>12.000 − 8.500 =</span><b>3.500</b><small>untung per mangkuk</small>');S.inn.append(u);gsap.set(u,{opacity:0});
  tl.fromTo(u,{opacity:0,y:60,rotation:6},{opacity:1,y:0,rotation:-3,duration:.35,ease:'back.out(2)',immediateRender:false},t+2.0);
  const pc=el('spct','29%');S.inn.append(pc);gsap.set(pc,{opacity:0});
  tl.fromTo(pc,{opacity:0,scale:3,rotation:-30},{opacity:1,scale:1,rotation:-10,duration:.3,ease:'back.out(2.2)',immediateRender:false},t+4.85);
  cap(S,t+.08,'untung per mangkuk','rp3.500',{kwAt:2.25});}

/* 4 — telur tambahan: jual Rp4.000, modal Rp2.000 → untung separuh harga (margin 50% vs mie 29%) */
{const S=stage(4,'purple'),t=S.t0,e=telur(S.inn,330,520,420);gsap.set(e,{opacity:0});pop(e,t+.05,{from:.3,rot:-14,dur:.4});BOBS.push({e,a:10,f:2.6,ph:0});
  [['jual','Rp4.000',2.3,'a'],['modal','Rp2.000',4.05,'b']].forEach(([a,v,at,k])=>{const c=el('sjm '+k,`<span>${a}</span><b>${v}</b>`);S.inn.append(c);gsap.set(c,{opacity:0});pop(c,t+at,{from:.4,rot:k==='a'?-8:8});});
  const mg=el('smg','<div class="g m"><span>untung mie</span><i><em></em></i><b>29%</b></div><div class="g e"><span>untung telur</span><i><em></em></i><b>50%</b></div>');S.inn.append(mg);gsap.set(mg,{opacity:0});gsap.set($$('em',mg),{width:'0%'});
  tl.fromTo(mg,{opacity:0,y:40},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},t+5.2);
  tl.fromTo($('.m em',mg),{width:'0%'},{width:'29%',duration:.5,ease:'power2.out',immediateRender:false},t+5.3);
  tl.fromTo($('.e em',mg),{width:'0%'},{width:'50%',duration:.6,ease:'power2.out',immediateRender:false},t+5.5);
  cap(S,t+.08,'tambah telur, untungnya','separuh harga',{kwAt:5.8});}

/* 5 — satu pertanyaan: kasir bertanya, pembeli menjawab */
{const S=stage(5,'cream'),t=S.t0;
  const q=el('sbub big','Mau tambah telur?');S.inn.append(q);gsap.set(q,{opacity:0});pop(q,t+1.55,{from:.3,rot:-6});
  const a=el('sbub rep','Boleh!');S.inn.append(a);gsap.set(a,{opacity:0});pop(a,t+2.05,{from:.3,rot:8});
  const e=telur(S.inn,700,1500,240);gsap.set(e,{opacity:0});pop(e,t+2.15,{from:.2,rot:20});
  cap(S,t+.08,'cukup satu pertanyaan','mau tambah telur?',{kwAt:1.55});}

/* 6 — MOMEN: 40 mangkuk, separuh tambah telur → +Rp40.000 sehari → +Rp1,2 juta sebulan */
{const S=stage(6,'dark'),t=S.t0;
  const G=[];for(let i=0;i<40;i++){const b=mie(S.inn,70+(i%8)*120,540+Math.floor(i/8)*92,110);gsap.set(b,{opacity:0});G.push(b);}
  tl.fromTo(G,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.2,stagger:.03,ease:'back.out(2.4)',immediateRender:false},t+.3);
  const cnt=num(S.inn,'scnt',x=>Math.max(0,Math.min(40,Math.round(40*clamp((x-t-.3)/1.2)))),v=>v+' mangkuk');tl.fromTo(cnt,{opacity:0},{opacity:1,duration:.1,immediateRender:false},t+.3);
  G.filter((_,i)=>i%2===0).forEach((b,i)=>{const d=el('seb');b.append(d);gsap.set(d,{opacity:0});pop(d,t+2.4+i*.04,{from:.2});});
  const eq=el('bigeq sm','<span>20</span><i>×</i><span>2.000</span>');S.inn.append(eq);
  $$('span, i',eq).forEach((x,i)=>{gsap.set(x,{opacity:0});tl.fromTo(x,{opacity:0,y:60,scale:.6},{opacity:1,y:0,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+[3.5,3.7,3.9][i]);});
  tl.set(eq,{opacity:1},t+3.5);
  const n=num(S.inn,'mega2',x=>Math.round(40000*ease2((x-t-4.85)/.6)/1000)*1000,v=>'+Rp'+rb(v)+'/hari');tl.fromTo(n,{opacity:0,scale:1.5},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},t+4.8);
  tl.to([eq,n,cnt],{opacity:0,y:-40,duration:.25,ease:'power2.in'},t+6.45);
  const big=el('sjt mm','<b>+Rp1.200.000</b><small>sebulan</small>');S.inn.append(big);gsap.set(big,{opacity:0});
  tl.fromTo(big,{opacity:0,scale:3,rotation:-20},{opacity:1,scale:1,rotation:-5,duration:.28,ease:'power4.in',immediateRender:false},t+7.1);
  tl.fromTo(big,{scale:1},{scale:1.08,duration:.12,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},t+7.4);
  [[120,1700],[930,1680],[220,1840],[860,1850],[540,1880]].forEach(([x,y],i)=>{const sp=el('spk','✦');sp.style.left=x+'px';sp.style.top=y+'px';S.inn.append(sp);pop(sp,t+7.2+i*.05,{from:.2,rot:90});BOBS.push({e:sp,a:12,f:4,ph:i});});
  cap(S,t+.08,'separuh pembeli mau, sehari','+rp40 ribu',{kwAt:4.8,out:t+6.45});
  cap(S,t+6.53,'sebulan','+rp1,2 juta',{kwAt:.55});}

/* 7 — pelajaran: tawarkan di setiap pesanan, jangan tunggu diminta */
{const S=stage(7,'green'),t=S.t0,a=el('scmp yes','<i>✓</i><span>tawarkan tiap pesanan</span>'),b=el('scmp no','<i>✕</i><span>tunggu diminta</span>');
  a.style.top='700px';b.style.top='1080px';S.inn.append(a,b);[a,b].forEach(e=>gsap.set(e,{opacity:0}));
  pop(a,t+.6,{from:.4,rot:-10});pop(b,t+2.75,{from:.4,rot:10});
  tl.fromTo($('i',a),{scale:0},{scale:1,duration:.25,ease:'back.out(3)',immediateRender:false},t+.85);tl.fromTo($('i',b),{scale:0},{scale:1,duration:.25,ease:'back.out(3)',immediateRender:false},t+3.1);
  tl.to(b,{opacity:.45,filter:'grayscale(1)',duration:.3},t+4.0);BOBS.push({e:a,a:10,f:2.6,ph:0});
  cap(S,t+.08,'jangan tunggu diminta,','tawarkan',{kwAt:.85});}

/* 8 — di aplikasi: keranjang kasir + "Tawarkan juga:" (Saran Menu), telur ditambahkan, total naik */
{const S=stage(8,'purple'),t=S.t0,ph=phone(S,'<div class="hd">Keranjang</div>'
   +'<div class="row ci"><span>Mie Pedas</span><b>Rp12.000</b></div>'
   +'<div class="row ci added"><span>Telur</span><b>Rp4.000</b></div>'
   +'<div class="stw"><p>Tawarkan juga:</p><div class="sch t">+ Telur</div><div class="sch">+ Pangsit</div><div class="sch">+ Es Teh</div></div>'
   +'<div class="row tot"><span>Total</span><b></b></div>',{cls:'hph'});
  const add=$('.added',ph),pan=$('.stw',ph),chips=$$('.sch',pan),tch=$('.sch.t',pan);
  gsap.set([add,pan,...chips],{opacity:0});
  tl.fromTo(pan,{opacity:0,y:30},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},t+1.9);
  tl.fromTo(chips,{opacity:0,scale:.5},{opacity:1,scale:1,duration:.25,stagger:.12,ease:'back.out(2.4)',immediateRender:false},t+2.1);
  const hand=el('shand phh','👆');S.inn.append(hand);gsap.set(hand,{opacity:0});
  tl.fromTo(hand,{opacity:1,x:300,y:500},{x:0,y:0,duration:.5,ease:'power3.out',immediateRender:false},t+3.4);
  tl.fromTo(hand,{scale:1},{scale:.82,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},t+4.0);
  tl.fromTo(tch,{scale:1},{scale:.9,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},t+4.0);
  tl.fromTo(add,{opacity:0,x:-40},{opacity:1,x:0,duration:.3,ease:'power3.out',immediateRender:false},t+4.15);
  tl.fromTo(add,{backgroundColor:'#DCFCE7'},{backgroundColor:'#f1f5f9',duration:1.2,immediateRender:false},t+4.15);
  tl.to(hand,{opacity:0,y:200,duration:.3},t+4.8);
  num($('.tot b',ph),'',x=>12000+Math.round(4000*ease2((x-t-4.2)/.5)/100)*100,v=>'Rp'+rb(v));
  cap(S,t+.08,'saran menu muncul langsung','di keranjang kasir',{kwAt:5.7});}

/* 9 — AJAKAN FOLLOW: kartu profil @kasir_umkm, jari mengetuk "Ikuti" → "Mengikuti", maskot di bawah */
{const S=stage(9,'purple'),t=S.t0;
  const pf=el('sprof','<div class="top"><div class="av"><img src="assets/avatar.png" alt=""></div><div class="nm"><b>Kasir UMKM</b><span>@kasir_umkm</span></div></div><div class="bio">Tips hitung untung &amp; bedah menu untuk UMKM</div><div class="btn"><span class="f">Ikuti</span><span class="d">Mengikuti</span></div>');
  S.inn.append(pf);gsap.set(pf,{opacity:0});tl.fromTo(pf,{opacity:1,y:-900,rotation:-6},{y:0,rotation:-2,duration:.55,ease:'back.out(1.5)',immediateRender:false},t+.05);
  const btn=$('.btn',pf),d=$('.d',pf),f=$('.f',pf);gsap.set(d,{opacity:0});
  const hand=el('shand','👆');S.inn.append(hand);gsap.set(hand,{opacity:0});
  tl.fromTo(hand,{opacity:1,x:300,y:500},{x:0,y:0,duration:.5,ease:'power3.out',immediateRender:false},t+1.15);
  tl.fromTo(hand,{scale:1},{scale:.82,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},t+1.6);
  tl.fromTo(btn,{scale:1},{scale:.92,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},t+1.6);
  tl.set(f,{opacity:0},t+1.7);tl.set(d,{opacity:1},t+1.7);tl.set(btn,{backgroundColor:'#EFEFEF',borderColor:'#DBDBDB'},t+1.7);
  const rg=el('sring');btn.append(rg);tl.fromTo(rg,{opacity:.9,scale:.6},{opacity:0,scale:1.8,duration:.5,ease:'power2.out',immediateRender:false},t+1.7);
  tl.to(hand,{opacity:0,y:200,duration:.3},t+2.5);
  const fox=el('fox sfox','','img');fox.src='assets/maskot.webp';fox.alt='';S.inn.append(fox);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},t+.4);tl.set(fox,{opacity:1},t+.4);BOBS.push({e:fox,a:14,f:2.6,ph:0});
  for(let i=0;i<6;i++){const h=el('sheart','♥');h.style.left=(560+i*60)+'px';S.inn.append(h);gsap.set(h,{opacity:0});
    tl.fromTo(h,{opacity:1,y:0,scale:.4},{opacity:0,y:-380-i*30,scale:1.3,x:(i%2?1:-1)*80,duration:1.2,ease:'power1.out',immediateRender:false},t+1.75+i*.08);}
  cap(S,t+.08,'mau bedah menu lainnya?','follow kasir umkm',{kwAt:1.55,out:t+3.05});
  cap(S,t+3.13,'biar nggak ketinggalan','bedah menu berikutnya',{kwAt:.95});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
}
