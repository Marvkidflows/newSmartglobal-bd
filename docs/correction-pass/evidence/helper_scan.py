import re,os,sys
LARAVEL=set('''actingAs assertStatus assertOk assertJson assertJsonPath assertJsonMissingPath assertSame assertEquals assertEqualsWithDelta assertTrue assertFalse assertNull assertNotNull assertCount assertContains assertNotContains assertNotSame assertStringContainsString assertStringNotContainsString assertStringStartsWith assertGreaterThanOrEqual assertDatabaseHas assertDatabaseMissing assertDatabaseCount assertEmpty assertNotEmpty assertInstanceOf assertIsArray assertMatchesRegularExpression assertSoftDeleted expectException expectExceptionMessage fail travel travelTo travelBack freezeTime getJson postJson putJson patchJson deleteJson json get post put delete call withHeaders withHeader withoutMiddleware withMiddleware seed artisan mock partialMock instance app setUp tearDown refreshApplication assertNotSame markTestSkipped markTestIncomplete assertDirectoryExists assertFileExists assertGreaterThan assertLessThan assertLessThanOrEqual assertStringEqualsFile assertArrayHasKey assertArrayNotHasKey assertJsonCount assertJsonStructure assertForbidden assertUnauthorized assertNotFound assertCreated assertAccepted assertUnprocessable assertJsonValidationErrors assertSessionHas withoutExceptionHandling assertAlmostEquals'''.split())
props_laravel={'app','artisan'}
classes={}
for d,_,fs in os.walk('tests'):
    for f in fs:
        if not f.endswith('.php'): continue
        p=os.path.join(d,f); s=open(p,encoding='utf8').read()
        m=re.search(r'\bclass\s+(\w+)\s+extends\s+([\\\w]+)',s)
        if not m: continue
        meths=set(re.findall(r'function\s+(\w+)\s*\(',s))
        props=set(re.findall(r'(?:protected|public|private)\s+(?:\??[\w\\]+\s+)?\$(\w+)',s))
        uses=set()
        for tr in re.findall(r'^\s*use\s+([\w\\]+);',s,re.M): uses.add(tr.split('\\')[-1])
        classes[m.group(1)]=dict(parent=m.group(2).split('\\')[-1],meths=meths,props=props,path=p,src=s,uses=uses)
def anc(c):
    out=[];n=c
    while n in classes: out.append(n); n=classes[n]['parent']
    return out
targets=sys.argv[1:]
bad=0
for c,info in classes.items():
    if not any(info['path'].endswith(t) for t in targets): continue
    chain=anc(c); M=set().union(*[classes[x]['meths'] for x in chain]); P=set().union(*[classes[x]['props'] for x in chain])
    # traits used (e.g. DatabaseMigrations) — Laravel ones are fine
    for name in set(re.findall(r'\$this->(\w+)\(',info['src'])):
        if name not in M and name not in LARAVEL: print(f"{c}: UNDEFINED METHOD $this->{name}()  [{info['path']}]"); bad+=1
    for name in set(re.findall(r'\$this->(\w+)(?!\w|\()',info['src'])):
        if re.search(r'\$this->'+name+r'\(',info['src']): continue
        if name not in P and name not in props_laravel and name not in M: print(f"{c}: UNDEFINED PROPERTY $this->{name}  [{info['path']}]"); bad+=1
print("problems:",bad)
