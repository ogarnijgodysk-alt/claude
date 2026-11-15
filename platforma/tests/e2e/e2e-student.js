const { chromium } = require('playwright');
const base = 'http://127.0.0.1:8080';
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('response', r => { if (r.status() >= 400) errs.push(r.status() + ' ' + r.url()); });
  await p.goto(base + '/logowanie'); await p.fill('#le', '00ania@gmx.net'); await p.fill('#lp', 'Dlugie-haslo-1'); await p.click('button[type=submit]'); await p.waitForURL('**/moje-kursy');
  await p.screenshot({ path: 's-courses.png', fullPage: true });
  console.log('karty:', await p.locator('.card').count(), '| wygasły:', await p.locator('.card.lock').count(), '| "Przedłuż dostęp":', await p.locator('text=Przedłuż dostęp').count());
  console.log('soon:', (await p.locator('.warnc').allTextContents()).join(' / '));
  // wygasły kurs: bezpośrednie wejście zablokowane
  const r = await p.goto(base + '/kurs/3'); console.log('wejście do wygasłego kursu ->', p.url());
  // kurs testowy z quizem
  await p.goto(base + '/kurs/89'); await p.waitForSelector('h1'); console.log('lekcja:', await p.textContent('h1'));
  await p.screenshot({ path: 's-lesson.png', fullPage: true });
  // quiz
  const quiz = p.locator('section.quiz'); console.log('quiz widoczny:', await quiz.count());
  if (await quiz.count()) {
    await p.locator('section.quiz input[type=radio]').first().check(); await p.fill('section.quiz textarea', 'Moja odpowiedź');
    await p.click('section.quiz button[type=submit]'); await p.waitForSelector('.qres'); console.log('wynik:', await p.textContent('.qres'));
    await p.screenshot({ path: 's-quiz.png', fullPage: true });
  }
  // ukończ lekcję
  await p.click('text=Oznacz jako ukończoną'); await p.waitForLoadState(); console.log('po ukończeniu, h1:', await p.textContent('h1'), '| flash:', await p.locator('.notice').allTextContents());
  // pobranie pliku i obrazu
  const links = await p.locator('a.btn:has-text("Pobierz")').evaluateAll(a => a.map(x => x.href)); console.log('pliki:', links);
  await p.goto(base + '/kurs/89'); 
  const f = await ctx.request.get(base + '/plik/2'); console.log('plik/2:', f.status(), f.headers()['content-disposition'], f.headers()['content-type']);
  const im = await ctx.request.get(base + '/obraz/1'); console.log('obraz/1:', im.status(), im.headers()['content-type'], im.headers()['content-disposition']);
  const im2 = await ctx.request.get(base + '/obraz/2'); console.log('obraz/2 (pdf jako obraz):', im2.status());
  await p.setViewportSize({ width: 375, height: 800 }); await p.goto(base + '/kurs/89'); 
  console.log('poziomy scroll (375px):', await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth));
  await p.screenshot({ path: 's-mobile.png', fullPage: true });
  console.log('ERR', JSON.stringify(errs));
  await b.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
