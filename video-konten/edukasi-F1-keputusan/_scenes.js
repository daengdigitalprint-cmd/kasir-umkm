/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (25,88 dtk) lewat transkripsi per kata ---------- */
/* kata pembuka: Tahukah 0 · pemilik 1.48 · Masak 5.02 · belanja 6.72 · harga 8.04 · stok 9.48 · Sayangnya 11.18 · Kalau 15.04 · keputusanmu 17.16 · Usaha 21.04 · Mulai 24.50 */
const C=[0,1.35,4.9,6.6,7.95,9.4,11.0,14.9,17.0,20.85,24.35,26.9];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const SHIRT=['#7943e5','#f97316','#57b01c','#FFC83D','#e2557a'];
const FACE={flat:'<path d="M40 68h20" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',happy:'<path d="M38 64q12 12 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',
  think:'<path d="M40 68q6-4 12 0t10 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/><path d="M30 34l14 2M70 30l-14 4" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>'};
const person=(par,i,{x=0,y=0,mood='flat',s=1}={})=>{const p=el('person',`<svg viewBox="0 0 100 180"><rect x="18" y="86" width="64" height="94" rx="30" fill="${SHIRT[i%5]}" stroke="#3B1F12" stroke-width="6"/><circle cx="50" cy="48" r="34" fill="#FFD9B0" stroke="#3B1F12" stroke-width="6"/><path d="M18 40q32-38 64 0q-10-26-32-26t-32 26z" fill="#3B1F12"/><circle cx="38" cy="50" r="4.5" fill="#3B1F12"/><circle cx="62" cy="50" r="4.5" fill="#3B1F12"/>${FACE[mood]}</svg>`);
  p.style.left=x+'px';p.style.top=y+'px';p.style.width=170*s+'px';p.style.height=306*s+'px';par.append(p);return p;};
const qmark=(par,x,y,size=150)=>{const q=el('qs','?');q.style.cssText=`left:${x}px;top:${y}px;width:${size}px;height:${size}px;font-size:${size*.72}px;line-height:${size*.86}px`;par.append(q);return q;};
const DIE=(p)=>{const pip={3:[[28,28],[50,50],[72,72]],5:[[28,28],[72,28],[50,50],[28,72],[72,72]],6:[[30,24],[70,24],[30,50],[70,50],[30,76],[70,76]]}[p];
  return `<svg viewBox="0 0 100 100"><rect x="5" y="5" width="90" height="90" rx="20" fill="#fff" stroke="#3B1F12" stroke-width="6"/>${pip.map(([x,y])=>`<circle cx="${x}" cy="${y}" r="8.5" fill="#7943e5"/>`).join('')}</svg>`;};

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — TAHUKAH KAMU? */
{const S=stage(0,'dark'),q=qmark(S.inn,365,760,350);
  pop(q,.12,{from:.2,rot:-40,dur:.5,ease:'back.out(2.4)'});BOBS.push({e:q,a:18,f:4,ph:0});
  cap(S,.05,'tahukah','kamu?',{kwAt:.45});}

/* 1 — pemilik warung ambil PULUHAN KEPUTUSAN: tanda tanya bermunculan di sekitar kepala */
{const S=stage(1,'cream'),p=person(S.inn,0,{x:404,y:1060,mood:'think',s:1.6});
  tl.fromTo(p,{opacity:0,y:300},{opacity:1,y:0,duration:.45,ease:'back.out(1.6)',immediateRender:false},S.t0+.05);
  const SP=[[150,820],[760,800],[90,1180],[820,1160],[300,640],[620,620],[170,1500],[780,1480],[460,520],[40,960],[880,980],[600,1640]];
  SP.forEach(([x,y],i)=>{const q=qmark(S.inn,x,y,120+(i%3)*30);pop(q,1.6+i*.24,{from:.2,rot:(i%2?1:-1)*40,dur:.35,ease:'back.out(2.6)'});BOBS.push({e:q,a:12,f:3+i%3,ph:i});});
  BOBS.push({e:p,a:8,f:3,ph:1});
  cap(S,S.t0+.08,'pemilik warung ambil','puluhan keputusan',{kwAt:1.23});}

