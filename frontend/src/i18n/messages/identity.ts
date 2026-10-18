import { defineMessages } from '../core'

/** Machine identities in the member list (service principals, audit A23). */
export const identity = defineMessages({
  'identity.service.badge': { id: 'Identitas layanan', en: 'Service identity' },
  'identity.service.revoked': { id: 'Identitas layanan (dicabut)', en: 'Service identity (revoked)' },
  'identity.service.hint': {
    id: 'Akun mesin untuk integrasi. Tidak memakai kursi pengguna, tidak bisa masuk, dan tidak memegang peran. Dikelola oleh operator platform.',
    en: 'A machine account for an integration. It takes no user seat, cannot sign in and holds no role. Managed by the platform operator.',
  },
})
