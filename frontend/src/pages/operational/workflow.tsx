import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ConfirmDialog } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Button } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { useAction } from '../../lib/hooks'
import { MODULES, type DocSod } from '../../lib/operational'
import { ReverseDialog } from './shared'

export type WorkflowConfig = {
  /** API path of this document, e.g. `/app/accounting/ap-invoices/<id>`. */
  base: string
  status: string
  sod?: DocSod
  /** "faktur", "pembayaran", "beban", "transaksi" — used in the toasts and dialogs. */
  noun: string
  perms: { update: string; submit?: string; approve?: string; post: string; reverse: string }
  /** OA2 modules this document writes to; every one of them, and the accounting core, must be writable for a button to show. */
  modules: string[]
  /**
   * Modules that must be writable, besides `modules`, to submit, approve or post (not to edit, reject, reopen, cancel or reverse): a customer
   * receipt draws on a cash or bank account only when it moves forward, so a read-only cash and bank module still lets a draft be cancelled.
   */
  stepModules?: string[]
  /** false for documents with no approval step (cash payments and receipts): a DRAFT is posted directly. Default true. */
  approvalFlow?: boolean
  /** Route of the editor; shown as "Ubah" while the document is a draft. */
  editPath?: string
  /** false when the document is reversed through another document (e.g. a payable created by an expense). Default true. */
  reversible?: boolean
  onChanged: () => void
}

type Dialog = null | 'reject' | 'cancel' | 'post' | 'reverse'

/** Every module the user needs writable (plus the accounting core) — a READ_ONLY entitlement keeps the buttons away. */
export function useWritable(modules: string[]): boolean {
  const { moduleMode } = useCapabilities()
  return [MODULES.core, ...modules].every((m) => moduleMode(m) === 'FULL')
}

/**
 * The lifecycle buttons of a document: submit, approve, reject, reopen, cancel, post and reverse, with the confirmation dialogs.
 * Which buttons show comes from the status, the user's permissions, the entitlement and the server's segregation-of-duties flags;
 * the API re-checks every one of them. Returns the buttons for `PageHeader actions` and the explanatory notes for banners.
 */
