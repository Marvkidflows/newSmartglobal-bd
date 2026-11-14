// Frontend static check (NOT a build): parses every src/**/*.{js,jsx} with @babel/parser (jsx), then verifies every RELATIVE import resolves to a file
// and that each NAMED import / default import exists in the target module. Package (bare) imports are checked against package.json dependencies.
import fs from 'node:fs'; import path from 'node:path'; import { createRequire } from 'node:module';
const root = process.argv[2]; const require = createRequire(path.join(root, 'package.json'));
const { parse } = require('@babel/parser');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const declared = new Set([...Object.keys(pkg.dependencies || {}), ...Object.keys(pkg.devDependencies || {})]);
const files = []; (function walk(d) { for (const f of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, f.name); if (f.isDirectory()) walk(p); else if (/\.(jsx?|mjs)$/.test(f.name)) files.push(p); } })(path.join(root, 'src'));
let problems = 0; const bad = (f, m) => { console.log(`${path.relative(root, f)}: ${m}`); problems++; };
const exportsOf = new Map();
function getExports(file, ast) {
  const names = new Set(); let def = false;
  for (const n of ast.program.body) {
    if (n.type === 'ExportDefaultDeclaration') def = true;
    if (n.type === 'ExportNamedDeclaration') {
      if (n.declaration) { const d = n.declaration; if (d.id) names.add(d.id.name); if (d.declarations) d.declarations.forEach(x => x.id.name && names.add(x.id.name)); }
      (n.specifiers || []).forEach(s => { if (s.exported.name === 'default') def = true; else names.add(s.exported.name); });
    }
    if (n.type === 'ExportAllDeclaration') names.add('*');
  }
  return { names, def };
}
const asts = new Map();
for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  try { const ast = parse(src, { sourceType: 'module', plugins: ['jsx'] }); asts.set(f, ast); exportsOf.set(f, getExports(f, ast)); }
  catch (e) { bad(f, 'PARSE ERROR ' + e.message); }
}
const resolve = (from, spec) => { const b = path.resolve(path.dirname(from), spec); for (const c of [b, b + '.js', b + '.jsx', path.join(b, 'index.js'), path.join(b, 'index.jsx')]) if (fs.existsSync(c) && fs.statSync(c).isFile()) return c; return null; };
for (const [f, ast] of asts) for (const n of ast.program.body) {
  if (n.type !== 'ImportDeclaration' && !(n.type === 'ExportNamedDeclaration' && n.source) && !(n.type === 'ExportAllDeclaration')) continue;
  const spec = n.source.value;
  if (spec.startsWith('.')) {
    const t = resolve(f, spec);
    if (!t) { bad(f, `unresolved import ${spec}`); continue; }
    if (!/\.jsx?$/.test(t)) continue;
    const ex = exportsOf.get(t); if (!ex || n.type !== 'ImportDeclaration') continue;
    for (const s of n.specifiers) {
      if (s.type === 'ImportDefaultSpecifier' && !ex.def) bad(f, `${spec} has no default export`);
      if (s.type === 'ImportSpecifier' && !ex.names.has(s.imported.name) && !ex.names.has('*')) bad(f, `${spec} has no export "${s.imported.name}"`);
    }
  } else if (!spec.startsWith('node:') && !spec.startsWith('vite') && !spec.startsWith('virtual:') && !spec.startsWith('/')) {
    const name = spec.startsWith('@') ? spec.split('/').slice(0, 2).join('/') : spec.split('/')[0];
    if (!declared.has(name) && !spec.endsWith('.css')) bad(f, `bare import "${spec}" is not declared in package.json`);
  }
}
console.log(`parsed ${asts.size}/${files.length} files; ${problems} problem(s)`);
process.exit(problems ? 1 : 0);
