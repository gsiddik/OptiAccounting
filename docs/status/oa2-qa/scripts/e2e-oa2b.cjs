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
async function postAs(page, url) {
  await page.goto(url); await settle(page)
  const approve = page.getByRole('button', { name: 'Setujui' })
  if (await approve.count()) { await approve.click(); await settle(page) }
  await page.getByRole('button', { name: 'Posting' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Posting' }).click(); await settle(page)
  await page.getByText('Diposting', { exact: false }).first().waitFor({ timeout: 8000 })
}
;(async () => {
  const browser = await chromium.launch()
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const page = await ctx.newPage()
  page.on('console', (m) => { if (m.type() === 'error') note(`CONSOLE: ${m.text().slice(0, 200)}`) })
  page.on('pageerror', (e) => note(`PAGEERROR: ${e.message.slice(0, 200)}`))
  page.on('response', async (r) => { if (r.status() >= 400 && r.url().includes('/api/')) { let b = ''; try { b = (await r.text()).slice(0, 200) } catch {} note(`HTTP ${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')} ${b}`) } })
  let receiptUrl, expenseUrl, statementUrl
  try {
    await login(page, 'akuntan')
    await step(page, 'akuntan membuat penerimaan kas (draf)', async () => {
      await page.goto(BASE + '/app/akuntansi/penerimaan-kas/baru'); await settle(page)
      await page.getByLabel('Akun kas/bank').selectOption({ label: 'BCA-OPS · BCA Operasional' })
      const counter = page.locator('select').nth(1)
      const opt = counter.locator('option', { hasText: '4100' }).first()
      await counter.selectOption({ value: await opt.getAttribute('value') })
      await page.getByLabel('Jumlah', { exact: true }).fill('330000')
      await page.getByLabel('Tujuan').fill('Setoran E2E')
      await page.getByLabel('Deskripsi').fill('Setoran penjualan E2E')
      await shot(page, '10-penerimaan-form')
      await page.getByRole('button', { name: 'Simpan draf' }).click()
      await page.waitForURL(/penerimaan-kas\/[0-9a-f-]{36}$/, { timeout: 15000 }); await settle(page)
      receiptUrl = page.url()
    })
    await step(page, 'akuntan membuat beban dibayar langsung dan mengajukannya', async () => {
      await page.goto(BASE + '/app/akuntansi/beban/baru'); await settle(page)
      await page.getByLabel('Dibayar langsung', { exact: false }).check().catch(async () => { await page.locator('input[name=settlement]').nth(1).check() })
      await page.getByLabel('Kategori', { exact: true }).selectOption({ label: 'MEALS · Konsumsi dan jamuan' }).catch(async () => { await page.getByLabel('Kategori', { exact: true }).selectOption({ index: 1 }) })
      await page.getByLabel('Deskripsi', { exact: true }).fill('Konsumsi tamu E2E')
      await page.getByLabel('Akun kas/bank').selectOption({ label: 'KAS-KECIL · Kas Kecil Jakarta' })
      await page.getByLabel('Jumlah neto').fill('210000')
      await page.getByLabel('Nama penerima').fill('Warung Makan E2E')
      await shot(page, '11-beban-form')
      await page.getByRole('button', { name: 'Simpan & ajukan' }).click()
      await page.waitForURL(/beban\/[0-9a-f-]{36}$/, { timeout: 15000 }); await settle(page)
      expenseUrl = page.url()
    })
    await logout(page)

    await login(page, 'manajer')
    await step(page, 'manajer memposting penerimaan kas', async () => { await postAs(page, receiptUrl); await shot(page, '12-penerimaan-diposting') })
    await step(page, 'manajer menyetujui dan memposting beban', async () => { await postAs(page, expenseUrl); await shot(page, '13-beban-diposting') })
    await logout(page)

    await login(page, 'akuntan')
    await step(page, 'akuntan menambah baris rekening koran dan mencocokkannya', async () => {
      await page.goto(BASE + '/app/akuntansi/rekening-koran'); await settle(page)
      await page.getByRole('link', { name: 'BCA-2026-10' }).click(); await settle(page)
      statementUrl = page.url()
      await page.getByRole('button', { name: 'Tambah baris' }).first().click()
      const dlg = page.getByRole('dialog')
      await dlg.getByLabel('Tanggal', { exact: false }).first().fill('2026-10-10')
      await dlg.getByLabel('Keterangan', { exact: false }).first().fill('Setoran tunai E2E')
      await dlg.getByLabel('Jumlah', { exact: false }).first().fill('330000')
      await shot(page, '14-baris-form')
      await dlg.getByRole('button', { name: /Simpan|Tambah/ }).last().click(); await settle(page)
      await page.getByRole('button', { name: 'Cocokkan baris 5' }).click(); await settle(page)
      await shot(page, '15-cocokkan-dialog')
      await page.getByRole('dialog').locator('input[name=candidate]').first().check()
      await page.getByRole('dialog').getByRole('button', { name: 'Cocokkan' }).click(); await settle(page)
      await page.getByRole('button', { name: 'Lepas baris 5' }).waitFor({ timeout: 8000 })
      await shot(page, '16-rekening-koran-cocok')
    })
    await step(page, 'akuntan menyelesaikan rekonsiliasi dan sistem menahan karena masih ada baris belum dicocokkan', async () => {
      await page.getByRole('button', { name: 'Selesaikan rekonsiliasi' }).click()
      await page.getByRole('dialog').getByRole('button', { name: /Selesaikan/ }).last().click(); await settle(page)
      await page.getByText(/belum dicocokkan|BANK_STATEMENT_HAS_UNMATCHED/i).first().waitFor({ timeout: 8000 })
      await shot(page, '17-selesaikan-ditolak')
    })
  } catch (e) { note(`STOPPED: ${String(e.message).split('\n')[0]}`) }
  await browser.close()
  console.log(`done: ${log.filter((l) => l.startsWith('OK')).length} ok, ${log.filter((l) => l.startsWith('FAIL')).length} fail`)
})()
