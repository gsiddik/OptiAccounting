// A small set of 24px stroke icons (no icon library needed for the OA0 screens).
const PATHS: Record<string, string> = {
  home: 'M3 11.5 12 4l9 7.5M5 10v10h5v-6h4v6h5V10',
  building: 'M4 21V5l8-2v18M12 8h8v13M8 9h.01M8 13h.01M8 17h.01M16 12h.01M16 16h.01M3 21h18',
  users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
  shield: 'M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Zm-3 9 2.2 2.2L15.5 10',
  grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  box: 'm21 8-9-5-9 5 9 5 9-5ZM3 8v8l9 5 9-5V8M12 13v8',
  tag: 'M3 12V4h8l10 10-8 8L3 12Zm5-5h.01',
  card: 'M3 6h18v12H3zM3 10h18M7 15h3',
  chart: 'M4 20V4M4 20h16M8 16v-5M12 16V8M16 16v-3',
  log: 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7',
  key: 'm15 8 6-6m-4 2 3 3M11.5 12.5a4.5 4.5 0 1 1-6.4 0 4.5 4.5 0 0 1 6.4 0Zm0 0L15 9',
  menu: 'M4 7h16M4 12h16M4 17h16',
  close: 'M6 6l12 12M18 6 6 18',
  logout: 'M9 21H5V3h4M16 17l5-5-5-5M21 12H9',
  plus: 'M12 5v14M5 12h14',
  book: 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5Zm0 14a2 2 0 0 1 2-2h13M9 7h6',
  scale: 'M12 3v18M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0L5 7Zm14 0-3 7a3.5 3.5 0 0 0 6 0l-3-7ZM8 21h8',
  calendar: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
  list: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
  cog: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.3l2-1.6-2-3.4-2.4 1a7.5 7.5 0 0 0-2.2-1.3L14.3 3h-4l-.4 2.4a7.5 7.5 0 0 0-2.2 1.3l-2.4-1-2 3.4 2 1.6a7.400 7.400 0 0 0 0 2.600l-2 1.600 2 3.400 2.400-1a7.500 7.500 0 0 0 2.200 1.300l.4 2.400h4l.4-2.400a7.500 7.500 0 0 0 2.200-1.300l2.400 1 2-3.400-2-1.600c.1-.4.1-.9.1-1.300Z',
  plug: 'M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0V8ZM12 18v4',
}

export function Icon({ name, size = 18 }: { name: keyof typeof PATHS | string; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={PATHS[name] ?? PATHS.grid} />
    </svg>
  )
}
