export function LoadingSpinner({ label = 'Loading...' }: { label?: string }) {
  return (
    <div className="loading-spinner">
      <div className="spinner" />
      <span>{label}</span>
    </div>
  );
}
