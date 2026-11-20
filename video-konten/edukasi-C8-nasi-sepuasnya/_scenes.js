/* ---------- batas panggung (dtk) — disinkronkan ke vo.wav (55,6 dtk) lewat whisper per kata ---------- */
/* Semua waktu di dalam panggung RELATIF terhadap S.t0, jadi cukup ubah array C saat sinkron. */
const C=[0,4.6,9.55,13.85,18.05,25.35,34.05,38.75,43.4,52.25,56.8];
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

/* ---------- objek: piring, gundukan nasi, paha ayam, sayur, karung beras, wajah pelanggan ---------- */
const plate=(par,x,y,w=760)=>{const p=el('nplate');p.style.cssText=`left:${x}px;top:${y}px;width:${w}px;height:${w*.36}px`;par.append(p);return p;};
/* gundukan nasi: butir bulat disusun membentuk kubah; urutan dari bawah ke atas */
const MOUND=[[-230,0],[-130,0],[-30,0],[70,0],[170,0],[-180,-80],[-80,-80],[20,-80],[120,-80],[-130,-160],[-30,-160],[70,-160],[-80,-235],[20,-235],[-30,-305]];
function mound(par,cx,cy,s=1,n=MOUND.length){return MOUND.slice(0,n).map(([x,y])=>{const r=el('nrice');r.style.cssText=`left:${cx+x*s}px;top:${cy+y*s}px;width:${120*s}px;height:${110*s}px`;par.append(r);return r;});}
const LEG=`<svg viewBox="0 0 260 200"><path d="M150 120l70 45" stroke="#3B1F12" stroke-width="34" stroke-linecap="round"/><path d="M150 120l70 45" stroke="#FFF6E5" stroke-width="18" stroke-linecap="round"/><circle cx="226" cy="150" r="18" fill="#FFF6E5" stroke="#3B1F12" stroke-width="7"/><circle cx="238" cy="172" r="18" fill="#FFF6E5" stroke="#3B1F12" stroke-width="7"/><path d="M30 90q0-70 80-76q76-4 70 70q-6 66-80 64Q30 146 30 90z" fill="#D9822B" stroke="#3B1F12" stroke-width="9"/><path d="M62 70q14-30 46-34" stroke="#F6C27A" stroke-width="12" stroke-linecap="round" fill="none"/></svg>`;
const leg=(par,x,y,w=300)=>{const l=el('nleg',LEG);l.style.cssText=`left:${x}px;top:${y}px;width:${w}px`;par.append(l);return l;};
const veg=(par,x,y,w=200)=>{const v=el('nveg');v.style.cssText=`left:${x}px;top:${y}px;width:${w}px;height:${w*.62}px`;par.append(v);return v;};
const face=(par,x,y,s=200)=>{const f=el('nface','<i class="e l"></i><i class="e r"></i><i class="m"></i>');f.style.cssText=`left:${x}px;top:${y}px;width:${s}px;height:${s}px`;par.append(f);return f;};
const ntag=(par,html,cls='')=>{const t=el('ntag '+cls,html);par.append(t);return t;};

/* ================= PANGGUNG ================= */
/* 0 — HOOK: piring, nasi ditumpuk butir demi butir tiap 0,13 dtk, penghitung "nambah", lalu stempel UNTUNG (hijau) menghantam.
   Kontradiksinya: kasih lebih banyak, kok malah untung. */
{const S=stage(0,'dark'),t=S.t0,p=plate(S.inn,160,1330,760);pop(p,t+.02,{from:.5,dur:.3});
  const rs=mound(S.inn,520,1450,1.15);rs.forEach((r,i)=>{gsap.set(r,{opacity:0});
    tl.fromTo(r,{opacity:1,y:-900,scale:.6},{y:0,scale:1,duration:.28,ease:'power2.in',immediateRender:false},t+.12+i*.13);
    tl.fromTo(r,{scaleY:.65},{scaleY:1,duration:.2,ease:'back.out(3)',immediateRender:false},t+.4+i*.13);});
  const n=num(S.inn,'nadd',x=>{const k=Math.floor((x-t-.12)/.13)+1;return k<=0?0:Math.min(3,Math.ceil(k/5));},v=>v?('nambah '+v+'x'):'');
  tl.fromTo(n,{opacity:0},{opacity:1,duration:.1,immediateRender:false},t+.12);
  const st=el('stamp ngood','UNTUNG');S.inn.append(st);
  tl.fromTo(st,{opacity:0,scale:3.2,rotation:-30},{opacity:1,scale:1,rotation:-10,duration:.22,ease:'power4.in',immediateRender:false},t+3.88);
  tl.fromTo(S.inn,{x:0},{x:18,duration:.05,yoyo:true,repeat:5,ease:'none',immediateRender:false},t+4.1);
  tl.fromTo(rs,{rotation:0},{rotation:6,duration:.13,yoyo:true,repeat:5,stagger:.015,ease:'sine.inOut',immediateRender:false},t+2.2);
  cap(S,t+.04,'nasi boleh ambil sepuasnya,','malah makin untung',{kwAt:2.6});}

