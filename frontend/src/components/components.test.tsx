import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ConfirmDialog, Modal } from './Modal'
import { DataTable } from './DataTable'
import { ScopeEditor } from './ScopeEditor'
import { Meter } from './ui'

describe('Modal', () => {
  it('is a labelled dialog that closes on Escape', async () => {
    const onClose = vi.fn()
    render(<Modal title="Ubah cabang" onClose={onClose}><input aria-label="Nama" /></Modal>)
    expect(screen.getByRole('dialog', { name: 'Ubah cabang' })).toBeInTheDocument()
    await userEvent.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('puts focus on the first field when it opens', () => {
    render(<Modal title="Form" onClose={() => undefined}><input aria-label="Nama" /></Modal>)
    expect(screen.getByLabelText('Nama')).toHaveFocus()
  })
})

describe('ConfirmDialog', () => {
  it('will not confirm without the reason the audit trail needs', async () => {
    const onConfirm = vi.fn()
    render(<ConfirmDialog title="Tangguhkan" message="Yakin?" confirmLabel="Tangguhkan" reasonRequired onConfirm={onConfirm} onClose={() => undefined} />)

    await userEvent.click(screen.getByRole('button', { name: 'Tangguhkan' }))
    expect(onConfirm).not.toHaveBeenCalled()

    await userEvent.type(screen.getByLabelText(/Alasan/), 'Menunggak 3 bulan')
    await userEvent.click(screen.getByRole('button', { name: 'Tangguhkan' }))
    expect(onConfirm).toHaveBeenCalledWith('Menunggak 3 bulan')
  })

  it('confirms straight away when no reason is needed', async () => {
    const onConfirm = vi.fn()
    render(<ConfirmDialog title="Aktifkan" message="Yakin?" confirmLabel="Aktifkan" onConfirm={onConfirm} onClose={() => undefined} />)
    await userEvent.click(screen.getByRole('button', { name: 'Aktifkan' }))
    expect(onConfirm).toHaveBeenCalledOnce()
  })
})

describe('DataTable', () => {
  it('labels every cell so the phone layout can show the column name', () => {
    render(<DataTable caption="Cabang" rows={[{ id: '1', code: 'JKT', name: 'Jakarta' }]} rowKey={(r) => r.id} columns={[
      { header: 'Kode', cell: (r) => r.code },
      { header: 'Nama', cell: (r) => r.name },
      { header: 'Aksi', actions: true, cell: () => <button>Ubah</button> },
    ]} />)
    expect(screen.getByRole('table', { name: 'Cabang' })).toBeInTheDocument()
    expect(screen.getByText('JKT')).toHaveAttribute('data-label', 'Kode')
    expect(screen.getByText('Jakarta')).toHaveAttribute('data-label', 'Nama')
    expect(screen.getByRole('button', { name: 'Ubah' }).closest('td')).not.toHaveAttribute('data-label')
  })
})

describe('Meter', () => {
  it('exposes the usage to assistive technology and handles unlimited capacity', () => {
    const { rerender } = render(<Meter used={4} limit={5} />)
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '4')
    rerender(<Meter used={4} limit={null} />)
    expect(screen.getByText('Tanpa batas')).toBeInTheDocument()
  })
})

describe('ScopeEditor', () => {
  it('adds a row and asks for a branch only for branch scopes', async () => {
    const onChange = vi.fn()
    const branches = [{ id: 'b-1', code: 'JKT', name: 'Jakarta', status: 'ACTIVE' as const }]
    const { rerender } = render(<ScopeEditor rows={[]} onChange={onChange} branches={branches} units={[]} />)
    expect(screen.getByText(/tidak dapat mengakses data apa pun/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Tambah cakupan' }))
    expect(onChange).toHaveBeenCalledWith([{ scope_type: 'TENANT', branch_id: '', business_unit_id: '' }])

    rerender(<ScopeEditor rows={[{ scope_type: 'BRANCH', branch_id: '', business_unit_id: '' }]} onChange={onChange} branches={branches} units={[]} />)
    expect(screen.getByLabelText('Cabang')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'JKT · Jakarta' })).toBeInTheDocument()
  })
})
