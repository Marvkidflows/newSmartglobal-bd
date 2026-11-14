import re,os,sys
root=sys.argv[1]
classes={}
for d,_,fs in os.walk(os.path.join(root,'tests')):
    for f in fs:
        if not f.endswith('.php'): continue
        p=os.path.join(d,f); s=open(p,encoding='utf8',errors='ignore').read()
        m=re.search(r'\b(?:abstract\s+)?class\s+(\w+)\s+extends\s+([\\\w]+)',s)
        if not m: continue
        meths={}
        for mm in re.finditer(r'(?m)^\s*(public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)(\s*:\s*[\w\\?|]+)?',s):
            meths[mm.group(2)]=(mm.group(1),re.sub(r'\s+',' ',mm.group(3)).strip(),(mm.group(4) or '').strip(),p)
        classes[m.group(1)]=(m.group(2).split('\\')[-1],meths,p)
bad=0
for c,(par,meths,p) in classes.items():
    anc=par
    while anc in classes:
        for n,(vis,args,ret,_) in meths.items():
            if n.startswith('test'): continue
            if n in classes[anc][1]:
                pv=classes[anc][1][n]
                print(f"OVERRIDE {c}::{n}({args}){ret}  <- {anc}::{n}({pv[1]}){pv[2]}  [{p}]"); bad+=1
        anc=classes[anc][0]
print("classes:",len(classes),"override findings:",bad)
