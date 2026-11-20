/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (67,88 dtk) lewat whisper per kata ---------- */
/* Semua waktu di dalam panggung RELATIF terhadap S.t0, jadi cukup ubah array C saat sinkron. */
const C=[0,9.5,11.2,24.1,33.6,36.8,43.75,50.1,55.7,61.5,69.0];
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

/* ---------- objek: mangkuk seblak, uang kertas, struk ---------- */
const SEB=`<svg viewBox="0 0 120 92"><path d="M8 40h104q-4 42-52 46Q12 82 8 40z" fill="#fff" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><path d="M14 40q10-22 46-22t46 22z" fill="#E4472B" stroke="#3B1F12" stroke-width="6" stroke-linejoin="round"/><path d="M30 30q8-8 16 0M62 26q8-8 16 0" stroke="#FFD27A" stroke-width="6" fill="none" stroke-linecap="round"/><circle cx="54" cy="31" r="7" fill="#FFF3D6" stroke="#3B1F12" stroke-width="3"/><circle cx="54" cy="31" r="3" fill="#F5A524"/><path d="M86 30l5-7" stroke="#57b01c" stroke-width="5" stroke-linecap="round"/><path d="M38 6q4 8 0 14M58 2q4 8 0 14M78 6q4 8 0 14" stroke="#fff" stroke-width="4" fill="none" stroke-linecap="round" opacity=".7"/></svg>`;
const seb=(par,x,y,w=200)=>{const b=el('sbowl',SEB);b.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(b);return b;};
const bill=(par,x,y,r=0)=>{const b=el('sbill','<b>Rp</b>');b.style.cssText=`left:${x}px;top:${y}px`;b.dataset.r=r;par.append(b);return b;};
function receipt(par,title,rows,tot,cls=''){const rc=el('hrc '+cls,`<h5>${title}</h5>`+rows.map(([a,b])=>`<div class="r"><span>${a}</span><b>${b}</b></div>`).join('')+(tot?`<div class="tt"><span>${tot}</span><b></b></div>`:''));par.append(rc);return rc;}

/* ================= PANGGUNG ================= */
/* 0 — HOOK: mangkuk seblak bermunculan tiap 0,1 dtk (penghitung porsi naik ke 50), tumpukan uang tumbuh (omzet ke 750.000),
   lalu di "cuma segini" sebagian besar uang terbang pergi dan tersisa tumpukan kecil + label Rp???. */
{const S=stage(0,'dark'),t=S.t0;
  const bs=[];for(let i=0;i<10;i++){const c=i%5,r=Math.floor(i/5),b=seb(S.inn,60+c*196,560+r*150,170);gsap.set(b,{opacity:0});bs.push(b);
    tl.fromTo(b,{opacity:0,scale:.2,y:-160,rotation:(i%2?1:-1)*30},{opacity:1,scale:1,y:0,rotation:(i%2?1:-1)*4,duration:.26,ease:'back.out(2.6)',immediateRender:false},t+.08+i*.12);}
  const pn=num(S.inn,'sporsi',x=>Math.max(0,Math.min(50,Math.round(50*clamp((x-t-.08)/2.1)))),v=>v+' porsi');tl.fromTo(pn,{opacity:0},{opacity:1,duration:.1,immediateRender:false},t+.08);
  const BL=[];for(let i=0;i<14;i++){const b=bill(S.inn,330+((i*37)%5-2)*14,1500-i*30,((i*53)%7-3)*3);gsap.set(b,{opacity:0});BL.push(b);
    tl.fromTo(b,{opacity:1,y:-1200,rotation:(i%2?1:-1)*40},{y:0,rotation:+b.dataset.r,duration:.3,ease:'power2.in',immediateRender:false},t+3.0+i*.1);}
  const om=el('somzet','<small>omzet sehari</small><b></b>');S.inn.append(om);gsap.set(om,{opacity:0});
  num($('b',om),'',x=>Math.round(750000*ease2((x-t-3.2)/1.4)/10000)*10000,v=>'Rp'+rb(v));
  tl.fromTo(om,{opacity:0,y:40},{opacity:1,y:0,duration:.3,ease:'back.out(2)',immediateRender:false},t+3.1);
  /* napas tunggu: mangkuk mengepul bergoyang */
  tl.fromTo(bs,{rotation:0},{rotation:6,duration:.14,yoyo:true,repeat:9,stagger:.02,ease:'sine.inOut',immediateRender:false},t+5.7);
  /* cuma segini: 10 dari 14 lembar terbang */
  BL.slice(4).forEach((b,i)=>tl.to(b,{x:(i%2?1:-1)*(700+i*30),y:'-=900',rotation:(i%2?1:-1)*120,duration:.55,ease:'power2.in'},t+7.9+i*.04));
  const q=el('sq','Rp???');S.inn.append(q);gsap.set(q,{opacity:0});
  tl.fromTo(q,{opacity:0,scale:3,rotation:-20},{opacity:1,scale:1,rotation:-8,duration:.25,ease:'power4.in',immediateRender:false},t+8.3);
  tl.fromTo(S.inn,{x:0},{x:18,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},t+8.55);
  cap(S,t+.04,'omzet 750 ribu sehari,','masuk kantong?',{kwAt:6.8});}

