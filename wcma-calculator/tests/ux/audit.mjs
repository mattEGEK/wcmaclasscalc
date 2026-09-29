// wcma-calculator/tests/ux/audit.mjs — phone audit (mobile UX spec 2026-09-28 §B4). Run via run-audit.sh.
import { chromium } from 'playwright';

const BASE = process.env.UX_BASE || 'http://localhost:8170';
const RULES = { tap: 44, check: 24, inputFont: 16, text: 16, contrast: 4.5 };

/** Runs inside the page. Returns one string per problem. */
function auditInPage(R) {
  const problems = [];
  const visible = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0'; };
  const describe = el => `${el.tagName.toLowerCase()}${typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : ''} "${(el.innerText || el.value || el.getAttribute('aria-label') || el.name || '').trim().replace(/\s+/g, ' ').slice(0, 40)}"`;
  // Content of a closed <details> isn't shown (Chrome still gives it a box), so it isn't judged until opened.
  const inClosedDetails = el => { const d = el.closest('details'); return !!d && !d.open && !el.closest('summary'); };
  const skipped = el => el.closest('.hub-skip, .no-audit, [aria-hidden="true"], script, style, noscript') || inClosedDetails(el);
  // A link inside a sentence is exempt from the tap-height rule; header, footer, sub-nav and row links are not.
  const inProse = a => {
    if (a.closest('.hub-account, .hub-footer, .hub-subnav, .hub-line')) return false;
    const p = a.parentElement;
    return getComputedStyle(a).display === 'inline' && ['P', 'LI', 'TD', 'DD', 'SPAN', 'STRONG', 'EM', 'SMALL', 'LABEL'].includes(p.tagName)
      && (p.innerText || '').trim().length > (a.innerText || '').trim().length + 3;
  };

  const over = document.documentElement.scrollWidth - innerWidth;
  if (over > 0) problems.push(`page scrolls sideways by ${over}px`);

  for (const el of document.querySelectorAll('a, button, input, select, textarea, summary, label.pretech-upload')) {
    if (!visible(el) || skipped(el)) continue;
    const type = (el.getAttribute('type') || '').toLowerCase();
    if (el.tagName === 'INPUT' && ['hidden', 'file'].includes(type)) continue;
    const r = el.getBoundingClientRect();
    if (el.tagName === 'INPUT' && (type === 'checkbox' || type === 'radio')) {
      if (r.width < R.check || r.height < R.check) problems.push(`${type} smaller than ${R.check}px: ${describe(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
      const row = el.closest('label');
      if (row && row.getBoundingClientRect().height < R.tap) problems.push(`${type} label row shorter than ${R.tap}px: ${describe(row)}`);
      continue;
    }
    if (el.tagName === 'A' && inProse(el)) continue;
    if (r.height < R.tap) problems.push(`tap target shorter than ${R.tap}px: ${describe(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
    if (/INPUT|SELECT|TEXTAREA/.test(el.tagName) && parseFloat(getComputedStyle(el).fontSize) < R.inputFont)
      problems.push(`input text under ${R.inputFont}px: ${describe(el)}`);
  }

  // A disabled button must not look like an enabled one: its border must be dashed.
  for (const el of document.querySelectorAll('button:disabled, .hub-btn[aria-disabled="true"]')) {
    if (visible(el) && !skipped(el) && getComputedStyle(el).borderTopStyle !== 'dashed') problems.push(`disabled button looks enabled: ${describe(el)}`);
  }
  // An enabled button must not be grey (the legacy #95a5a6 look).
  for (const el of document.querySelectorAll('button:not(:disabled), a.btn, a.hub-btn, label.pretech-upload')) {
    if (!visible(el) || skipped(el)) continue;
    if (getComputedStyle(el).backgroundColor.replace(/\s/g, '') === 'rgb(149,165,166)') problems.push(`enabled button is grey: ${describe(el)}`);
  }

  // Empty message boxes and "0 of 0" progress read as broken (spec §C2).
  for (const el of document.querySelectorAll('.form-messages')) {
    if (visible(el) && !skipped(el) && !(el.innerText || '').trim()) problems.push(`empty message box is showing: ${describe(el)}`);
  }
  if (/\b0 of 0 items\b/.test(document.body.innerText)) problems.push('checklist shows "0 of 0 items"');

  const lum = c => { const v = c.map(x => { x /= 255; return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
  const rgba = s => { const m = s.match(/[\d.]+/g) || []; return m.map(Number); };
  const bgOf = el => { for (let e = el; e; e = e.parentElement) { const c = rgba(getComputedStyle(e).backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0.5)) return c.slice(0, 3); } return [255, 255, 255]; };
  const seen = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const el = node.parentElement;
    if (!node.textContent.trim() || !el || seen.has(el) || !visible(el) || skipped(el) || el.closest('select')) continue;
    seen.add(el);
    const s = getComputedStyle(el);
    const size = parseFloat(s.fontSize);
    if (size < R.text) problems.push(`text under ${R.text}px (${size}px): ${describe(el)}`);
    const [a, b] = [lum(rgba(s.color).slice(0, 3)), lum(bgOf(el))].sort((x, y) => y - x);
    const ratio = (a + 0.05) / (b + 0.05);
    if (ratio < R.contrast) problems.push(`contrast ${ratio.toFixed(2)}:1 (${s.color} on rgb(${bgOf(el).join(',')})): ${describe(el)}`);
  }
  return [...new Set(problems)];
}

/**
 * Markup from pages the flow doesn't visit (admin, inspector, calculator, Garage), injected into a
 * real page so the cascade is the real one. Returns one string per problem.
 */
function styleFixturesInPage() {
  const box = document.createElement('div');
  box.innerHTML = '<form><button type="submit" class="btn btn-secondary" id="fx-secondary">Send back</button>'
    + '<button type="submit" class="btn btn-primary" id="fx-primary">Accept</button></form>'
    + '<details id="fx-details"><summary>How is my class calculated?</summary><p>x</p></details>'
    + '<button type="button" class="nudge-dismiss" id="fx-nudge">Not now</button>'
    + '<fieldset class="garage-season"><div class="garage-season-options"><label id="fx-season"><input type="radio" name="fx"><span>Ice</span></label></div></fieldset>';
  document.querySelector('main, .container, body').appendChild(box);
  const problems = [];
  const bg = id => getComputedStyle(document.getElementById(id)).backgroundColor;
  if (bg('fx-secondary') === bg('fx-primary')) problems.push(`secondary submit button looks like the primary (${bg('fx-secondary')})`);
  const google = document.querySelector('.btn-google');
  if (google && getComputedStyle(google).backgroundColor === bg('fx-primary')) problems.push('"Sign in with Google" looks like the primary button');
  const sum = document.querySelector('#fx-details summary');
  const marker = getComputedStyle(sum).display === 'list-item' || getComputedStyle(sum, '::before').content !== 'none';
  if (!marker) problems.push('<summary> has no disclosure triangle');
  const season = parseFloat(getComputedStyle(document.getElementById('fx-season')).minHeight);
  if (!(season >= 56)) problems.push(`Garage season card min-height is ${season}px, not its own 56px+`);
  const nudge = document.getElementById('fx-nudge').getBoundingClientRect().height;
  if (nudge < 44) problems.push(`save-nudge dismiss button is ${Math.round(nudge)}px tall`);
  box.remove();
  return problems;
}

let failed = 0;
async function audit(page, name) {
  await page.waitForLoadState('networkidle');
  // php -S serves one request at a time: wait until hub.css has applied (body.hub is 18px) before judging.
  await page.waitForFunction(() => getComputedStyle(document.body).fontSize === '18px', null, { timeout: 15000 });
  for (const [label, scale] of [[name, 1], [name + ' @150%', 1.5]]) {
    const style = scale === 1 ? null : await page.addStyleTag({ content: `html { font-size: ${scale * 100}% !important; } body.hub { font-size: ${18 * scale}px !important; }` });
    const problems = await page.evaluate(auditInPage, RULES);
    if (style) await style.evaluate(n => n.remove());
    report(label, problems);
  }
  // Browser zoom at 150% on a 375px phone lays the page out at 250px wide, so px-sized rules scale too.
  const size = page.viewportSize();
  await page.setViewportSize({ width: 250, height: 533 });
  report(name + ' @250px (150% zoom)', await page.evaluate(auditInPage, RULES));
  await page.setViewportSize(size);
}

function report(label, problems) {
  if (problems.length) failed++;
  console.log(`${problems.length ? 'FAIL' : 'ok  '} ${label}${problems.length ? '\n  - ' + problems.join('\n  - ') : ''}`);
}

const browser = await chromium.launch();
try {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const go = async sel => Promise.all([page.waitForNavigation(), page.click(sel)]);

  await page.goto(BASE + '/calculator.php'); await audit(page, 'calculator');
  await page.click('.calc-explainer-details summary'); await audit(page, 'calculator (explainer open)');
  await page.goto(BASE + '/index.php'); await audit(page, 'landing');
  await page.goto(BASE + '/auth.php?action=login'); await audit(page, 'sign in');
  report('style fixtures (admin, inspector, calculator markup)', await page.evaluate(styleFixturesInPage));
  await page.goto(BASE + '/auth.php?action=register'); await audit(page, 'create account');
  await page.fill('#name', 'Pat Winters');
  await page.fill('#email', `pat${Date.now()}@example.com`);
  await page.fill('input[name=password]', 'password123');
  await page.fill('input[name=password_confirm]', 'password123');
  await go('button[type=submit]');
  await audit(page, 'home');

  await go('section.hub-event:has-text("NASCC") a:has-text("Add a car for this event")');
  await audit(page, 'add a car');
  await page.fill('#car-car_number', '42');
  await page.fill('#car-make', 'Honda');
  await page.fill('#car-model', 'Civic');
  await page.fill('#car-colour', 'Blue');
  await go('button:has-text("Add car")');
  await audit(page, 'ice tech sheet (no class yet)');

  await page.selectOption('select[name=class]', 'SS');
  await page.waitForTimeout(300);
  await audit(page, 'ice tech sheet');
  await page.click('#tech-sheet-submit-btn');
  await page.waitForTimeout(300);
  const firstProblem = await page.evaluate(() => ({
    message: (document.querySelector('.field-message') || {}).textContent || '',
    focused: document.activeElement && document.activeElement.id,
  }));
  report('submitting an empty sheet says what is missing', firstProblem.message === 'Enter the race weight.' && firstProblem.focused === 'car_weight'
    ? [] : [`expected "Enter the race weight." with focus on car_weight, got ${JSON.stringify(firstProblem)}`]);
  await audit(page, 'ice tech sheet with problems shown');
  await page.check('input[name=log_book_turned_in][value="1"]');
  const logMsg = await page.locator('.radio-group + .field-message').count();
  report('answering the log book clears its message', logMsg === 0 ? [] : ['"Choose Yes or No for the log book." is still showing after answering']);
  const pads = () => page.evaluate(() => [...document.querySelectorAll('canvas')].filter(c => c.getBoundingClientRect().width > 0).length);
  const onePad = await pads();
  report('one signature pad when you are the driver', onePad === 1 ? [] : [`expected 1 visible pad, saw ${onePad}`]);
  await page.selectOption('#driver1_choice', 'new');
  const twoPads = await pads();
  report('two signature pads for a co-driver', twoPads === 2 ? [] : [`expected 2 visible pads, saw ${twoPads}`]);
  await page.selectOption('#driver1_choice', { index: 0 });

  // Answers survive a reload (spec §C1): weight, class and a ticked checklist item.
  const sheetUrl = page.url();
  await page.fill('input[name=car_weight]', '2700');
  await page.locator('.checklist-section-header').first().click();
  await page.locator('button:text-is("OK")').first().click();
  await page.waitForTimeout(400);
  await page.reload();
  await page.waitForLoadState('networkidle');
  const kept = await page.evaluate(() => ({
    notice: !!document.querySelector('.draft-notice'),
    weight: document.getElementById('car_weight').value,
    cls: document.getElementById('ice_class').value,
    okCount: document.querySelectorAll('.checklist-chip-selected-ok').length,
  }));
  report('answers kept after a reload', kept.notice && kept.weight === '2700' && kept.cls === 'SS' && kept.okCount >= 1
    ? [] : [`expected notice, weight 2700, class SS and a ticked item, got ${JSON.stringify(kept)}`]);
  await audit(page, 'ice tech sheet with a kept draft');
  await page.route(url => url.href.includes('action=new-ice'), async route => { await new Promise(r => setTimeout(r, 700)); await route.continue(); });
  await Promise.all([page.waitForNavigation(), page.click('#draft-start-over')]);
  await page.unroute(url => url.href.includes('action=new-ice'));
  await page.waitForLoadState('networkidle');
  const fresh = await page.evaluate(() => ({ notice: !!document.querySelector('.draft-notice'), weight: document.getElementById('car_weight').value }));
  report('Start over clears the kept answers', !fresh.notice && fresh.weight === '' ? [] : [`expected no notice and an empty weight, got ${JSON.stringify(fresh)}`]);
  await page.selectOption('select[name=class]', 'SS');
  await page.waitForTimeout(300);
  await page.fill('input[name=car_weight]', '2700');
  await page.fill('input[name=engine_hp]', '140');
  for (const h of await page.locator('.checklist-section-header').all()) await h.click();
  for (const b of await page.locator('button:text-is("OK"), button:text-is("Confirm")').all()) if (await b.isVisible()) await b.click();
  const ratings = page.locator('input[placeholder^="Rating"]');
  for (let i = 0; i < await ratings.count(); i++) await ratings.nth(i).fill(i ? 'SFI 3.2A/1' : 'SA2020');
  await page.check('input[name=log_book_turned_in][value="1"]');
  const sign = () => page.evaluate(() => {
    for (const cv of document.querySelectorAll('canvas')) {
      if (!cv.getBoundingClientRect().width) continue;
      const r = cv.getBoundingClientRect();
      const ev = (t, x, y) => cv.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: r.left + x, clientY: r.top + y, pointerId: 1, pointerType: 'touch', isPrimary: true, buttons: 1 }));
      ev('pointerdown', 20, 40); for (let i = 1; i <= 10; i++) ev('pointermove', 20 + i * 15, 40 + i * 5); ev('pointerup', 170, 90);
    }
  });
  await page.selectOption('#driver1_choice', 'new');
  await page.fill('#driver1_new_name', 'Sam Co');
  await sign();
  await page.selectOption('#driver1_choice', { index: 0 });
  await page.selectOption('#driver1_choice', 'new');
  await page.click('#tech-sheet-submit-btn');
  await page.waitForTimeout(400);
  const sigMsg = await page.evaluate(() => { const e = document.getElementById('sig-error'); return e && !e.hidden ? e.textContent : ''; });
  report('a signature wiped by changing the driver is asked for again', /Driver's signature box/.test(sigMsg) && page.url().includes('new-ice')
    ? [] : [`expected "Please sign in the Driver's signature box." and no submit, got "${sigMsg}" (box: "${await page.locator('#tech-sheet-error').textContent()}") at ${page.url()}`]);
  await page.evaluate(() => {
    const cv = document.getElementById('entrant-sig-canvas'); const r = cv.getBoundingClientRect();
    cv.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: r.left + 10, clientY: r.top + 10, pointerId: 1, pointerType: 'touch', isPrimary: true, buttons: 1 }));
    cv.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: r.left + 10, clientY: r.top + 10, pointerId: 1, pointerType: 'touch', isPrimary: true }));
  });
  report('touching the other pad keeps the message', await page.evaluate(() => !document.getElementById('sig-error').hidden)
    ? [] : ['the Driver\'s signature message went away when the Entrant pad was touched']);
  await page.evaluate(() => {
    const cv = document.getElementById('driver-sig-canvas'); const r = cv.getBoundingClientRect();
    cv.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: r.left + 10, clientY: r.top + 10, pointerId: 1, pointerType: 'touch', isPrimary: true, buttons: 1 }));
    cv.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: r.left + 10, clientY: r.top + 10, pointerId: 1, pointerType: 'touch', isPrimary: true }));
  });
  report('the signature message goes when you start signing', await page.evaluate(() => document.getElementById('sig-error').hidden && document.getElementById('tech-sheet-error').hidden)
    ? [] : ['the signature message is still showing after starting to sign']);
  await page.evaluate(() => {
    for (const cv of document.querySelectorAll('canvas')) {
      if (!cv.getBoundingClientRect().width) continue;
      const r = cv.getBoundingClientRect();
      const ev = (t, x, y) => cv.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: r.left + x, clientY: r.top + y, pointerId: 1, pointerType: 'touch', isPrimary: true, buttons: 1 }));
      ev('pointerdown', 20, 40); for (let i = 1; i <= 10; i++) ev('pointermove', 20 + i * 15, 40 + i * 5); ev('pointerup', 170, 90);
    }
  });
  await go('button[type=submit]');
  await audit(page, 'submitted sheet');
  const sigImgs = await page.locator('.sheet-doc img[src*="action=sig"]').count();
  report('submitted sheet has both signatures', sigImgs >= 2 ? [] : [`expected 2 signature images, found ${sigImgs}`]);

  const carUrl = await page.locator('.hub-subnav a').first().getAttribute('href');
  await go('a:has-text("Pre-tech with photos")');
  await audit(page, 'pre-tech photos');
  await page.goto(BASE + '/' + carUrl);
  await audit(page, 'car page');
  await page.click('.garage-edit summary'); await audit(page, 'car page (edit details open)');

  // Submitting cleared the draft: the same sheet starts fresh.
  await page.goto(sheetUrl);
  await page.waitForLoadState('networkidle');
  const leftover = await page.locator('.draft-notice').count();
  report('no draft left after submitting', leftover === 0 ? [] : ['the submitted sheet\'s draft was offered again']);
  // Admin tabs (admin desktop UX spec 2026-09-29): phone rules, the edit modal, Deactivate's confirm
  // inside the modal, and on desktop one-line user rows and a centred modal.
  const signInAdmin = async ctx => {
    const p = await ctx.newPage();
    await p.goto(BASE + '/auth.php?action=login');
    await p.fill('#email', process.env.UX_ADMIN_EMAIL || 'matt.sinfield@gmail.com');
    await p.fill('input[name=password]', 'password123');
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    return p;
  };
  const phoneAdmin = await browser.newContext({ viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const ap = await signInAdmin(phoneAdmin);
  for (const tab of ['users', 'events', 'clubs', 'season-links', 'settings', 'msr']) {
    await ap.goto(BASE + '/admin.php?action=' + tab);
    await audit(ap, 'admin ' + tab);
  }
  await ap.goto(BASE + '/admin.php?action=users');
  await ap.click('#users-table [data-dialog-open]');
  await audit(ap, 'admin user modal');
  await ap.click('dialog[open] .admin-dialog-danger button[type=submit]');
  // The page behind a modal dialog is inert: a confirm box outside the dialog can't be clicked.
  const answered = await ap.click('dialog[open] .confirm-modal [data-role=cancel]', { timeout: 5000 }).then(() => true, () => false);
  const stillOpen = await ap.locator('dialog.admin-dialog[open]').count();
  report('admin: Deactivate asks inside the modal, Cancel keeps the modal open', answered && stillOpen === 1 ? [] : ['the modal closed, or the confirm box could not be answered']);
  await phoneAdmin.close();

  const deskAdmin = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const dp = await signInAdmin(deskAdmin);
  await dp.goto(BASE + '/admin.php?action=users');
  const tallest = await dp.evaluate(() => Math.max(...[...document.querySelectorAll('#users-table tbody tr')].map(r => r.getBoundingClientRect().height)));
  report('admin: user rows at 1280px are one line', tallest <= 110 ? [] : [`a user row is ${Math.round(tallest)}px tall`]);
  await dp.click('#users-table [data-dialog-open]');
  const box = await dp.locator('dialog.admin-dialog[open]').boundingBox();
  const offCentre = box ? Math.abs(box.x + box.width / 2 - 640) : 999;
  report('admin: the edit modal is centred at 1280px', offCentre <= 2 ? [] : [`the modal is ${Math.round(offCentre)}px off centre`]);
  await deskAdmin.close();
} finally {
  await browser.close();
}
console.log(failed ? `\n${failed} page check(s) failed` : '\nAll pages pass');
process.exit(failed ? 1 : 0);
