/* ---------- batas panggung (dtk) — disetel ulang saat VO masuk ---------- */
const C=[0,3.0,5.0,7.6,9.4,11.4,13.6,16.2,18.6,20.8,25.4,28.0,31.0];
const DURATION=C[C.length-1];
const SPOTS=[], BOBS=[], NUMS=[];
const KQ=i=>Math.min(1,(C[i+1]-C[i])/1.3);
const countTo=(a,dur,v,step=1)=>t=>{const p=clamp((t-a)/dur);return p>=1?v:Math.round(v*(1-Math.pow(1-p,2.2))/step)*step;};
const lin=(a,dur,v)=>t=>v*clamp((t-a)/dur);
const num=(par,cls,f,fm=fmt)=>{const e=el(cls);par.append(e);NUMS.push({e,f,fm});return e;};
const mmss=v=>`${Math.floor(v/60)}:${String(Math.floor(v%60)).padStart(2,'0')}`;
const tmr=(par,f,cls='')=>{const w=el('tmr '+cls,'<i></i><b></b>');par.append(w);NUMS.push({e:$('b',w),f,fm:mmss});return w;};
/* orang antre: kepala + badan, warna baju berganti */
const SHIRT=['#7943e5','#f97316','#57b01c','#FFC83D','#e2557a'];
const person=(par,i,{x=0,y=0,happy=false,s=1}={})=>{const p=el('person',`<svg viewBox="0 0 100 180"><rect x="18" y="86" width="64" height="94" rx="30" fill="${SHIRT[i%5]}" stroke="#3B1F12" stroke-width="6"/><circle cx="50" cy="48" r="34" fill="#FFD9B0" stroke="#3B1F12" stroke-width="6"/><path d="M18 40q32-38 64 0q-10-26-32-26t-32 26z" fill="#3B1F12"/><circle cx="38" cy="50" r="4.5" fill="#3B1F12"/><circle cx="62" cy="50" r="4.5" fill="#3B1F12"/>${happy?'<path d="M38 64q12 12 24 0" fill="none" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>':'<path d="M40 68h20" stroke="#3B1F12" stroke-width="5" stroke-linecap="round"/>'}</svg>`);
  p.style.left=x+'px';p.style.top=y+'px';p.style.transform=`scale(${s})`;par.append(p);return p;};

/* ---------- panggung: cut + geser 96 px, lalu push pelan sampai cut berikutnya ---------- */
function stage(i,bg){const t0=C[i],t1=C[i+1],st=el('st '+bg),spot=el('spot'),inn=el('in');st.append(spot,inn);stageEl.insertBefore(st,chip);
  SPOTS.push({spot,ph:i*1.3});
  tl.set(st,{autoAlpha:1},t0); if(i<C.length-2)tl.set(st,{autoAlpha:0},t1);
  const dir=i%2?1:-1;
  tl.fromTo(inn,{x:dir*96,scale:1.1},{x:0,scale:1,duration:.5,ease:'power3.out',immediateRender:false},t0);
  tl.fromTo(inn,{scale:1},{scale:1.06,duration:t1-t0-.5,ease:'none',immediateRender:false},t0+.5);
  return {st,inn,t0,t1};}
/* nota tulis tangan: baris muncul kiri→kanan seperti ditulis, pensil mengikuti */
const NOTA=[['Es teh 2','10.000'],['Nasi goreng','15.000'],['Mie ayam','12.000'],['Kerupuk 3','6.000'],['Air mineral','4.000']];
function nota(par,{total=null,strike=false}={}){const n=el('nota','<div class="nh">Nota</div>'+NOTA.map(([a,b])=>`<div class="nl"><span>${a}</span><span>${b}</span></div>`).join('')+(total?`<div class="nt"><span>Total</span><span class="nv">${total}</span><i class="ns"></i><span class="nfix">47.000</span></div>`:''));
  const pen=el('pen','<svg viewBox="0 0 40 200"><path d="M8 30h24v140l-12 26-12-26z" fill="#FFC83D" stroke="#3B1F12" stroke-width="5" stroke-linejoin="round"/><path d="M8 30h24v-14q0-12-12-12t-12 12z" fill="#e2557a" stroke="#3B1F12" stroke-width="5"/><path d="M8 170l12 26 12-26z" fill="#FFE3B8" stroke="#3B1F12" stroke-width="5" stroke-linejoin="round"/></svg>');
  par.append(n,pen);return {n,pen};}
