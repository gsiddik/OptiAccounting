const { chromium } = require('/opt/node-tools/node_modules/playwright')
const path = require('path')
const OUT = process.argv[2]
const BASE = 'http://127.0.0.1:5173'
const PASSWORD = 'Demo#Passw0rd2026'
const log = []
const note = (m) => { log.push(m); console.log(m) }
async function login(page, who) {
  await page.goto(BASE + '/login')
  await page.getByLabel('E-mail', { exact: true }).fill(`${who}@majujaya.demo.test`)
  await page.getByLabel('Kata sandi', { exact: true }).fill(PASSWORD)
  await page.getByRole('button', { name: 'Masuk' }).click()
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 })
}
async function logout(page) { await page.getByRole('button', { name: 'Keluar' }).click(); await page.waitForURL(/login/, { timeout: 10000 }) }
async function settle(page) {
  await page.waitForLoadState('networkidle')
  await page.waitForSelector('.loading', { state: 'detached', timeout: 10000 }).catch(() => {})
  await page.waitForTimeout(250)
}
async function shot(page, name) { await page.screenshot({ path: path.join(OUT, `e2e-${name}.png`), fullPage: true }) }
async function step(page, name, fn) {
  try { await fn(); note(`OK   ${name}`) } catch (e) {
    note(`FAIL ${name}: ${String(e.message).split('\n')[0].slice(0, 220)}`)
    const btns = await page.getByRole('button').allInnerTexts().catch(() => [])
    note(`     buttons: ${btns.slice(0, 20).join(' | ')}`)
    await shot(page, `FAIL-${name.replace(/\W+/g, '-')}`)
    throw e
  }
}
;(async () => {
  const browser = await chromium.launch()
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const page = await ctx.newPage()
  page.on('console', (m) => { if (m.type() === 'error') note(`CONSOLE: ${m.text().slice(0, 200)}`) })
  page.on('pageerror', (e) => note(`PAGEERROR: ${e.message.slice(0, 200)}`))
  page.on('response', async (r) => { if (r.status() >= 400 && r.url().includes('/api/')) { let b = ''; try { b = (await r.text()).slice(0, 200) } catch {} note(`HTTP ${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')} ${b}`) } })
  let invoiceUrl, paymentUrl
  try {
    await login(page, 'akuntan')
    await step(page, 'akuntan membuat faktur vendor dan mengajukannya', async () => {
      await page.goto(BASE + '/app/akuntansi/faktur-vendor/baru'); await settle(page)
      await page.getByLabel('Vendor', { exact: true }).selectOption({ label: 'ATK · Toko ATK Sejahtera' })
      await page.getByLabel('Nomor faktur vendor').fill('E2E-ATK-'+Date.now().toString().slice(-6))
      await page.getByLabel('Deskripsi', { exact: true }).fill('Kertas dan tinta printer')
      await page.getByLabel('Deskripsi baris 1').fill('Kertas A4 dan tinta')
      await page.getByLabel('Jumlah baris 1').fill('2000000')
      await shot(page, '01-faktur-form')
      await page.getByRole('button', { name: 'Simpan & ajukan' }).click()
      await page.waitForURL(/faktur-vendor\/[0-9a-f-]{36}$/, { timeout: 15000 }); await settle(page)
      invoiceUrl = page.url()
      await page.getByText('Diajukan', { exact: false }).first().waitFor({ timeout: 8000 })
      await shot(page, '02-faktur-diajukan')
    })
    await logout(page)

    await login(page, 'manajer')
    await step(page, 'manajer menyetujui dan memposting faktur', async () => {
      await page.goto(invoiceUrl); await settle(page)
      await page.getByRole('button', { name: 'Setujui' }).click(); await settle(page)
      await page.getByRole('button', { name: 'Posting' }).click()
      await page.getByRole('dialog').getByRole('button', { name: 'Posting' }).click(); await settle(page)
      await page.getByText('Diposting', { exact: false }).first().waitFor({ timeout: 8000 })
      await shot(page, '03-faktur-diposting')
    })
    await logout(page)

    await login(page, 'akuntan')
    await step(page, 'akuntan membuat pembayaran vendor dengan alokasi otomatis dan mengajukannya', async () => {
      await page.goto(BASE + '/app/akuntansi/pembayaran-vendor/baru'); await settle(page)
      await page.getByLabel('Vendor', { exact: true }).selectOption({ label: 'ATK · Toko ATK Sejahtera' }); await settle(page)
      await page.getByLabel('Akun kas/bank').selectOption({ label: 'BCA-OPS · BCA Operasional' })
      await page.getByLabel('Jumlah pembayaran', { exact: true }).fill('2000000')
      await page.getByRole('button', { name: 'Alokasikan otomatis' }).click(); await settle(page)
      await shot(page, '04-pembayaran-alokasi')
      await page.getByRole('button', { name: 'Simpan & ajukan' }).click()
      await page.waitForURL(/pembayaran-vendor\/[0-9a-f-]{36}$/, { timeout: 15000 }); await settle(page)
      paymentUrl = page.url()
      await page.getByText('Diajukan', { exact: false }).first().waitFor({ timeout: 8000 })
    })
    await step(page, 'akuntan tidak dapat menyetujui atau memposting', async () => {
      const body = await page.locator('body').innerText()
      if (await page.getByRole('button', { name: 'Setujui' }).count()) throw new Error('accountant sees an approve button')
      if (await page.getByRole('button', { name: 'Posting' }).count()) throw new Error('accountant sees a post button')
    })
    await logout(page)

    await login(page, 'manajer')
    await step(page, 'manajer menyetujui dan memposting pembayaran', async () => {
      await page.goto(paymentUrl); await settle(page)
      await page.getByRole('button', { name: 'Setujui' }).click(); await settle(page)
      await page.getByRole('button', { name: 'Posting' }).click()
      await page.getByRole('dialog').getByRole('button', { name: 'Posting' }).click(); await settle(page)
      await page.getByText('Diposting', { exact: false }).first().waitFor({ timeout: 8000 })
      await shot(page, '05-pembayaran-diposting')
    })
    await step(page, 'faktur kini lunas dan sisa utang nol', async () => {
      await page.goto(invoiceUrl); await settle(page)
      await shot(page, '06-faktur-lunas')
      const body = await page.locator('body').innerText()
      if (!/Lunas/i.test(body)) throw new Error('invoice does not show as paid')
    })
    await step(page, 'rekonsiliasi utang tetap cocok', async () => {
      await page.goto(BASE + '/app/akuntansi/rekonsiliasi/utang'); await settle(page)
      await shot(page, '07-rekonsiliasi-utang')
      const body = await page.locator('body').innerText()
      if (/Selisih\s+[1-9]/.test(body) && /BERBEDA|MISMATCH|Tidak cocok/i.test(body)) throw new Error('AP reconciliation shows a mismatch')
    })
  } catch (e) { note(`STOPPED: ${String(e.message).split('\n')[0]}`) }
  await browser.close()
  console.log(`done: ${log.filter((l) => l.startsWith('OK')).length} ok, ${log.filter((l) => l.startsWith('FAIL')).length} fail`)
})()
