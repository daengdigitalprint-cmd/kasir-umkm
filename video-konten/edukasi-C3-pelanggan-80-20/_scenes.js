/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (47,52 dtk) lewat transkripsi per kata ---------- */
/* kata pembuka: Dari 0 · Namanya 5.08 · Sekitar 8.36 · Contohnya 13.36 · Sebulan 19.14 · Sama 22.26 · Jadi 25.90 · Pertama ~28.7 · Kedua 31.70 · Ketiga 35.56 · Di Kasir 38.84 · Kasir 44.18 */
const C=[0,4.95,8.2,13.2,19.0,22.1,25.75,28.5,31.55,35.4,38.7,44.05,48.7];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const ease2=p=>1-Math.pow(1-clamp(p),2.4);
const rb=v=>Math.round(v).toLocaleString('id-ID');
const SHIRT=['#7943e5','#f97316','#57b01c','#FFC83D','#e2557a'];
const FACE={flat:'<path d="M40 68h20" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>',happy:'<path d="M38 64q12 12 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>'};
const person=(par,i,{x=0,y=0,mood='flat',s=1}={})=>{const p=el('person',`<svg viewBox="0 0 100 180"><rect x="18" y="86" width="64" height="94" rx="30" fill="${SHIRT[i%5]}" stroke="#3B1F12" stroke-width="6"/><circle cx="50" cy="48" r="34" fill="#FFD9B0" stroke="#3B1F12" stroke-width="6"/><path d="M18 40q32-38 64 0q-10-26-32-26t-32 26z" fill="#3B1F12"/><circle cx="38" cy="50" r="4.5" fill="#3B1F12"/><circle cx="62" cy="50" r="4.5" fill="#3B1F12"/>${FACE[mood]}</svg>`);
  p.style.left=x+'px';p.style.top=y+'px';p.style.width=170*s+'px';p.style.height=306*s+'px';par.append(p);return p;};
/* pelanggan mini: kepala + badan sederhana */
const mini=(par,x,y,i,w=64)=>{const m=el('mini',`<svg viewBox="0 0 40 52"><rect x="6" y="26" width="28" height="26" rx="12" fill="${SHIRT[i%5]}" stroke="#3B1F12" stroke-width="3"/><circle cx="20" cy="16" r="12" fill="#FFD9B0" stroke="#3B1F12" stroke-width="3"/></svg>`);m.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(m);return m;};
const CUP=`<svg viewBox="0 0 120 130"><path d="M14 30h76l-8 86q-1 8-9 8H31q-8 0-9-8z" fill="#fff" stroke="#3B1F12" stroke-width="7" stroke-linejoin="round"/><path d="M18 52h68l-5 50H23z" fill="#8a5a2b"/><path d="M90 46q24 0 22 22t-26 20" fill="none" stroke="#3B1F12" stroke-width="7"/><path d="M40 4q-8 8 0 16M60 4q-8 8 0 16" fill="none" stroke="#94a3b8" stroke-width="5" stroke-linecap="round"/></svg>`;
function phone(S,html,{from=1,cls=''}={}){const ph=el('phone '+cls,html);S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8*from},{y:0,rotation:-2*from,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);return ph;}
const badge=(S,n)=>{const b=el('tipn',String(n));S.inn.append(b);pop(b,S.t0+.15,{from:.2,rot:-30,dur:.4});BOBS.push({e:b,a:10,f:3,ph:n});return b;};

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}

/* ================= PANGGUNG ================= */
/* 0 — HOOK: 100 pelanggan bermunculan cepat, lalu 80 memudar dan 20 menyala emas */
const VIP=[3,8,12,17,21,26,34,39,41,47,52,58,63,66,71,77,84,88,92,97];
{const S=stage(0,'dark'),ms=[];
  for(let i=0;i<100;i++){const c=i%10,r=Math.floor(i/10),m=mini(S.inn,95+c*92,700+r*104,i,70);gsap.set(m,{opacity:0});ms.push(m);
    tl.fromTo(m,{opacity:0,scale:.2,y:-60},{opacity:1,scale:1,y:0,duration:.22,ease:'back.out(2.6)',immediateRender:false},.06+i*.016);}
  const n=num(S.inn,'cnt',t=>Math.max(0,Math.min(100,Math.floor((t-.06)/.016)+1)),v=>v+' pelanggan');tl.fromTo(n,{opacity:0},{opacity:1,duration:.1},.06);
  const rest=ms.filter((_,i)=>!VIP.includes(i)),vip=ms.filter((_,i)=>VIP.includes(i));
  tl.to(rest,{opacity:.18,filter:'grayscale(1)',duration:.3},2.2);
  tl.to(vip,{scale:1.35,duration:.3,ease:'back.out(3)'},2.2);
  vip.forEach(v=>{const g=el('glow');v.prepend(g);gsap.set(g,{opacity:0});tl.to(g,{opacity:1,duration:.25},2.25);});
  num(S.inn,'cnt2',t=>t<2.2?'':'20 yang bikin usahamu hidup',v=>v);
  cap(S,.04,'dari 100 pelanggan, cuma','20 yang penting',{kwAt:2.2});}

