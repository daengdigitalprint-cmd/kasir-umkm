/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (56,92 dtk) lewat whisper per kata ---------- */
/* Semua waktu di dalam panggung RELATIF terhadap S.t0, jadi cukup ubah array C saat sinkron. */
const C=[0,6.3,9.35,14.75,22.8,31.55,36.8,42.9,45.9,52.2,58.2];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const ease2=p=>1-Math.pow(1-clamp(p),2.4);
const rb=v=>Math.round(v).toLocaleString('id-ID');
function phone(S,html,{from=1,cls=''}={}){const ph=el('phone '+cls,html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);return ph;}
/* gelas es teh: SVG dengan teh yang bisa naik (scaleY) dan sedotan */
const CUP=`<svg viewBox="-20 -150 460 780"><defs><clipPath id="cupc"><path d="M14 14H406L366 606H54Z"/></clipPath><linearGradient id="teag" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F2A04A"/><stop offset="1" stop-color="#A9480C"/></linearGradient></defs>
<rect x="246" y="-140" width="40" height="560" rx="20" fill="#a0ea71" stroke="#3B1F12" stroke-width="10" transform="rotate(12 266 140)"/>
<path d="M14 14H406L366 606H54Z" fill="rgba(255,255,255,.55)"/>
<g clip-path="url(#cupc)"><rect class="tea" x="0" y="90" width="420" height="530" fill="url(#teag)"/><path class="tea" d="M0 90q105 -26 210 0t210 0v20H0z" fill="#F6B66A"/></g>
<path d="M60 60L92 560" stroke="#fff" stroke-width="22" stroke-linecap="round" opacity=".55"/>
<path d="M14 14H406L366 606H54Z" fill="none" stroke="#3B1F12" stroke-width="13" stroke-linejoin="round"/></svg>`;
let _cupN=0;
function cup(par,x,y,w=420){const k=++_cupN,c=el('hcup',CUP.replace(/cupc/g,'cupc'+k).replace(/teag/g,'teag'+k));c.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(c);gsap.set($$('.tea',c),{transformOrigin:'50% 100%'});return c;}
const ice=(par,x,y,s=96)=>{const i=el('hice');i.style.cssText=`left:${x}px;top:${y}px;width:${s}px;height:${s}px`;par.append(i);return i;};
const tag=(par,html,cls='')=>{const t=el('htag '+cls,html);par.append(t);return t;};

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — HOOK (0–3 dtk): gelas terisi teh, es berjatuhan tiap 0,12 dtk, label harga & modal masuk,
   angka untung berputar naik ke 3.000 lalu stempel BELUM TENTU menghantam dan angkanya dicoret. */
{const S=stage(0,'dark'),t=S.t0,c=cup(S.inn,300,700,480);gsap.set(c,{opacity:0});
  pop(c,t+.02,{from:.5,rot:-8,dur:.35});
  tl.fromTo($$('.tea',c),{scaleY:0},{scaleY:1,duration:1.1,ease:'power2.out',immediateRender:false},t+.1);
  const IC=[[380,1010],[500,990],[620,1015],[420,1110],[560,1100],[470,1200],[600,1190],[400,1280]];
  const ices=IC.map(([x,y],i)=>{const e=ice(S.inn,x,y);gsap.set(e,{opacity:0});
    tl.fromTo(e,{opacity:1,y:-900,rotation:(i%2?1:-1)*50},{y:0,rotation:(i%2?1:-1)*(8+i*3),duration:.32,ease:'power2.in',immediateRender:false},t+.15+i*.12);
    tl.fromTo(e,{scaleY:.7},{scaleY:1,duration:.2,ease:'back.out(3)',immediateRender:false},t+.47+i*.12);return e;});
  const pr=tag(S.inn,'<small>harga</small><b>Rp5.000</b>','pr');gsap.set(pr,{opacity:0,transformOrigin:'50% 0%'});
  tl.fromTo(pr,{opacity:1,rotation:-70,y:-500},{rotation:0,y:0,duration:.55,ease:'elastic.out(1,.45)',immediateRender:false},t+.68);
  const md=tag(S.inn,'<small>modal</small><b>Rp2.000</b>','md');gsap.set(md,{opacity:0});
  tl.fromTo(md,{opacity:1,x:-700,rotation:-14},{x:0,rotation:-4,duration:.42,ease:'back.out(1.7)',immediateRender:false},t+1.35);
  const u=el('huntung','<small>untung</small><b></b>');S.inn.append(u);gsap.set(u,{opacity:0});
  num($('b',u),'',x=>Math.round(3000*ease2((x-t-3.05)/.65)/100)*100,v=>'Rp'+rb(v)+'?');
  tl.fromTo(u,{opacity:0,scale:1.8},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+3.0);
  const strike=el('hstrike');u.append(strike);gsap.set(strike,{scaleX:0});
  tl.fromTo(strike,{scaleX:0},{scaleX:1,duration:.18,ease:'power3.out',immediateRender:false},t+5.0);
  const st=el('stamp hst','BELUM<br>TENTU');S.inn.append(st);
  tl.fromTo(st,{opacity:0,scale:3.2,rotation:-30},{opacity:1,scale:1,rotation:-12,duration:.22,ease:'power4.in',immediateRender:false},t+4.78);
  tl.fromTo(S.inn,{x:0},{x:18,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},t+5.0);
  tl.fromTo(c,{rotation:0},{rotation:4,duration:.2,yoyo:true,repeat:5,ease:'sine.inOut',immediateRender:false},t+2.2);
  tl.fromTo(ices,{rotation:0},{rotation:'+=8',duration:.14,yoyo:true,repeat:5,stagger:.02,ease:'sine.inOut',immediateRender:false},t+1.55);
  cap(S,t+.04,'es teh 5 ribu, modal 2 ribu,','untung 3 ribu?',{kwAt:3.0});}

