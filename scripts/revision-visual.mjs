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
  ajustes: '/admin/config/services/ai-whatsapp-automation',
  editar_bot: '/admin/content/ai-whatsapp/bots/1/edit',
};
const ENGLISH = ['Operations', 'Web visitor', 'Knowledge base', 'Current assignments', 'Unassigned',
  'Updated', 'Status', 'Label', 'Apply', 'Reference date', 'All time', 'Cost by', 'Active conversations',
  'Sent messages', 'No active bot', 'Edit', 'Delete', 'Manage QR', 'Web integration', 'Default', 'None',
  'Save configuration', 'Add ', 'Showing', 'Active', 'Inactive', 'Pending', 'Draft', 'Yes', 'No results'];

export default async function (page) {
  await page.setViewportSize({ width: 1440, height: 900 });
  // The browser keeps its context between runs, so a session left by the
  // previous role would silently log this one in as somebody else and turn
  // every page into a 403.
  await page.context().clearCookies();
  await page.goto(base + '/user/login', { waitUntil: 'networkidle' });
  await page.fill('#edit-name', process.env.REVISION_USER || 'josera');
  await page.fill('#edit-pass', 'PruebaLocal123!');
  await page.click('#edit-submit');
  await page.waitForLoadState('networkidle');

  const report = {};
  // Both widths, always: a defect that only shows on a phone is still a
  // defect a client sees, and checking it by hand is what gets skipped.
  for (const [label, width] of [['escritorio', 1440], ['portatil', 1280], ['portatil-chico', 1024], ['tableta', 820], ['celular', 390]]) {
    await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
    report[label] = await walk(page);
  }
  return report;
}

async function walk(page) {
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

      // Words split across two lines. Claro hyphenates by default, which in
      // a narrow column produces "JG My-lard": a word broken mid-way is
      // always a defect, never a wrapping choice worth keeping.
      const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
      const broken = new Set();
      while (walker.nextNode()) {
        const node = walker.currentNode;
        const parent = node.parentElement;
        if (!parent || parent.offsetParent === null) continue;
        const pattern = /[^\s]{4,}/g;
        let match;
        while ((match = pattern.exec(node.nodeValue || '')) !== null) {
          const range = document.createRange();
          range.setStart(node, match.index);
          range.setEnd(node, match.index + match[0].length);
          const lines = new Set(
            [...range.getClientRects()].filter(r => r.width > 0).map(r => Math.round(r.top)),
          );
          // Two words are allowed to break: a long URL, which has to break
          // somewhere, and a compound that already carries a hyphen, which
          // breaks at its own hyphen the way any prose does. Identifiers
          // that must survive whole say so in their own stylesheet.
          const mayBreak = /https?:\/\/|www\.|-/.test(match[0]);
          if (lines.size > 1 && !mayBreak) broken.add(match[0]);
        }
      }
      for (const word of [...broken].slice(0, 6)) out.push(`palabra partida: "${word}"`);

      // English left in the interface.
      const text = main.innerText;
      for (const word of english) {
        // Whole words only: "Edit" must not match inside "Editar".
        const re = new RegExp(`(^|[^\\wÁÉÍÓÚÑáéíóúñ])${word.trim()}([^\\wÁÉÍÓÚÑáéíóúñ]|$)`);
        if (re.test(text)) out.push(`inglés: "${word}"`);
      }

      // Filter bars: fields and buttons on the same baseline.
      const bar = main.querySelector('.aiwa-filters');
      if (bar) {
        const field = bar.querySelector('.form-item input, .form-item select');
        const button = bar.querySelector('.form-actions .button, .form-actions input[type=submit]');
        if (field && button) {
          const f = field.getBoundingClientRect();
          const b = button.getBoundingClientRect();
          // Only when they share a row. Narrow screens stack the filters and
          // put the button underneath, which is the right thing to do.
          const sameRow = Math.abs(f.top - b.top) < 8;
          const diff = Math.round(f.bottom - b.bottom);
          if (sameRow && Math.abs(diff) > 3) out.push(`filtros desalineados: ${diff}px`);
        }
      }

      // Tables: nothing inside a cell may spill past it. Every descendant,
      // not only the direct children: the link that overlapped the next
      // column sat two levels down and went unseen for a whole release.
      for (const cell of main.querySelectorAll('th, td')) {
        const cr = cell.getBoundingClientRect();
        for (const el of cell.querySelectorAll('*')) {
          const r = el.getBoundingClientRect();
          if (r.width && r.right > cr.right + 2) {
            out.push(`celda cortada: "${el.innerText.trim().slice(0, 18)}" sale ${Math.round(r.right - cr.right)}px`);
          }
        }
        // A heading with no element inside clips its own text instead.
        if (cell.querySelector('*') === null && cell.scrollWidth > cell.clientWidth + 2) {
          out.push(`texto cortado: "${cell.innerText.trim().slice(0, 18)}"`);
        }
      }
      return [...new Set(out)];
    }, ENGLISH);
    const pageOverflow = await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth + 2
        ? `la página se desplaza de lado: ${document.documentElement.scrollWidth}px en ${window.innerWidth}px`
        : null,
    );
    report[name] = {
      status: res.status(),
      findings: pageOverflow ? [pageOverflow, ...findings] : findings,
    };
  }

  return report;
}
