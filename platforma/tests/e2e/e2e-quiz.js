const { chromium } = require('playwright');
const base = 'http://127.0.0.1:8080';
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 1100, height: 900 } })).newPage();
  await p.goto(base + '/logowanie'); await p.fill('#le', '00ania@gmx.net'); await p.fill('#lp', 'Dlugie-haslo-1'); await p.click('button[type=submit]'); await p.waitForURL('**/moje-kursy');
  await p.goto(base + '/kurs/89/lekcja/1702'); await p.waitForSelector('section.quiz');
  // błędna odpowiedź (druga) + opis
  await p.locator('section.quiz input[type=radio]').nth(1).check(); await p.fill('section.quiz textarea', 'Moja odpowiedź');
  await p.click('section.quiz button[type=submit]'); await p.waitForSelector('.qres');
  console.log('wynik 1:', (await p.textContent('.qres')).trim()); console.log('retry link:', await p.locator('text=Spróbuj ponownie').count());
  await p.screenshot({ path: 's-quiz1.png', fullPage: true });
  await p.click('text=Spróbuj ponownie'); await p.locator('section.quiz input[type=radio]').first().check(); await p.fill('section.quiz textarea', 'Druga próba');
  await p.click('section.quiz button[type=submit]'); await p.waitForSelector('.qres'); console.log('wynik 2:', (await p.textContent('.qres')).trim());
  await b.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
