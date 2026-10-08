import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { describeError } from '../lib/labels'
import { Banner, Button, Field } from './ui'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** Accessible dialog: labelled, closes on Escape, keeps focus inside and returns it when closed. */
export function Modal({ title, onClose, children, footer, wide }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const titleId = useId()
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const first = ref.current?.querySelector<HTMLElement>('input, select, textarea') ?? ref.current?.querySelector<HTMLElement>(FOCUSABLE)
    first?.focus()
    return () => previous?.focus()
  }, [])

  function onKeyDown(event: React.KeyboardEvent) {
    if (event.key === 'Escape') {
      event.stopPropagation()
      onClose()
      return
    }
    if (event.key !== 'Tab' || !ref.current) return
    const items = [...ref.current.querySelectorAll<HTMLElement>(FOCUSABLE)]
    if (items.length === 0) return
    const [first, last] = [items[0], items[items.length - 1]]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  return (
    <div className="modal-scrim" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div ref={ref} className={`modal${wide ? ' wide' : ''}`} role="dialog" aria-modal="true" aria-labelledby={titleId} onKeyDown={onKeyDown}>
        <div className="modal-head">
          <h2 id={titleId}>{title}</h2>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Tutup">
            ✕
          </Button>
        </div>
        {children}
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  )
}

/** A dialog that is also a form: Enter submits, errors from the last attempt are shown inside. */
export function FormModal({
  title,
  submitLabel = 'Simpan',
  busy,
  error,
  onSubmit,
  onClose,
  children,
  wide,
  danger,
}: {
  title: string
  submitLabel?: string
  busy?: boolean
  error?: unknown
  onSubmit: () => void
  onClose: () => void
  children: ReactNode
  wide?: boolean
  danger?: boolean
}) {
  function submit(event: FormEvent) {
    event.preventDefault()
    onSubmit()
  }

  return (
    <Modal title={title} onClose={onClose} wide={wide}>
      <form onSubmit={submit} noValidate>
        <div className="modal-body">
          {error != null && <Banner tone="bad">{describeError(error)}</Banner>}
          {children}
        </div>
        <div className="modal-foot">
          <Button onClick={onClose}>Batal</Button>
          <Button type="submit" variant={danger ? 'danger' : 'primary'} loading={busy}>
            {submitLabel}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** Confirm a state change; `reasonRequired` collects the reason that the API records in the audit trail. */
export function ConfirmDialog({
  title,
  message,
  confirmLabel,
  danger,
  reasonRequired,
  busy,
  error,
  onConfirm,
  onClose,
}: {
  title: string
  message: ReactNode
  confirmLabel: string
  danger?: boolean
  reasonRequired?: boolean
  busy?: boolean
  error?: unknown
  onConfirm: (reason: string) => void
  onClose: () => void
}) {
  const [reason, setReason] = useState('')
  const tooShort = reasonRequired && reason.trim().length < 3

  return (
    <FormModal title={title} submitLabel={confirmLabel} busy={busy} error={error} danger={danger} onClose={onClose} onSubmit={() => !tooShort && onConfirm(reason.trim())}>
      <div>{message}</div>
      {reasonRequired && (
        <Field label="Alasan (dicatat di audit)" hint="Minimal 3 karakter.">
          {(p) => <textarea className="textarea" value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
        </Field>
      )}
    </FormModal>
  )
}