/* 1 — namanya aturan 80/20 */
{const S=stage(1,'cream'),a=el('rule','<b>80</b><i>/</i><b>20</b>');S.inn.append(a);
  [...a.children].forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:-300,rotation:(i-1)*20},{opacity:1,y:0,rotation:0,duration:.4,ease:'bounce.out',immediateRender:false},[6.25,6.5,6.7][i]);});
  cap(S,S.t0+.08,'namanya aturan','80/20',{kwAt:1.27});}

/* 2 — sekitar 20% pelanggan → 80% omzet */
{const S=stage(2,'purple'),bars=el('pb','<div class="pr"><span>Pelanggan</span><div class="tr"><i class="g"></i><em>20%</em></div></div><div class="pr"><span>Omzet</span><div class="tr"><i class="g"></i><em>80%</em></div></div>');S.inn.append(bars);
  pop(bars,S.t0+.02,{from:.7,dur:.35});
  const gs=$$('.g',bars);tl.fromTo(gs[0],{width:'0%'},{width:'20%',duration:.5,ease:'power3.out',immediateRender:false},8.9);tl.fromTo(gs[1],{width:'0%'},{width:'80%',duration:.8,ease:'power3.out',immediateRender:false},11.1);
  const ar=el('arrDn','⬇');S.inn.append(ar);pop(ar,10.2,{rot:0});BOBS.push({e:ar,a:14,f:4,ph:0});
  cap(S,S.t0+.08,'sekitar 20% pelanggan bawa','80% omzet',{kwAt:2.87});}

/* 3 — contoh: Pak Budi ngopi TIAP HARI */
{const S=stage(3,'orange'),p=person(S.inn,0,{x:180,y:900,mood:'happy',s:1.6}),cp=el('cup',CUP),nm=el('nm','Pak Budi'),tg=el('tagx','Rp20.000 / datang');S.inn.append(cp,nm,tg);
  tl.fromTo(p,{opacity:0,x:-500},{opacity:1,x:0,duration:.45,ease:'back.out(1.4)',immediateRender:false},S.t0+.05);
  pop(nm,14.25,{rot:-8});pop(cp,14.7,{from:.3,rot:20});pop(tg,17.2,{rot:6});
  BOBS.push({e:p,a:10,f:4,ph:0},{e:cp,a:14,f:3,ph:1});
  cap(S,S.t0+.08,'contoh pak budi, ngopi','tiap hari',{kwAt:2.87});}

/* 4 — sebulan belanja RP600 RIBU */
{const S=stage(4,'green'),k=el('cal m30','<div class="ch">Pak Budi · 30 hari</div><div class="cg">'+Array.from({length:30},()=>`<div class="cd">${CUP}</div>`).join('')+'</div>');S.inn.append(k);
  pop(k,S.t0+.02,{from:.6,rot:-6,dur:.35});
  $$('.cd svg',k).forEach((c,i)=>{gsap.set(c,{opacity:0});tl.fromTo(c,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.15,ease:'back.out(2.5)',immediateRender:false},19.4+i*.043);});
  const n=num(S.inn,'mega2',t=>Math.round(600000*clamp((t-19.4)/1.3)/20000)*20000,v=>'Rp'+rb(v));tl.fromTo(n,{opacity:0},{opacity:1,duration:.2},19.4);
  cap(S,S.t0+.08,'sebulan belanja','rp600 ribu',{kwAt:1.54});}

/* 5 — sama dengan 30 PEMBELI sekali lewat */
{const S=stage(5,'dark'),one=el('oneP','<b>1</b><span>Pak Budi</span>'),eq=el('eqS','=');S.inn.append(one,eq);
  pop(one,S.t0+.05,{from:.5,dur:.35});pop(eq,S.t0+.4,{from:2,rot:-20,dur:.3});
  for(let i=0;i<30;i++){const c=i%6,r=Math.floor(i/6),m=mini(S.inn,240+c*110,1080+r*120,i+1,80);gsap.set(m,{opacity:0});
    tl.fromTo(m,{opacity:0,scale:.2},{opacity:1,scale:1,duration:.18,ease:'back.out(2.6)',immediateRender:false},22.85+i*.045);}
  cap(S,S.t0+.08,'sama dengan','30 pembeli',{kwAt:.67});}

