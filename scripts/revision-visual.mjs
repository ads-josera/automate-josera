const base = 'https://automate-josera.ddev.site';
const PAGES = {
  dashboard: '/admin/reports/ai-whatsapp-automation',
  conversaciones: '/admin/content/ai-whatsapp/conversations',
  mensajes: '/admin/content/ai-whatsapp/messages',
  prospectos: '/admin/content/ai-whatsapp/leads',
  routing: '/admin/content/ai-whatsapp/routing',
  clientes: '/admin/content/ai-whatsapp/clients',
  bots: '/admin/content/ai-whatsapp/bots',
  cuentas: '/admin/content/ai-whatsapp/accounts',
  bitacora: '/admin/content/ai-whatsapp/operator-actions',
  conocimiento: '/admin/content/ai-whatsapp/knowledge-bases',
  documentos: '/admin/content/ai-whatsapp/knowledge-documents',
  correos: '/admin/config/services/ai-whatsapp-automation/correos',
};
const ENGLISH = ['Operations', 'Web visitor', 'Knowledge base', 'Current assignments', 'Unassigned', 'Updated', 'Status', 'Label', 'Apply', 'Reference date', 'All time', 'Cost by', 'Active conversations', 'Sent messages', 'No active bot'];

export default async function (page) {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(base + '/user/login', { waitUntil: 'networkidle' });
  await page.fill('#edit-name', 'josera');
  await page.fill('#edit-pass', 'PruebaLocal123!');
  await page.click('#edit-submit');
  await page.waitForLoadState('networkidle');

  const report = {};
  for (const [name, path] of Object.entries(PAGES)) {
    const res = await page.goto(base + path, { waitUntil: 'networkidle' });
    const findings = await page.evaluate((english) => {
      const out = [];
      const main = document.querySelector('.region-content') || document.body;
      const mainRect = main.getBoundingClientRect();

      // Anything sticking out of the content column.
      for (const el of main.querySelectorAll('*')) {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        if (r.right > mainRect.right + 2) {
          // A scrolling ancestor makes the overflow reachable on purpose.
          let scrolls = false;
          for (let p = el.parentElement; p && p !== main; p = p.parentElement) {
            const ox = getComputedStyle(p).overflowX;
            if (ox === 'auto' || ox === 'scroll') { scrolls = true; break; }
          }
          if (scrolls) continue;
          out.push(`desborde: ${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]} sale ${Math.round(r.right - mainRect.right)}px`);
        }
      }

      // English left in the interface.
      const text = main.innerText;
      for (const word of english) {
        if (text.includes(word)) out.push(`inglés: "${word}"`);
      }

      // Filter bars: fields and buttons on the same baseline.
      const bar = main.querySelector('.aiwa-filters');
      if (bar) {
        const field = bar.querySelector('.form-item input, .form-item select');
        const button = bar.querySelector('.form-actions .button, .form-actions input[type=submit]');
        if (field && button) {
          const diff = Math.round(field.getBoundingClientRect().bottom - button.getBoundingClientRect().bottom);
          if (Math.abs(diff) > 3) out.push(`filtros desalineados: ${diff}px`);
        }
      }

      // Tables: no cell content wider than its cell.
      for (const cell of main.querySelectorAll('td')) {
        const cr = cell.getBoundingClientRect();
        for (const child of cell.children) {
          const chr = child.getBoundingClientRect();
          if (chr.width && chr.right > cr.right + 2) {
            out.push(`celda cortada: ${child.className || child.tagName} sale ${Math.round(chr.right - cr.right)}px`);
          }
        }
      }
      return [...new Set(out)];
    }, ENGLISH);
    report[name] = { status: res.status(), findings };
  }
  return report;
}
