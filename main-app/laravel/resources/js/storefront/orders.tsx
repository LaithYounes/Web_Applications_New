import { ArrowRight, Check, Package, Truck } from 'lucide-react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { api, ApiError, date, errorText, money, type Order, type OrderStatus } from './api';
import { useOrderEvents } from './realtime';
import { privateKeys, useSession } from './state';
import { Badge, Confirm, Empty, Loading, Message, PageHead, Pagination, Problem, statusLabel, usePage } from './ui';

export function Orders({ admin = false }: { admin?: boolean }) {
    const { user } = useSession();
    const { page, setPage } = usePage();
    const result = useQuery({
        queryKey: user ? [...privateKeys.orders(user), page] : ['disabled-orders'],
        queryFn: ({ signal }) => api.orders(page, signal),
        enabled: !!user,
        refetchInterval: 45_000,
    });
    const refetch = result.refetch;
    const refresh = useCallback(() => { void refetch(); }, [refetch]);
    useOrderEvents(user?.id, admin && user?.role === 'admin' ? 'admin.orders' : undefined, 'order.created', refresh);

    return <>
        <PageHead title={admin ? 'Orders' : 'My orders'} subtitle={admin ? 'Review orders and update fulfillment status.' : 'View your order history and track deliveries.'} />
        {result.isPending && <Loading text="Loading orders…" />}
        {result.isError && <Problem error={result.error} retry={refresh} />}
        {result.data && <>
            {result.data.data.length ? <div className="panel table-wrap" role="region" aria-label="Orders" tabIndex={0}>
                <table>
                    <thead><tr><th scope="col">Order</th><th scope="col">Placed</th><th scope="col">Status</th>
                        <th scope="col">Items</th><th scope="col" className="numeric">Total</th></tr></thead>
                    <tbody>{result.data.data.map((order) => <tr key={order.id}>
                        <th scope="row"><Link className="text-link" to={`${admin ? '/admin' : ''}/orders/${order.id}`}>#{order.id} <ArrowRight size={14} /></Link></th>
                        <td>{date(order.created_at)}</td><td><Badge order={order} /></td>
                        <td>{order.items.reduce((count, item) => count + item.quantity, 0)}</td>
                        <td className="numeric">{money(order.total_amount)}</td>
                    </tr>)}</tbody>
                </table>
            </div> : <Empty title="No orders on this page" text={admin ? 'New orders will appear here.' : 'Orders you place will appear here.'}
                action={admin ? undefined : 'Shop products'} to="/shop" />}
            <Pagination meta={result.data.meta} onPage={setPage} />
        </>}
    </>;
}

const nextStatuses: Record<OrderStatus, OrderStatus[]> = {
    pending: ['confirmed', 'cancelled'],
    confirmed: ['processing', 'cancelled'],
    processing: ['shipped', 'cancelled'],
    shipped: ['delivered'],
    delivered: [],
    cancelled: [],
};
const milestones: OrderStatus[] = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];

function OrderMilestones({ order }: { order: Order }) {
    const history = order.status_history ?? [];
    return <section className="panel order-panel" aria-labelledby="shipping-heading">
        <h2 id="shipping-heading"><Truck size={19} /> Shipping progress</h2>
        <ol className="milestones">
            {milestones.map((status) => {
                const event = history.find(({ to_status }) => to_status === status);
                const current = order.status === status && !order.cancellation_pending;
                return <li key={status} className={event || current ? 'reached' : ''} aria-current={current ? 'step' : undefined}>
                    <span className="milestone-dot" aria-hidden="true">{(event || current) && <Check size={13} />}</span>
                    <span>{statusLabel(status)}</span>
                    {event && <time dateTime={event.changed_at}>{date(event.changed_at)}</time>}
                </li>;
            })}
        </ol>
        {order.status === 'cancelled' && <p className="muted">This order was cancelled.</p>}
    </section>;
}

export function OrderDetails({ admin = false }: { admin?: boolean }) {
    const { id = '' } = useParams();
    return <OrderContent key={id} id={id} admin={admin} />;
}