function write(N,t0,gap,{lines=null}={}){const ls=lines||$$('.nl',N.n);ls.forEach((l,i)=>{const t=t0+i*gap;
  tl.fromTo(l,{clipPath:'inset(0 100% 0 0)'},{clipPath:'inset(0 0% 0 0)',duration:gap*.85,ease:'none',immediateRender:false},t);
  tl.fromTo(N.pen,{x:250,y:330+i*92},{x:770,y:330+i*92,duration:gap*.85,ease:'none',immediateRender:false},t);});
  tl.set(N.pen,{opacity:1},t0);}

/* ================= PANGGUNG ================= */
/* 0 — nota vs ponsel: ADU CEPAT */
{const S=stage(0,'dark'),L=el('vs l','<svg viewBox="0 0 100 100"><rect x="22" y="10" width="56" height="80" rx="4" fill="#fff" stroke="#3B1F12" stroke-width="5"/><path d="M32 28h36M32 42h36M32 56h22M32 70h30" stroke="#94a3b8" stroke-width="5" stroke-linecap="round"/></svg>'),R=el('vs r','<svg viewBox="0 0 100 100"><rect x="28" y="6" width="44" height="88" rx="9" fill="#7943e5" stroke="#3B1F12" stroke-width="5"/><rect x="34" y="16" width="32" height="58" rx="3" fill="#fff"/><path d="M39 28h22M39 40h22M39 52h12" stroke="#a0ea71" stroke-width="5" stroke-linecap="round"/><circle cx="50" cy="84" r="4" fill="#fff"/></svg>'),V=el('vsb','VS');
  S.inn.append(L,R,V);
  tl.fromTo(L,{opacity:0,x:-700,rotation:-30},{opacity:1,x:0,rotation:-6,duration:.55,ease:'back.out(1.4)',immediateRender:false},.15);
  tl.fromTo(R,{opacity:0,x:700,rotation:30},{opacity:1,x:0,rotation:6,duration:.55,ease:'back.out(1.4)',immediateRender:false},.3);
  pop(V,.75,{from:3,rot:-30,dur:.4,ease:'back.out(2.5)'});
  tl.fromTo([L,R],{scale:1},{scale:1.06,duration:.1,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},.95);
  BOBS.push({e:L,a:12,f:3,ph:0},{e:R,a:12,f:3,ph:3.1});
  cap(S,.15,'nota tulis vs kasir digital','adu cepat',{kwAt:1.1});}

/* 1 — lima pembeli antre */
{const S=stage(1,'cream');
  for(let i=0;i<5;i++){const p=person(S.inn,i,{x:70+i*190,y:880});tl.fromTo(p,{opacity:0,x:900},{opacity:1,x:0,duration:.5,ease:'back.out(1.3)',immediateRender:false},S.t0+.05+i*.1);BOBS.push({e:p,a:8,f:5,ph:i});}
  cap(S,S.t0+.1,'yang antre ada','5 pembeli',{kwAt:.5});}

/* 2 — nota: harga ditulis satu-satu, stopwatch berjalan */
{const S=stage(2,'orange'),N=nota(S.inn);
  write(N,S.t0+.3,.42);
  tmr(S.inn,lin(S.t0,C[3]-C[2],40));
  cap(S,S.t0+.1,'pakai nota, harga','ditulis satu-satu');}

/* 3 — kalkulator: total dihitung sendiri */
{const S=stage(3,'cream'),k=el('calc','<div class="cs"></div><div class="ck">'+['7','8','9','÷','4','5','6','×','1','2','3','−','0','000','=','+'].map(x=>`<i>${x}</i>`).join('')+'</div>');S.inn.append(k);
  pop(k,S.t0+.02,{from:.6,rot:-8,dur:.4});
  const seq=[1,13,14,4,5,8,13,14,0,10,12,13,6,12,13,14];const keys=$$('.ck i',k);
  seq.forEach((ki,j)=>tl.fromTo(keys[ki],{scale:1,backgroundColor:'#fff'},{scale:.86,backgroundColor:'#FFC83D',duration:.05,yoyo:true,repeat:1,ease:'power1.out',immediateRender:false},S.t0+.25+j*.08));
  const SC=['10.000','10.000+15.000','25.000+12.000','37.000+6.000','43.000+4.000','47.000'];
  num($('.cs',k),'csv',t=>Math.min(SC.length-1,Math.max(0,Math.floor((t-S.t0-.25)/.26))),v=>SC[v]);
  tmr(S.inn,lin(S.t0,C[4]-C[3],30,),'');NUMS[NUMS.length-1].f=t=>40+30*clamp((t-S.t0)/(C[4]-C[3]));
  cap(S,S.t0+.1,'totalnya','dihitung sendiri',{kwAt:.2});}

