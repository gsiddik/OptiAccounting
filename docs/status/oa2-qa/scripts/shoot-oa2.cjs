const { chromium } = require('/opt/node-tools/node_modules/playwright')
const path = require('path')
const OUT = process.argv[2]
const who = process.argv[3] || 'manajer'
const BASE = 'http://127.0.0.1:5173'
const PASSWORD = 'Demo#Passw0rd2026'
const VIEWPORTS = { '1440': { width: 1440, height: 900 }, '820': { width: 820, height: 1100 }, '390': { width: 390, height: 844 } }
const LISTS = [
  ['home', '/app/akuntansi', null],
  ['vendor', '/app/akuntansi/vendor', null],
  ['faktur-vendor', '/app/akuntansi/faktur-vendor', 'detail'],
  ['faktur-vendor-baru', '/app/akuntansi/faktur-vendor/baru', null],
  ['pembayaran-vendor', '/app/akuntansi/pembayaran-vendor', 'detail'],
  ['pembayaran-vendor-baru', '/app/akuntansi/pembayaran-vendor/baru', null],
  ['umur-utang', '/app/akuntansi/umur-utang', null],
  ['rekonsiliasi-utang', '/app/akuntansi/rekonsiliasi/utang', null],
  ['beban', '/app/akuntansi/beban', 'detail'],
  ['beban-baru', '/app/akuntansi/beban/baru', null],
  ['kategori-beban', '/app/akuntansi/kategori-beban', null],
  ['kas-bank', '/app/akuntansi/kas-bank', 'detail'],
  ['pembayaran-kas', '/app/akuntansi/pembayaran-kas', 'detail'],
  ['pembayaran-kas-baru', '/app/akuntansi/pembayaran-kas/baru', null],
  ['penerimaan-kas', '/app/akuntansi/penerimaan-kas', 'detail'],
  ['rekening-koran', '/app/akuntansi/rekening-koran', 'detail'],
  ['rekonsiliasi-kas-bank', '/app/akuntansi/rekonsiliasi/kas-bank', null],
]
const notes = []
const note = (m) => { notes.push(m); console.log(m) }
async function login(page, email) {
  await page.goto(BASE + '/login')
  await page.getByLabel('E-mail', { exact: true }).fill(email)
  await page.getByLabel('Kata sandi', { exact: true }).fill(PASSWORD)
  await page.getByRole('button', { name: 'Masuk' }).click()
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 })
}
async function settle(page) {
  await page.waitForLoadState('networkidle')
  await page.waitForSelector('.loading', { state: 'detached', timeout: 10000 }).catch(() => {})
  await page.waitForTimeout(250)
}
async function overflow(page, label) {
  const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }))
  if (o.sw > o.cw) note(`OVERFLOW ${label}: ${o.sw} > ${o.cw}`)
}
;(async () => {
  const browser = await chromium.launch()
  for (const [vk, vp] of Object.entries(VIEWPORTS)) {
    const ctx = await browser.newContext({ viewport: vp })
    const page = await ctx.newPage()
    page.on('console', (m) => { if (m.type() === 'error') note(`CONSOLE ${who}-${vk}: ${m.text().slice(0, 200)}`) })
    page.on('pageerror', (e) => note(`PAGEERROR ${who}-${vk}: ${e.message.slice(0, 200)}`))
    page.on('response', async (r) => { if (r.status() >= 400 && r.url().includes('/api/')) { let b = ''; try { b = (await r.text()).slice(0, 160) } catch {} note(`HTTP ${r.status()} ${who}-${vk}: ${r.url().replace(BASE, '')} ${b}`) } })
    await login(page, `${who}@majujaya.demo.test`)
    for (const [name, url, detail] of LISTS) {
      await page.goto(BASE + url); await settle(page)
      await page.screenshot({ path: path.join(OUT, `${who}-${name}-${vk}.png`), fullPage: true })
      await overflow(page, `${who}-${name}-${vk}`)
      if (detail) {
        const link = page.locator('main a, .content a, table a, [class*=card] a').filter({ hasText: /\S/ }).locator('xpath=self::a[contains(@href,"/app/akuntansi/")]').first()
        if (await link.count()) {
          await link.click(); await settle(page)
          await page.screenshot({ path: path.join(OUT, `${who}-${name}-detail-${vk}.png`), fullPage: true })
          await overflow(page, `${who}-${name}-detail-${vk}`)
        } else note(`NO DETAIL LINK ${name} ${vk}`)
      }
    }
    await ctx.close()
  }
  await browser.close()
  console.log('done', notes.length, 'notes')
})()
