/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (±69,2 dtk) lewat whisper per kata ---------- */
/* Semua waktu di dalam panggung RELATIF terhadap S.t0, jadi cukup ubah array C saat sinkron. */
const C=[0,10.8,15.4,31.6,38.5,47.4,53.0,59.4,66.1,70.3];
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

/* ---------- objek: gelas es kopi susu (gerobak & kafe), karton susu, gerobak, struk ---------- */
const KOPI=(kafe)=>`<svg viewBox="0 0 200 330"><rect x="114" y="0" width="20" height="160" rx="10" fill="${kafe?'#fff':'#a0ea71'}" stroke="#3B1F12" stroke-width="6" transform="rotate(10 124 80)"/>
<path d="M21 72h158l-5 70H26z" fill="#5A2E14"/><path d="M26 142h148l-8 104H34z" fill="#C98F5A"/><path d="M34 246h132l-4 50H38z" fill="#7A3B12"/>
<rect x="52" y="96" width="38" height="38" rx="8" fill="#fff" opacity=".55" transform="rotate(-12 71 115)"/><rect x="104" y="112" width="34" height="34" rx="8" fill="#fff" opacity=".5" transform="rotate(14 121 129)"/><rect x="74" y="160" width="36" height="36" rx="8" fill="#fff" opacity=".4"/>
${kafe?'<path d="M30 150h140l-6 70H36z" fill="#2E7D4F" stroke="#3B1F12" stroke-width="6"/><circle cx="100" cy="185" r="24" fill="#fff" stroke="#3B1F12" stroke-width="5"/><path d="M88 185q12-16 24 0q-12 16-24 0z" fill="#2E7D4F"/>':'<circle cx="100" cy="200" r="30" fill="#7943e5" stroke="#3B1F12" stroke-width="5"/><path d="M86 200h28M100 186v28" stroke="#fff" stroke-width="7" stroke-linecap="round"/>'}
<path d="M30 80L44 290" stroke="#fff" stroke-width="10" stroke-linecap="round" opacity=".5"/>
<path d="M20 60h160l-18 240H38z" fill="none" stroke="#3B1F12" stroke-width="9" stroke-linejoin="round"/>
${kafe?'<path d="M12 62q88-60 176 0z" fill="rgba(255,255,255,.7)" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/>':'<rect x="12" y="50" width="176" height="18" rx="8" fill="#fff" stroke="#3B1F12" stroke-width="7"/>'}</svg>`;
const SUSU=`<svg viewBox="0 0 140 230"><path d="M20 66l30-40h40l30 40v150H20z" fill="#fff" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/><path d="M52 26V8h36v18" fill="#7DC4F5" stroke="#3B1F12" stroke-width="7" stroke-linejoin="round"/><path d="M24 116h92v64H24z" fill="#7DC4F5"/><text x="70" y="160" font-family="Outfit" font-weight="900" font-size="34" text-anchor="middle" fill="#fff">SUSU</text><path d="M20 66h100" stroke="#3B1F12" stroke-width="7"/><path d="M20 66l30-40h40l30 40v150H20z" fill="none" stroke="#3B1F12" stroke-width="8" stroke-linejoin="round"/></svg>`;
const GEROBAK=`<svg viewBox="0 0 260 210"><rect x="34" y="14" width="170" height="34" rx="10" fill="#7943e5" stroke="#3B1F12" stroke-width="7"/><path d="M56 48v46M182 48v46" stroke="#3B1F12" stroke-width="8"/><rect x="20" y="94" width="196" height="76" rx="14" fill="#FFC83D" stroke="#3B1F12" stroke-width="7"/><path d="M60 118h116" stroke="#3B1F12" stroke-width="6" stroke-linecap="round" opacity=".5"/><circle cx="62" cy="180" r="22" fill="#fff" stroke="#3B1F12" stroke-width="7"/><circle cx="174" cy="180" r="22" fill="#fff" stroke="#3B1F12" stroke-width="7"/><path d="M216 112h34" stroke="#3B1F12" stroke-width="9" stroke-linecap="round"/></svg>`;
const kopi=(par,x,y,w=300,kafe=false)=>{const b=el('skop',KOPI(kafe));b.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(b);return b;};
const susu=(par,x,y,w=280)=>{const b=el('skop',SUSU);b.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(b);return b;};
const gerobak=(par,x,y,w=420)=>{const b=el('skop',GEROBAK);b.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(b);return b;};
const tag=(par,txt,x,y)=>{const g=el('stag',txt);g.style.left=x+'px';g.style.top=y+'px';par.append(g);gsap.set(g,{opacity:0});return g;};
function receipt(par,title,rows,tot,cls=''){const rc=el('hrc '+cls,`<h5>${title}</h5>`+rows.map(([a,b])=>`<div class="r"><span>${a}</span><b>${b}</b></div>`).join('')+(tot?`<div class="tt"><span>${tot}</span><b></b></div>`:''));par.append(rc);return rc;}

