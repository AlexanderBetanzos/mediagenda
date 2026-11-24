import puppeteer from 'puppeteer-core';
import { spawn } from 'node:child_process';
const [,, mode, ...rest] = process.argv;
const b = await puppeteer.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true, args: ['--hide-scrollbars'] });
const p = await b.newPage();
await p.setViewport({ width: 1920, height: 1080, deviceScaleFactor: 1 });
await p.goto('file://' + process.cwd() + '/' + (process.env.DEMO || 'bienvita.html'), { waitUntil: 'networkidle0' });
await p.evaluate(() => document.fonts.ready);
if (mode === 'shots') {
  for (const t of rest) { await p.evaluate(x => render(x), +t); await p.screenshot({ path: `shot_${t}.jpg`, quality: 80 }); }
} else {
  const fps = 30, out = rest[0] || 'demo.mp4';
  const dur = await p.evaluate(() => DUR);
  const ff = spawn('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', String(fps), '-c:v', 'mjpeg', '-i', '-',
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'slow', '-crf', '20', '-movflags', '+faststart', out], { stdio: ['pipe', 'inherit', 'inherit'] });
  const N = Math.round(dur * fps);
  for (let i = 0; i < N; i++) {
    await p.evaluate(x => render(x), i / fps);
    const buf = await p.screenshot({ type: 'jpeg', quality: 92 });
    if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r));
    if (i % 300 === 0) console.log(`frame ${i}/${N}`);
  }
  ff.stdin.end();
  await new Promise(r => ff.on('close', r));
}
await b.close();
