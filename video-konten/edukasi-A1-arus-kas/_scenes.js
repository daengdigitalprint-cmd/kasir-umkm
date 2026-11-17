/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (37,88 dtk) lewat transkripsi per kata ---------- */
/* kata pembuka: Tahukah 0 · Laporan 4.28 · tapi 6.28 · Kemana 8.90 · Pertama 10.52 · Kedua 14.38 · Untung 17.04 · Jadi 22.04 · dan belanja 24.06 · Di Kasir 26.80 · Kasir 33.94 */
const C=[0,4.15,6.15,8.75,10.35,14.25,16.9,21.9,24.0,26.65,33.8,39.1];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const ease2=p=>1-Math.pow(1-clamp(p),2.4);
const qmark=(par,x,y,size=150)=>{const q=el('qs','?');q.style.cssText=`left:${x}px;top:${y}px;width:${size}px;height:${size}px;font-size:${size*.72}px;line-height:${size*.86}px`;par.append(q);return q;};
function phone(S,html,{from=1,cls='',at=null}={}){const ph=el('phone '+cls,html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});const t=at??S.t0+.02;
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},t);tl.set(ph,{opacity:1},t);return ph;}
const billN=(par,label,cls='')=>{const b=el('bill '+cls,`<i></i><b>${label}</b>`);par.append(b);return b;};
const box=(par,label)=>{const b=el('box','<i></i>'+label);par.append(b);return b;};

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — untung tapi KAS KOSONG: panah untung naik, laci terbuka kosong */
{const S=stage(0,'dark'),d=drawer(S.inn,540,1330),up=el('arr up','▲');S.inn.append(up);
  pop(d.g,.1,{from:.5,dur:.45});pop(up,.5,{rot:-20});BOBS.push({e:up,a:16,f:4,ph:0});
  tl.fromTo(d.g,{rotation:0},{rotation:3,duration:.08,yoyo:true,repeat:5,ease:'none',immediateRender:false},2.9);
  const em=el('emptyT','kosong');S.inn.append(em);pop(em,3.3,{rot:-10});
  cap(S,.05,'untung, tapi','kas kosong',{kwAt:2.8});}

/* 1 — laporan bilang untung RP3 JUTA */
{const S=stage(1,'cream'),ph=phone(S,'<div class="hd">Laba rugi</div><div class="lr"><small>Laba bersih</small><b></b></div><div class="row"><span>Penjualan</span><span>Rp12.400.000</span></div><div class="row"><span>Modal &amp; biaya</span><span>Rp9.400.000</span></div>');
  num($('.lr b',ph),'',t=>Math.round(3000000*ease2((t-S.t0-.7)/.75)/1000)*1000);
  tl.fromTo($$('.row',ph),{opacity:0,x:-60},{opacity:1,x:0,duration:.3,stagger:.2,ease:'power3.out',immediateRender:false},S.t0+1.0);
  cap(S,S.t0+.08,'laporan bilang untung','rp3 juta',{kwAt:1.12});}

/* 2 — di laci cuma RP500 RIBU */
{const S=stage(2,'orange'),d=drawer(S.inn,540,1180);pop(d.g,S.t0+.02,{from:.7,dur:.35});
  const bs=[['100.000',''],['200.000','b2'],['200.000','b3']].map(([l,c],i)=>{const b=billN(S.inn,l,c);gsap.set(b,{opacity:0});
    tl.set(b,{opacity:1,left:330,top:1700,rotation:0,scale:.75},S.t0+.3+i*.15);tl.to(b,{left:330+(i-1)*230,top:1360+i*40,rotation:(i-1)*10,duration:.4,ease:'back.out(1.6)'},S.t0+.3+i*.15);return b;});
  const n=num(S.inn,'mega2',t=>Math.round(500000*ease2((t-S.t0-1.0)/.7)/1000)*1000);tl.fromTo(n,{opacity:0},{opacity:1,duration:.2},S.t0+.3);
  cap(S,S.t0+.08,'di laci cuma','rp500 ribu',{kwAt:1.42});}

