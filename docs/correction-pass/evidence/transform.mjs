// Offline JSX→ESM transformer used ONLY for the render harness. Not a production build. Uses @babel/core + an inline JSX→createElement plugin
// (the official jsx plugin is not installed and there is no network). Rewrites relative imports to .mjs, drops asset/css imports, stubs import.meta.env.
import fs from 'node:fs'; import path from 'node:path'; import { createRequire } from 'node:module';
const [srcRoot, outRoot, modulesRoot] = process.argv.slice(2);
const require = createRequire(path.join(modulesRoot, 'package.json'));
const babel = require('@babel/core'); const t = require('@babel/types');

function cleanText(raw) {
  const lines = raw.split(/\r\n|\n|\r/); let last = 0;
  lines.forEach((l, i) => { if (/[^ \t]/.test(l)) last = i; });
  let out = '';
  lines.forEach((line, i) => {
    const first = i === 0, isLast = i === lines.length - 1, nonEmptyLast = i === last;
    let s = line.replace(/\t/g, ' ');
    if (!first) s = s.replace(/^[ ]+/, '');
    if (!isLast) s = s.replace(/[ ]+$/, '');
    if (s) { if (!nonEmptyLast) s += ' '; out += s; }
  });
  return out;
}
const nameExpr = (n) => n.type === 'JSXIdentifier' ? (/^[a-z]/.test(n.name) ? t.stringLiteral(n.name) : t.identifier(n.name))
  : n.type === 'JSXMemberExpression' ? t.memberExpression(nameExpr(n.object), t.identifier(n.property.name)) : t.stringLiteral(n.name);
const buildChildren = (children) => { const out = [];
  for (const c of children) {
    if (c.type === 'JSXText') { const s = cleanText(c.value); if (s) out.push(t.stringLiteral(s)); }
    else if (c.type === 'JSXExpressionContainer') { if (c.expression.type !== 'JSXEmptyExpression') out.push(c.expression); }
    else if (c.type === 'JSXSpreadChild') out.push(c.expression);
    else out.push(c);
  } return out; };
const plugin = () => ({ visitor: {
  JSXElement: { exit(p) {
    const el = p.node, props = [];
    for (const a of el.openingElement.attributes) {
      if (a.type === 'JSXSpreadAttribute') { props.push(t.spreadElement(a.argument)); continue; }
      const key = a.name.type === 'JSXNamespacedName' ? `${a.name.namespace.name}:${a.name.name.name}` : a.name.name;
      let val = a.value === null ? t.booleanLiteral(true) : a.value.type === 'JSXExpressionContainer' ? a.value.expression : a.value.type === 'StringLiteral' ? t.stringLiteral(a.value.value) : a.value;
      props.push(t.objectProperty(/^[A-Za-z_$][\w$]*$/.test(key) ? t.identifier(key) : t.stringLiteral(key), val));
    }
    p.replaceWith(t.callExpression(t.memberExpression(t.identifier('React'), t.identifier('createElement')),
      [nameExpr(el.openingElement.name), props.length ? t.objectExpression(props) : t.nullLiteral(), ...buildChildren(el.children)]));
  } },
  JSXFragment: { exit(p) { p.replaceWith(t.callExpression(t.memberExpression(t.identifier('React'), t.identifier('createElement')),
    [t.memberExpression(t.identifier('React'), t.identifier('Fragment')), t.nullLiteral(), ...buildChildren(p.node.children)])); } },
  MemberExpression(p) { if (t.isMetaProperty(p.node.object) && p.node.object.meta.name === 'import') { p.replaceWith(t.objectExpression([])); } },
  Program: { exit(p, st) {
    if (st.hasJsx) { const has = p.node.body.some(n => n.type === 'ImportDeclaration' && n.specifiers.some(s => s.local.name === 'React'));
      if (!has) p.node.body.unshift(t.importDeclaration([t.importDefaultSpecifier(t.identifier('React'))], t.stringLiteral('react'))); } } },
  JSXOpeningElement(p, st) { st.hasJsx = true; },
  ImportDeclaration(p, st) {
    const src = p.node.source.value; const file = st.file.opts.filename;
    if (/\.(css|scss|svg|png|jpe?g|webp|gif|ico)$/.test(src)) {
      if (p.node.specifiers.length === 0) p.remove();
      else { const id = p.node.specifiers[0].local.name; p.replaceWith(t.variableDeclaration('const', [t.variableDeclarator(t.identifier(id), t.stringLiteral('asset:' + path.basename(src)))])); }
      return;
    }
    if (src.startsWith('.')) {
      const base = path.resolve(path.dirname(file), src);
      const cand = [base, base + '.js', base + '.jsx', path.join(base, 'index.js'), path.join(base, 'index.jsx')].find(c => fs.existsSync(c) && fs.statSync(c).isFile());
      if (cand) { let rel = path.relative(path.dirname(file), cand).replace(/\.jsx?$/, '.mjs'); if (!rel.startsWith('.')) rel = './' + rel; p.node.source = t.stringLiteral(rel); }
    }
  },
} });
let n = 0;
(function walk(d) { for (const f of fs.readdirSync(d, { withFileTypes: true })) {
  const p = path.join(d, f.name); if (f.isDirectory()) { walk(p); continue; }
  if (!/\.jsx?$/.test(f.name) || /\.test\.jsx?$/.test(f.name)) continue;
  const rel = path.relative(srcRoot, p).replace(/\.jsx?$/, '.mjs'); const out = path.join(outRoot, rel);
  const res = babel.transformSync(fs.readFileSync(p, 'utf8'), { filename: p, babelrc: false, configFile: false, sourceType: 'module', parserOpts: { plugins: ['jsx'] }, plugins: [plugin], generatorOpts: { compact: false } });
  fs.mkdirSync(path.dirname(out), { recursive: true }); fs.writeFileSync(out, res.code); n++;
} })(srcRoot);
console.log(`transformed ${n} files`);