/* 1 — yang dihitung cuma teh & gula */
{const S=stage(1,'cream'),t=S.t0;
  const pk=el('hpack','<i>🍃</i><b>TEH</b>'),jr=el('hjar','<i></i><b>GULA</b>');S.inn.append(pk,jr);
  pop(pk,t+.04,{from:.4,rot:-16,dur:.4});pop(jr,t+.3,{from:.4,rot:14,dur:.4});
  const sm=tag(S.inn,'<b>= Rp2.000</b>','sum');gsap.set(sm,{opacity:0});pop(sm,t+2.3,{from:.5,rot:-8});
  BOBS.push({e:pk,a:12,f:2.4,ph:0},{e:jr,a:12,f:2.4,ph:1.6});
  cap(S,t+.08,'banyak penjual cuma hitung','teh & gula',{kwAt:1.6});}

/* 2 — yang sering lupa: gelas plastik, sedotan, es batu terbang masuk mengelilingi gelas */
{const S=stage(2,'orange'),t=S.t0,c=cup(S.inn,370,640,340);gsap.set(c,{opacity:0});pop(c,t+.02,{from:.6,dur:.3});
  const L=[['🥤','Gelas plastik',110,1290,-7],['🥢','Sedotan',560,1440,6],['🧊','Es batu',250,1600,-3]];
  L.forEach(([ic,a,x,y,r],i)=>{const k=el('hitem',`<em>${ic}</em><span>${a}</span>`);k.style.left=x+'px';k.style.top=y+'px';S.inn.append(k);gsap.set(k,{opacity:0});
    tl.fromTo(k,{opacity:1,x:(i%2?700:-700),rotation:r*4},{x:0,rotation:r,duration:.42,ease:'back.out(1.6)',immediateRender:false},t+[2.05,3.4,4.3][i]);BOBS.push({e:k,a:9,f:2.8,ph:i*1.3});});
  const q=el('qs','!');q.style.cssText='left:810px;top:700px;width:140px;height:140px;font-size:100px;line-height:118px';S.inn.append(q);pop(q,t+.4,{rot:30});BOBS.push({e:q,a:14,f:3,ph:0});
  cap(S,t+.08,'padahal ada','yang sering lupa',{kwAt:.9});}

/* 3 — struk modal: 2.000 + 500 + 100 + 500 = RP3.100 */
{const S=stage(3,'green'),t=S.t0,rc=el('hrc','<h5>Modal 1 gelas</h5>'+[['Teh & gula','2.000'],['Gelas plastik','500'],['Sedotan','100'],['Es batu','500']].map(([a,b])=>`<div class="r"><span>${a}</span><b>${b}</b></div>`).join('')+'<div class="tt"><span>Total</span><b></b></div>');
  S.inn.append(rc);pop(rc,t+.02,{from:.7,dur:.35});
  $$('.r',rc).forEach((r,i)=>{gsap.set(r,{opacity:0});tl.fromTo(r,{opacity:0,x:-60},{opacity:1,x:0,duration:.25,ease:'power3.out',immediateRender:false},t+[.05,.2,1.55,3.07][i]);
    if(i)tl.fromTo(r,{backgroundColor:'#FFE3B8'},{backgroundColor:'rgba(255,227,184,0)',duration:.8,immediateRender:false},t+[.05,.2,1.55,3.07][i]);});
  const tt=$('.tt',rc);gsap.set(tt,{opacity:0});tl.fromTo(tt,{opacity:0,scale:1.3},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+5.05);
  num($('b',tt),'',x=>2000+Math.round(1100*ease2((x-t-5.1)/.9)/100)*100,v=>'Rp'+rb(v));
  cap(S,t+.08,'modal sebenarnya','rp3.100',{kwAt:6.0});}

