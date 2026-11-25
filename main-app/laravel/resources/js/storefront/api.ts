export type Id = number | string;
export type Decimal = string;
export type Role = 'customer' | 'admin';
export type User = { id: Id; name: string; email: string; role: Role };
export type Category = { id: Id; name: string; description: string | null };
export type Product = {
    id: Id;
    name: string;
    description: string | null;
    price: Decimal;
    image: string | null;
    category: Category;
    available_quantity?: number | null;
};
export type CartItem = {
    id: Id;
    product: Pick<Product, 'id' | 'name' | 'price' | 'image'>;
    quantity: number;
    subtotal: Decimal;
};
export type Cart = { id: Id; checkout_pending: boolean; items: CartItem[]; total_amount: Decimal };
export type OrderStatus = 'pending' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled';
export type StatusChange = {
    id: Id;
    from_status: OrderStatus | 'cancelling' | null;
    to_status: OrderStatus | 'cancelling';
    changed_at: string;
};
export type Order = {
    id: Id;
    status: OrderStatus;
    cancellation_pending?: boolean;
    status_history?: StatusChange[];
    total_amount: Decimal;
    created_at: string;
    items: {
        id: Id;
        product_id: Id | null;
        product_name: string;
        unit_price: Decimal;
        quantity: number;
        subtotal: Decimal;
    }[];
};
export type Notice = {
    id: string;
    title: string;
    body: string;
    order_id: Id;
    created_at: string;
    read_at: string | null;
};
export type EmailFailure = {
    id: Id;
    order_id: Id;
    recipient_email: string;
    attempts: number;
    failed_at: string | null;
    failure_message: string;
};
type Envelope<T> = {
    success: boolean;
    message: string;
    data: T;
    errors?: Record<string, string[]> | null;
};
export type Page<T> = Envelope<T[]> & {
    meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    links: { first: string; last: string; prev: string | null; next: string | null };
};

type Config = {
    brand: string;
    currency?: string | null;
    api_origin?: string;
    storage_origin?: string;
    reverb: { key?: string | null; url?: string | null };
};

export const config: Config = JSON.parse(document.getElementById('storefront')?.dataset.config || '{}');
const browserApi = '/api/browser';
const publicApi = `${(config.api_origin || '').replace(/\/$/, '')}/api/v1`;
const storageOrigin = new URL(config.storage_origin || location.origin, location.origin).origin;
export const sessionExpiredEvent = 'storefront:session-expired';
let sessionGeneration = 0;
export const advanceSession = () => { sessionGeneration++; };

const errorMessages: Record<number, string> = {
    0: 'The request did not reach the store. Check your connection and try again.',
    401: 'Your session has ended. Sign in to continue.',
    403: 'You do not have permission to access this page or action.',
    404: 'This item could not be found or is no longer available.',
    409: 'The store could not complete the request because the current state has changed.',
    419: 'Your session changed. Please try again.',
    422: 'Check the highlighted fields and try again.',
    429: 'Too many requests. Wait a moment and try again.',
    503: 'The service is temporarily unavailable. Please try again shortly.',
};

export class ApiError extends Error {
    constructor(public status: number, message?: string, public fields: Record<string, string[]> = {}) {
        super(message || errorMessages[status] || 'The store could not complete your request. Please try again.');
    }
}

export function errorText(error: unknown) {
    if (!(error instanceof ApiError)) return 'Something went wrong. Please try again.';
    return error.status === 422 ? Object.values(error.fields).flat()[0] ?? error.message : error.message;
}

function csrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

async function request<T>(
    path: string,
    options: {
        method?: 'POST' | 'PATCH' | 'PUT' | 'DELETE';
        body?: object | FormData;
        signal?: AbortSignal;
        idempotencyKey?: string;
        public?: boolean;
        authenticating?: boolean;
    } = {},
): Promise<T> {
    const generation = sessionGeneration;
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (options.method) {
        const csrf = csrfToken();
        if (csrf) headers['X-XSRF-TOKEN'] = csrf;
        else headers['X-CSRF-TOKEN'] = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    }

    let body: FormData | string | undefined;
    if (options.body instanceof FormData) {
        body = options.body;
    } else if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.body);
    }
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;

    const timeout = AbortSignal.timeout(20_000);
    const signal = options.signal ? AbortSignal.any([options.signal, timeout]) : timeout;
    let response: Response;
    let payload: Envelope<unknown> | null;
    try {
        response = await fetch(`${options.public ? publicApi : browserApi}${path}`, {
            method: options.method ?? 'GET',
            credentials: options.public ? 'omit' : 'same-origin',
            cache: 'no-store',
            headers,
            body,
            signal,
        });
        payload = await response.json().catch(() => null);
    } catch (error) {
        if (options.signal?.aborted) throw error;
        throw new ApiError(0, timeout.aborted ? 'The store took too long to respond. Please check the current status.' : undefined);
    }

    if (!options.public && generation !== sessionGeneration) {
        throw new DOMException('The active session changed.', 'AbortError');
    }

    if (!response.ok || payload === null || payload.success === false) {
        if (response.status === 401 && !options.public && !options.authenticating) {
            window.dispatchEvent(new Event(sessionExpiredEvent));
        }
        if (response.status === 419 && !options.public && options.method) {
            void request('/auth/me').catch(() => undefined);
        }
        const message = response.status > 0 && response.status < 500 && response.status !== 419 ? payload?.message : undefined;
        throw new ApiError(response.ok ? 502 : response.status, message, payload?.errors ?? {});
    }
    return payload as T;
}

