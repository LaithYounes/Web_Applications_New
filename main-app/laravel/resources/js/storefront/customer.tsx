import { ArrowRight, ArrowUpRight, Bell, LogOut, Package, ShoppingBag, Trash2, UserRound } from 'lucide-react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError, api, config, date, errorText, money, type Cart, type CartItem } from './api';
import { privateKeys, safeReturn, useCart, useSession } from './state';
import { Empty, Field, FormError, Loading, Message, PageHead, Pagination, Problem, ProductImage, Quantity, usePage, useTitle, validQuantity } from './ui';

export function AuthPage({ register = false }: { register?: boolean }) {
    const title = register ? 'Create your account' : 'Sign in';
    useTitle(title);
    const [params] = useSearchParams();
    const { user, loading, setUser } = useSession();
    const navigate = useNavigate();
    const [error, setError] = useState<unknown>();
    const [busy, setBusy] = useState(false);
    const returnTo = params.get('returnTo');
    const alternative = `${register ? '/login' : '/register'}${returnTo ? `?returnTo=${encodeURIComponent(returnTo)}` : ''}`;
    if (user) return <Navigate replace to={safeReturn(returnTo, user.role)} />;

    return <div className="auth-wrap">
        <div className="auth-card panel">
            <div className="state-icon"><UserRound size={22} /></div>
            <PageHead title={title} subtitle={register ? `Start shopping with ${config.brand}.` : 'Access your account, orders, and cart.'} />
            <form className="form" onSubmit={async (event) => {
                event.preventDefault();
                if (busy) return;
                const input = new FormData(event.currentTarget);
                const email = String(input.get('email'));
                const password = String(input.get('password'));
                setBusy(true);
                setError(undefined);
                try {
                    const result = register ? await api.register({ email, password,
                        name: String(input.get('name')), password_confirmation: String(input.get('password_confirmation')) })
                        : await api.login({ email, password });
                    setUser(result.user);
                    navigate(safeReturn(returnTo, result.user.role), { replace: true });
                } catch (failure) {
                    setError(failure);
                } finally {
                    setBusy(false);
                }
            }}>
                {register && <Field label="Full name" name="name" error={error}>
                    <input id="name" name="name" autoComplete="name" required maxLength={255} />
                </Field>}
                <Field label="Email address" name="email" error={error}>
                    <input id="email" name="email" type="email" autoComplete="email" required maxLength={255} />
                </Field>
                <Field label="Password" name="password" error={error} hint={register ? 'Use at least 8 characters.' : undefined}>
                    <input id="password" name="password" type="password" autoComplete={register ? 'new-password' : 'current-password'}
                        aria-describedby={register ? 'password-hint' : undefined} required minLength={register ? 8 : undefined} />
                </Field>
                {register && <Field label="Confirm password" name="password_confirmation" error={error}>
                    <input id="password_confirmation" name="password_confirmation" type="password" autoComplete="new-password" required />
                </Field>}
                <FormError error={error} fields={['name', 'email', 'password', 'password_confirmation']} />
                <button className="button primary" disabled={busy || loading}>{busy ? 'Submitting…' : title} <ArrowRight size={17} /></button>
            </form>
            <p className="auth-switch">{register ? 'Already have an account?' : 'New here?'}{' '}
                <Link className="text-link" to={alternative}>{register ? 'Sign in' : 'Create an account'}</Link></p>
        </div>
    </div>;
}

export function Account() {
    const { user, setUser } = useSession();
    const navigate = useNavigate();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    if (!user) return null;

    return <>
        <PageHead title="My account" />
        <div className="account-grid">
            <section className="panel account-profile" aria-labelledby="account-profile-title">
                <span className="state-icon"><UserRound size={25} /></span>
                <h2 id="account-profile-title">{user.name}</h2><p>{user.email}</p>
                <button className="button secondary" disabled={busy} onClick={async () => {
                    setBusy(true);
                    setError('');
                    try {
                        await api.logout();
                        setUser(null);
                        navigate('/', { replace: true });
                    } catch (failure) {
                        if (failure instanceof ApiError && failure.status === 401) {
                            setUser(null);
                            navigate('/', { replace: true });
                        } else {
                            setError(errorText(failure));
                        }
                    } finally {
                        setBusy(false);
                    }
                }}><LogOut size={17} /> {busy ? 'Signing out…' : 'Sign out'}</button>
                {error && <Message>{error}</Message>}
            </section>
            <section className="panel account-links" aria-label="Account shortcuts">
                {user.role === 'admin' ? <Link to="/admin"><Package size={21} /> Administration <ArrowUpRight size={16} /></Link> : <>
                    <Link to="/orders"><Package size={21} /> My orders <ArrowUpRight size={16} /></Link>
                    <Link to="/notifications"><Bell size={21} /> Notifications <ArrowUpRight size={16} /></Link>
                </>}
            </section>
        </div>
    </>;
}