/* ================= PANGGUNG ================= */
/* 0 — HOOK: es kopi gerobak Rp8.000 → modal Rp6.800 (angka di detik ke-2), lalu gelas kafe Rp25.000 dan tanda tanya */
{const S=stage(0,'dark'),t=S.t0;
  const a=kopi(S.inn,120,600,300);gsap.set(a,{opacity:0});pop(a,t+.05,{from:.4,rot:-10,dur:.4});BOBS.push({e:a,a:8,f:2.2,ph:0});
  const ta=tag(S.inn,'Rp8.000',90,1130);pop(ta,t+.55,{from:.3,rot:-12});
  const md=el('sjt smod','<small>modal segelas</small><b>Rp6.800</b>');S.inn.append(md);gsap.set(md,{opacity:0});
  tl.fromTo(md,{opacity:0,scale:3,rotation:-16},{opacity:1,scale:1,rotation:-4,duration:.25,ease:'power4.in',immediateRender:false},t+4.25);
  tl.fromTo(S.inn,{x:0},{x:16,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},t+4.5);
  tl.to(md,{opacity:0,y:40,duration:.25,ease:'power2.in'},t+6.25);
  const b=kopi(S.inn,660,600,300,true);gsap.set(b,{opacity:0});pop(b,t+6.6,{from:.4,rot:10,dur:.4});BOBS.push({e:b,a:8,f:2.2,ph:1.5});
  const tb=tag(S.inn,'Rp25.000',540,1130);pop(tb,t+8.0,{from:.3,rot:10});
  const q=el('sq','?');q.style.cssText='left:452px;top:760px';S.inn.append(q);pop(q,t+9.2,{from:.2,rot:-20});
  tl.fromTo(q,{scale:1},{scale:1.15,duration:.15,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},t+9.7);
  cap(S,t+.04,'keliling Rp8.000, modalnya','rp6.800',{kwAt:4.2,out:t+6.3});
  cap(S,t+6.4,'di kafe bisa Rp25.000.','selisihnya ke mana?',{kwAt:2.8});}

/* 1 — judul seri: BEDAH MENU #3, kaca pembesar menyapu gelas, label harga eceran 2026 */
{const S=stage(1,'cream'),t=S.t0,b=kopi(S.inn,380,520,320);gsap.set(b,{opacity:0});pop(b,t+.02,{from:.5,rot:-8,dur:.35});BOBS.push({e:b,a:10,f:2.4,ph:0});
  const tg=el('sseri','<b>BEDAH MENU</b><span>#3 es kopi keliling</span>');S.inn.append(tg);gsap.set(tg,{opacity:0});pop(tg,t+.4,{from:.4,rot:-10});
  const mg=el('smag','<i></i>');S.inn.append(mg);gsap.set(mg,{opacity:0});
  tl.fromTo(mg,{opacity:1,x:-500,y:60,rotation:-20},{x:120,y:-80,rotation:10,duration:1.2,ease:'sine.inOut',immediateRender:false},t+.1);
  const dv=el('sdiv','<span>harga eceran 2026</span>');dv.style.top='1200px';S.inn.append(dv);gsap.set(dv,{opacity:0});pop(dv,t+2.8,{from:.4,rot:6});}

/* 2 — modal segelas: kopi, susu UHT, kental manis, gula aren, es, gelas & sedotan → Rp6.800 */
{const S=stage(2,'orange'),t=S.t0,AT=[.24,3.16,5.68,7.94,9.46,10.74];
  const rc=receipt(S.inn,'Modal 1 gelas',[['Kopi 14 g','1.800'],['Susu UHT 100 ml','2.100'],['Kental manis','700'],['Gula aren','900'],['Es batu','300'],['Gelas & sedotan','1.000']],'Modal','m6');
  pop(rc,t+.02,{from:.7,dur:.35});
  $$('.r',rc).forEach((r,i)=>{gsap.set(r,{opacity:0});tl.fromTo(r,{opacity:0,x:-60},{opacity:1,x:0,duration:.25,ease:'power3.out',immediateRender:false},t+AT[i]);
    tl.fromTo(r,{backgroundColor:'#FFE3B8'},{backgroundColor:'rgba(255,227,184,0)',duration:.8,immediateRender:false},t+AT[i]);});
  const tt=$('.tt',rc);gsap.set(tt,{opacity:0});tl.fromTo(tt,{opacity:0,scale:1.3},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+13.45);
  num($('b',tt),'',x=>Math.round(6800*ease2((x-t-13.5)/.8)/100)*100,v=>'Rp'+rb(v));
  cap(S,t+.08,'modal segelas','rp6.800',{kwAt:13.9});}

