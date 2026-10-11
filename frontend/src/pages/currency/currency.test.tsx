import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { boot, currency, exchangeRate, profile } from './testkit'

const AP = '/app/accounting'

afterEach(() => vi.restoreAllMocks())

describe('currencies', () => {
  const view = ['accounting.currency.view']
  const manage = [...view, 'accounting.currency.manage']
  const eur = currency({ id: 'cur-eur', code: 'EUR', name: 'Euro', symbol: '€', status: 'INACTIVE', in_use: true })
  const list = { 'GET /app/accounting/currencies': { data: page([currency(), eur]) } }
  const withProfile = { 'GET /app/accounting/profile': { data: { data: profile, frameworks: [] } } }

  it('shows the functional currency from the accounting profile and the foreign currencies, without management controls', async () => {
    boot([...view, 'accounting.profile.view'], { ...list, ...withProfile })
    renderApp('/app/akuntansi/mata-uang')

    expect(await screen.findByText('Dolar Amerika Serikat')).toBeInTheDocument()
    expect(await screen.findByText('IDR')).toBeInTheDocument()
    expect(screen.getByText(/selalu tersedia, tidak didaftarkan/)).toBeInTheDocument()
    expect(screen.getByText('Belum dipakai')).toBeInTheDocument()
    expect(screen.getByText('Sudah dipakai')).toBeInTheDocument()
    for (const label of ['Mata uang baru', 'Ubah', 'Nonaktifkan', 'Aktifkan', 'Hapus']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('explains instead of failing when the user may not read the accounting profile', async () => {
    const calls = boot(view, list)
    renderApp('/app/akuntansi/mata-uang')
    expect(await screen.findByText(/Menampilkannya membutuhkan izin melihat profil akuntansi/)).toBeInTheDocument()
    expect(calls.some((c) => c.url === `${AP}/profile`)).toBe(false)
  })

  it('offers no management control in a read-only subscription even for a user who may manage', async () => {
    boot(manage, list, { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/mata-uang')
    expect(await screen.findByText('Dolar Amerika Serikat')).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mata uang baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah' })).not.toBeInTheDocument()
  })

  it('adds a foreign currency with its ISO code in capitals, name, symbol and decimal places', async () => {
    const calls = boot(manage, { 'GET /app/accounting/currencies': { data: page([]) }, 'POST /app/accounting/currencies': { status: 201, data: currency({ code: 'JPY', decimal_places: 0 }) } })
    renderApp('/app/akuntansi/mata-uang')
    await userEvent.click(await screen.findByRole('button', { name: 'Mata uang baru' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Mata uang asing baru' }))
    await userEvent.type(dialog.getByLabelText('Kode ISO'), 'jpy')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Yen Jepang')
    await userEvent.type(dialog.getByLabelText('Simbol'), '¥')
    await userEvent.selectOptions(dialog.getByLabelText('Jumlah desimal'), '0')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ code: 'JPY', name: 'Yen Jepang', symbol: '¥', decimal_places: 0 })
    expect(await screen.findByText('Mata uang ditambahkan.')).toBeInTheDocument()
  })

  it('refuses a malformed code before asking, and shows the API refusal for the functional currency in Indonesian', async () => {
    const calls = boot(manage, {
      'GET /app/accounting/currencies': { data: page([]) },
      'POST /app/accounting/currencies': { status: 422, data: { message: 'The functional currency is always available', code: 'CURRENCY_IS_FUNCTIONAL', details: { field: 'code' } } },
    })
    renderApp('/app/akuntansi/mata-uang')
    await userEvent.click(await screen.findByRole('button', { name: 'Mata uang baru' }))
    const dialog = within(screen.getByRole('dialog'))

    await userEvent.type(dialog.getByLabelText('Kode ISO'), 'US')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Dolar')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText(/tiga huruf ISO 4217/, { selector: '[role="alert"]' })).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await userEvent.type(dialog.getByLabelText('Kode ISO'), 'D')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect((await dialog.findAllByText('Mata uang fungsional selalu tersedia dan tidak perlu didaftarkan.')).length).toBeGreaterThan(0)
  })

  it('edits a used currency without its code and with the precision locked', async () => {
    const calls = boot(manage, { 'GET /app/accounting/currencies': { data: page([currency({ in_use: true })]) }, 'PATCH /app/accounting/currencies/cur-usd': { data: currency({ in_use: true, name: 'US Dollar' }) } })
    renderApp('/app/akuntansi/mata-uang')
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Ubah mata uang USD' }))
    expect(dialog.getByLabelText('Kode ISO')).toBeDisabled()
    expect(dialog.getByLabelText('Jumlah desimal')).toBeDisabled()
    await userEvent.clear(dialog.getByLabelText('Nama'))
    await userEvent.type(dialog.getByLabelText('Nama'), 'US Dollar')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toBeDefined())
    expect(calls.find((c) => c.method === 'PATCH')!.data).toEqual({ name: 'US Dollar', symbol: '$', decimal_places: 2 })
  })

  it('deactivates and re-activates after confirmation', async () => {
    const calls = boot(manage, { ...list, 'POST /app/accounting/currencies/cur-usd/deactivate': { data: currency({ status: 'INACTIVE' }) }, 'POST /app/accounting/currencies/cur-eur/activate': { data: eur } })
    renderApp('/app/akuntansi/mata-uang')
    await screen.findByText('Dolar Amerika Serikat')

    await userEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog', { name: 'Nonaktifkan mata uang' })).getByRole('button', { name: 'Nonaktifkan' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/currencies/cur-usd/deactivate`)).toBeDefined())

    await userEvent.click(await screen.findByRole('button', { name: 'Aktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog', { name: 'Aktifkan mata uang' })).getByRole('button', { name: 'Aktifkan' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/currencies/cur-eur/activate`)).toBeDefined())
  })

  it('offers Hapus only for a currency nothing used, and explains the API refusal', async () => {
    const calls = boot(manage, {
      ...list,
      'DELETE /app/accounting/currencies/cur-usd': { status: 409, data: { message: 'This currency has been used', code: 'CURRENCY_IN_USE', details: {} } },
    })
    renderApp('/app/akuntansi/mata-uang')
    await screen.findByText('Dolar Amerika Serikat')

    // USD is unused, EUR is used: exactly one delete button exists
    const remove = screen.getAllByRole('button', { name: 'Hapus' })
    expect(remove).toHaveLength(1)
    await userEvent.click(remove[0])
    const dialog = within(screen.getByRole('dialog', { name: 'Hapus mata uang' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'DELETE')).toBeDefined())
    expect(await dialog.findByText('Mata uang ini sudah dipakai: nonaktifkan, jangan hapus atau ubah presisinya.')).toBeInTheDocument()
  })

  describe('selisih kurs setup', () => {
    const fx = (over: Record<string, unknown> = {}) => ({
      events: [
        { event_type: 'VENDOR_PAYMENT_FX', name: 'Pembayaran vendor mata uang asing', ready: false, rule_code: null, effective_from: null },
        { event_type: 'CUSTOMER_RECEIPT_FX', name: 'Penerimaan pelanggan mata uang asing', ready: true, rule_code: 'AR-RECEIPT-FX', effective_from: '2026-01-01' },
      ],
      unmapped_roles: ['FX_LOSS', 'FX_GAIN'],
      ...over,
    })
    const ready = { events: fx().events.map((e) => ({ ...e, ready: true, rule_code: 'X', effective_from: '2026-01-01' })), unmapped_roles: [] }
    const allow = [...view, 'accounting.exchange_rate.view']

    it('shows which rules and roles are ready and says single-currency organisations need none of it', async () => {
      boot(allow, { ...list, 'GET /app/accounting/fx-rules': { data: fx() } })
      renderApp('/app/akuntansi/mata-uang')

      expect(await screen.findByText(/hanya memakai satu mata uang tidak membutuhkan pengaturan ini/)).toBeInTheDocument()
      expect(await screen.findByText('Belum lengkap: pelunasan faktur mata uang asing belum dapat diposting sampai aturan dan akunnya siap.')).toBeInTheDocument()
      expect(screen.getByText('Pembayaran vendor mata uang asing')).toBeInTheDocument()
      expect(screen.getByText(/Belum dipetakan ke akun: Rugi selisih kurs \(terealisasi\), Laba selisih kurs \(terealisasi\)/)).toBeInTheDocument()
      // no posting_rule.manage permission: the status is shown but nothing can be applied
      expect(screen.queryByRole('button', { name: 'Terapkan aturan bawaan' })).not.toBeInTheDocument()
    })

    it('applies the default rules for a user who may manage posting rules and reloads the status', async () => {
      let applied = false
      const calls = boot([...allow, 'accounting.posting_rule.manage'], {
        ...list,
        'GET /app/accounting/fx-rules': () => ({ data: applied ? ready : fx() }),
        'POST /app/accounting/fx-rules/defaults': () => {
          applied = true
          return { status: 201, data: { created: ['VENDOR_PAYMENT_FX'], skipped: [{ event_type: 'CUSTOMER_RECEIPT_FX', reason: 'ALREADY_PUBLISHED' }] } }
        },
      })
      renderApp('/app/akuntansi/mata-uang')
      await userEvent.click(await screen.findByRole('button', { name: 'Terapkan aturan bawaan' }))
      const dialog = within(screen.getByRole('dialog', { name: 'Terapkan aturan posting selisih kurs' }))
      await userEvent.click(dialog.getByRole('button', { name: 'Terapkan' }))

      await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
      expect(calls.find((c) => c.method === 'POST')!.data).toEqual({})
      expect(await screen.findByText('1 aturan posting selisih kurs diterbitkan.')).toBeInTheDocument()
      expect(await screen.findByText(/Siap: aturan posting dan peran akun selisih kurs sudah lengkap/)).toBeInTheDocument()
      expect(screen.getByText(/Dilewati: Penerimaan pelanggan mata uang asing \(sudah ada aturan yang terbit\)/)).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Terapkan aturan bawaan' })).not.toBeInTheDocument()
    })

    it('can start the rules from a chosen date and reports a refusal in Indonesian', async () => {
      const calls = boot([...allow, 'accounting.posting_rule.manage'], {
        ...list,
        'GET /app/accounting/fx-rules': { data: fx() },
        'POST /app/accounting/fx-rules/defaults': { status: 409, data: { message: 'Create a fiscal year', code: 'FISCAL_YEAR_MISSING', details: {} } },
      })
      renderApp('/app/akuntansi/mata-uang')
      await userEvent.click(await screen.findByRole('button', { name: 'Terapkan aturan bawaan' }))
      const dialog = within(screen.getByRole('dialog'))
      fireEvent.change(dialog.getByLabelText('Berlaku mulai'), { target: { value: '2026-01-01' } })
      await userEvent.click(dialog.getByRole('button', { name: 'Terapkan' }))

      await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
      expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ effective_from: '2026-01-01' })
      expect(await dialog.findByText('Buat tahun fiskal sebelum menerapkan aturan posting standar.')).toBeInTheDocument()
    })

    it('hides the panel from a user who may not read rates, and offers no apply button in a read-only subscription', async () => {
      const calls = boot(view, list)
      renderApp('/app/akuntansi/mata-uang')
      await screen.findByText('Dolar Amerika Serikat')
      expect(screen.queryByText('Selisih kurs')).not.toBeInTheDocument()
      expect(calls.some((c) => c.url === `${AP}/fx-rules`)).toBe(false)
    })

    it('shows no apply button when the setup is complete', async () => {
      boot([...allow, 'accounting.posting_rule.manage'], { ...list, 'GET /app/accounting/fx-rules': { data: ready } })
      renderApp('/app/akuntansi/mata-uang')
      expect(await screen.findByText(/Siap: aturan posting dan peran akun selisih kurs sudah lengkap/)).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Terapkan aturan bawaan' })).not.toBeInTheDocument()
    })

    it('does not offer applying in a read-only subscription', async () => {
      boot([...allow, 'accounting.posting_rule.manage'], { ...list, 'GET /app/accounting/fx-rules': { data: fx() } }, { mode: 'READ_ONLY' })
      renderApp('/app/akuntansi/mata-uang')
      expect(await screen.findByText(/Belum lengkap/)).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Terapkan aturan bawaan' })).not.toBeInTheDocument()
    })
  })
})

describe('exchange rates', () => {
  const view = ['accounting.exchange_rate.view']
  const manage = [...view, 'accounting.exchange_rate.manage']
  const rates = [exchangeRate(), exchangeRate({ id: 'fx-2', rate_type: 'SPOT', rate: '16100.0000000000', effective_date: '2026-09-30', source: null, notes: 'koreksi', status: 'INACTIVE' })]
  const list = { 'GET /app/accounting/exchange-rates': { data: page(rates) } }
  const currencies = { 'GET /app/accounting/currencies': { data: page([currency(), currency({ id: 'cur-eur', code: 'EUR', name: 'Euro' })]) } }

  it('lists the rates as the server returned them and offers no management control without the permission', async () => {
    boot(view, list)
    renderApp('/app/akuntansi/kurs')

    expect(await screen.findByText('16.250,50')).toBeInTheDocument()
    expect(screen.getByText('16.100,00')).toBeInTheDocument()
    expect(screen.getByText('Bank Indonesia')).toBeInTheDocument()
    expect(screen.getByText('Ditarik', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('Aktif', { selector: '.badge' })).toBeInTheDocument()
    for (const label of ['Kurs baru', 'Tarik', 'Aktifkan', 'Hapus']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('offers no management control in a read-only subscription even for a user who may manage', async () => {
    boot(manage, list, { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/kurs')
    expect(await screen.findByText('16.250,50')).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Kurs baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tarik' })).not.toBeInTheDocument()
  })

  it('filters on the server by currency, type, status and date range', async () => {
    const calls = boot([...view, 'accounting.currency.view'], { ...list, ...currencies })
    renderApp('/app/akuntansi/kurs')
    await screen.findByText('16.250,50')

    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Mata uang' }), 'USD')
    await userEvent.selectOptions(screen.getByLabelText('Jenis kurs'), 'SPOT')
    await userEvent.selectOptions(screen.getByLabelText('Status kurs'), 'INACTIVE')
    fireEvent.change(screen.getByLabelText('Dari tanggal'), { target: { value: '2026-09-01' } })
    fireEvent.change(screen.getByLabelText('Sampai tanggal'), { target: { value: '2026-10-31' } })
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/exchange-rates` && c.params?.currency === 'USD' && c.params?.rate_type === 'SPOT' && c.params?.status === 'INACTIVE' && c.params?.date_from === '2026-09-01' && c.params?.date_to === '2026-10-31')).toBe(true))
  })

  it('without permission to read currencies the currency filter is a code box that sends only a complete ISO code', async () => {
    const calls = boot(view, list)
    renderApp('/app/akuntansi/kurs')
    await screen.findByText('16.250,50')

    const box = screen.getByRole('textbox', { name: 'Mata uang' })
    await userEvent.type(box, 'us')
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(calls.some((c) => c.url === `${AP}/exchange-rates` && c.params?.currency !== undefined)).toBe(false)
    await userEvent.type(box, 'd')
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/exchange-rates` && c.params?.currency === 'USD')).toBe(true))
  })

  it('enters a new rate and sends the rate as a string with the decimal comma turned into a point', async () => {
    const calls = boot([...manage, 'accounting.currency.view', 'accounting.profile.view'], {
      ...list,
      ...currencies,
      'GET /app/accounting/profile': { data: { data: profile, frameworks: [] } },
      'POST /app/accounting/exchange-rates': { status: 201, data: exchangeRate({ id: 'fx-9', rate_type: 'SPOT' }) },
    })
    renderApp('/app/akuntansi/kurs')
    await userEvent.click(await screen.findByRole('button', { name: 'Kurs baru' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Kurs baru' }))
    await waitFor(() => expect(dialog.getAllByRole('option').map((o) => o.textContent)).toContain('USD · Dolar Amerika Serikat'))
    await userEvent.selectOptions(dialog.getByLabelText('Mata uang'), 'USD')
    await userEvent.selectOptions(dialog.getByLabelText('Jenis kurs'), 'SPOT')
    await userEvent.type(dialog.getByLabelText('Kurs'), '16250,5')
    await userEvent.type(dialog.getByLabelText('Sumber'), 'Bank Indonesia')
    await waitFor(() => expect(dialog.getByText(/1 USD = berapa IDR/)).toBeInTheDocument())
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ from_currency: 'USD', rate: '16250.5', effective_date: '2026-10-08', rate_type: 'SPOT', source: 'Bank Indonesia', notes: null })
    expect(await screen.findByText('Kurs disimpan.')).toBeInTheDocument()
  })

  it('shows the Indonesian message when a rate already exists for that currency, type and date', async () => {
    boot([...manage, 'accounting.currency.view'], {
      ...list,
      ...currencies,
      'POST /app/accounting/exchange-rates': { status: 409, data: { message: 'An active rate already exists', code: 'EXCHANGE_RATE_DUPLICATE', details: {} } },
    })
    renderApp('/app/akuntansi/kurs')
    await userEvent.click(await screen.findByRole('button', { name: 'Kurs baru' }))
    const dialog = within(screen.getByRole('dialog'))
    await waitFor(() => expect(dialog.getAllByRole('option').length).toBeGreaterThan(1))
    await userEvent.selectOptions(dialog.getByLabelText('Mata uang'), 'USD')
    await userEvent.type(dialog.getByLabelText('Kurs'), '16000')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    expect(await dialog.findByText('Sudah ada kurs aktif untuk mata uang, jenis, dan tanggal ini. Tarik kurs itu lebih dulu untuk memasukkan yang lain.')).toBeInTheDocument()
  })

  it('points an invalid rate out under the rate field', async () => {
    boot(manage, {
      ...list,
      'POST /app/accounting/exchange-rates': { status: 422, data: { message: 'The rate is a positive decimal string', code: 'EXCHANGE_RATE_INVALID', details: { field: 'rate' } } },
    })
    renderApp('/app/akuntansi/kurs')
    await userEvent.click(await screen.findByRole('button', { name: 'Kurs baru' }))
    const dialog = within(screen.getByRole('dialog'))

    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText(/tiga huruf, atau isi kode ISO|Pilih mata uang asing/)).toBeInTheDocument()
    await userEvent.type(dialog.getByLabelText('Mata uang'), 'usd')
    await userEvent.type(dialog.getByLabelText('Kurs'), '-5')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect((await dialog.findAllByText('Kurs harus bilangan positif dengan paling banyak enam desimal.')).length).toBeGreaterThan(0)
  })

  it('withdraws, re-activates and deletes a rate, and explains why a cited rate cannot be deleted', async () => {
    const calls = boot(manage, {
      ...list,
      'POST /app/accounting/exchange-rates/fx-1/deactivate': { data: exchangeRate({ status: 'INACTIVE' }) },
      'POST /app/accounting/exchange-rates/fx-2/activate': { data: exchangeRate({ id: 'fx-2' }) },
      'DELETE /app/accounting/exchange-rates/fx-1': { status: 409, data: { message: 'A document cites this rate', code: 'EXCHANGE_RATE_IN_USE', details: {} } },
    })
    renderApp('/app/akuntansi/kurs')
    await screen.findByText('16.250,50')

    await userEvent.click(screen.getByRole('button', { name: 'Tarik' }))
    await userEvent.click(within(screen.getByRole('dialog', { name: 'Tarik kurs' })).getByRole('button', { name: 'Tarik kurs' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/exchange-rates/fx-1/deactivate`)).toBeDefined())

    await userEvent.click(await screen.findByRole('button', { name: 'Aktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog', { name: 'Aktifkan kurs' })).getByRole('button', { name: 'Aktifkan' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/exchange-rates/fx-2/activate`)).toBeDefined())

    await userEvent.click((await screen.findAllByRole('button', { name: 'Hapus' }))[0])
    const dialog = within(screen.getByRole('dialog', { name: 'Hapus kurs' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))
    expect(await dialog.findByText('Ada dokumen yang memakai kurs ini: tarik (nonaktifkan), jangan hapus.')).toBeInTheDocument()
  })

  describe('cari kurs', () => {
    const found = {
      currency: 'USD', functional_currency: 'IDR', rate: '16250.5000000000', rate_id: 'fx-1', effective_date: '2026-10-05', rate_type: 'MANUAL', source: 'Bank Indonesia',
    }

    it('asks the server which rate a document would use and shows the answer', async () => {
      const calls = boot([...view, 'accounting.currency.view'], { ...list, ...currencies, 'GET /app/accounting/exchange-rates/lookup': { data: found } })
      renderApp('/app/akuntansi/kurs')
      await waitFor(() => expect(within(screen.getByLabelText('Mata uang dokumen')).getAllByRole('option').length).toBeGreaterThan(1))

      await userEvent.selectOptions(screen.getByLabelText('Mata uang dokumen'), 'USD')
      await userEvent.selectOptions(screen.getByLabelText('Jenis kurs (opsional)'), 'MANUAL')
      await userEvent.click(screen.getByRole('button', { name: 'Cari kurs' }))

      expect(await screen.findByText('1 USD = 16.250,50 IDR')).toBeInTheDocument()
      expect(calls.find((c) => c.url === `${AP}/exchange-rates/lookup`)!.params).toEqual({ currency: 'USD', date: '2026-10-08', rate_type: 'MANUAL' })
      expect(screen.getByText('Bank Indonesia', { selector: 'dd' })).toBeInTheDocument()
    })

    it('does not ask without a currency, lets the server choose the type by default and explains a missing rate', async () => {
      const calls = boot(view, {
        ...list,
        'GET /app/accounting/exchange-rates/lookup': { status: 422, data: { message: 'There is no exchange rate', code: 'EXCHANGE_RATE_NOT_FOUND', details: {} } },
      })
      renderApp('/app/akuntansi/kurs')
      await screen.findByText('16.250,50')

      await userEvent.click(screen.getByRole('button', { name: 'Cari kurs' }))
      expect(await screen.findByText(/Pilih mata uang, atau isi kode ISO/)).toBeInTheDocument()
      expect(calls.some((c) => c.url === `${AP}/exchange-rates/lookup`)).toBe(false)

      await userEvent.type(screen.getByLabelText('Mata uang dokumen'), 'eur')
      await userEvent.click(screen.getByRole('button', { name: 'Cari kurs' }))
      expect(await screen.findByText('Belum ada kurs yang berlaku untuk mata uang dan tanggal ini (atau kursnya sudah terlalu lama). Masukkan kurs terlebih dulu.')).toBeInTheDocument()
      expect(calls.find((c) => c.url === `${AP}/exchange-rates/lookup`)!.params).toEqual({ currency: 'EUR', date: '2026-10-08' })
    })
  })
})