/* 4 — salah hitung: total 52.000 dicoret jadi 47.000 */
{const S=stage(4,'dark'),N=nota(S.inn,{total:'52.000'});gsap.set(N.pen,{opacity:0});
  gsap.set(N.n,{y:-80});
  const nt=$('.nt',N.n);
  tl.fromTo(nt,{clipPath:'inset(0 100% 0 0)'},{clipPath:'inset(0 0% 0 0)',duration:.35,ease:'none',immediateRender:false},S.t0+.15);
  tl.fromTo($('.ns',nt),{scaleX:0},{scaleX:1,duration:.25,ease:'power3.out',immediateRender:false},S.t0+.75);
  tl.fromTo($('.nfix',nt),{opacity:0,scale:2,rotation:-20},{opacity:1,scale:1,rotation:-8,duration:.35,ease:'back.out(2.5)',immediateRender:false},S.t0+1.0);
  const ex=el('qm','!');ex.style.cssText='left:820px;top:560px';S.inn.append(ex);pop(ex,S.t0+.75,{rot:30});BOBS.push({e:ex,a:14,f:4,ph:1});
  tl.fromTo(N.n,{rotation:-3},{rotation:2,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},S.t0+.8);
  tmr(S.inn,t=>70+30*clamp((t-S.t0)/(C[5]-C[4])));
  cap(S,S.t0+.1,'kadang malah','salah hitung',{kwAt:.5});}

/* 5 — stopwatch: satu pembeli 2 MENIT */
{const S=stage(5,'purple'),sw=el('sw','<i class="btn"></i><div class="face"></div>');S.inn.append(sw);
  pop(sw,S.t0+.02,{from:.6,dur:.45});
  num($('.face',sw),'swv',t=>100+20*clamp((t-S.t0-.1)/.9),mmss);
  tl.fromTo(sw,{rotation:0},{rotation:6,duration:.06,yoyo:true,repeat:7,ease:'none',immediateRender:false},S.t0+1.0);
  cap(S,S.t0+.1,'satu pembeli','2 menit',{kwAt:.6});}

/* 6 — ponsel kasir: tinggal TAP PRODUK */
{const S=stage(6,'green'),TI=[['Es teh','5.000'],['Nasi goreng','15.000'],['Mie ayam','12.000'],['Kerupuk','2.000'],['Air mineral','4.000'],['Kopi','6.000']];
  const ph=el('phone','<div class="hd">Kasir</div><div class="tiles">'+TI.map(([a,b])=>`<div class="tile"><span>${a}</span><b>${b}</b></div>`).join('')+'</div><div class="cart"><span>Keranjang</span><b class="cn">0 item</b></div>');
  S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:8},{y:0,rotation:-2,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);
  const tiles=$$('.tile',ph),taps=[0,0,1,2,3,3,3,4];
  const fin=el('finger');S.inn.append(fin);gsap.set(fin,{opacity:0});
  taps.forEach((ti,j)=>{const t=S.t0+.7+j*.2,r=Math.floor(ti/2),c=ti%2;
    tl.to(fin,{opacity:1,x:400+c*262,y:1000+r*172,duration:.1,ease:'power2.out'},t-.1);
    tl.fromTo(tiles[ti],{scale:1,backgroundColor:'#f1f5f9'},{scale:.9,backgroundColor:'#DCF7C8',duration:.06,yoyo:true,repeat:1,immediateRender:false},t);});
  num($('.cn',ph),'',t=>Math.max(0,Math.min(8,Math.floor((t-S.t0-.7)/.2)+1)),v=>v+' item');
  tl.to(fin,{opacity:0,duration:.2},S.t0+2.4);
  tmr(S.inn,t=>11*clamp((t-S.t0)/(C[7]-C[6])),'g');
  cap(S,S.t0+.1,'pakai kasir umkm, tinggal','tap produk',{kwAt:.4});}