/* 1 — nasi bahan paling murah di piring */
{const S=stage(1,'cream'),t=S.t0,p=plate(S.inn,120,1240,840);pop(p,t+.02,{from:.6,dur:.3});
  const rs=mound(S.inn,380,1370,.9);rs.forEach(r=>gsap.set(r,{opacity:0}));tl.fromTo(rs,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.25,stagger:.02,ease:'back.out(2)',immediateRender:false},t+.1);
  const l=leg(S.inn,580,1180,300),v=veg(S.inn,700,1330,180);[l,v].forEach(e=>gsap.set(e,{opacity:0}));pop(l,t+.35,{from:.4,rot:-20});pop(v,t+.5,{from:.4,rot:20});
  const tg=ntag(S.inn,'paling murah','cheap');gsap.set(tg,{opacity:0});pop(tg,t+3.4,{from:.4,rot:-12});
  const ar=el('narr','▼');S.inn.append(ar);pop(ar,t+3.5,{from:.2});BOBS.push({e:ar,a:16,f:4,ph:0});
  BOBS.push({e:l,a:10,f:2.4,ph:0},{e:v,a:10,f:2.4,ph:1.4});
  cap(S,t+.08,'kok bisa? karena nasi','paling murah',{kwAt:3.35});}

/* 2 — beras sekilo Rp14.000 → sekitar 10 piring */
{const S=stage(2,'orange'),t=S.t0,sk=el('nsack','<b>BERAS</b><small>1 kg</small>');S.inn.append(sk);pop(sk,t+.02,{from:.5,rot:-8,dur:.35});
  const pr=ntag(S.inn,'<b>Rp14.000</b>','price');gsap.set(pr,{opacity:0,transformOrigin:'50% 0%'});
  tl.fromTo(pr,{opacity:1,rotation:-70,y:-400},{rotation:0,y:0,duration:.55,ease:'elastic.out(1,.45)',immediateRender:false},t+.9);
  for(let i=0;i<10;i++){const c=i%5,r=Math.floor(i/5),m=el('nmini','<i></i>');m.style.left=(105+c*180)+'px';m.style.top=(1380+r*170)+'px';S.inn.append(m);gsap.set(m,{opacity:0});
    tl.fromTo(m,{opacity:1,x:540-(105+c*180)-80,y:-420,scale:.2},{x:0,y:0,scale:1,duration:.4,ease:'back.out(1.6)',immediateRender:false},t+2.6+i*.1);}
  const n=num(S.inn,'ncount',x=>Math.max(0,Math.min(10,Math.floor((x-t-2.6)/.1)+1)),v=>v+' piring');tl.fromTo(n,{opacity:0},{opacity:1,duration:.1,immediateRender:false},t+2.6);
  cap(S,t+.08,'beras sekilo, jadi sekitar','10 piring',{kwAt:2.85});}

/* 3 — satu piring nasi modalnya Rp1.400 */
{const S=stage(3,'green'),t=S.t0,p=plate(S.inn,190,1150,700);pop(p,t+.02,{from:.6,dur:.3});
  const rs=mound(S.inn,520,1265,1.05);rs.forEach(r=>gsap.set(r,{opacity:0}));tl.fromTo(rs,{opacity:0,y:-200},{opacity:1,y:0,duration:.25,stagger:.03,ease:'back.out(2)',immediateRender:false},t+.1);
  const n=num(S.inn,'nbig',x=>Math.round(1400*ease2((x-t-2.3)/.6)/100)*100,v=>'Rp'+rb(v));tl.fromTo(n,{opacity:0,scale:1.6},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+2.25);
  const sub=el('nsub','per piring');S.inn.append(sub);gsap.set(sub,{opacity:0});tl.fromTo(sub,{opacity:0,y:20},{opacity:1,y:0,duration:.25,immediateRender:false},t+2.7);
  cap(S,t+.08,'modal sepiring nasi cuma','rp1.400',{kwAt:2.3});}