/* 4 — sewa tempat: 600.000 ÷ 1.500 gelas = RP400 per gelas */
{const S=stage(4,'purple'),t=S.t0;
  const a=el('hcard hc1','<em>🏪</em><span>Sewa sebulan</span><b>600.000</b>'),b=el('hcard hc2','<em>🥤</em><span>Gelas sebulan</span><b>1.500</b>'),dv=el('hdiv','÷');
  S.inn.append(a,dv,b);[a,b,dv].forEach(e=>gsap.set(e,{opacity:0}));
  tl.fromTo(a,{opacity:1,x:-800,rotation:-10},{x:0,rotation:-2,duration:.42,ease:'back.out(1.6)',immediateRender:false},t+.75);
  pop(dv,t+3.2,{from:.2,rot:90});
  tl.fromTo(b,{opacity:1,x:800,rotation:10},{x:0,rotation:2,duration:.42,ease:'back.out(1.6)',immediateRender:false},t+3.85);
  const n=num(S.inn,'mega2 h400',x=>Math.round(400*ease2((x-t-6.75)/.5)/10)*10,v=>'Rp'+rb(v)+'/gelas');tl.fromTo(n,{opacity:0,scale:1.5},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},t+6.7);
  cap(S,t+.08,'belum lagi','sewa tempat',{kwAt:.75,out:t+5.45});
  cap(S,t+5.53,'tambahan per gelas','rp400',{kwAt:1.2});}

/* 5 — MOMEN: 5.000 − 3.500 = RP1.500 · dua batang: dikira vs aslinya */
{const S=stage(5,'dark'),t=S.t0,eq=el('bigeq','<span>5.000</span><i>−</i><span>3.500</span>');S.inn.append(eq);
  $$('span, i',eq).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:60,scale:.6},{opacity:1,y:0,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+[.05,.2,.35][i]);});
  tl.set(eq,{opacity:1},t+.05);
  const bars=el('hbars','<div class="b k"><span>dikira</span><i><b>3.000</b></i></div><div class="b a"><span>aslinya</span><i><b></b></i></div>');S.inn.append(bars);pop(bars,t+.5,{from:.7,dur:.35});
  const ka=$('.k i',bars),aa=$('.a i',bars);tl.fromTo(ka,{width:200},{width:900,duration:.5,ease:'power3.out',immediateRender:false},t+.7);
  tl.fromTo(aa,{width:900},{width:450,duration:.9,ease:'power2.inOut',immediateRender:false},t+1.3);
  num($('.a b',bars),'',x=>3000-Math.round(1500*clamp((x-t-1.3)/.9)/100)*100,v=>rb(v));
  const half=el('hhalf','½');S.inn.append(half);
  tl.fromTo(half,{opacity:0,scale:3,rotation:-30},{opacity:1,scale:1,rotation:-10,duration:.3,ease:'back.out(2.2)',immediateRender:false},t+3.6);
  tl.fromTo(half,{scale:1},{scale:1.12,duration:.12,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},t+3.95);
  const SPK=[[120,1580],[930,1560],[200,1760],[860,1770],[540,1800]];
  SPK.forEach(([x,y],i)=>{const sp=el('spk','✦');sp.style.left=x+'px';sp.style.top=y+'px';S.inn.append(sp);pop(sp,t+3.7+i*.05,{from:.2,rot:90});BOBS.push({e:sp,a:12,f:4,ph:i});});
  cap(S,t+.08,'untung bersihnya cuma','rp1.500',{kwAt:1.75,out:t+3.45});
  cap(S,t+3.53,'setengah dari','yang dikira',{kwAt:.7});}

