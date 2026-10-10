import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'
import { setToken, setUnauthorizedHandler } from '../lib/api'

afterEach(() => {
  cleanup()
  window.localStorage.clear()
  setToken(null)
  setUnauthorizedHandler(null)
})