/* 4 — untung dari lauk: ayam goreng 12.000 − 7.000 = 5.000 */
{const S=stage(4,'purple'),t=S.t0,l=leg(S.inn,340,560,400);gsap.set(l,{opacity:0});pop(l,t+.05,{from:.4,rot:-24,dur:.4});BOBS.push({e:l,a:12,f:2.4,ph:0});
  const eq=el('neq','<div class="er"><span>12.000</span><i>−</i><span class="md">7.000</span></div><div class="ex"><span>harga jual</span><span>modal</span></div><div class="eb">= 5.000</div>');S.inn.append(eq);pop(eq,t+2.6,{from:.7,dur:.35});
  $$('.er > *',eq).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:30},{opacity:1,y:0,duration:.25,ease:'back.out(2)',immediateRender:false},t+[3.7,4.9,5.0][i]);});
  gsap.set($('.ex',eq),{opacity:0});tl.fromTo($('.ex',eq),{opacity:0},{opacity:1,duration:.2,immediateRender:false},t+5.1);
  gsap.set($('.eb',eq),{opacity:0});tl.fromTo($('.eb',eq),{opacity:0,scale:1.6},{opacity:1,scale:1,duration:.3,ease:'back.out(2.4)',immediateRender:false},t+6.65);
  cap(S,t+.08,'untungnya datang dari','lauk',{kwAt:2.0,out:t+5.9});
  cap(S,t+5.98,'untung sepotong ayam','rp5.000',{kwAt:.6});}

/* 5 — MOMEN: timbangan — nambah nasi Rp1.400 vs untung ayam Rp5.000 → masih untung Rp3.600 */
{const S=stage(5,'dark'),t=S.t0,sc=el('nscale','<i class="post"></i><i class="base"></i><div class="beam"><div class="pan l"><b>+1.400</b><small>nambah nasi</small></div><div class="pan r"><b>5.000</b><small>untung ayam</small></div></div>');
  S.inn.append(sc);pop(sc,t+.05,{from:.6,dur:.35});const bm=$('.beam',sc);
  [$('.pan.l',sc),$('.pan.r',sc)].forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,y:-300},{opacity:1,y:0,duration:.35,ease:'back.out(1.8)',immediateRender:false},t+[1.0,5.45][i]);});
  tl.fromTo(bm,{rotation:0},{rotation:-10,duration:.5,ease:'back.out(2)',immediateRender:false},t+1.35);
  tl.to(bm,{rotation:12,duration:.7,ease:'elastic.out(1,.5)'},t+5.8);
  const res=el('nres','<small>masih untung</small><b></b>');S.inn.append(res);gsap.set(res,{opacity:0});
  num($('b',res),'',x=>Math.round(3600*ease2((x-t-7.25)/.7)/100)*100,v=>'Rp'+rb(v));
  tl.fromTo(res,{opacity:0,scale:3,rotation:-20},{opacity:1,scale:1,rotation:-4,duration:.35,ease:'back.out(2.2)',immediateRender:false},t+7.2);
  tl.fromTo(res,{scale:1},{scale:1.1,duration:.12,yoyo:true,repeat:3,ease:'power2.out',immediateRender:false},t+7.6);
  [[120,1700],[930,1690],[200,1840],[860,1850],[540,1880]].forEach(([x,y],i)=>{const sp=el('spk','✦');sp.style.left=x+'px';sp.style.top=y+'px';S.inn.append(sp);pop(sp,t+7.35+i*.05,{from:.2,rot:90});BOBS.push({e:sp,a:12,f:4,ph:i});});
  cap(S,t+.08,'nambah nasi, modal naik','rp1.400',{kwAt:3.3,out:t+4.55});
  cap(S,t+4.63,'tapi dari ayamnya','masih untung',{kwAt:1.4});}

/* 6 — pembeli kenyang, besok balik lagi: tiga hari, wajah yang sama datang lagi */
{const S=stage(6,'cream'),t=S.t0;
  ['Senin','Selasa','Rabu'].forEach((d,i)=>{const k=el('nday',`<small>${d}</small>`);k.style.left=(90+i*310)+'px';S.inn.append(k);gsap.set(k,{opacity:0});
    tl.fromTo(k,{opacity:1,y:900,rotation:(i-1)*10},{y:0,rotation:(i-1)*3,duration:.42,ease:'back.out(1.6)',immediateRender:false},t+.1+i*.15);
    const f=face(k,40,170,220);gsap.set(f,{opacity:0});pop(f,t+1.2+i*.55,{from:.2,rot:(i-1)*30});});
  const lp=el('nloop','↻');S.inn.append(lp);pop(lp,t+3.6,{from:.2});tl.fromTo(lp,{rotation:0},{rotation:540,duration:1,ease:'power2.inOut',immediateRender:false},t+3.6);
  cap(S,t+.08,'kenyang, merasa dihargai,','datang kembali',{kwAt:3.6});}