/* 6 — tips 1: hitung semua yang ikut terjual (daftar centang) */
{const S=stage(6,'cream'),t=S.t0,ck=el('hchk',['Teh & gula','Gelas & sedotan','Es batu','Sewa tempat'].map(a=>`<div class="r"><i></i><span>${a}</span></div>`).join(''));
  S.inn.append(ck);pop(ck,t+.02,{from:.7,rot:-4,dur:.35});
  $$('.r i',ck).forEach((e,i)=>tl.fromTo(e,{backgroundColor:'#fff',scale:1},{backgroundColor:'#a0ea71',scale:1.15,duration:.18,ease:'back.out(3)',immediateRender:false},t+1.3+i*.45));
  $$('.r i',ck).forEach((e,i)=>{const m=el('m','✓');e.append(m);gsap.set(m,{opacity:0});tl.fromTo(m,{opacity:0,scale:2.4},{opacity:1,scale:1,duration:.2,ease:'back.out(2.5)',immediateRender:false},t+1.3+i*.45);});
  cap(S,t+.08,'hitung semua biaya','yang dikeluarkan',{kwAt:2.9});}

/* 7 — tips 2: harga bahan naik → hitung ulang */
{const S=stage(7,'green'),t=S.t0,jr=el('hjar big','<i></i><b>GULA</b>');S.inn.append(jr);pop(jr,t+.02,{from:.5,rot:-10,dur:.35});
  const pt=tag(S.inn,'<small>1 kg</small><b></b>','up');gsap.set(pt,{opacity:0});pop(pt,t+.3,{from:.5,rot:12});
  num($('b',pt),'',x=>x<t+1.95?16000:18000,v=>'Rp'+rb(v));
  tl.fromTo($('b',pt),{color:'#22104F'},{color:'#E0430E',duration:.1,immediateRender:false},t+1.95);
  tl.fromTo(pt,{scale:1},{scale:1.15,duration:.12,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},t+1.95);
  const ar=el('harr','▲');S.inn.append(ar);pop(ar,t+1.95,{from:.2});BOBS.push({e:ar,a:16,f:4,ph:0});
  const rf=el('hrf','↻');S.inn.append(rf);pop(rf,t+.6,{from:.2});
  tl.fromTo(rf,{rotation:0},{rotation:720,duration:1.6,ease:'power2.inOut',immediateRender:false},t+.7);
  cap(S,t+.08,'jangan lupa','hitung ulang',{kwAt:.6});}

/* 8 — di aplikasi: Bahan yang terpakai saat terjual → Harga modal terisi (istilah dari form produk asli) */
{const S=stage(8,'purple'),t=S.t0,ph=phone(S,'<div class="hd">Es Teh</div><div class="cs">Bahan yang terpakai saat terjual</div>'
   +[['Teh','10 gram'],['Gula','30 gram'],['Gelas plastik','1 pcs'],['Sedotan','1 pcs'],['Es batu','150 gram']].map(([a,b])=>`<div class="cr"><span>${a}</span><b>${b}</b></div>`).join('')
   +'<div class="fl"><small>Harga Jual</small><b>5.000</b></div><div class="fl hm"><small>Harga modal</small><b></b></div><div class="fe">Estimasi dari komponen: Rp 3.100</div><div class="fk">Keuntungan 61.29% dari modal</div>',{cls:'hph'});
  $$('.cs, .cr',ph).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,x:-50},{opacity:1,x:0,duration:.25,ease:'power3.out',immediateRender:false},t+1.6+i*.28);});
  $$('.fl',ph).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:30},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},t+[3.6,4.3][i]);});
  num($('.hm b',ph),'',x=>Math.round(3100*ease2((x-t-4.4)/.7)/100)*100,v=>rb(v));
  $$('.fe, .fk',ph).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0},{opacity:1,duration:.25,immediateRender:false},t+[4.7,5.3][i]);});
  tl.fromTo($('.hm',ph),{backgroundColor:'#f6f8fa'},{backgroundColor:'#EDE4FF',duration:.3,immediateRender:false},t+4.4);
  cap(S,t+.08,'di kasir umkm, cukup isi','bahan per gelas',{kwAt:1.9,out:t+4.1});
  cap(S,t+4.18,'harga modalnya','terhitung otomatis',{kwAt:1.05});}

/* 9 — maskot + logo */
const RISE=[];
{const S=stage(9,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';
  const cta=el('cta','<span>Tahu untung aslimu</span>');S.inn.append(lg,fox,cta);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},S.t0+.05);tl.set(fox,{opacity:1},S.t0+.05);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.55,ease:'back.out(1.8)',immediateRender:false},S.t0+.3);
  pop(cta,S.t0+1.85,{from:.4,rot:-6});
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  RISE.forEach(o=>{if(t<C[9])return;const y=2100-((o.y0+(t-C[9])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