/* 2 — masak BERAPA PORSI? */
{const S=stage(2,'green');
  for(let i=0;i<4;i++){const pl=el('plate','<i></i>');S.inn.append(pl);gsap.set(pl,{left:300,top:1400-i*120,opacity:0});
    tl.fromTo(pl,{opacity:1,y:-900,rotation:(i%2?1:-1)*20},{y:0,rotation:(i%2?1:-1)*3,duration:.4,ease:'bounce.out',immediateRender:false},S.t0+.15+i*.2);}
  const q=qmark(S.inn,760,760,170);pop(q,S.t0+.6,{rot:30});BOBS.push({e:q,a:14,f:3.4,ph:0});
  cap(S,S.t0+.08,'masak','berapa porsi?',{kwAt:.55});}

/* 3 — belanja KAPAN? */
{const S=stage(3,'orange'),days=['Sen','Sel','Rab','Kam','Jum','Sab','Min'];
  const k=el('cal wk','<div class="ch">Belanja bahan</div><div class="cg">'+days.map(d=>`<div class="cd">${d}<em>?</em></div>`).join('')+'</div>');S.inn.append(k);
  pop(k,S.t0+.02,{from:.6,rot:-6,dur:.35});
  $$('.cd',k).forEach((c,i)=>{const e=$('em',c);tl.fromTo(e,{opacity:0,scale:2},{opacity:1,scale:1,duration:.18,ease:'back.out(2.5)',immediateRender:false},S.t0+.3+i*.1);});
  cap(S,S.t0+.08,'belanja','kapan?',{kwAt:.3});}

/* 4 — harga NAIK ATAU TIDAK? */
{const S=stage(4,'purple'),tg=el('ptag','<i></i><b>Rp15.000</b>'),up=el('arr up','▲'),dn=el('arr dn','▼');S.inn.append(tg,up,dn);
  pop(tg,S.t0+.02,{from:.5,rot:-14,dur:.4});
  pop(up,S.t0+.3,{rot:-20});pop(dn,S.t0+.55,{rot:20});
  tl.fromTo(tg,{rotation:-8},{rotation:8,duration:.22,yoyo:true,repeat:3,ease:'sine.inOut',immediateRender:false},S.t0+.6);
  BOBS.push({e:up,a:20,f:5,ph:0},{e:dn,a:20,f:5,ph:3.14});
  cap(S,S.t0+.08,'harga','naik atau tidak?',{kwAt:.25});}

/* 5 — stok CUKUP? */
{const S=stage(5,'cream');
  for(let i=0;i<3;i++){const bx=el('box','<i></i>BAHAN');S.inn.append(bx);gsap.set(bx,{left:280+(i%2)*60,top:1380-i*190,opacity:0});
    tl.fromTo(bx,{opacity:1,y:-900,rotation:-12+i*10},{y:0,rotation:-3+i*3,duration:.42,ease:'bounce.out',immediateRender:false},S.t0+.1+i*.15);}
  const q=qmark(S.inn,760,900,170);pop(q,S.t0+.5,{rot:-30});BOBS.push({e:q,a:14,f:3.4,ph:1});
  cap(S,S.t0+.08,'stok','cukup?',{kwAt:.3});}

/* 6 — kebanyakan pakai PERASAAN: pikiran "kayaknya…" + dadu */
{const S=stage(6,'dark'),p=person(S.inn,0,{x:404,y:1300,mood:'think',s:1.5}),th=el('think','<i class="c1"></i><i class="c2"></i><div class="tq">kayaknya…</div>');S.inn.append(th);
  tl.fromTo(p,{opacity:0,y:300},{opacity:1,y:0,duration:.45,ease:'back.out(1.6)',immediateRender:false},S.t0+.05);
  pop(th,S.t0+.5,{from:.4,rot:-6,dur:.45});
  [[170,1460,6,-1],[740,1440,3,1]].forEach(([x,y,pp,s],i)=>{const d=el('die',DIE(pp));d.style.left=x+'px';d.style.top=y+'px';S.inn.append(d);gsap.set(d,{opacity:0});
    tl.fromTo(d,{opacity:1,x:s*-600,y:-400,rotation:-360*s},{x:0,y:0,rotation:s*12,duration:.8,ease:'bounce.out',immediateRender:false},S.t0+1.4+i*.2);BOBS.push({e:d,a:10,f:2.6,ph:i*2});});
  tl.fromTo(p,{rotation:-5},{rotation:5,duration:.5,yoyo:true,repeat:5,ease:'sine.inOut',immediateRender:false},S.t0+.6);
  cap(S,S.t0+.08,'kebanyakan pakai','perasaan',{kwAt:2.42});}

