import { Link } from 'react-router-dom'

/**
 * The OptiEntry logo in its own container. The image fills the container (`object-fit: contain`), so it follows the
 * container's size; `srcSet` hands the browser the smallest raster that is still sharp for that size and pixel ratio.
 * The widths are the real pixel widths of the files in `public/brand/`.
 */
const SRC_SET = [400, 800, 1200, 1878].map((w) => `/brand/optientry-logo-${w}.png ${w}w`).join(', ')

export function BrandLogo({ to, size = 'sidebar' }: { to?: string; size?: 'sidebar' | 'auth' }) {
  const image = <img src="/brand/optientry-logo-800.png" srcSet={SRC_SET} sizes={size === 'auth' ? '270px' : '(max-width: 900px) 250px, 200px'} width={1878} height={437} alt={to ? '' : 'OptiEntry'} decoding="async" />
  const className = `brand-logo brand-logo-${size}`
  return to ? (
    <Link to={to} className={className} aria-label="OptiEntry, ke beranda">
      {image}
    </Link>
  ) : (
    <div className={className}>{image}</div>
  )
}
