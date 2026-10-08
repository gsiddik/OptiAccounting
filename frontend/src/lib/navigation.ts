/** Leaving the SPA for another origin (OptiNexus). A seam so tests can observe it; jsdom cannot navigate. */
export const navigation = {
  go(url: string): void {
    window.location.assign(url)
  },
}
