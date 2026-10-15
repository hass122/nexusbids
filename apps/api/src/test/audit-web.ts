import fs from 'fs';
import path from 'path';

const webDir = path.resolve('apps/web');

function getHtmlFiles(dir: string): string[] {
  let results: string[] = [];
  const list = fs.readdirSync(dir);
  for (const file of list) {
    const full = path.join(dir, file);
    const stat = fs.statSync(full);
    if (stat.isDirectory()) {
      if (file !== 'node_modules' && file !== '.git') {
        results = results.concat(getHtmlFiles(full));
      }
    } else if (file.endsWith('.html')) {
      results.push(full);
    }
  }
  return results;
}

const htmlFiles = getHtmlFiles(webDir);
console.log('Total HTML pages audited:', htmlFiles.length);

let brokenLinks = 0;
let brokenAssets = 0;

for (const file of htmlFiles) {
  const content = fs.readFileSync(file, 'utf8');

  // Check local hrefs
  const hrefMatches = content.match(/href=["']([^"'#]+)["']/g) || [];
  for (const m of hrefMatches) {
    const link = m.replace(/^href=["']/, '').replace(/["']$/, '').split('?')[0];
    if (
      link.startsWith('http://') ||
      link.startsWith('https://') ||
      link.startsWith('mailto:') ||
      link.startsWith('tel:') ||
      link.startsWith('javascript:') ||
      link.startsWith('chrome-extension:')
    ) {
      continue;
    }

    const resolved = link.startsWith('/')
      ? path.join(webDir, link)
      : path.join(path.dirname(file), link);

    if (
      !fs.existsSync(resolved) &&
      !fs.existsSync(resolved + '.html') &&
      !fs.existsSync(resolved + '/index.html')
    ) {
      console.log(`[Broken Link] in ${path.relative(webDir, file)} -> ${link}`);
      brokenLinks++;
    }
  }

  // Check src
  const srcMatches = content.match(/src=["']([^"']+)["']/g) || [];
  for (const m of srcMatches) {
    const src = m.replace(/^src=["']/, '').replace(/["']$/, '').split('?')[0];
    if (
      src.startsWith('http://') ||
      src.startsWith('https://') ||
      src.startsWith('data:')
    ) {
      continue;
    }

    const resolved = src.startsWith('/')
      ? path.join(webDir, src)
      : path.join(path.dirname(file), src);

    if (!fs.existsSync(resolved)) {
      console.log(`[Broken Asset] in ${path.relative(webDir, file)} -> ${src}`);
      brokenAssets++;
    }
  }
}

console.log(`Audit Summary: ${brokenLinks} broken links, ${brokenAssets} broken assets.`);