function CartRow({ item, locked, update, remove }: {
    item: CartItem; locked: boolean; update?: (quantity: number) => void; remove?: () => void;
}) {
    const [quantity, setQuantity] = useState(String(item.quantity));
    return <li className="cart-row">
        <Link className="cart-image" to={`/products/${item.product.id}`} aria-label={`View ${item.product.name}`}><ProductImage image={item.product.image} /></Link>
        <div className="cart-name">
            <Link to={`/products/${item.product.id}`}>{item.product.name}</Link>
            <span>{money(item.product.price)} each{!update && ` · Quantity ${item.quantity}`}</span>
        </div>
        {update && <form className="cart-quantity" onSubmit={(event) => {
            event.preventDefault();
            if (!locked && validQuantity(quantity)) update(Number(quantity));
        }}>
            <Quantity id={`quantity-${item.id}`} value={quantity} label={`Quantity for ${item.product.name}`} onChange={setQuantity} disabled={locked} />
            <button className="button secondary small" disabled={locked || !validQuantity(quantity) || Number(quantity) === item.quantity}>Update</button>
        </form>}
        <strong className="cart-subtotal">{money(item.subtotal)}</strong>
        {remove && <button className="icon-button" type="button" disabled={locked} aria-label={`Remove ${item.product.name}`} onClick={remove}><Trash2 size={17} /></button>}
    </li>;
}

function CartSummary({ cart, children }: { cart: Cart; children: React.ReactNode }) {
    return <aside className="panel summary">
        <h2>Order summary</h2>
        <div><span>Items</span><span>{cart.items.reduce((count, item) => count + item.quantity, 0)}</span></div>
        <div className="summary-total"><span>Total</span><strong>{money(cart.total_amount)}</strong></div>
        {children}
    </aside>;
}

export function CartPage() {
    const result = useCart();
    const { user } = useSession();
    const client = useQueryClient();
    const [busy, setBusy] = useState(false);
    const [feedback, setFeedback] = useState<{ text: string; error?: boolean }>();
    const cart = result.data;

    async function change(operation: () => Promise<Cart>, text: string) {
        if (busy || !user) return;
        setBusy(true);
        setFeedback(undefined);
        try {
            await client.cancelQueries({ queryKey: privateKeys.cart(user) });
            client.setQueryData(privateKeys.cart(user), await operation());
            setFeedback({ text });
        } catch (error) {
            setFeedback({ text: errorText(error), error: true });
            await result.refetch();
        } finally {
            setBusy(false);
        }
    }

    return <>
        <PageHead title="Your cart" action={<Link className="text-link" to="/shop">Continue shopping <ArrowRight size={16} /></Link>} />
        {result.isPending && <Loading text="Loading your cart…" />}
        {result.isError && <Problem error={result.error} retry={() => void result.refetch()} />}
        {cart?.checkout_pending && <Message tone="info">Checkout is awaiting inventory confirmation. <Link to="/orders">Check orders</Link> <Link to="/checkout">Retry checkout</Link></Message>}
        {feedback && <Message tone={feedback.error ? 'error' : 'success'}>{feedback.text}</Message>}
        {cart && (cart.items.length || cart.checkout_pending ? <div className="cart-layout">
            <ul className="panel cart-list">
                {cart.items.map((item) => <CartRow key={`${item.id}:${item.quantity}`} item={item} locked={busy || cart.checkout_pending || result.isError}
                    update={(quantity) => void change(() => api.updateItem(item.id, quantity), 'Cart updated.')}
                    remove={() => void change(() => api.removeItem(item.id), 'Item removed.')} />)}
                {!cart.items.length && <li className="cart-empty-line">No items to display.</li>}
            </ul>
            <CartSummary cart={cart}>
                <Link className="button primary" to="/checkout">{cart.checkout_pending ? 'Review checkout' : 'Go to checkout'} <ArrowRight size={17} /></Link>
            </CartSummary>
        </div> : <Empty title="Your cart is empty" text="Find something you’ll use every day." action="Shop products" to="/shop" />)}
    </>;
}

