const { chromium } = require('playwright');
const base = 'http://127.0.0.1:8080';
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push('pageerror: ' + e.message)); p.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text()); });
  await p.goto(base + '/logowanie');
  await p.fill('#le', 'latuszekmarek@gmail.com'); await p.fill('#lp', 'Testowe-haslo-123'); await p.click('button[type=submit]'); await p.waitForURL('**/admin');
  // nowy kurs
  await p.click('[data-open=new-course]'); await p.fill('#nct', 'Kurs testowy E2E'); await p.click('dialog button[type=submit]'); await p.waitForURL('**/admin/kurs/*');
  console.log('editor url', p.url());
  await p.waitForSelector('[data-act=add-les]'); await p.click('[data-act=add-les]'); await p.waitForSelector('#lt');
  await p.screenshot({ path: 'ed1.png' });
  // tytuł lekcji + pierwszy blok tekstu
  await p.fill('#lt', 'Lekcja pierwsza');
  await p.click('.rte'); await p.keyboard.type('Witaj w kursie.');
  await p.keyboard.press('Control+A'); await p.click('[data-fmt=bold]');
  // dodaj Vimeo
  await p.click('[data-add=vimeo]'); await p.waitForSelector('[data-vurl]');
  await p.fill('[data-vurl]', 'https://vimeo.com/824517390/abcdef1234?share=copy');
  await p.waitForSelector('.vstat .ok'); console.log('vstat', await p.textContent('.vstat'));
  // youtube
  await p.click('[data-add=youtube]'); const yt = p.locator('[data-vurl]').nth(1); await yt.fill('https://youtu.be/M7lc1UVf-VE');
  // obraz poprawny
  await p.click('[data-add=image]'); await p.waitForSelector('input[data-up=image]',{state:'attached'});
  await p.setInputFiles('input[data-up=image]', 'ok.png'); await p.waitForSelector('.thumb img');
  // obraz fałszywy (zawartość nie pasuje)
  await p.setInputFiles('input[data-up=image]', 'evil.png'); await p.waitForTimeout(800);
  console.log('err evil.png:', await p.locator('[data-err]').last().textContent());
  // plik pdf
  await p.click('[data-add=file]'); await p.setInputFiles('.blk:last-child input[data-up=file]', 'ok.pdf'); await p.waitForSelector('.blk:last-child .upl b:has-text("ok.pdf")');
  await p.setInputFiles('.blk:last-child input[data-up=file]', 'evil.pdf'); await p.waitForTimeout(800);
  console.log('err evil.pdf:', await p.locator('[data-err]').last().textContent());
  // quiz
  await p.click('[data-add=quiz]'); await p.waitForSelector('[data-qf=qtext]');
  await p.fill('[data-qf=title]', 'Mój quiz'); await p.fill('[data-qf=qtext]', 'Ile to 2+2?');
  const ans = p.locator('[data-qf=atext]'); await ans.nth(0).fill('4'); await ans.nth(1).fill('5');
  await p.click('[data-qact=addq]'); await p.waitForTimeout(300);
  await p.locator('[data-qf=qtype]').nth(1).selectOption('text'); await p.locator('[data-qf=qtext]').nth(1).fill('Opisz własnymi słowami');
  await p.waitForTimeout(1500);
  // dodaj moduł i lekcję
  await p.click('#add-module'); await p.waitForTimeout(500);
  await p.locator('[data-act=add-les]').last().click(); await p.waitForFunction(() => document.querySelector('#lt') && document.querySelector('#lt').value === 'Nowa lekcja'); await p.fill('#lt', 'Lekcja w drugim module'); await p.waitForTimeout(800);
  // przeciągnij lekcję 2 do modułu 1 (drag-and-drop)
  const les = p.locator('.les').filter({ hasText: 'Lekcja w drugim module' }); const target = p.locator('.mod').first().locator('.les').first();
  const gb = await les.locator('.grip').boundingBox(), tb = await target.boundingBox();
  await p.mouse.move(gb.x + 5, gb.y + 5); await p.mouse.down(); await p.mouse.move(gb.x + 5, gb.y - 20, { steps: 5 }); await p.mouse.move(tb.x + 30, tb.y + 4, { steps: 12 }); await p.mouse.up(); await p.waitForTimeout(1200);
  console.log('lekcji w module1 po przeciągnięciu:', await p.locator('.mod').first().locator('.les').count());
  await p.screenshot({ path: 'ed2.png', fullPage: true });
  // Czas dostępu
  await p.selectOption('#acc-mode', 'lim'); await p.fill('#acc-n', '6'); await p.waitForTimeout(900);
  await p.reload(); await p.waitForSelector('#lt');
  console.log('po reload: blok-ow w lekcji 1:', await p.locator('.blk').count(), 'acc:', await p.inputValue('#acc-n'));
  console.log('ERRORS', JSON.stringify(errs));
  await b.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
