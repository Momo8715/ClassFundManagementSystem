/**
 * JS 语法检查（CI / 本地）：node tools/check-js.js
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const root = path.join(__dirname, '..');
const files = [];
for (const dir of ['assets/js', 'src-js']) {
  const p = path.join(root, dir);
  if (!fs.existsSync(p)) continue;
  for (const f of fs.readdirSync(p)) {
    if (f.endsWith('.js')) files.push(path.join(p, f));
  }
}
for (const f of files) {
  try {
    execFileSync(process.execPath, ['--check', f], { stdio: 'pipe' });
    console.log('  ✅ ' + path.relative(root, f));
  } catch (e) {
    console.error('  ❌ ' + path.relative(root, f) + '\n' + (e.stderr ? e.stderr.toString() : e.message));
    process.exitCode = 1;
  }
}
