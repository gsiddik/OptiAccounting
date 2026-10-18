import { defineMessages } from '../core'

/**
 * Navigation labels of the shell (sidebar, breadcrumbs, back button) that exist in both languages. The key is `nav.` plus the Indonesian label
 * the shell is written with, so a screen that moves over adds its labels here and the shell needs no change; a label with no entry is shown as written.
 */
export const nav = defineMessages({
  'nav.Beranda': { id: 'Beranda', en: 'Home' },
  'nav.Detail': { id: 'Detail', en: 'Details' },
  'nav.Kembali': { id: 'Kembali', en: 'Back' },
  'nav.Kembali ke halaman sebelumnya': { id: 'Kembali ke halaman sebelumnya', en: 'Back to the previous page' },
  'nav.Bentangkan semua': { id: 'Bentangkan semua', en: 'Expand all' },
  'nav.Ciutkan semua': { id: 'Ciutkan semua', en: 'Collapse all' },
  'nav.Integrasi': { id: 'Integrasi', en: 'Integration' },
  'nav.Koneksi': { id: 'Koneksi', en: 'Connections' },
  'nav.Kotak masuk kejadian': { id: 'Kotak masuk kejadian', en: 'Event inbox' },
})