/* 3 — KE MANA SISANYA? batang untung pecah jadi tiga */
{const S=stage(3,'dark'),bar=el('split','<div class="sb kas"><span>Kas</span><b>0,5 jt</b></div><div class="sb piu"><span>Bon</span><b>2 jt</b></div><div class="sb stk"><span>Stok</span><b>0,5 jt</b></div>'),lbl=el('splitT','Untung Rp3 jt');
  S.inn.append(lbl,bar);pop(lbl,S.t0+.05,{from:.6,dur:.3});pop(bar,S.t0+.1,{from:.8,dur:.35});
  const sbs=$$('.sb',bar);tl.fromTo(sbs[1],{x:0},{x:0,y:0,duration:.01,immediateRender:false},S.t0);
  tl.to(sbs[0],{y:-60,rotation:-6,duration:.4,ease:'back.out(2)'},S.t0+.9);tl.to(sbs[1],{y:70,duration:.4,ease:'back.out(2)'},S.t0+1.05);tl.to(sbs[2],{y:-30,rotation:6,duration:.4,ease:'back.out(2)'},S.t0+1.2);
  const q=qmark(S.inn,850,520,140);pop(q,S.t0+.4,{rot:30});BOBS.push({e:q,a:14,f:3,ph:0});
  cap(S,S.t0+.08,'ke mana','sisanya?',{kwAt:.3});}

/* 4 — pertama, tertahan di BON PELANGGAN */
{const S=stage(4,'purple'),n=el('nota','<div class="nh">Buku bon</div>'+[['Bu Siti','450.000'],['Pak Andi','700.000'],['Warung Rina','850.000']].map(([a,b])=>`<div class="nl"><span>${a}</span><span>${b}</span></div>`).join('')+'<div class="nt"><span>Total</span><span>2.000.000</span></div>');
  S.inn.append(n);pop(n,S.t0+.02,{from:.7,rot:-8,dur:.4});
  tl.fromTo($$('.nl',n),{clipPath:'inset(0 100% 0 -20px)'},{clipPath:'inset(0 -40px 0 -20px)',duration:.35,stagger:.3,ease:'none',immediateRender:false},S.t0+.5);
  tl.fromTo($('.nt',n),{opacity:0,scale:1.4},{opacity:1,scale:1,duration:.3,ease:'back.out(2)',immediateRender:false},S.t0+2.65);
  const hg=el('hourg','⏳');S.inn.append(hg);pop(hg,S.t0+2.9,{rot:20});BOBS.push({e:hg,a:12,f:3,ph:1});
  cap(S,S.t0+.08,'pertama, tertahan di','bon pelanggan',{kwAt:1.67});}

/* 5 — kedua, jadi STOK NUMPUK */
{const S=stage(5,'cream');
  for(let i=0;i<5;i++){const b=box(S.inn,'STOK');gsap.set(b,{left:i<3?140+i*270:275+(i-3)*270,top:i<3?1420:1240,opacity:0});
    tl.fromTo(b,{opacity:1,y:-900,rotation:-14+i*7},{y:0,rotation:-3+i*1.5,duration:.42,ease:'bounce.out',immediateRender:false},S.t0+.15+i*.15);}
  const tg=el('tagx','Rp500.000');S.inn.append(tg);pop(tg,S.t0+1.2,{rot:-12});
  const dust=el('dust','');S.inn.append(dust);tl.fromTo(dust,{opacity:0},{opacity:1,duration:.4},S.t0+1.4);
  cap(S,S.t0+.08,'kedua, jadi stok','numpuk',{kwAt:1.32});}

/* 6 — UNTUNG ≠ KAS: kertas vs uang hari ini */
{const S=stage(6,'green'),L=el('cmp l','<div class="ci">📄</div><b>Untung</b><span>di atas kertas</span>'),R=el('cmp r','<div class="ci">💵</div><b>Kas</b><span>bisa dipakai hari ini</span>'),ne=el('neq','≠');
  S.inn.append(L,R,ne);
  tl.fromTo(L,{opacity:0,x:-500,rotation:-20},{opacity:1,x:0,rotation:-4,duration:.5,ease:'back.out(1.5)',immediateRender:false},S.t0+.1);
  tl.fromTo(R,{opacity:0,x:500,rotation:20},{opacity:1,x:0,rotation:4,duration:.5,ease:'back.out(1.5)',immediateRender:false},S.t0+2.15);
  pop(ne,S.t0+1.4,{from:2.5,rot:-30,dur:.4,ease:'back.out(2.5)'});
  cap(S,S.t0+.08,'untung itu','beda dengan kas',{kwAt:2.02});}

