import fs from 'fs/promises';
import path from 'path';
import sharp from 'sharp';
import { removeBackground } from '@imgly/background-removal-node';
import { Potrace } from 'potrace';

const toSvg = (filePath, params) =>
  new Promise((resolve, reject) => {
    Potrace.trace(filePath, params, (err, svg) => {
      if (err) return reject(err);
      resolve(svg);
    });
  });

const tryRemoveBackground = async (inputBuffer) => {
  try {
    const bgRemovedBuffer = await removeBackground(inputBuffer, {
      output: { quality: 0.95, format: 'image/png' }
    });
    return Buffer.from(bgRemovedBuffer);
  } catch {
    return inputBuffer;
  }
};

export const processAndVectorize = async (inputPath) => {
  const base = path.parse(inputPath).name;
  const tmpDir = path.resolve('tmp');
  await fs.mkdir(tmpDir, { recursive: true });

  const bgRemovedPath = path.join(tmpDir, `${base}-bg.png`);
  const preprocessedPath = path.join(tmpDir, `${base}-bw.png`);

  const inputBuffer = await fs.readFile(inputPath);
  const cleanedBuffer = await tryRemoveBackground(inputBuffer);
  await fs.writeFile(bgRemovedPath, cleanedBuffer);

  const meta = await sharp(bgRemovedPath).metadata();
  const hasTransparency = Boolean(meta.hasAlpha);

  await sharp(bgRemovedPath)
    .flatten({ background: hasTransparency ? '#ffffff' : undefined })
    .grayscale()
    .sharpen({ sigma: 1.2, m1: 0.7, m2: 1.2 })
    .median(1)
    .normalise()
    .threshold(150)
    .toFile(preprocessedPath);

  const svg = await toSvg(preprocessedPath, {
    threshold: 145,
    turdSize: 10,
    color: 'black',
    background: 'transparent',
    optTolerance: 0.3,
    turnPolicy: Potrace.TURNPOLICY_MINORITY
  });

  await Promise.allSettled([
    fs.rm(bgRemovedPath, { force: true }),
    fs.rm(preprocessedPath, { force: true }),
    fs.rm(inputPath, { force: true })
  ]);

  return svg;
};
