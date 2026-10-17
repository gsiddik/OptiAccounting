import { common } from './messages/common'
import { delegation } from './messages/delegation'
import { errors } from './messages/errors'
import { session } from './messages/session'
import { states } from './messages/states'

/** Every message of the console that is available in both languages. A module is added here when its screens move over. */
export const CATALOG = { ...common, ...errors, ...states, ...session, ...delegation }

export type MessageKey = keyof typeof CATALOG
