// The in-page reader for yearly report PDFs (templates/financial-reports.php). PDF.js is
// served from this site; one viewer open at a time; every control is a real button.
import * as pdfjsLib from '/assets/vendor/pdfjs/pdf.min.js';
pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';

const EN = window.OM_REPORT_LANG === 'en';
const T = EN
  ? { prev: 'Previous page', next: 'Next page', zin: 'Zoom in', zout: 'Zoom out', close: 'Close', fail: 'The document cannot be shown here. Use the download link above.', page: 'Page' }
  : { prev: 'Предишна страница', next: 'Следваща страница', zin: 'Увеличи', zout: 'Намали', close: 'Затвори', fail: 'Документът не може да се покаже тук. Използвайте връзката „Изтегли“ по-горе.', page: 'Страница' };
const BTN = 'min-width:44px;min-height:44px;';
let current = null;   // { button, box }

function close() {
  if (!current) return;
  current.box.hidden = true;
  current.box.innerHTML = '';
  current.button.setAttribute('aria-expanded', 'false');
  current.button.focus();
  current = null;
}

async function open(button) {
  if (current && current.button === button) { close(); return; }
  close();
  const box = document.getElementById(button.getAttribute('aria-controls'));
  current = { button, box };
  button.setAttribute('aria-expanded', 'true');
  box.hidden = false;
  box.innerHTML =
    '<div role="group" aria-label="' + button.dataset.reportTitle.replace(/"/g, '&quot;') + '" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem;">'
    + '<button type="button" class="btn btn--outline" data-act="prev" style="' + BTN + '" aria-label="' + T.prev + '">‹</button>'
    + '<span data-report-page aria-live="polite" style="min-width:4rem;text-align:center;font-weight:600;"></span>'
    + '<button type="button" class="btn btn--outline" data-act="next" style="' + BTN + '" aria-label="' + T.next + '">›</button>'
    + '<button type="button" class="btn btn--outline" data-act="zout" style="' + BTN + '" aria-label="' + T.zout + '">−</button>'
    + '<button type="button" class="btn btn--outline" data-act="zin" style="' + BTN + '" aria-label="' + T.zin + '">+</button>'
    + '<button type="button" class="btn btn--outline" data-act="close" style="' + BTN + '">' + T.close + '</button>'
    + '</div><div style="overflow:auto;border:1px solid var(--border);border-radius:8px;background:#f5f5f5;"><canvas style="display:block;margin:0 auto;max-width:none;"></canvas></div>';

  const canvas = box.querySelector('canvas');
  const label = box.querySelector('[data-report-page]');
  let pdf, num = 1, zoom = 1, busy = false, again = false;

  // A click while a page is still drawing is not lost: it redraws once the current one ends.
  async function render() {
    if (busy) { again = true; return; }
    busy = true;
    const page = await pdf.getPage(num);
    const fit = (box.clientWidth - 2) / page.getViewport({ scale: 1 }).width;
    const dpr = window.devicePixelRatio || 1;
    const vp = page.getViewport({ scale: fit * zoom * dpr });
    canvas.width = vp.width; canvas.height = vp.height;
    canvas.style.width = (vp.width / dpr) + 'px';
    canvas.setAttribute('aria-label', T.page + ' ' + num + ' / ' + pdf.numPages);
    await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    label.textContent = num + ' / ' + pdf.numPages;
    busy = false;
    if (again) { again = false; render(); }
  }

  box.querySelector('[role=group]').addEventListener('click', (e) => {
    const act = e.target.closest('button')?.dataset.act;
    if (act === 'close') return close();
    if (!pdf) return;
    if (act === 'prev' && num > 1) num--;
    else if (act === 'next' && num < pdf.numPages) num++;
    else if (act === 'zin') zoom = Math.min(zoom * 1.25, 4);
    else if (act === 'zout') zoom = Math.max(zoom / 1.25, 0.5);
    else return;
    render();
  });
  box.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  try {
    pdf = await pdfjsLib.getDocument({ url: button.dataset.reportOpen }).promise;   // PDF.js 6 takes an options object only
    await render();
  } catch (err) {
    box.innerHTML = '<p role="alert" style="margin:0;color:#7f1d1d;font-weight:600;">' + T.fail + '</p>';
    console.error('report viewer:', err);
  }
}

document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-report-open]');
  if (b) open(b);
});