/* 3 — RAHASIA: batang biaya, susu paling mahal (bukan kopi) → untung cuma Rp1.200 segelas */
{const S=stage(3,'purple'),t=S.t0,R=[['Susu UHT',2100,'hot'],['Kopi',1800,''],['Gelas & sedotan',1000,''],['Gula aren',900,''],['Kental manis',700,''],['Es batu',300,'']];
  const bs=el('sbars',R.map(([a,v,k])=>`<div class="g ${k}"><span>${a}</span><i><em></em></i><b>${rb(v)}</b></div>`).join(''));S.inn.append(bs);gsap.set(bs,{opacity:0});
  const ems=$$('em',bs);gsap.set(ems,{width:'0%'});
  tl.fromTo(bs,{opacity:0,y:40},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},t+.2);
  ems.forEach((e,i)=>tl.fromTo(e,{width:'0%'},{width:(R[i][1]/2100*100)+'%',duration:.45,ease:'power2.out',immediateRender:false},t+.3+i*.1));
  const hot=$('.hot',bs);tl.fromTo(hot,{scale:1},{scale:1.06,duration:.14,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},t+2.34);
  const u=el('sjt sunt','<b>Rp1.200</b><small>untung segelas · 15%</small>');S.inn.append(u);gsap.set(u,{opacity:0});
  tl.fromTo(u,{opacity:0,scale:3,rotation:-18},{opacity:1,scale:1,rotation:-4,duration:.25,ease:'power4.in',immediateRender:false},t+5.38);
  cap(S,t+.08,'rahasianya: paling mahal itu','susu, bukan kopi',{kwAt:2.3});}

/* 4 — kenapa kafe Rp25.000: yang dibayar sewa tempat, AC, kursi; gerobak tidak punya semua itu */
{const S=stage(4,'cream'),t=S.t0,b=kopi(S.inn,90,560,300,true);gsap.set(b,{opacity:0});pop(b,t+.05,{from:.4,rot:-8,dur:.4});BOBS.push({e:b,a:8,f:2.3,ph:0});
  const tb=tag(S.inn,'Rp25.000',70,1080);pop(tb,t+.6,{from:.3,rot:-10});
  const CH=[['🏠 sewa tempat',600,4.12],['❄️ AC',760,5.24],['🪑 kursi',920,5.98]].map(([s,y,at])=>{const c=el('schip',s+'<s></s>');c.style.top=y+'px';S.inn.append(c);pop(c,t+at,{from:.4,rot:8});return c;});
  const g=gerobak(S.inn,150,1260,440);gsap.set(g,{opacity:0});pop(g,t+6.92,{from:.4,rot:6,dur:.4});BOBS.push({e:g,a:8,f:2.6,ph:1});
  const tg=tag(S.inn,'Rp8.000',590,1300);pop(tg,t+7.2,{from:.3,rot:8});
  CH.forEach((c,i)=>{tl.fromTo($('s',c),{scaleX:0},{scaleX:1,duration:.25,ease:'power2.out',immediateRender:false},t+7.6+i*.15);tl.to(c,{opacity:.5,duration:.3},t+7.8+i*.15);});
  cap(S,t+.08,'di kafe kamu juga bayar','sewa, AC, kursi',{kwAt:4.12});}

/* 5 — volume: 84 gelas sehari biar dapat ±Rp100 ribu */
{const S=stage(5,'dark'),t=S.t0;
  const G=[];for(let i=0;i<84;i++){const c=kopi(S.inn,64+(i%12)*80,520+Math.floor(i/12)*102,62);gsap.set(c,{opacity:0});G.push(c);}
  tl.fromTo(G,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.18,stagger:.016,ease:'back.out(2.4)',immediateRender:false},t+.3);
  const cnt=num(S.inn,'scnt sc2',x=>Math.max(0,Math.min(84,Math.round(84*clamp((x-t-.3)/1.35)))),v=>v+' gelas');tl.fromTo(cnt,{opacity:0},{opacity:1,duration:.1,immediateRender:false},t+.3);
  const eq=el('bigeq sm seq','<span>84</span><i>×</i><span>1.200</span>');S.inn.append(eq);
  $$('span, i',eq).forEach((x,i)=>{gsap.set(x,{opacity:0});tl.fromTo(x,{opacity:0,y:60,scale:.6},{opacity:1,y:0,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+[3.6,3.75,3.9][i]);});
  tl.to(cnt,{opacity:0,y:-30,duration:.2},t+3.5);
  tl.set(eq,{opacity:1},t+3.6);
  const n=num(S.inn,'mega2 smeg',x=>Math.round(100800*ease2((x-t-4.35)/.6)/100)*100,v=>'Rp'+rb(v)+'/hari');tl.fromTo(n,{opacity:0,scale:1.5},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},t+4.3);
  cap(S,t+.08,'biar dapat Rp100 ribu sehari','84 gelas',{kwAt:3.68});}

