/* Run with Node.js and sharp available on NODE_PATH or installed locally. */
const sharp = require('sharp');
const path = require('node:path');
const fs = require('node:fs/promises');

async function build() {
  const root = path.resolve(__dirname, '..');
  const directory = path.join(root, '.wordpress-org');
  const icon = await fs.readFile(path.join(directory, 'icon.svg'));
  const source = path.join(root, 'docs/branding/source/approved-banner.png');
  const metadata = await sharp(source).metadata();
  if (metadata.width !== 2170 || metadata.height !== 725) {
    throw new Error('The approved banner must be 2170 by 725 pixels.');
  }
  // Cover the generated logo only, then composite the unchanged original SVG.
  const blank = await sharp({ create: { width: 240, height: 230, channels: 4, background: '#ffffff' } }).png().toBuffer();
  const mark = await sharp(icon, { density: 384 }).resize(216, 216).png().toBuffer();
  const banner = await sharp(source).composite([
    { input: blank, left: 90, top: 225 },
    { input: mark, left: 101, top: 237 },
  ]).png().toBuffer();
  for (const [width, height] of [[1544, 500], [772, 250]]) {
    await sharp(banner).resize(width, height, { fit: 'cover', position: 'centre' })
      .png().toFile(path.join(directory, `banner-${width}x${height}.png`));
  }
  for (const size of [128, 256]) {
    await sharp(icon, { density: 384 }).resize(size, size).png()
      .toFile(path.join(directory, `icon-${size}x${size}.png`));
  }
}
build().catch((error) => { console.error(error); process.exitCode = 1; });
