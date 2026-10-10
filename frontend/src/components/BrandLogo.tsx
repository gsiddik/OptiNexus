import { Link } from 'react-router-dom';

/**
 * The OptiNexus logo in its own container. The image fills the container (`object-fit: contain`), so it follows the container's
 * size; `srcSet` hands the browser the smallest raster that is still sharp for that size and pixel ratio. The widths are the
 * real pixel widths of the files in `public/brand/`.
 */
const SRC_SET = [320, 640, 960, 1207].map((w) => `/brand/optinexus-logo-${w}.png ${w}w`).join(', ');

export function BrandLogo({ to, variant = 'sidebar' }: { to?: string; variant?: 'sidebar' | 'login' }) {
  const image = (
    <img
      src="/brand/optinexus-logo-640.png"
      srcSet={SRC_SET}
      sizes={variant === 'login' ? '160px' : '(max-width: 900px) 120px, 130px'}
      width={1207}
      height={1045}
      alt={to ? '' : 'OptiNexus'}
      decoding="async"
    />
  );
  const className = `brand-logo brand-logo-${variant}`;
  return to ? (
    <Link to={to} className={className} aria-label="OptiNexus, go to the dashboard">
      {image}
    </Link>
  ) : (
    <div className={className}>{image}</div>
  );
}
