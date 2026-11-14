import re,sys,glob
# Flatten routes/api.php into (METHOD, uri) using a brace/prefix stack. Heuristic but checks the shapes this codebase uses.
src=open('routes/api.php',encoding='utf8').read().split('\n')
stack=[]; routes=[]; depth=0; pending=''
for ln in src:
    s=ln.strip()
    if s.startswith('//') or s.startswith('*'): continue
    pm=re.search(r"Route::(?:[a-zA-Z]+\([^)]*\)->)*prefix\('([^']*)'\)",s)
    opens=s.count('{')-s.count('}')
    pm2=re.search(r"prefix\('([^']*)'\)",s)
    if pm2 and 'group(' not in s: pending=pm2.group(1)
    if 'group(' in s and 'function' in s:
        pref=pm2.group(1) if pm2 else pending
        pending=''
        stack.append((depth,pref)); depth+=opens; continue
    m=re.search(r"Route::(get|post|put|patch|delete|any|match|apiResource)\(\s*(?:\[[^\]]*\],\s*)?'([^']*)'",s)
    if m:
        uri='/'.join([p for _,p in stack if p]+[m.group(2).strip('/')]).strip('/')
        routes.append((m.group(1).upper(),'api/'+uri))
    depth+=opens
    while stack and depth<=stack[-1][0]: stack.pop()
pat=lambda u: re.compile('^'+re.sub(r'\\\{[^}]+\\\}','[^/]+',re.escape(u))+'$')
rx=[(m,u,pat(u)) for m,u in routes]
print(f"parsed {len(routes)} routes")
files=sys.argv[1:]
bad=0
for f in files:
    t=open(f,encoding='utf8').read()
    for m in re.finditer(r"(getJson|postJson|putJson|deleteJson|patchJson|get|post)\(\s*(?:\"|')(/api/[^\"'?]*)(?:\"|')",t):
        meth=m.group(1).replace('Json','').upper(); url=m.group(2).strip('/')
        url=re.sub(r'\{[^}]*\}','1',url)
        url=re.sub(r'\$[A-Za-z_>\-\[\]\'\"]+','1',url)
        ok=[r for r in rx if r[2].match(url) and (r[0]==meth or r[0] in('ANY','MATCH'))]
        if not ok: print(f"NO ROUTE  {meth} /{url}   [{f}]"); bad+=1
print("unresolved:",bad)