function OrderContent({ id, admin }: { id: string; admin: boolean }) {
    const { user } = useSession();
    const client = useQueryClient();
    const location = useLocation();
    const [confirm, setConfirm] = useState(false);
    const [busy, setBusy] = useState(false);
    const [choice, setChoice] = useState<OrderStatus | ''>('');
    const [feedback, setFeedback] = useState<{ text: string; error?: boolean }>();
    const result = useQuery({
        queryKey: user ? privateKeys.order(user, id) : ['disabled-order'],
        queryFn: ({ signal }) => api.order(id, signal),
        enabled: !!user && !!id,
        refetchInterval: (query) => {
            if (query.state.data?.cancellation_pending) return 15_000;
            if (query.state.data && ['cancelled', 'delivered'].includes(query.state.data.status)) return false;
            return query.state.status === 'error' && query.state.error instanceof ApiError && query.state.error.status === 404 ? false : 45_000;
        },
    });
    const order = result.data;
    const refetch = result.refetch;
    const refresh = useCallback(() => { void refetch(); }, [refetch]);
    useOrderEvents(user?.id, !admin && user?.role === 'customer' && order ? `orders.${id}` : undefined, 'order.status.updated', refresh);
    const back = admin ? '/admin/orders' : '/orders';

    async function change(operation: () => Promise<Order>, cancellation = false) {
        if (!user || busy) return;
        setConfirm(false);
        setBusy(true);
        setFeedback(undefined);
        try {
            await client.cancelQueries({ queryKey: privateKeys.order(user, id) });
            const updated = await operation();
            client.setQueryData(privateKeys.order(user, id), updated);
            setChoice('');
            if (cancellation) {
                if (updated.status === 'cancelled') setFeedback({ text: 'Order cancelled.' });
                else if (updated.cancellation_pending) setFeedback({ text: 'Cancellation in progress.' });
            } else {
                setFeedback({ text: `Order updated to ${statusLabel(updated.status)}.` });
            }
        } catch (error) {
            const current = await refetch();
            if (cancellation && !current.isError && current.data?.status === 'cancelled') setFeedback({ text: 'Order cancelled.' });
            else if (cancellation && !current.isError && current.data?.cancellation_pending) setFeedback({ text: 'Cancellation in progress.' });
            else setFeedback({ text: errorText(error), error: true });
        } finally {
            setBusy(false);
            void client.invalidateQueries({ queryKey: privateKeys.orders(user) });
            void client.invalidateQueries({ queryKey: privateKeys.notices(user) });
            void client.invalidateQueries({ queryKey: privateKeys.unread(user) });
        }
    }

    return <>
        <PageHead title={`Order #${id}`} subtitle={order ? `Placed ${date(order.created_at)}` : undefined}
            action={<Link className="text-link" to={back}>{admin ? 'Back to orders' : 'Back to My Orders'}</Link>} />
        {result.isPending && <Loading text="Loading order details…" />}
        {result.isError && (result.error instanceof ApiError && [403, 404].includes(result.error.status)
            ? <Empty title="Order unavailable" text="This order could not be found for your account." action="View orders" to={back} />
            : <Problem error={result.error} retry={refresh} />)}
        {order && !(result.error instanceof ApiError && [403, 404].includes(result.error.status)) && <>
            {location.state?.placed && <Message tone="success">Order #{order.id} was placed successfully.</Message>}
            {feedback && <Message tone={feedback.error ? 'error' : 'success'}>{feedback.text}</Message>}
            <div className="order-detail-grid">
                <div className="order-main">
                    <section className="panel order-panel" aria-labelledby="items-heading">
                        <div className="panel-heading"><h2 id="items-heading"><Package size={19} /> Order items</h2><Badge order={order} /></div>
                        <div className="table-wrap" role="region" aria-label="Order items" tabIndex={0}><table>
                            <thead><tr><th scope="col">Product</th><th scope="col">Quantity</th><th scope="col" className="numeric">Unit price</th>
                                <th scope="col" className="numeric">Subtotal</th></tr></thead>
                            <tbody>{order.items.map((item) => <tr key={item.id}>
                                <th scope="row">{item.product_name}</th><td>{item.quantity}</td>
                                <td className="numeric">{money(item.unit_price)}</td><td className="numeric">{money(item.subtotal)}</td>
                            </tr>)}</tbody>
                            <tfoot><tr><th scope="row" colSpan={3}>Order total</th><td className="numeric">{money(order.total_amount)}</td></tr></tfoot>
                        </table></div>
                    </section>
                    <section className="panel order-panel" aria-labelledby="history-heading">
                        <h2 id="history-heading">Status history</h2>
                        {order.status_history?.length ? <ol className="history">
                            {order.status_history.map((entry) => <li key={entry.id}>
                                <span>{statusLabel(entry.to_status)}</span><time dateTime={entry.changed_at}>{date(entry.changed_at)}</time>
                            </li>)}
                        </ol> : <p className="muted">No status changes have been recorded for this order.</p>}
                    </section>
                </div>
                <div className="order-main">
                    <OrderMilestones order={order} />
                    <OrderActions order={order} admin={admin} busy={busy || result.isError} choice={choice} setChoice={setChoice}
                        cancel={() => setConfirm(true)} update={(status) => void change(() => api.updateStatus(id, status))} />
                </div>
            </div>
            {confirm && <Confirm title={order.cancellation_pending ? 'Retry cancellation?' : 'Cancel this order?'} text="The store will cancel this order and release the reserved items."
                action={order.cancellation_pending ? 'Retry cancellation' : 'Cancel order'} cancel={() => setConfirm(false)}
                confirm={() => void change(() => api.cancelOrder(id), true)} />}
        </>}
    </>;
}

function OrderActions({ order, admin, busy, choice, setChoice, cancel, update }: {
    order: Order; admin: boolean; busy: boolean; choice: OrderStatus | ''; setChoice: (status: OrderStatus | '') => void;
    cancel: () => void; update: (status: OrderStatus) => void;
}) {
    if (!admin && order.status !== 'pending') return null;
    if (!admin) return <section className="panel order-panel">
        <h2>Order actions</h2>
        {order.cancellation_pending && <p className="muted">Cancellation in progress. You can retry the request.</p>}
        <button className="button danger-outline" onClick={cancel} disabled={busy}>
            {busy ? 'Checking status…' : order.cancellation_pending ? 'Retry cancellation' : 'Cancel order'}
        </button>
    </section>;

    const options: OrderStatus[] = order.cancellation_pending ? ['cancelled'] : nextStatuses[order.status];
    const selected = choice && options.includes(choice) ? choice : '';
    return <section className="panel order-panel">
        <h2>Update status</h2>
        {options.length ? <form className="form" onSubmit={(event) => { event.preventDefault(); if (selected) update(selected); }}>
            <label htmlFor="next-status">Next status</label>
            <select id="next-status" value={selected} required disabled={busy} onChange={(event) => {
                const status = options.find((option) => option === event.target.value) ?? '';
                setChoice(status);
            }}>
                <option value="">Choose a status</option>
                {options.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
            </select>
            <button className="button primary" disabled={busy || !selected}>{busy ? 'Updating…' : 'Update status'}</button>
        </form> : <p className="muted">This order has reached its final status.</p>}
    </section>;
}
