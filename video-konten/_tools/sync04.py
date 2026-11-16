import io, os
os.chdir(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
p = '04-salah-kembalian/_scenes.js'
s = io.open(p, encoding='utf-8').read()
rep = [
    ("const C=[0,2.6,5.0,7.0,9.6,11.8,14.2,16.0,18.4,20.8,23.0,25.6,28.8];",
     "/* disinkronkan ke vo.wav (35,04 dtk; awal kalimat: 0.21 3.93 6.89 8.64 11.91 14.51 ~17.65 20.34 22.64 ~26.2 28.78 31.61) */\nconst C=[0,3.8,6.75,8.5,11.75,14.35,17.6,20.2,22.5,26.1,28.6,31.45,35.2];"),
    ("cap(S,.1,'jam makan siang, warung','ramai',{kwAt:.7});", "cap(S,.1,'jam makan siang, warung','ramai',{kwAt:3.0});"),
    ("cap(S,S.t0+.1,'total belanja','37.500',{kwAt:1.1});", "cap(S,S.t0+.1,'total belanja','37.500',{kwAt:1.2});"),
    ("cap(S,S.t0+.1,'pembeli bayar','100 ribu',{kwAt:.5});", "cap(S,S.t0+.1,'pembeli bayar','100 ribu',{kwAt:.7});"),
    ("Math.floor((t-S.t0-.6)/.18))),fm:v=>'= '+SPIN[v]});", "Math.floor((t-S.t0-1.0)/.2))),fm:v=>'= '+SPIN[v]});"),
    ("cap(S,S.t0+.1,'kembalian dihitung','di kepala',{kwAt:.9});", "cap(S,S.t0+.1,'kembalian dihitung','di kepala',{kwAt:1.7});"),
    ("cap(S,S.t0+.1,'kembalian dikasih','72.500',{kwAt:1.0});", "cap(S,S.t0+.1,'kembalian dikasih','72.500',{kwAt:.8});"),
    ("cap(S,S.t0+.1,'padahal harusnya','62.500',{kwAt:1.1});", "cap(S,S.t0+.1,'padahal harusnya','62.500',{kwAt:1.4});"),
    ("cap(S,S.t0+.1,'kelebihan, warung','rugi 10 ribu',{kwAt:.25});", "cap(S,S.t0+.1,'kelebihan, warung','rugi 10 ribu',{kwAt:1.4});"),
    ("cap(S,S.t0+.1,'kalau kurang,','pembeli kesal',{kwAt:.6});", "cap(S,S.t0+.1,'kalau kurang,','pembeli kesal',{kwAt:1.0});"),
    ("Math.floor((t-S.t0-.9)/.12))),fm:v=>v<0?'':typ[v]});", "Math.floor((t-S.t0-1.9)/.12))),fm:v=>v<0?'':typ[v]});"),
    ("tl.to($$('.chips span',ph)[2],{backgroundColor:'#7943e5',color:'#fff',duration:.12},S.t0+1.55);",
     "tl.to($$('.chips span',ph)[2],{backgroundColor:'#7943e5',color:'#fff',duration:.12},S.t0+2.55);"),
    ("cap(S,S.t0+.1,'di kasir umkm tinggal isi','uang diterima',{kwAt:1.1});", "cap(S,S.t0+.1,'di kasir umkm tinggal isi','uang diterima',{kwAt:2.6});"),
    ("immediateRender:false},S.t0+.95);\n  cap(S,S.t0+.1,'kasih sesuai layar,','pasti pas',{kwAt:1.0});",
     "immediateRender:false},S.t0+1.6);\n  cap(S,S.t0+.1,'kasih sesuai layar,','pasti pas',{kwAt:1.7});"),
]
for a, b in rep:
    assert s.count(a) == 1, a[:60]
    s = s.replace(a, b)
io.open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('ok')