/* 6 — risiko: susu naik Rp3.000/liter → untung segelas turun Rp300 (seperempat hilang) */
{const S=stage(6,'green'),t=S.t0,s=susu(S.inn,130,560,300);gsap.set(s,{opacity:0});pop(s,t+.05,{from:.4,rot:-10,dur:.4});BOBS.push({e:s,a:8,f:2.4,ph:0});
  const tn=tag(S.inn,'+Rp3.000/liter',450,780);tn.style.fontSize='62px';pop(tn,t+1.0,{from:.3,rot:8});
  const u=el('sper','<span>untung segelas</span><b></b><small>&nbsp;</small>');S.inn.append(u);gsap.set(u,{opacity:0});
  tl.fromTo(u,{opacity:0,y:60,rotation:6},{opacity:1,y:0,rotation:-3,duration:.35,ease:'back.out(2)',immediateRender:false},t+2.3);
  num($('b',u),'',x=>1200-Math.round(300*ease2((x-t-2.95)/.6)/100)*100,v=>'Rp'+rb(v));
  const sm=$('small',u);tl.set(sm,{innerHTML:'turun Rp300'},t+3.5);
  const pc=el('spct sp6','−25%');S.inn.append(pc);gsap.set(pc,{opacity:0});
  tl.fromTo(pc,{opacity:0,scale:3,rotation:-30},{opacity:1,scale:1,rotation:-10,duration:.3,ease:'back.out(2.2)',immediateRender:false},t+4.4);
  cap(S,t+.08,'susu naik, untung segelas','−rp300',{kwAt:2.9});}

/* 7 — di aplikasi: Master Recipe es kopi susu, harga susu naik → HPP per satuan output ikut berubah */
{const S=stage(7,'purple'),t=S.t0,ph=phone(S,'<div class="hd">Master Recipe</div><div class="snm">Es Kopi Susu</div>'
   +[['Kopi bubuk','14 g'],['Susu UHT','100 ml'],['Kental manis','20 g'],['Gula aren','15 ml'],['Es batu','150 g'],['Gelas & sedotan','1 pcs']].map(([a,b],i)=>`<div class="row ${i===1?'mk':''}"><span>${a}</span><b>${b}</b></div>`).join('')
   +'<div class="rcs"><div><span>Total biaya bahan</span><b class="x1"></b></div><div><span>HPP per satuan output</span><b class="x2"></b></div></div>',{cls:'hph rcp'});
  const mk=$('.mk',ph);
  tl.fromTo(mk,{backgroundColor:'#f1f5f9'},{backgroundColor:'#FEE2E2',duration:.3,immediateRender:false},t+3.3);
  const bd=el('sbd','+Rp3.000/L');mk.append(bd);gsap.set(bd,{opacity:0});pop(bd,t+3.35,{from:.3,rot:-8});
  const v=x=>6800+Math.round(300*ease2((x-t-4.45)/.6)/100)*100;
  num($('.x1',ph),'',v,x=>'Rp'+rb(x));num($('.x2',ph),'',v,x=>'Rp'+rb(x)+'/gelas');
  const r=$('.rcs',ph);tl.fromTo(r,{backgroundColor:'#f8fafc'},{backgroundColor:'#FEF3C7',duration:.3,yoyo:true,repeat:1,immediateRender:false},t+4.45);
  cap(S,t+.08,'harga bahan berubah,','hpp ikut berubah',{kwAt:4.45});}

/* 8 — AJAKAN FOLLOW: kartu profil @kasir_umkm, jari mengetuk "Ikuti" → "Mengikuti", maskot di bawah */
{const S=stage(8,'purple'),t=S.t0;
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
  cap(S,t+.08,'mau menu lain dibedah?','follow kasir umkm',{kwAt:1.65});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
}