/* 7 — mulai dari CATATAN penjualan: struk tercetak */
{const S=stage(7,'green'),pw=el('paperW'),p=el('paper','<h4>Penjualan hari ini</h4><div class="prow"><span>Nasi goreng ×18</span><span>270.000</span></div><div class="prow"><span>Es teh ×31</span><span>155.000</span></div><div class="prow"><span>Mie ayam ×12</span><span>144.000</span></div><div class="ptot"><small>Total</small><b>Rp569.000</b></div>');
  pw.append(p);S.inn.append(pw,el('slot'));
  tl.fromTo(pw,{height:0},{height:860,duration:1.0,ease:'steps(11)',immediateRender:false},S.t0+.15);
  tl.fromTo($$('.prow',p),{opacity:0,x:-30},{opacity:1,x:0,duration:.25,ease:'power3.out',stagger:.15,immediateRender:false},S.t0+.5);
  tl.fromTo($('.ptot',p),{opacity:0,y:20},{opacity:1,y:0,duration:.3,ease:'back.out(2)',immediateRender:false},S.t0+1.05);
  cap(S,S.t0+.08,'mulai dari','catatan',{kwAt:.72});}

/* 8 — keputusan jadi LEBIH UNTUNG: grafik naik + cepat / tepat / untung */
{const S=stage(8,'purple'),ch=el('bars2','<i class="g1"></i><i class="g2"></i><i class="g3"></i><i class="g4"></i>');S.inn.append(ch);
  pop(ch,S.t0+.02,{from:.7,dur:.35});
  $$('i',ch).forEach((b,i)=>tl.fromTo(b,{scaleY:0},{scaleY:1,duration:.45,ease:'back.out(1.8)',immediateRender:false},S.t0+.2+i*.15));
  [['cepat',18.0,-8,110,1450],['tepat',18.95,6,400,1590],['untung',19.95,-4,640,1450]].forEach(([w,t,r,x,y])=>{const g=el('tag','✓ '+w);g.style.cssText=`left:${x}px;top:${y}px;background:#fff;color:#2d8a12`;S.inn.append(g);pop(g,t,{rot:r*3});BOBS.push({e:g,a:10,f:3,ph:x});});
  cap(S,S.t0+.08,'keputusan jadi','lebih untung',{kwAt:2.82});}

/* 9 — bukan karena HOKI, tapi karena DATA */
{const S=stage(9,'orange');
  const ds=[[220,880,5,-1],[600,920,6,1]].map(([x,y,pp,s],i)=>{const d=el('die',DIE(pp));d.style.left=x+'px';d.style.top=y+'px';S.inn.append(d);gsap.set(d,{opacity:0});
    tl.fromTo(d,{opacity:1,x:s*-600,y:-400,rotation:-360*s},{x:0,y:0,rotation:s*10,duration:.7,ease:'bounce.out',immediateRender:false},S.t0+.2+i*.15);return d;});
  const x=el('bigx');S.inn.append(x);tl.fromTo(x,{opacity:0,scale:2.4,rotation:-30},{opacity:1,scale:1,rotation:-8,duration:.3,ease:'back.out(2.5)',immediateRender:false},22.7);
  tl.to([...ds,x],{opacity:0,y:600,rotation:40,duration:.3,ease:'power2.in'},23.05);
  const ph=el('phone rep','<div class="hd">Laporan</div><div class="minib"><i></i><i></i><i></i><i></i></div><div class="tot"><span>Untung</span><span>Rp2,4 jt</span></div>');S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8},{y:0,rotation:-2,duration:.5,ease:'back.out(1.3)',immediateRender:false},23.2);tl.set(ph,{opacity:0},S.t0);tl.set(ph,{opacity:1},23.2);
  $$('.minib i',ph).forEach((b,i)=>tl.fromTo(b,{scaleY:0},{scaleY:1,duration:.35,ease:'back.out(2)',immediateRender:false},23.45+i*.1));
  tl.fromTo($('.tot',ph),{opacity:0},{opacity:1,duration:.25,immediateRender:false},23.9);
  cap(S,S.t0+.08,'bukan karena','hoki',{kwAt:1.5,out:23.0});
  cap(S,23.1,'tapi karena','data',{kwAt:.5});}

/* 10 — maskot + logo + ajakan */
const RISE=[];
{const S=stage(10,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';
  const cta=el('cta','<span>Mulai catat hari ini</span>');S.inn.append(lg,fox,cta);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},S.t0+.05);tl.set(fox,{opacity:1},S.t0+.05);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.55,ease:'back.out(1.8)',immediateRender:false},S.t0+.3);
  pop(cta,24.5,{from:.4,rot:-6});
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  RISE.forEach(o=>{if(t<C[10])return;const y=2100-((o.y0+(t-C[10])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
