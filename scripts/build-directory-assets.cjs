/* Run with Node.js and sharp available on NODE_PATH or installed locally. */
const sharp = require('sharp');
const path = require('node:path');
const fs = require('node:fs/promises');

async function build() {
  const root = path.resolve(__dirname, '..');
  const directory = path.join(root, '.wordpress-org');
  const icon = await fs.readFile(path.join(directory, 'icon.svg'));
  // Each size was approved separately. Copy the finished artwork without resampling it.
  for (const [width, height] of [[1544, 500], [772, 250]]) {
    const name = `banner-${width}x${height}.png`;
    const source = path.join(root, 'docs/branding/source/1.2.3', name);
    const metadata = await sharp(source).metadata();
    if (metadata.format !== 'png' || metadata.width !== width || metadata.height !== height) {
      throw new Error(`Expected a ${width} by ${height} PNG: ${name}`);
    }
    await fs.copyFile(source, path.join(directory, name));
  }
  for (const size of [128, 256]) {
    await sharp(icon, { density: 384 }).resize(size, size).png()
      .toFile(path.join(directory, `icon-${size}x${size}.png`));
  }
}
build().catch((error) => { console.error(error); process.exitCode = 1; });