/* 1 — judul seri: BEDAH MENU, kaca pembesar menyapu mangkuk */
{const S=stage(1,'cream'),t=S.t0,b=seb(S.inn,240,780,600);gsap.set(b,{opacity:0});pop(b,t+.02,{from:.5,rot:-8,dur:.35});
  const tg=el('sseri','<b>BEDAH MENU</b><span>#1 seblak</span>');S.inn.append(tg);gsap.set(tg,{opacity:0});pop(tg,t+.5,{from:.4,rot:-10});
  const mg=el('smag','<i></i>');S.inn.append(mg);gsap.set(mg,{opacity:0});
  tl.fromTo(mg,{opacity:1,x:-500,y:100,rotation:-20},{x:120,y:-40,rotation:10,duration:1.2,ease:'sine.inOut',immediateRender:false},t+.1);
  BOBS.push({e:b,a:10,f:2.4,ph:0});}

/* 2 — bahan seporsi: kerupuk, telur, topping, bumbu → Rp7.500 */
{const S=stage(2,'orange'),t=S.t0,rc=receipt(S.inn,'Bahan 1 porsi',[['Kerupuk','1.000'],['Telur','2.500'],['Bakso & sosis','2.500'],['Bumbu & cabai','1.500']],'Total');
  pop(rc,t+.02,{from:.7,dur:.35});
  $$('.r',rc).forEach((r,i)=>{gsap.set(r,{opacity:0});tl.fromTo(r,{opacity:0,x:-60},{opacity:1,x:0,duration:.25,ease:'power3.out',immediateRender:false},t+[1.5,3.1,5.0,7.55][i]);
    tl.fromTo(r,{backgroundColor:'#FFE3B8'},{backgroundColor:'rgba(255,227,184,0)',duration:.8,immediateRender:false},t+[1.5,3.1,5.0,7.55][i]);});
  const tt=$('.tt',rc);gsap.set(tt,{opacity:0});tl.fromTo(tt,{opacity:0,scale:1.3},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+10.0);
  num($('b',tt),'',x=>Math.round(7500*ease2((x-t-10.05)/.8)/100)*100,v=>'Rp'+rb(v));
  cap(S,t+.08,'bahan seporsi','rp7.500',{kwAt:10.3});}

/* 3 — + kemasan + gas → modal Rp9.000 → untung per porsi Rp6.000 */
{const S=stage(3,'green'),t=S.t0,rc=receipt(S.inn,'Modal 1 porsi',[['Bahan','7.500'],['Kemasan','1.000'],['Gas','500']],'Modal','sm');
  pop(rc,t+.02,{from:.7,dur:.35});
  $$('.r',rc).forEach((r,i)=>{gsap.set(r,{opacity:0});tl.fromTo(r,{opacity:0,x:-60},{opacity:1,x:0,duration:.25,ease:'power3.out',immediateRender:false},t+[.05,.65,1.8][i]);});
  const tt=$('.tt',rc);gsap.set(tt,{opacity:0});tl.fromTo(tt,{opacity:0,scale:1.3},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+3.05);
  num($('b',tt),'',x=>7500+Math.round(1500*ease2((x-t-3.1)/.6)/100)*100,v=>'Rp'+rb(v));
  const u=el('sper','<span>15.000 − 9.000 =</span><b>6.000</b><small>untung per porsi</small>');S.inn.append(u);gsap.set(u,{opacity:0});
  tl.fromTo(u,{opacity:0,y:60,rotation:6},{opacity:1,y:0,rotation:-3,duration:.35,ease:'back.out(2)',immediateRender:false},t+5.5);
  cap(S,t+.08,'modal seporsi','rp9.000',{kwAt:3.6,out:t+5.3});
  cap(S,t+5.38,'untung per porsi','rp6.000',{kwAt:2.9});}