/* 7 — pelajaran: murah hati di bahan yang murah, untung di menu lain */
{const S=stage(7,'green'),t=S.t0,p=plate(S.inn,60,1150,440),q=plate(S.inn,580,1150,440);pop(p,t+.02,{from:.6,dur:.3});pop(q,t+.1,{from:.6,dur:.3});
  const rs=mound(S.inn,290,1215,.62);rs.forEach(r=>gsap.set(r,{opacity:0}));tl.fromTo(rs,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.2,stagger:.02,ease:'back.out(2)',immediateRender:false},t+.1);
  const l=leg(S.inn,640,1050,320);gsap.set(l,{opacity:0});pop(l,t+.3,{from:.4,rot:-20});
  const g=ntag(S.inn,'gratis tambah','free'),u=ntag(S.inn,'untung','gain');[g,u].forEach(e=>gsap.set(e,{opacity:0}));
  pop(g,t+1.8,{from:.3,rot:-14});pop(u,t+3.0,{from:.3,rot:14});BOBS.push({e:g,a:8,f:3,ph:0},{e:u,a:8,f:3,ph:1.5});
  cap(S,t+.08,'murah hati di yang murah,','untung di menu lain',{kwAt:2.9});}

/* 8 — di aplikasi: Laporan & Analitik → Produk terlaris, dengan laba per menu (istilah halaman asli) */
{const S=stage(8,'purple'),t=S.t0,ph=phone(S,'<div class="hd">Laporan &amp; Analitik</div><div class="pc">Produk terlaris</div>'
   +[['1','Es Teh','120','600.000','228.000'],['2','Ayam Goreng','86','1.032.000','430.000'],['3','Telur Balado','64','384.000','160.000']].map(([n,a,q,r,l])=>`<div class="tp"><i>${n}</i><div class="nm"><b>${a}</b><small>${q} terjual</small></div><div class="rv"><b>${r}</b><small>laba ${l}</small></div></div>`).join(''),{cls:'hph'});
  $$('.pc, .tp',ph).forEach((e,i)=>{gsap.set(e,{opacity:0});tl.fromTo(e,{opacity:0,x:-50},{opacity:1,x:0,duration:.28,ease:'power3.out',immediateRender:false},t+4.2+i*.3);});
  const hi=$$('.tp',ph)[1];tl.fromTo(hi,{backgroundColor:'#f6f8fa',scale:1},{backgroundColor:'#DCFCE7',scale:1.04,duration:.3,ease:'back.out(2)',immediateRender:false},t+8.1);
  cap(S,t+.08,'tahu menu mana yang','paling untung',{kwAt:2.95,out:t+4.4});
  cap(S,t+4.48,'cek di kasir umkm,','produk terlaris',{kwAt:1.42});}

/* 9 — maskot + logo */
const RISE=[];
{const S=stage(9,'purple');
  for(let i=0;i<12;i++){const c=coin(S.inn,i%3?'':'xl');gsap.set(c,{opacity:0});RISE.push({c,x:70+rnd()*940,y0:rnd()*2300,v:150+rnd()*160,ph:rnd()*6.28});}
  const lg=el('logoC','<img src="assets/logo-macca.png" alt="MaccaPOS">'),fox=el('fox','','img');fox.src='assets/maskot.webp';fox.alt='';
  const cta=el('cta','<span>Tahu sumber untungmu</span>');S.inn.append(lg,fox,cta);
  tl.fromTo(fox,{opacity:1,y:1200,rotation:8},{y:0,rotation:0,duration:.6,ease:'back.out(1.5)',immediateRender:false},S.t0+.05);tl.set(fox,{opacity:1},S.t0+.05);
  tl.fromTo(lg,{opacity:0,scale:.5,rotation:-8,y:-80},{opacity:1,scale:1,rotation:-2,y:0,duration:.55,ease:'back.out(1.8)',immediateRender:false},S.t0+.3);
  pop(cta,S.t0+1.25,{from:.4,rot:-6});
  BOBS.push({e:fox,a:14,f:2.6,ph:0});}
tl.to({},{duration:.01},DURATION);

/* ---------- semua yang bergerak terus: fungsi waktu ---------- */
function tick(t){
  NUMS.forEach(o=>{o.e.textContent=o.fm(o.f(t));});
  SPOTS.forEach(o=>{o.spot.style.transform=`translate(${Math.sin(t*.7+o.ph)*150}px,${Math.cos(t*.55+o.ph)*190}px)`;});
  BOBS.forEach(o=>{o.e.style.marginTop=(Math.sin(t*o.f+o.ph)*o.a)+'px';});
  RISE.forEach(o=>{if(t<C[9])return;const y=2100-((o.y0+(t-C[9])*o.v)%2300);o.c.style.opacity=.85;o.c.style.transform=`translate(${o.x+Math.sin(t*1.3+o.ph)*40}px,${y}px) rotate(${t*60+o.ph*57}deg)`;});
}