/* 7 — total & kembalian OTOMATIS */
{const S=stage(7,'cream'),ph=el('phone','<div class="hd">Pembayaran</div><div class="row"><span>Total</span><span>Rp47.000</span></div><div class="row"><span>Uang diterima</span><span>Rp100.000</span></div><div class="tot"><span>Kembalian</span><span>Rp53.000</span></div><div class="ok">Selesai</div>');
  S.inn.append(ph);gsap.set(ph,{transformOrigin:'50% 100%'});
  tl.fromTo(ph,{opacity:1,y:1300,rotation:-8},{y:0,rotation:2,duration:.55,ease:'back.out(1.3)',immediateRender:false},S.t0+.02);tl.set(ph,{opacity:1},S.t0+.02);
  tl.fromTo($$('.row',ph),{opacity:0,x:-60},{opacity:1,x:0,duration:.3,ease:'power3.out',stagger:.25,immediateRender:false},S.t0+.5);
  tl.fromTo($('.tot',ph),{opacity:0,scale:.6},{opacity:1,scale:1,duration:.4,ease:'back.out(2.4)',immediateRender:false},S.t0+1.1);
  tl.fromTo($('.ok',ph),{opacity:0,scale:.3},{opacity:1,scale:1,duration:.4,ease:'back.out(2.6)',immediateRender:false},S.t0+1.6);
  tmr(S.inn,t=>11+4*clamp((t-S.t0)/(C[8]-C[7])),'g');
  cap(S,S.t0+.1,'total dan kembalian','otomatis',{kwAt:.9});}

/* 8 — stopwatch hijau: satu pembeli 15 DETIK */
{const S=stage(8,'green'),sw=el('sw g','<i class="btn"></i><div class="face"></div>');S.inn.append(sw);
  pop(sw,S.t0+.02,{from:.6,dur:.45});
  num($('.face',sw),'swv',t=>15*clamp((t-S.t0-.1)/.6),mmss);
  tl.fromTo(sw,{scale:1},{scale:1.08,duration:.14,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},S.t0+.75);
  cap(S,S.t0+.1,'satu pembeli','15 detik',{kwAt:.6});}

/* 9 — layar terbelah: lima pembeli, 10 menit vs 75 detik */
{const S=stage(9,'dark'),L=el('half l','<div class="hh">Nota</div>'),R=el('half r','<div class="hh">Kasir UMKM</div>');S.inn.append(L,R);
  tl.fromTo(L,{xPercent:-100},{xPercent:0,duration:.45,ease:'power3.out',immediateRender:false},S.t0+.02);
  tl.fromTo(R,{xPercent:100},{xPercent:0,duration:.45,ease:'power3.out',immediateRender:false},S.t0+.02);
  const doneR=[.6,.9,1.2,1.5,1.8],doneL=[1.3,2.6,3.9,99,99];
  [[L,doneL],[R,doneR]].forEach(([H,dn],side)=>{for(let i=0;i<5;i++){const p=person(H,i,{x:60+(i%3)*150,y:640+Math.floor(i/3)*300,s:.72});const ck=el('ck','✓');H.append(ck);ck.style.left=(130+(i%3)*150)+'px';ck.style.top=(610+Math.floor(i/3)*300)+'px';
    if(dn[i]<50){tl.fromTo(ck,{opacity:0,scale:.2},{opacity:1,scale:1,duration:.25,ease:'back.out(3)',immediateRender:false},S.t0+dn[i]);tl.to(p,{opacity:.45,duration:.2},S.t0+dn[i]);}}});
  const tL=tmr(L,t=>600*clamp((t-S.t0-.3)/3.6),'big'),tR=tmr(R,t=>75*clamp((t-S.t0-.3)/1.5),'big g');
  tl.fromTo(tR,{scale:1},{scale:1.14,duration:.14,yoyo:true,repeat:1,ease:'power2.out',immediateRender:false},S.t0+1.85);
  const win=el('win','Selesai duluan');R.append(win);pop(win,S.t0+1.9,{rot:-12});
  cap(S,S.t0+.1,'lima pembeli','8× lebih cepat',{kwAt:2.6});}

/* 10 — antrean cepat, pembeli NGGAK KABUR */
{const S=stage(10,'green');
  for(let i=0;i<5;i++){const p=person(S.inn,i,{x:70+i*190,y:900,happy:true});tl.fromTo(p,{opacity:0,y:200},{opacity:1,y:0,duration:.45,ease:'back.out(2)',immediateRender:false},S.t0+.05+i*.08);BOBS.push({e:p,a:16,f:6,ph:i*1.3});
    const h=el('heart','♥');h.style.left=(150+i*190)+'px';h.style.top='820px';S.inn.append(h);pop(h,S.t0+.6+i*.1,{rot:i%2?20:-20});BOBS.push({e:h,a:14,f:4,ph:i});}
  cap(S,S.t0+.1,'antrean cepat, pembeli','nggak kabur',{kwAt:.6});}

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