/* 4 — × 50 porsi = Rp300.000 sehari */
{const S=stage(4,'purple'),t=S.t0,eq=el('bigeq','<span>6.000</span><i>×</i><span>50</span>');S.inn.append(eq);
  $$('span, i',eq).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:60,scale:.6},{opacity:1,y:0,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+[.05,.3,.5][i]);});
  tl.set(eq,{opacity:1},t+.05);
  const mb=[];for(let i=0;i<10;i++){const b=seb(S.inn,90+(i%5)*190,1000+Math.floor(i/5)*150,160);gsap.set(b,{opacity:0});mb.push(b);}
  tl.fromTo(mb,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.22,stagger:.06,ease:'back.out(2.4)',immediateRender:false},t+.5);
  const n=num(S.inn,'mega2',x=>Math.round(300000*ease2((x-t-1.5)/.8)/10000)*10000,v=>'Rp'+rb(v));tl.fromTo(n,{opacity:0,scale:1.5},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},t+1.45);
  cap(S,t+.08,'kali 50 porsi, sehari','rp300 ribu',{kwAt:1.6});}

/* 5 — biaya tetap: sewa + listrik & air sebulan ÷ 30 = Rp60.000 sehari */
{const S=stage(5,'cream'),t=S.t0,L=[['🏠','Sewa','1.500.000'],['⚡','Listrik & air','300.000']];
  L.forEach(([ic,a,b],i)=>{const c=el('costc',`<em>${ic}</em><span>${a}</span><b>${b}</b>`);c.style.top=(640+i*190)+'px';S.inn.append(c);gsap.set(c,{opacity:0});
    tl.fromTo(c,{opacity:1,x:-800,rotation:-8},{x:0,rotation:(i?2:-2),duration:.4,ease:'back.out(1.6)',immediateRender:false},t+[1.0,2.65][i]);});
  const dv=el('sdiv','<span>÷ 30 hari</span>');S.inn.append(dv);gsap.set(dv,{opacity:0});pop(dv,t+4.45,{from:.4,rot:-8});
  const n=num(S.inn,'mega2 dk',x=>Math.round(60000*ease2((x-t-5.9)/.6)/1000)*1000,v=>'Rp'+rb(v)+'/hari');tl.fromTo(n,{opacity:0,scale:1.5},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},t+5.85);
  cap(S,t+.08,'belum biaya tetap, sehari','rp60 ribu',{kwAt:5.95});}

/* 6 — MOMEN: omzet 750.000 vs masuk kantong 240.000 (sepertiga) */
{const S=stage(6,'dark'),t=S.t0,eq=el('bigeq sm','<span>300.000</span><i>−</i><span>60.000</span>');S.inn.append(eq);
  $$('span, i',eq).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:60,scale:.6},{opacity:1,y:0,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+[.05,.2,.35][i]);});
  tl.set(eq,{opacity:1},t+.05);
  const bars=el('hbars','<div class="b k"><span>omzet</span><i><b>750.000</b></i></div><div class="b a"><span>masuk kantong</span><i><b></b></i></div>');S.inn.append(bars);pop(bars,t+.5,{from:.7,dur:.35});
  const ka=$('.k i',bars),aa=$('.a i',bars);
  tl.fromTo(ka,{width:200},{width:900,duration:.5,ease:'power3.out',immediateRender:false},t+.7);
  tl.fromTo(aa,{width:760},{width:288,duration:1,ease:'power2.inOut',immediateRender:false},t+1.4);
  num($('.a b',bars),'',x=>750000-Math.round(510000*clamp((x-t-1.4)/1)/10000)*10000,v=>rb(v));
  const th=el('hhalf','⅓');S.inn.append(th);
  tl.fromTo(th,{opacity:0,scale:3,rotation:-30},{opacity:1,scale:1,rotation:-10,duration:.3,ease:'back.out(2.2)',immediateRender:false},t+4.85);
  tl.fromTo(th,{scale:1},{scale:1.12,duration:.12,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},t+5.2);
  [[120,1580],[930,1560],[200,1760],[860,1770],[540,1800]].forEach(([x,y],i)=>{const sp=el('spk','✦');sp.style.left=x+'px';sp.style.top=y+'px';S.inn.append(sp);pop(sp,t+4.95+i*.05,{from:.2,rot:90});BOBS.push({e:sp,a:12,f:4,ph:i});});
  cap(S,t+.08,'yang masuk kantong','rp240 ribu',{kwAt:1.85,out:t+4.3});
  cap(S,t+4.38,'cuma','sepertiga omzet',{kwAt:.35});}

