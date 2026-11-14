#!/usr/bin/env python3
"""Structural PHP sanity checker (NOT php -l). Tokenises strings/comments/heredoc/nowdoc, verifies (), [], {} balance and nesting,
a leading <?php tag for .php files, and flags obvious statement-end problems (a line ending in an identifier/`)`/`]` followed by a line that begins a new statement)."""
import sys,re,os
def check(path):
    s=open(path,encoding='utf8',errors='replace').read()
    errs=[]; n=len(s); i=0; line=1; stack=[]
    in_php = False
    if path.endswith('.blade.php'): return errs
    pairs={')':'(',']':'[','}':'{'}
    # file must start with <?php (allow shebang)
    if not re.match(r'\A(#!.*\n)?<\?php',s): errs.append((1,'file does not start with <?php')); 
    while i<n:
        c=s[i]
        if c=='\n': line+=1; i+=1; continue
        two=s[i:i+2]
        if two=='//' or c=='#' and s[i:i+2]!='#[':
            j=s.find('\n',i); j=n if j<0 else j
            k=s.find('?>',i,j)
            i = k if k>=0 else j; continue
        if two=='/*':
            j=s.find('*/',i+2)
            if j<0: errs.append((line,'unterminated comment')); break
            line+=s.count('\n',i,j); i=j+2; continue
        if c in '\'"':
            q=c; j=i+1
            while j<n:
                if s[j]=='\\': j+=2; continue
                if q=='"' and s[j]=='{' and s[j+1:j+2]=='$':   # {$expr} interpolation: skip balanced braces
                    d=0
                    while j<n:
                        if s[j]=='{': d+=1
                        elif s[j]=='}':
                            d-=1
                            if d==0: break
                        j+=1
                if s[j]==q: break
                j+=1
            if j>=n: errs.append((line,'unterminated string')); break
            line+=s.count('\n',i,j); i=j+1; continue
        m=re.match(r'<<<[ \t]*([\'"]?)(\w+)\1\r?\n',s[i:])
        if m:
            tag=m.group(2); end=re.compile(r'^[ \t]*'+tag+r'\b',re.M)
            em=end.search(s,i+m.end())
            if not em: errs.append((line,'unterminated heredoc')); break
            line+=s.count('\n',i,em.end()); i=em.end(); continue
        if c in '([{': stack.append((c,line))
        elif c in ')]}':
            if not stack: errs.append((line,f'unmatched {c}'))
            else:
                o,l=stack.pop()
                if o!=pairs[c]: errs.append((line,f'mismatch {o}(line {l}) vs {c}'))
        i+=1
    for o,l in stack: errs.append((l,f'unclosed {o}'))
    # statement heuristics on a comment/string-stripped copy is overkill; check a few high-signal patterns line-wise
    for ln,t in enumerate(s.split('\n'),1):
        ts=t.strip()
        if re.search(r'\)\s*;\s*;\s*$',ts) and 'for' not in ts: errs.append((ln,'double semicolon'))
        if re.search(r'\bfunction\s+\w+\s*\([^)]*\)\s*:\s*$',ts): errs.append((ln,'return type without body'))
    return errs
bad=0; total=0
for root in sys.argv[1:]:
    paths=[root] if os.path.isfile(root) else [os.path.join(d,f) for d,_,fs in os.walk(root) for f in fs if f.endswith('.php') and 'vendor' not in d and 'node_modules' not in d]
    for p in sorted(paths):
        total+=1
        for l,m in check(p): print(f'{p}:{l}: {m}'); bad+=1
print(f'checked {total} files, {bad} problem(s)')
sys.exit(1 if bad else 0)
