import os,re,subprocess,sys
orig='orig/orig-be'; new='rem-be'
def methods(path):
    if not os.path.exists(path): return []
    return re.findall(r'public function (test_\w+)\(',open(path,encoding='utf8').read())
def env_of(p):
    if '/MySql/' in p: return 'PHP + **MySQL 8** (real parallel processes)'
    if '/Unit/' in p: return 'PHP (no DB)'
    return 'PHP + SQLite in-memory (default suite)'
rows=[]; tot_new=0; tot_changed=0
for d,_,fs in os.walk(os.path.join(new,'tests')):
    for f in fs:
        if not f.endswith('Test.php'): continue
        p=os.path.join(d,f); rel=os.path.relpath(p,new); o=os.path.join(orig,rel)
        nm=methods(p); om=set(methods(o))
        if not os.path.exists(o): rows.append((rel,'NEW FILE',nm,[])); tot_new+=len(nm)
        else:
            added=[m for m in nm if m not in om]; changed=open(p,encoding='utf8').read()!=open(o,encoding='utf8').read()
            if changed: rows.append((rel,'MODIFIED',added,[m for m in nm if m in om])); tot_changed+=len(added)
rows.sort()
out=[]
out.append("# OUTSTANDING TESTS — exact list (generated from the code by `gen_outstanding.py`; nothing here has been executed against PHP / MySQL / a browser)\n")
total=sum(len(methods(os.path.join(d,f))) for d,_,fs in os.walk(os.path.join(new,'tests')) for f in fs if f.endswith('Test.php'))
out.append(f"Whole backend suite: **{total} test methods**, in {sum(1 for d,_,fs in os.walk(os.path.join(new,'tests')) for f in fs if f.endswith('Test.php'))} classes. **None of them has been run against the corrected code: this pass had no PHP, and the second independent audit states it had none either.** (Whether the first remediation ran any is not documented in the material supplied.) So the entire suite is outstanding for this release; the tables below single out what *this correction pass* added or touched, because those are the likeliest to contain authoring mistakes.\n")
out.append(f"Added in this pass: **{tot_new} test methods in new files + {tot_changed} new methods in modified files**.\n")
out.append("## A. PHP / SQLite — `php artisan test` (default suite)\n")
for rel,kind,added,keep in rows:
    if '/MySql/' in rel: continue
    out.append(f"### `{rel}` — {kind} — {env_of(rel)}")
    if added: out.append("New methods (all **NOT EXECUTED**):"); out += [f"- `{m}`" for m in added]
    if kind=='MODIFIED' and keep: out.append(f"File also changed in the setup/other methods; {len(keep)} pre-existing method(s) in the file must be re-run (not listed individually).")
    out.append("")
out.append("## B. PHP / **MySQL 8** — `php artisan test --configuration=phpunit.mysql.xml --testsuite=MySQL`\n")
for rel,kind,added,keep in rows:
    if '/MySql/' not in rel: continue
    out.append(f"### `{rel}` — {kind}")
    out += [f"- `{m}` — **NOT EXECUTED**" for m in (added if kind=='MODIFIED' else added)]
    out.append("")
out.append("Also required on MySQL: `php artisan migrate:fresh` on a *_test database, `php artisan ssi:schema-check`, migration `down()`/`up()` round trip (`scripts/verify-all.sh` steps 08–09), and the earlier MySQL tests that pre-date this pass (`MySqlConcurrencyTest`).\n")
open('outstanding_php.md','w').write('\n'.join(out))
print(total,tot_new,tot_changed,len(rows))