type ReviewState = 'normal' | 'checking' | 'pending' | 'uncertain' | 'conflict' | 'blocked';

export function Checkout() {
    const result = useCart();
    const { user } = useSession();
    const client = useQueryClient();
    const navigate = useNavigate();
    const placing = useRef(false);
    const [busy, setBusy] = useState(false);
    const [review, setReview] = useState<ReviewState>('normal');
    const [sawPending, setSawPending] = useState(false);
    const [feedback, setFeedback] = useState('');
    const cart = result.data;
    const pending = cart?.checkout_pending ?? false;
    const unclear = review === 'uncertain' || (sawPending && !pending && cart?.items.length === 0);

    useEffect(() => {
        if (pending) setSawPending(true);
    }, [pending]);

    async function reconcile(conflict: boolean): Promise<ReviewState> {
        if (!user) return 'blocked';
        setReview('checking');
        const [cartResult, orders] = await Promise.allSettled([result.refetch(), api.orders(1)]);
        if (orders.status === 'fulfilled') client.setQueryData([...privateKeys.orders(user), 1], orders.value);
        if (cartResult.status === 'rejected' || cartResult.value.isError || orders.status === 'rejected') {
            setReview('blocked');
            return 'blocked';
        }
        const current = cartResult.value.data;
        const state = current?.checkout_pending ? 'pending' : !current?.items.length ? 'uncertain' : conflict ? 'conflict' : 'normal';
        setReview(state);
        return state;
    }

    async function place() {
        if (!user || !cart || placing.current || unclear || review === 'blocked' || (!pending && !cart.items.length)) return;
        placing.current = true;
        setBusy(true);
        setFeedback('');
        try {
            if (pending && await reconcile(false) !== 'pending') return;
            const order = await api.placeOrder();
            if (!order?.id) throw new ApiError(502);
            client.setQueryData(privateKeys.order(user, order.id), order);
            void client.invalidateQueries({ queryKey: privateKeys.account(user) });
            void Promise.allSettled([
                client.fetchQuery({ queryKey: privateKeys.cart(user), queryFn: ({ signal }) => api.cart(signal) }),
                client.fetchQuery({ queryKey: [...privateKeys.orders(user), 1], queryFn: ({ signal }) => api.orders(1, signal) }),
                client.fetchQuery({ queryKey: privateKeys.unread(user), queryFn: ({ signal }) => api.unreadCount(signal) }),
                client.fetchQuery({ queryKey: [...privateKeys.notices(user), 1, false], queryFn: ({ signal }) => api.notices(1, false, signal) }),
            ]);
            navigate(`/orders/${order.id}`, { replace: true, state: { placed: true } });
        } catch (error) {
            const status = error instanceof ApiError ? error.status : 0;
            setFeedback(errorText(error));
            if (status === 0 || status === 409 || status >= 500 || (pending && status === 422)) await reconcile(status === 409);
        } finally {
            setBusy(false);
            placing.current = false;
        }
    }

    return <>
        <PageHead title="Checkout" subtitle="Review your items before placing the order." action={<Link className="text-link" to="/cart">Back to cart</Link>} />
        {result.isPending && <Loading text="Loading your order review…" />}
        {result.isError && <Problem error={result.error} retry={() => void result.refetch()} />}
        {pending && <Message tone="info">Checkout is awaiting inventory confirmation. <Link to="/orders">Check orders</Link></Message>}
        {review === 'checking' && <Loading text="Checking your cart and recent orders…" />}
        {review === 'blocked' && <Message tone="info">The latest checkout status is unavailable. Check your orders before trying again. <Link to="/orders">Check orders</Link></Message>}
        {unclear && <Message tone="info">Your cart has cleared and the order result is uncertain. Check My Orders for the latest result. <Link to="/orders">Check orders</Link></Message>}
        {review === 'conflict' && <Message>The available inventory may have changed. Review your cart before placing the order again.</Message>}
        {feedback && !pending && !unclear && review !== 'conflict' && <Message>{feedback}</Message>}
        {cart && (cart.items.length || pending || review === 'blocked' ? <div className="cart-layout">
            <ul className="panel cart-list">
                {cart.items.map((item) => <CartRow key={item.id} item={item} locked />)}
                {!cart.items.length && <li className="cart-empty-line">No items to display.</li>}
            </ul>
            <CartSummary cart={cart}>
                {review === 'blocked'
                    ? <button className="button secondary" onClick={() => void reconcile(false)}>Check checkout status</button>
                    : <button className="button primary" onClick={() => void place()} disabled={busy || unclear || result.isError || review === 'checking'}>
                        <ShoppingBag size={17} /> {busy ? 'Checking order…' : pending ? 'Retry checkout' : 'Place order'}
                    </button>}
            </CartSummary>
        </div> : !unclear && !busy && <Empty title="Your cart is empty" action="Shop products" to="/shop" />)}
    </>;
}

