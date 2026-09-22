'use strict';

// Usage: node scripts/import-lucide.cjs /path/to/lucide-1.47.0
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { createConverter } = require('./outline-build/convert.cjs');

const revision = '3b9ea6d08707edc439f25a4c354cb0d6b8bee973';
const version = '1.47.0';
const upstream = 'https://github.com/lucide-icons/lucide';
const root = path.resolve(__dirname, '..');
const title = slug => slug.split('-').map(word => word[0].toUpperCase() + word.slice(1)).join(' ');
const json = value => JSON.stringify(value, null, 4) + '\n';

async function main() {
  if (!process.argv[2]) throw new Error('Provide the pinned official Lucide checkout.');
  const source = fs.realpathSync(process.argv[2]);
  const git = args => execFileSync('git', ['-C', source, ...args], { encoding: 'utf8' }).trim();
  if (git(['rev-parse', 'HEAD']) !== revision || git(['status', '--porcelain', '--untracked-files=all'])) throw new Error('Source revision or clean-tree check failed.');
  execFileSync('php', ['-r', 'require $argv[1]; IconLibrary\\Build\\CollectionBuild::validate_source_checkout($argv[2], $argv[3], "LICENSE");', path.join(__dirname, 'lib/CollectionBuild.php'), source, upstream]);
  function read(relative) {
    const full = path.join(source, relative);
    if (fs.lstatSync(full).isSymbolicLink() || !fs.realpathSync(full).startsWith(source + path.sep)) throw new Error('Source file escapes checkout.');
    return fs.readFileSync(full, 'utf8');
  }
  const files = fs.readdirSync(path.join(source, 'icons')).filter(name => name.endsWith('.svg')).sort();
  if (files.length !== 1848) throw new Error('Unexpected pinned source count.');
  const parent = fs.realpathSync(path.join(root, 'assets/icons'));
  const destination = path.join(parent, 'lucide');
  if (fs.existsSync(destination) && (fs.lstatSync(destination).isSymbolicLink() || !fs.existsSync(path.join(destination, 'manifest.json')))) throw new Error('Unsafe existing destination.');
  const build = fs.mkdtempSync(path.join(parent, 'lucide.tmp-'));
  try {
    fs.mkdirSync(path.join(build, 'outline'));
    fs.writeFileSync(path.join(build, 'LICENSE'), read('LICENSE'));
    const convert = await createConverter();
    const icons = [];
    const categories = new Map();
    for (const filename of files) {
      const slug = filename.slice(0, -4);
      if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) throw new Error('Invalid icon slug.');
      const metadata = JSON.parse(read(`icons/${slug}.json`));
      const groups = metadata.categories;
      if (!Array.isArray(groups) || !groups.length || !groups.every(value => /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value))) throw new Error(`Invalid categories: ${slug}`);
      const tags = metadata.tags;
      if (!Array.isArray(tags) || !tags.every(value => typeof value === 'string')) throw new Error(`Invalid tags: ${slug}`);
      for (const group of groups) categories.set(group, (categories.get(group) || 0) + 1);
      const relative = `outline/${slug}.svg`;
      const content = convert(read(`icons/${filename}`)) + '\n';
      fs.writeFileSync(path.join(build, relative), content);
      icons.push({ id: `lucide/outline/${slug}`, coreIconName: `lucide/${slug}-outline`, label: title(slug), variant: 'outline', categories: groups, keywords: [...new Set([...slug.split('-'), ...tags])], path: relative, sha256: crypto.createHash('sha256').update(content).digest('hex') });
    }
    const manifest = {
      schemaVersion: 2, slug: 'lucide', name: 'Lucide', description: 'Lucide outline icons converted to filled paths at build time.', version,
      license: { name: 'ISC AND MIT', url: `${upstream}/blob/${revision}/LICENSE` },
      source: { name: 'lucide-icons/lucide', url: upstream, revision },
      variants: [{ slug: 'outline', label: 'Outline', coreCompatible: true, defaultEnabled: false, iconCount: icons.length }],
      categories: [...categories].sort(([a], [b]) => a.localeCompare(b, 'en')).map(([slug, iconCount]) => ({ slug, label: title(slug), iconCount })), icons,
    };
    fs.writeFileSync(path.join(build, 'manifest.json'), json(manifest));
    fs.writeFileSync(path.join(build, 'exclusions.json'), json({ schemaVersion: 1, slug: 'lucide', version, sourceRevision: revision, includedIconCount: icons.length, excludedIconCount: 0, exclusions: [] }));
    execFileSync('php', ['-r', 'require $argv[1]; $m=json_decode(file_get_contents($argv[2]."/manifest.json"),true); $e=IconLibrary\\Build\\CollectionBuild::validate_manifest($m,$argv[2]); if($e){fwrite(STDERR,implode("\\n",$e));exit(1);}', path.join(__dirname, 'lib/CollectionBuild.php'), build], { stdio: 'inherit' });
    const backup = `${build}.previous`;
    if (fs.existsSync(destination)) fs.renameSync(destination, backup);
    try { fs.renameSync(build, destination); } catch (error) { if (fs.existsSync(backup)) fs.renameSync(backup, destination); throw error; }
    if (fs.existsSync(backup)) fs.rmSync(backup, { recursive: true });
    console.log(`Imported ${icons.length} Lucide ${version} icons with no exclusions.`);
  } finally { if (fs.existsSync(build)) fs.rmSync(build, { recursive: true }); }
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