/* 6 — jadi, jaga PELANGGAN SETIA */
{const S=stage(6,'purple'),p=person(S.inn,0,{x:404,y:980,mood:'happy',s:1.6}),sh=el('shield','♥');S.inn.append(sh);
  tl.fromTo(p,{opacity:0,y:300},{opacity:1,y:0,duration:.45,ease:'back.out(1.6)',immediateRender:false},S.t0+.05);
  pop(sh,S.t0+.5,{from:.3,rot:-20,dur:.45});BOBS.push({e:sh,a:12,f:3,ph:0});
  for(let i=0;i<5;i++){const h=el('heart','♥');h.style.left=(160+i*180)+'px';h.style.top=(1580-(i%2)*80)+'px';S.inn.append(h);pop(h,S.t0+.8+i*.1,{rot:i%2?20:-20});BOBS.push({e:h,a:14,f:3.4,ph:i});}
  cap(S,S.t0+.08,'jadi, jaga','pelanggan setia',{kwAt:1.07});}

/* 7 — tips 1: KENALI MEREKA (kaca pembesar di daftar) */
{const S=stage(7,'cream'),lst=el('lst',['Pak Budi','Bu Sari','Mas Dika','Bu Rina'].map((n,i)=>`<div class="li"><i style="background:${SHIRT[i]}"></i>${n}</div>`).join('')),mg=el('mag','<i></i>');S.inn.append(lst,mg);badge(S,1);
  pop(lst,S.t0+.05,{from:.7,dur:.35});
  tl.fromTo(mg,{opacity:0,x:600,y:300},{opacity:1,x:0,y:0,duration:.5,ease:'back.out(1.4)',immediateRender:false},29.5);
  tl.fromTo($('.li',lst),{backgroundColor:'#fff'},{backgroundColor:'#FFF3C4',duration:.2,immediateRender:false},30.0);
  cap(S,S.t0+.08,'pertama,','kenali mereka',{kwAt:1.12});}

/* 8 — tips 2: hafal pesanan, SAPA NAMANYA */
{const S=stage(8,'green'),p=person(S.inn,0,{x:560,y:1060,mood:'happy',s:1.5}),b=el('bub','Kopi susu kurang gula,<br>Pak Budi?');S.inn.append(b);badge(S,2);
  tl.fromTo(p,{opacity:0,x:500},{opacity:1,x:0,duration:.45,ease:'back.out(1.4)',immediateRender:false},S.t0+.05);
  pop(b,32.85,{rot:-6});BOBS.push({e:b,a:10,f:3,ph:0},{e:p,a:8,f:4,ph:1});
  cap(S,S.t0+.08,'kedua, hafal pesanannya,','sapa namanya',{kwAt:2.27});}

/* 9 — tips 3: KASIH POIN atau hadiah kecil (kartu stempel terisi) */
{const S=stage(9,'orange'),cd=el('stampc','<div class="sh">Kartu poin</div><div class="sg">'+Array.from({length:10},(_,i)=>`<i>${i===9?'🎁':''}</i>`).join('')+'</div>');S.inn.append(cd);badge(S,3);
  pop(cd,S.t0+.05,{from:.6,rot:-8,dur:.4});
  $$('.sg i',cd).forEach((c,i)=>tl.fromTo(c,{backgroundColor:'#fff'},{backgroundColor:i===9?'#a0ea71':'#7943e5',duration:.12,immediateRender:false},36.5+i*.12));
  cap(S,S.t0+.08,'ketiga,','kasih poin',{kwAt:.87});}

/* 10 — di aplikasi: Total Belanja & Poin tercatat otomatis */
{const S=stage(10,'purple'),ph=phone(S,'<div class="hd">Pelanggan</div>'+[['Pak Budi','Rp600.000','120'],['Bu Sari','Rp410.000','82'],['Mas Dika','Rp150.000','30']].map(([a,b,c])=>`<div class="cr"><b>${a}</b><span>Total Belanja ${b}</span><em>${c} poin</em></div>`).join(''));
  $$('.cr',ph).forEach((r,i)=>{gsap.set(r,{opacity:0});tl.fromTo(r,{opacity:0,x:-60},{opacity:1,x:0,duration:.3,ease:'power3.out',immediateRender:false},40.3+i*.35);});
  const r0=$('.cr',ph);tl.fromTo(r0,{backgroundColor:'#f1f5f9'},{backgroundColor:'#FFF3C4',duration:.25,immediateRender:false},42.4);
  cap(S,S.t0+.08,'di kasir umkm, semua','tercatat otomatis',{kwAt:3.62});}

/* 11 — maskot + logo */
const RISE=[];
{const S=stage(11,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';
  const cta=el('cta','<span>Kenali pelanggan terbaikmu</span>');S.inn.append(lg,fox,cta);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},S.t0+.05);tl.set(fox,{opacity:1},S.t0+.05);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.55,ease:'back.out(1.8)',immediateRender:false},S.t0+.3);
  pop(cta,45.6,{from:.4,rot:-6});
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  RISE.forEach(o=>{if(t<C[11])return;const y=2100-((o.y0+(t-C[11])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