export function Notifications() {
    const { user } = useSession();
    const client = useQueryClient();
    const { params, page, setPage, update } = usePage();
    const unread = params.get('unread') === '1';
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const result = useQuery({
        queryKey: user ? [...privateKeys.notices(user), page, unread] : ['disabled-notices'],
        queryFn: ({ signal }) => api.notices(page, unread, signal),
        enabled: !!user,
        refetchInterval: 45_000,
    });

    async function mark(id?: string) {
        if (!user || busy) return;
        setBusy(id ?? 'all');
        setError('');
        try {
            if (id) await api.readNotice(id);
            else await api.readAll();
            await Promise.all([
                client.invalidateQueries({ queryKey: privateKeys.notices(user) }),
                client.invalidateQueries({ queryKey: privateKeys.unread(user) }),
            ]);
        } catch (failure) {
            setError(errorText(failure));
        } finally {
            setBusy('');
        }
    }

    return <>
        <PageHead title="Notifications" subtitle="Updates about your orders."
            action={<button className="button secondary" disabled={!!busy || !result.data} onClick={() => void mark()}>
                {busy === 'all' ? 'Marking as read…' : 'Mark all as read'}</button>} />
        <div className="toolbar">
            <div className="segmented" aria-label="Notification filter">
                <button aria-pressed={!unread} onClick={() => update({ unread: undefined, page: undefined })}>All</button>
                <button aria-pressed={unread} onClick={() => update({ unread: '1', page: undefined })}>Unread</button>
            </div>
        </div>
        {error && <Message>{error}</Message>}
        {result.isPending && <Loading text="Loading notifications…" />}
        {result.isError && <Problem error={result.error} retry={() => void result.refetch()} />}
        {result.data && <>
            {result.data.data.length ? <ul className="panel inbox">
                {result.data.data.map((notice) => <li className="notice" key={notice.id}>
                    <span className={`notice-icon ${notice.read_at ? '' : 'unread'}`}><Bell size={18} aria-hidden="true" /></span>
                    <div><div className="notice-title"><h2>{notice.title}</h2>{!notice.read_at && <span>Unread</span>}</div>
                        <p>{notice.body}</p><small>{date(notice.created_at)}</small>
                        <Link className="text-link" to={`/orders/${notice.order_id}`}>View order #{notice.order_id} <ArrowRight size={15} /></Link>
                    </div>
                    {!notice.read_at && <button className="button secondary small" disabled={!!busy} onClick={() => void mark(notice.id)}>
                        {busy === notice.id ? 'Marking…' : 'Mark as read'}</button>}
                </li>)}
            </ul> : <Empty title={unread ? 'You’re all caught up' : 'No notifications yet'} text={unread ? 'There are no unread notifications on this page.' : 'Order updates will appear here.'} />}
            <Pagination meta={result.data.meta} onPage={setPage} />
        </>}
    </>;
}