/* 7 — tagih piutang TEPAT WAKTU */
{const S=stage(7,'purple'),ph=phone(S,'<div class="hd">Piutang</div>'+[['Pak Andi','Rp700.000'],['Warung Rina','Rp850.000']].map(([a,b])=>`<div class="row"><span>${a}</span><span>${b}</span></div>`).join('')+'<div class="ok">Tagih</div>');
  tl.fromTo($$('.row',ph),{opacity:0,x:-60},{opacity:1,x:0,duration:.3,stagger:.18,ease:'power3.out',immediateRender:false},S.t0+.5);
  tl.fromTo($('.ok',ph),{opacity:0,scale:.4},{opacity:1,scale:1,duration:.35,ease:'back.out(2.4)',immediateRender:false},S.t0+.9);
  const fin=el('finger');S.inn.append(fin);gsap.set(fin,{opacity:0});
  tl.fromTo(fin,{opacity:0,x:600,y:1700},{opacity:1,x:540,y:1255,duration:.3,ease:'power2.out',immediateRender:false},S.t0+1.2);
  tl.fromTo($('.ok',ph),{scale:1},{scale:.88,duration:.08,yoyo:true,repeat:1,immediateRender:false},S.t0+1.5);tl.to(fin,{opacity:0,duration:.2},S.t0+1.8);
  for(let i=0;i<6;i++){const c=el('coin xl','Rp');S.inn.append(c);gsap.set(c,{opacity:0});
    tl.fromTo(c,{opacity:1,x:540,y:1250,scale:.4},{x:120+i*170,y:1760,scale:1,rotation:360,duration:.55,ease:'power2.out',immediateRender:false},S.t0+1.6+i*.07);}
  cap(S,S.t0+.08,'tagih piutang','tepat waktu',{kwAt:1.47});}

/* 8 — belanja stok SECUKUPNYA */
{const S=stage(8,'orange'),ls=el('nota shop','<div class="nh">Daftar belanja</div>'+[['Beras','2 karung'],['Minyak','5 liter'],['Gula','3 kg']].map(([a,b])=>`<div class="nl"><span>${a}</span><span>${b}</span></div>`).join(''));
  S.inn.append(ls);pop(ls,S.t0+.02,{from:.7,rot:6,dur:.4});
  $$('.nl',ls).forEach((l,i)=>{const ck=el('ck','✓');l.style.position='relative';l.append(ck);tl.fromTo(ck,{opacity:0,scale:2.5},{opacity:1,scale:1,duration:.25,ease:'back.out(2.5)',immediateRender:false},S.t0+.6+i*.3);});
  cap(S,S.t0+.08,'belanja stok','secukupnya',{kwAt:1.22});}

/* 9 — di aplikasi: kelihatan uangmu NYANGKUT DI MANA */
{const S=stage(9,'green'),ph=phone(S,'<div class="hd">Laporan</div>'+[['Total Piutang','Rp2.000.000'],['Nilai Stok','Rp500.000'],['Arus Kas','Rp500.000']].map(([a,b])=>`<div class="kc"><small>${a}</small><b>${b}</b></div>`).join(''),{cls:'rep'});
  $$('.kc',ph).forEach((k,i)=>{gsap.set(k,{opacity:0});tl.fromTo(k,{opacity:0,y:40,scale:.9},{opacity:1,y:0,scale:1,duration:.35,ease:'back.out(2)',immediateRender:false},[28.1,28.95,29.85][i]);});
  /* "jadi kelihatan uangmu nyangkut di mana": kartu piutang (yang terbesar) disorot */
  const k0=$('.kc',ph);tl.fromTo(k0,{backgroundColor:'#F4EFFF',borderColor:'#e2d8fb'},{backgroundColor:'#FFF3C4',borderColor:'#FFC83D',duration:.25,immediateRender:false},31.9);
  tl.fromTo(k0,{scale:1},{scale:1.08,duration:.18,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},32.0);
  cap(S,S.t0+.08,'di kasir umkm, semua','tercatat',{kwAt:3.7,out:31.5});
  cap(S,31.62,'kelihatan uangmu','nyangkut di mana',{kwAt:.98});}

/* 10 — maskot + logo */
const RISE=[];
{const S=stage(10,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';
  const cta=el('cta','<span>Untung tercatat, kas aman</span>');S.inn.append(lg,fox,cta);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},S.t0+.05);tl.set(fox,{opacity:1},S.t0+.05);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.55,ease:'back.out(1.8)',immediateRender:false},S.t0+.3);
  pop(cta,35.4,{from:.4,rot:-6});
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  RISE.forEach(o=>{if(t<C[10])return;const y=2100-((o.y0+(t-C[10])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