function query(params: Record<string, Id | undefined>) {
    return new URLSearchParams(Object.entries(params)
        .filter((entry): entry is [string, Id] => entry[1] !== undefined)
        .map(([key, value]) => [key, String(value)])).toString();
}

async function data<T>(...args: Parameters<typeof request>) {
    return (await request<Envelope<T>>(...args)).data;
}

export type ProductMetadata = { category_id: Id; name: string; description: string | null; price: Decimal };

export const api = {
    categories: (page: number, signal?: AbortSignal) => request<Page<Category>>(`/categories?page=${page}`, { signal, public: true }),
    products: (page: number, categoryId?: Id, signal?: AbortSignal, perPage = 12) =>
        request<Page<Product>>(`/products?${query({ page, per_page: perPage, category_id: categoryId })}`, { signal, public: true }),
    product: (id: Id, signal?: AbortSignal) => data<Product>(`/products/${id}`, { signal, public: true }),
    login: (credentials: { email: string; password: string }) =>
        data<{ user: User }>('/auth/login', { method: 'POST', body: credentials, authenticating: true }),
    register: (credentials: { name: string; email: string; password: string; password_confirmation: string }) =>
        data<{ user: User }>('/auth/register', { method: 'POST', body: credentials, authenticating: true }),
    me: (signal?: AbortSignal) => data<{ user: User }>('/auth/me', { signal }),
    logout: () => data<null>('/auth/logout', { method: 'POST' }),
    cart: (signal?: AbortSignal) => data<Cart>('/cart', { signal }),
    addItem: (productId: Id, quantity: number) => data<Cart>('/cart/items', { method: 'POST', body: { product_id: productId, quantity } }),
    updateItem: (id: Id, quantity: number) => data<Cart>(`/cart/items/${id}`, { method: 'PUT', body: { quantity } }),
    removeItem: (id: Id) => data<Cart>(`/cart/items/${id}`, { method: 'DELETE' }),
    placeOrder: () => data<Order>('/orders', { method: 'POST' }),
    orders: (page: number, signal?: AbortSignal) => request<Page<Order>>(`/orders?page=${page}`, { signal }),
    order: (id: Id, signal?: AbortSignal) => data<Order>(`/orders/${id}`, { signal }),
    cancelOrder: (id: Id) => data<Order>(`/orders/${id}/cancel`, { method: 'POST' }),
    updateStatus: (id: Id, status: OrderStatus) => data<Order>(`/orders/${id}/status`, { method: 'PATCH', body: { status } }),
    notices: (page: number, unread: boolean, signal?: AbortSignal) =>
        request<Page<Notice>>(`/notifications?${query({ page, per_page: 20, unread: unread ? 1 : undefined })}`, { signal }),
    unreadCount: (signal?: AbortSignal) => data<{ unread_count: number }>('/notifications/unread-count', { signal }),
    readNotice: (id: string) => data<Notice>(`/notifications/${id}/read`, { method: 'PATCH' }),
    readAll: () => data<{ updated_count: number }>('/notifications/read-all', { method: 'PATCH' }),
    createCategory: (body: { name: string; description: string | null }) => data<Category>('/categories', { method: 'POST', body }),
    createProduct: (body: FormData, idempotencyKey: string) => data<Product>('/products', { method: 'POST', body, idempotencyKey }),
    updateProduct: (id: Id, body: ProductMetadata) => data<Product>(`/products/${id}`, { method: 'PATCH', body }),
    replaceImage: (id: Id, image: File) => {
        const body = new FormData();
        body.set('_method', 'PATCH');
        body.set('image', image);
        return data<Product>(`/products/${id}`, { method: 'POST', body });
    },
    deleteProduct: (id: Id) => data<null>(`/products/${id}`, { method: 'DELETE' }),
    emailFailures: (page: number, orderId?: Id, signal?: AbortSignal) =>
        request<Page<EmailFailure>>(`/admin/order-email-failures?${query({ page, order_id: orderId, per_page: 20 })}`, { signal }),
    emailFailure: (id: Id, signal?: AbortSignal) => data<EmailFailure>(`/admin/order-email-failures/${id}`, { signal }),
    broadcastAuth: (socket_id: string, channel_name: string) =>
        request<{ auth: string }>('/broadcasting/auth', { method: 'POST', body: { socket_id, channel_name } }),
};

export function imageUrl(image: string | null | undefined) {
    if (!image) return undefined;
    try {
        const parsed = new URL(image, `${storageOrigin}/storage/`);
        if (!['http:', 'https:'].includes(parsed.protocol)) return undefined;
        const parts = parsed.pathname.split('/storage/');
        const path = parts.length > 1 ? parts.slice(1).join('/storage/') : parsed.pathname.replace(/^\/+/, '');
        return path ? `${storageOrigin}/storage/${path}${parsed.search}` : undefined;
    } catch {
        return undefined;
    }
}

const currency = /^[A-Z]{3}$/.test(config.currency ?? '') ? config.currency : undefined;
const moneyFormatter = new Intl.NumberFormat('en-US', currency
    ? { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }
    : { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' });
export const money = (amount: Decimal) => moneyFormatter.format(Number(amount));
export const date = (value: string | null) => {
    const parsed = value ? new Date(value) : null;
    return parsed && Number.isFinite(parsed.getTime()) ? dateFormatter.format(parsed) : 'Unavailable';
};
