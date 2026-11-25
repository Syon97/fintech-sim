export function Problem({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="problem" role="alert">
      <p>{message}</p>
      {onRetry && <button onClick={onRetry}>Try again</button>}
    </div>
  );
}

/** A skeleton in the shape of the list that is coming (rows), not a spinner. */
export function SkeletonRows({ n = 5, label = 'Loading…' }: { n?: number; label?: string }) {
  return (
    <div role="status">
      <span className="visually-hidden">{label}</span>
      <div className="skeleton" aria-hidden="true">{Array.from({ length: n }, (_, i) => <div key={i} />)}</div>
    </div>
  );
}