/* 7 — pelajaran: jangan ukur dari omzet, ukur dari yang tersisa */
{const S=stage(7,'green'),t=S.t0,a=el('scmp no','<i>✕</i><span>omzet</span>'),b=el('scmp yes','<i>✓</i><span>yang tersisa</span>');S.inn.append(a,b);[a,b].forEach(e=>gsap.set(e,{opacity:0}));
  pop(a,t+.7,{from:.4,rot:-10});pop(b,t+3.45,{from:.4,rot:10});
  tl.fromTo($('i',a),{scale:0},{scale:1,duration:.25,ease:'back.out(3)',immediateRender:false},t+2.3);tl.fromTo($('i',b),{scale:0},{scale:1,duration:.25,ease:'back.out(3)',immediateRender:false},t+4.3);
  tl.to(a,{opacity:.45,filter:'grayscale(1)',duration:.3},t+3.45);BOBS.push({e:b,a:10,f:2.6,ph:0});
  cap(S,t+.08,'jangan ukur dari omzet, ukur','yang tersisa',{kwAt:4.2});}

/* 8 — di aplikasi: Laporan & Analitik → Total omzet, Total HPP, Laba kotor, Margin kotor (istilah halaman asli) */
{const S=stage(8,'purple'),t=S.t0,ph=phone(S,'<div class="hd">Laporan &amp; Analitik</div>'
   +[['Total omzet','750.000',''],['Total HPP','450.000','Modal barang yang terjual'],['Laba kotor','300.000','Omzet dikurangi HPP'],['Margin kotor','40%','Laba kotor terhadap omzet']].map(([a,b,c],i)=>`<div class="kc k${i}"><small>${a}</small><b>${b}</b>${c?`<em>${c}</em>`:''}</div>`).join(''),{cls:'hph'});
  $$('.kc',ph).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:40},{opacity:1,y:0,duration:.3,ease:'power3.out',immediateRender:false},t+[1.4,1.85,2.3,3.2][i]);});
  tl.fromTo($('.k2',ph),{scale:1},{scale:1.05,duration:.15,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},t+2.6);
  cap(S,t+.08,'di kasir umkm, langsung','kelihatan tiap hari',{kwAt:4.5});}

/* 9 — AJAKAN FOLLOW: kartu profil @kasir_umkm, jari mengetuk "Ikuti" → "Mengikuti", maskot di bawah */
{const S=stage(9,'purple'),t=S.t0;
  const pf=el('sprof','<div class="top"><div class="av"><img src="assets/avatar.png" alt=""></div><div class="nm"><b>Kasir UMKM</b><span>@kasir_umkm</span></div></div><div class="bio">Tips hitung untung &amp; bedah menu untuk UMKM</div><div class="btn"><span class="f">Ikuti</span><span class="d">Mengikuti</span></div>');
  S.inn.append(pf);gsap.set(pf,{opacity:0});tl.fromTo(pf,{opacity:1,y:-900,rotation:-6},{y:0,rotation:-2,duration:.55,ease:'back.out(1.5)',immediateRender:false},t+.05);
  const btn=$('.btn',pf),d=$('.d',pf),f=$('.f',pf);gsap.set(d,{opacity:0});
  const hand=el('shand','👆');S.inn.append(hand);gsap.set(hand,{opacity:0});
  tl.fromTo(hand,{opacity:1,x:300,y:500},{x:0,y:0,duration:.5,ease:'power3.out',immediateRender:false},t+1.5);
  tl.fromTo(hand,{scale:1},{scale:.82,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},t+2.05);
  tl.fromTo(btn,{scale:1},{scale:.92,duration:.1,yoyo:true,repeat:1,ease:'power2.inOut',immediateRender:false},t+2.05);
  tl.set(f,{opacity:0},t+2.15);tl.set(d,{opacity:1},t+2.15);tl.set(btn,{backgroundColor:'#EFEFEF',borderColor:'#DBDBDB'},t+2.15);
  const rg=el('sring');btn.append(rg);tl.fromTo(rg,{opacity:.9,scale:.6},{opacity:0,scale:1.8,duration:.5,ease:'power2.out',immediateRender:false},t+2.15);
  tl.to(hand,{opacity:0,y:200,duration:.3},t+2.9);
  const fox=el('fox sfox','','img');fox.src='assets/maskot.webp';fox.alt='';S.inn.append(fox);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},t+.4);tl.set(fox,{opacity:1},t+.4);BOBS.push({e:fox,a:14,f:2.6,ph:0});
  for(let i=0;i<6;i++){const h=el('sheart','♥');h.style.left=(560+i*60)+'px';S.inn.append(h);gsap.set(h,{opacity:0});
    tl.fromTo(h,{opacity:1,y:0,scale:.4},{opacity:0,y:-380-i*30,scale:1.3,x:(i%2?1:-1)*80,duration:1.2,ease:'power1.out',immediateRender:false},t+2.2+i*.08);}
  cap(S,t+.08,'mau bedah menu lainnya?','follow kasir umkm',{kwAt:1.9,out:t+3.5});
  cap(S,t+3.58,'biar nggak ketinggalan','tips berikutnya',{kwAt:.9});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
}
