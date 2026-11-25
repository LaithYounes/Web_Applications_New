import { ArrowLeft, ArrowRight, CircleAlert, ImageOff, LoaderCircle, PackageOpen } from 'lucide-react';
import { cloneElement, useEffect, useId, useRef, useState, type ReactElement, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ApiError, config, errorText, imageUrl, type Order, type Page, type StatusChange } from './api';

export function useTitle(title: string) {
    useEffect(() => {
        document.title = `${title} | ${config.brand}`;
    }, [title]);
}

export function PageHead({ title, subtitle, action }: { title: string; subtitle?: string; action?: ReactNode }) {
    useTitle(title);
    return <div className="page-head">
        <div><h1>{title}</h1>{subtitle && <p>{subtitle}</p>}</div>
        {action}
    </div>;
}

export function Loading({ text = 'Loading…' }: { text?: string }) {
    return <div className="state" role="status"><LoaderCircle className="spin" size={23} /> {text}</div>;
}

export function Problem({ error, retry }: { error: unknown; retry?: () => void }) {
    return <div className="state" role="alert">
        <CircleAlert size={25} /><p>{errorText(error)}</p>
        {retry && <button className="button secondary" onClick={retry}>Try again</button>}
    </div>;
}

export function Empty({ title, text, action, to }: { title: string; text?: string; action?: string; to?: string }) {
    return <div className="state empty">
        <span className="state-icon"><PackageOpen size={25} /></span>
        <h2>{title}</h2>{text && <p>{text}</p>}
        {action && to && <Link className="button primary" to={to}>{action}</Link>}
    </div>;
}

export function Message({ children, tone = 'error' }: { children: ReactNode; tone?: 'error' | 'success' | 'info' }) {
    return <p className={`message ${tone}`} role={tone === 'error' ? 'alert' : 'status'}>{children}</p>;
}

export function Field({ label, name, error, children, hint }: {
    label: string; name: string; error?: unknown; children: ReactElement<{ 'aria-invalid'?: boolean; 'aria-describedby'?: string }>; hint?: string;
}) {
    const messages = error instanceof ApiError ? error.fields[name] : undefined;
    const hasErrors = !!messages?.length;
    const description = [hint ? `${name}-hint` : '', hasErrors ? `${name}-errors` : ''].filter(Boolean).join(' ');
    return <div className="field">
        <label htmlFor={name}>{label}</label>
        {cloneElement(children, { 'aria-invalid': hasErrors || undefined, 'aria-describedby': description || children.props['aria-describedby'] })}
        {hint && <small id={`${name}-hint`}>{hint}</small>}
        {hasErrors && <span id={`${name}-errors`}>{messages.map((message) => <small className="field-error" key={message}>{message}</small>)}</span>}
    </div>;
}

export function FormError({ error, fields = [] }: { error?: unknown; fields?: string[] }) {
    if (!error) return null;
    const other = error instanceof ApiError ? Object.entries(error.fields)
        .filter(([key]) => !fields.includes(key)).flatMap(([, messages]) => messages) : [];
    return <Message>{other.length ? other.join(' ') : error instanceof ApiError ? error.message : errorText(error)}</Message>;
}

export function ProductImage({ image, eager = false, alt = '' }: {
    image?: string | null; eager?: boolean; alt?: string;
}) {
    const src = imageUrl(image);
    const [failedSource, setFailedSource] = useState<string>();
    return src && src !== failedSource
        ? <img src={src} alt={alt} loading={eager ? 'eager' : 'lazy'} decoding="async" onError={() => setFailedSource(src)} />
        : <span className="image-placeholder" role={alt ? 'img' : undefined}
            aria-label={alt ? 'Product image unavailable' : undefined} aria-hidden={alt ? undefined : true}><ImageOff size={29} /></span>;
}

export function positiveInt(value: string | null) {
    return value && /^\d+$/.test(value) && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? value : undefined;
}

export function usePage() {
    const [params, setParams] = useSearchParams();
    const page = Math.min(Number(positiveInt(params.get('page')) ?? 1), 1_000_000);
    const update = (changes: Record<string, string | undefined>) => {
        const next = new URLSearchParams(params);
        for (const [key, value] of Object.entries(changes)) {
            if (value) next.set(key, value);
            else next.delete(key);
        }
        setParams(next);
    };
    return { params, page, setPage: (value: number) => update({ page: value === 1 ? undefined : String(value) }), update };
}

export function Pagination({ meta, onPage }: { meta: Page<unknown>['meta']; onPage: (page: number) => void }) {
    if (meta.last_page <= 1 && meta.current_page <= 1) return null;
    return <nav className="pagination" aria-label="Pagination">
        <span>Page {meta.current_page.toLocaleString('en-US')} of {meta.last_page.toLocaleString('en-US')}</span>
        <div>
            <button className="button secondary small" onClick={() => onPage(Math.min(meta.last_page, meta.current_page - 1))}
                disabled={meta.current_page <= 1}><ArrowLeft size={16} /> Previous</button>
            <button className="button secondary small" onClick={() => onPage(meta.current_page + 1)}
                disabled={meta.current_page >= meta.last_page}>Next <ArrowRight size={16} /></button>
        </div>
    </nav>;
}

const statuses: Record<StatusChange['to_status'], string> = {
    pending: 'Pending', confirmed: 'Confirmed', processing: 'Processing', shipped: 'Shipped', delivered: 'Delivered',
    cancelled: 'Cancelled', cancelling: 'Cancellation requested',
};
export const statusLabel = (status: StatusChange['to_status']) => statuses[status];

export function Badge({ order }: { order: Order }) {
    return <span className={`badge ${order.cancellation_pending ? 'cancelling' : order.status}`}>
        {order.cancellation_pending ? 'Cancellation in progress' : statusLabel(order.status)}
    </span>;
}

export function Confirm({ title, text, action, cancel, confirm }: {
    title: string; text: string; action: string; cancel: () => void; confirm: () => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const id = useId();
    useEffect(() => {
        const element = dialog.current;
        element?.showModal();
        return () => element?.close();
    }, []);
    return <dialog ref={dialog} className="confirm" aria-labelledby={id} aria-describedby={`${id}-text`}
        onCancel={(event) => { event.preventDefault(); cancel(); }}>
        <h2 id={id}>{title}</h2><p id={`${id}-text`}>{text}</p>
        <div className="actions">
            <button className="button secondary" autoFocus onClick={cancel}>Keep it</button>
            <button className="button danger" onClick={confirm}>{action}</button>
        </div>
    </dialog>;
}

export function Quantity({ id, value, onChange, max = 1000, disabled = false, label = 'Quantity' }: {
    id: string; value: string; onChange: (value: string) => void; max?: number; disabled?: boolean; label?: string;
}) {
    return <div className="quantity">
        <label className="sr-only" htmlFor={id}>{label}</label>
        <button type="button" aria-label={`Decrease ${label.toLowerCase()}`} disabled={disabled || Number(value) <= 1}
            onClick={() => onChange(String(Math.max(1, Number(value) - 1)))}>−</button>
        <input id={id} type="number" inputMode="numeric" min={1} max={max} step={1} required value={value}
            disabled={disabled} onChange={(event) => onChange(event.target.value)} />
        <button type="button" aria-label={`Increase ${label.toLowerCase()}`} disabled={disabled || Number(value) >= max}
            onClick={() => onChange(String(Math.min(max, Number(value) + 1)))}>+</button>
    </div>;
}

export function validQuantity(value: string, max = 1000) {
    const n = Number(value);
    return Number.isInteger(n) && n >= 1 && n <= max;
}