export function useDocumentActions(config: WorkflowConfig): { buttons: ReactNode[]; notes: string[]; dialogs: ReactNode; error: unknown } {
  const { can } = useCapabilities()
  const writable = useWritable(config.modules)
  const stepWritable = useWritable([...config.modules, ...(config.stepModules ?? [])])
  const toast = useToast()
  const action = useAction()
  const [dialog, setDialog] = useState<Dialog>(null)
  const { status, perms, noun } = config
  const sod = config.sod ?? { approve: false, post: false }
  const approvalFlow = config.approvalFlow !== false
  const allowed = (permission: string | undefined) => writable && permission !== undefined && can(permission)
  /** Like `allowed`, for the steps that also need `stepModules` (submit, approve, post). */
  const allowedStep = (permission: string | undefined) => stepWritable && permission !== undefined && can(permission)

  async function step(path: string, body: object | undefined, done: string) {
    const r = await action.run(async () => (await api.post(`${config.base}/${path}`, body)).data)
    if (r.ok) {
      setDialog(null)
      toast.success(done)
      config.onChanged()
    }
  }

  const buttons: ReactNode[] = []
  const notes: string[] = []
  const postButton = (
    <Button key="post" variant="primary" disabled={!sod.post} onClick={() => setDialog('post')}>Posting</Button>
  )

  if (status === 'DRAFT') {
    if (allowed(perms.update) && config.editPath) buttons.push(<Link key="edit" className="btn" to={config.editPath}>Ubah</Link>)
    if (approvalFlow && sod.approval_required !== false && allowedStep(perms.submit)) {
      buttons.push(<Button key="submit" variant="primary" loading={action.busy} onClick={() => void step('submit', undefined, `${cap(noun)} diajukan.`)}>Ajukan</Button>)
    }
    // Without an approval policy (or for documents that have none) a draft is posted directly.
    if ((!approvalFlow || sod.approval_required === false) && allowedStep(perms.post)) {
      buttons.push(postButton)
      if (!sod.post) notes.push(`Pemisahan tugas: Anda tidak dapat memposting ${noun} yang Anda siapkan sendiri.`)
    }
  }
  if (status === 'SUBMITTED' && allowed(perms.approve)) {
    if (allowedStep(perms.approve)) {
      buttons.push(<Button key="approve" variant="primary" disabled={!sod.approve} loading={action.busy} onClick={() => void step('approve', undefined, `${cap(noun)} disetujui.`)}>Setujui</Button>)
    }
    buttons.push(<Button key="reject" disabled={!sod.approve} onClick={() => setDialog('reject')}>Tolak</Button>)
    if (!sod.approve) notes.push(`Pemisahan tugas: Anda tidak dapat menyetujui ${noun} yang Anda siapkan atau ajukan sendiri.`)
  }
  if (status === 'APPROVED' && allowedStep(perms.post)) {
    buttons.push(postButton)
    if (!sod.post) notes.push(`Pemisahan tugas: Anda tidak dapat memposting ${noun} yang Anda siapkan atau setujui sendiri.`)
  }
  if (status === 'REJECTED' && allowed(perms.update)) {
    buttons.push(<Button key="reopen" loading={action.busy} onClick={() => void step('reopen', undefined, `${cap(noun)} dibuka kembali sebagai draf.`)}>Jadikan draf</Button>)
  }
  const cancellable = approvalFlow ? ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'] : ['DRAFT']
  if (cancellable.includes(status) && allowed(perms.update)) {
    buttons.push(<Button key="cancel" variant="danger" onClick={() => setDialog('cancel')}>Batalkan</Button>)
  }
  if (status === 'POSTED' && config.reversible !== false && allowed(perms.reverse)) {
    buttons.push(<Button key="reverse" onClick={() => setDialog('reverse')}>Balik</Button>)
  }

  const dialogs = (
    <>
      {dialog === 'post' && (
        <ConfirmDialog title={`Posting ${noun}`} confirmLabel="Posting" busy={action.busy} error={action.error} onClose={() => setDialog(null)}
          message={`Setelah diposting, ${noun} mendapat nomor resmi dan jurnalnya tidak dapat diubah. Koreksi hanya dengan pembalikan.`}
          onConfirm={() => void step('post', undefined, `${cap(noun)} diposting.`)} />
      )}
      {dialog === 'reject' && (
        <ConfirmDialog title={`Tolak ${noun}`} confirmLabel="Tolak" danger reasonRequired busy={action.busy} error={action.error} onClose={() => setDialog(null)}
          message={`${cap(noun)} dikembalikan ke penyusun beserta alasan penolakan.`} onConfirm={(reason) => void step('reject', { reason }, `${cap(noun)} ditolak.`)} />
      )}
      {dialog === 'cancel' && (
        <ConfirmDialog title={`Batalkan ${noun}`} confirmLabel={`Batalkan ${noun}`} danger reasonRequired busy={action.busy} error={action.error} onClose={() => setDialog(null)}
          message={`${cap(noun)} yang dibatalkan tidak dapat diposting lagi dan tetap tersimpan sebagai riwayat.`} onConfirm={(reason) => void step('cancel', { reason }, `${cap(noun)} dibatalkan.`)} />
      )}
      {dialog === 'reverse' && (
        <ReverseDialog noun={noun} busy={action.busy} error={action.error} onClose={() => setDialog(null)} onConfirm={(body) => void step('reverse', body, `${cap(noun)} dibalik.`)} />
      )}
    </>
  )

  return { buttons, notes, dialogs, error: dialog === null ? action.error : null }
}

const cap = (text: string) => text.charAt(0).toUpperCase() + text.slice(1)
