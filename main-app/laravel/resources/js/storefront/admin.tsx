import { ArrowRight, Boxes, Folder, MailWarning, Package, Plus, Trash2, Upload } from 'lucide-react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { ApiError, api, date, money, type Product, type ProductMetadata } from './api';
import { CategoryPicker, categoryKeys, productKey, productKeys, useCatalog, useCategories, useProduct } from './catalog';
import { privateKeys, useSession } from './state';
import { Confirm, Empty, Field, FormError, Loading, Message, PageHead, Pagination, Problem, ProductImage, positiveInt, usePage } from './ui';

export function AdminHome() {
    const { user } = useSession();
    const products = useCatalog(1);
    const categories = useQuery({ queryKey: [...categoryKeys, 1], queryFn: ({ signal }) => api.categories(1, signal) });
    const orders = useQuery({
        queryKey: user ? [...privateKeys.orders(user), 1] : ['disabled-admin-orders'],
        queryFn: ({ signal }) => api.orders(1, signal), enabled: !!user,
    });
    const failures = useQuery({
        queryKey: user ? [...privateKeys.failures(user), 1, undefined] : ['disabled-admin-failures'],
        queryFn: ({ signal }) => api.emailFailures(1, undefined, signal), enabled: !!user,
    });
    const shortcuts = [
        { title: 'Products', to: '/admin/products', description: 'Manage product listings and images.', result: products, Icon: Boxes },
        { title: 'Categories', to: '/admin/categories', description: 'Organize the store catalog.', result: categories, Icon: Folder },
        { title: 'Orders', to: '/admin/orders', description: 'Review orders and update status.', result: orders, Icon: Package },
        { title: 'Order email failures', to: '/admin/order-email-failures', description: 'Inspect failed order emails.', result: failures, Icon: MailWarning },
    ];
    return <>
        <PageHead title="Administration" subtitle="Manage the catalog, fulfillment, and order email operations." />
        <div className="dashboard-grid">{shortcuts.map(({ title, to, description, result, Icon }) =>
            <Link className="panel dashboard-card" to={to} key={to}>
                <div><span className="state-icon"><Icon size={22} /></span><ArrowRight size={19} /></div>
                <h2>{title}</h2><p>{description}</p>
                <strong>{result.isPending ? 'Loading count…' : result.isError ? 'Count unavailable' : result.data.meta.total.toLocaleString('en-US')}</strong>
            </Link>)}</div>
    </>;
}

export function AdminProducts() {
    const location = useLocation();
    const { params, page, setPage, update } = usePage();
    const category = positiveInt(params.get('category'));
    const result = useCatalog(page, category);
    const categories = useCategories();
    return <>
        <PageHead title="Products" subtitle="Manage listings, prices, and product images."
            action={<Link className="button primary" to="/admin/products/new"><Plus size={18} /> Add product</Link>} />
        {location.state?.productDeleted && <Message tone="success">Product deleted.</Message>}
        <div className="toolbar"><span>{result.data ? `${result.data.meta.total.toLocaleString('en-US')} products` : 'Catalog'}</span>
            <CategoryPicker categories={categories.data ?? []} value={category} change={(value) => update({ category: value, page: undefined })} /></div>
        {categories.isError && <Message tone="info">Category filters are temporarily unavailable. <button className="text-link" onClick={() => void categories.refetch()}>Try again</button></Message>}
        {result.isPending && <Loading text="Loading products…" />}
        {result.isError && <Problem error={result.error} retry={() => void result.refetch()} />}
        {result.data && <>
            {result.data.data.length ? <div className="panel table-wrap" role="region" aria-label="Products" tabIndex={0}><table>
                <thead><tr><th scope="col">Product</th><th scope="col">Category</th><th className="numeric" scope="col">Price</th></tr></thead>
                <tbody>{result.data.data.map((product) => <tr key={product.id}>
                    <th scope="row"><div className="table-product"><span className="table-thumbnail"><ProductImage image={product.image} /></span>
                        <span><Link className="text-link" aria-label={`Manage ${product.name}`} to={`/admin/products/${product.id}`}>{product.name}</Link>
                            <small>#{product.id}</small></span></div></th>
                    <td>{product.category?.name ?? 'Uncategorized'}</td><td className="numeric">{money(product.price)}</td>
                </tr>)}</tbody>
            </table></div> : <Empty title="No products on this page" action="Add a product" to="/admin/products/new" />}
            <Pagination meta={result.data.meta} onPage={setPage} />
        </>}
    </>;
}

const imageTypes = ['image/jpeg', 'image/png', 'image/webp'];
function validateImage(file: File | undefined) {
    if (!file) return undefined;
    if (!imageTypes.includes(file.type)) return 'Choose a JPEG, PNG, or WebP image.';
    if (file.size > 5 * 1024 * 1024) return 'Images must be 5 MiB or smaller.';
    return undefined;
}

function ImagePicker({ label, id, file, onChange, error, required, disabled }: {
    label: string; id: string; file?: File; onChange: (file?: File) => void; error?: unknown; required?: boolean; disabled?: boolean;
}) {
    const [preview, setPreview] = useState<{ file: File; url: string }>();
    useEffect(() => {
        if (!file || validateImage(file)) return;
        const url = URL.createObjectURL(file);
        setPreview({ file, url });
        return () => URL.revokeObjectURL(url);
    }, [file]);

    return <>
        <Field name={id} label={label} error={error} hint="JPEG, PNG, or WebP · Up to 5 MiB">
            <input id={id} type="file" aria-describedby={`${id}-hint`} accept={imageTypes.join(',')}
                required={required} disabled={disabled} onChange={(event) => onChange(event.target.files?.[0])} />
        </Field>
        {file && preview?.file === file && <div className="image-preview"><img src={preview.url} alt="Selected image preview" /></div>}
        {validateImage(file) && <Message>{validateImage(file)}</Message>}
    </>;
}

export function AdminProductPage({ create = false }: { create?: boolean }) {
    const location = useLocation();
    const { id = '' } = useParams();
    const product = useProduct(create ? '' : id);
    const catalogProduct = create ? undefined : product.data;

    return <>
        <PageHead title={create ? 'Add product' : catalogProduct?.name ?? 'Edit product'}
            subtitle={create ? 'Create a listing and set its starting stock.' : 'Update the listing and check live inventory.'}
            action={<Link className="text-link" to="/admin/products">Back to products</Link>} />
        {location.state?.productCreated && <Message tone="success">Product created.</Message>}
        {!create && product.isPending && <Loading text="Loading product…" />}
        {!create && product.isError && !catalogProduct && (product.error instanceof ApiError && product.error.status === 404
            ? <Empty title="Product unavailable" action="Back to products" to="/admin/products" />
            : <Problem error={product.error} retry={() => void product.refetch()} />)}
        {(create || catalogProduct) && <ProductEditor key={create ? 'new' : id} product={catalogProduct} stock={product.liveStock}
            checkingStock={product.isFetching} refreshStock={() => void product.refetch()} />}
    </>;
}

function ProductEditor({ product, stock, checkingStock, refreshStock }: {
    product?: Product; stock?: number | null; checkingStock: boolean; refreshStock: () => void;
}) {
    const categories = useCategories();
    const client = useQueryClient();
    const navigate = useNavigate();
    const [image, setImage] = useState<File>();
    const [replacement, setReplacement] = useState<File>();
    const [imageInputVersion, setImageInputVersion] = useState(0);
    const [busy, setBusy] = useState('');
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [error, setError] = useState<{ operation: string; value: unknown }>();
    const [success, setSuccess] = useState('');
    const submission = useRef<{ signature: string; image: File; key: string } | undefined>(undefined);
    const metadataError = error?.operation === 'metadata' ? error.value : undefined;
    const replacementError = error?.operation === 'image' ? error.value : undefined;

    function invalidate(updated?: Product) {
        void client.invalidateQueries({ queryKey: productKeys });
        if (updated) {
            client.setQueryData(productKey(updated.id), updated);
            void client.invalidateQueries({ queryKey: productKey(updated.id) });
        }
    }

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (busy) return;
        const input = new FormData(event.currentTarget);
        const fields: ProductMetadata = {
            category_id: String(input.get('category_id')),
            name: String(input.get('name')).trim(),
            description: String(input.get('description')).trim() || null,
            price: String(input.get('price')).trim(),
        };
        setBusy('metadata');
        setSuccess('');
        setError(undefined);
        try {
            if (product) {
                const updated = await api.updateProduct(product.id, fields);
                invalidate(updated);
                setSuccess('Product details saved.');
            } else if (image && !validateImage(image)) {
                const initial = String(input.get('initial_quantity'));
                const signature = JSON.stringify({ ...fields, initial });
                if (submission.current?.signature !== signature || submission.current.image !== image) {
                    submission.current = { signature, image, key: crypto.randomUUID() };
                }
                const body = new FormData();
                body.set('category_id', String(fields.category_id));
                body.set('name', fields.name);
                if (fields.description !== null) body.set('description', fields.description);
                body.set('price', fields.price);
                body.set('initial_quantity', initial);
                body.set('image', image);
                const created = await api.createProduct(body, submission.current.key);
                invalidate(created);
                navigate(`/admin/products/${created.id}`, { replace: true, state: { productCreated: true } });
            }
        } catch (value) {
            setError({ operation: 'metadata', value });
        } finally {
            setBusy('');
        }
    }

    async function replace(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!product || !replacement || validateImage(replacement) || busy) return;
        setBusy('image');
        setSuccess('');
        setError(undefined);
        try {
            invalidate(await api.replaceImage(product.id, replacement));
            setReplacement(undefined);
            setImageInputVersion((version) => version + 1);
            setSuccess('Product image replaced.');
        } catch (value) {
            setError({ operation: 'image', value });
        } finally {
            setBusy('');
        }
    }

    async function remove() {
        if (!product || busy) return;
        setConfirmDelete(false);
        setBusy('delete');
        setSuccess('');
        setError(undefined);
        try {
            await api.deleteProduct(product.id);
            client.removeQueries({ queryKey: productKey(product.id) });
            invalidate();
            navigate('/admin/products', { replace: true, state: { productDeleted: true } });
        } catch (value) {
            setError({ operation: 'delete', value });
        } finally {
            setBusy('');
        }
    }

    return <>
        {success && <Message tone="success">{success}</Message>}
        <div className="editor-grid">
            <section className="panel order-panel">
                <h2>Product details</h2>
                {categories.isPending && <Loading text="Loading categories…" />}
                {categories.isError && <Problem error={categories.error} retry={() => void categories.refetch()} />}
                {categories.data?.length === 0 && <Message tone="info">Create a <Link to="/admin/categories">category</Link> before adding a product.</Message>}
                {!!categories.data?.length && <form className="form" onSubmit={(event) => void submit(event)}>
                    <Field label="Category" name="category_id" error={metadataError}>
                        <select id="category_id" name="category_id" defaultValue={product?.category?.id ?? ''} required disabled={!!busy}>
                            <option value="">Select a category</option>
                            {categories.data.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Product name" name="name" error={metadataError}>
                        <input id="name" name="name" defaultValue={product?.name} required maxLength={255} disabled={!!busy} />
                    </Field>
                    <Field label="Description" name="description" error={metadataError}>
                        <textarea id="description" name="description" rows={4} defaultValue={product?.description ?? ''} disabled={!!busy} />
                    </Field>
                    <Field label="Price" name="price" error={metadataError}>
                        <input id="price" name="price" type="number" step="0.01" min="0.01" max="99999999.99" inputMode="decimal"
                            defaultValue={product?.price} required disabled={!!busy} />
                    </Field>
                    {!product && <>
                        <Field label="Initial quantity" name="initial_quantity" error={metadataError}>
                            <input id="initial_quantity" name="initial_quantity" type="number" step={1} min={0} max={2147483647} required disabled={!!busy} />
                        </Field>
                        <ImagePicker id="image" label="Product image" file={image} onChange={setImage} required disabled={!!busy} error={metadataError} />
                    </>}
                    <FormError error={metadataError} fields={['category_id', 'name', 'description', 'price', 'initial_quantity', 'image']} />
                    <button className="button primary" disabled={!!busy || (!product && (!image || !!validateImage(image)))}>
                        {busy === 'metadata' ? 'Saving…' : product ? 'Save product details' : 'Create product'}
                    </button>
                </form>}
            </section>
            {product && <div className="order-main">
                <section className="panel order-panel">
                    <h2>Live inventory</h2>
                    <p className="muted">{stock == null ? 'Availability temporarily unavailable' :
                        stock === 0 ? 'Out of stock' : `${stock.toLocaleString('en-US')} available`}</p>
                    <button className="button secondary small" onClick={refreshStock} disabled={checkingStock}>{checkingStock ? 'Checking…' : 'Refresh stock'}</button>
                </section>
                <section className="panel order-panel">
                    <h2>Product image</h2>
                    <div className="editor-image"><ProductImage image={product.image} alt={product.name} /></div>
                    <form className="form" onSubmit={(event) => void replace(event)}>
                        <ImagePicker key={imageInputVersion} id="image" label="Replacement image" file={replacement}
                            onChange={setReplacement} disabled={!!busy} error={replacementError} />
                        <FormError error={replacementError} fields={['image']} />
                        <button className="button secondary" disabled={!!busy || !replacement || !!validateImage(replacement)}>
                            <Upload size={17} /> {busy === 'image' ? 'Uploading…' : 'Replace image'}
                        </button>
                    </form>
                </section>
                <section className="panel order-panel">
                    <h2>Delete product</h2>
                    <p className="muted">Remove this listing from the catalog.</p>
                    <FormError error={error?.operation === 'delete' ? error.value : undefined} />
                    <button className="button danger-outline" onClick={() => setConfirmDelete(true)} disabled={!!busy}>
                        <Trash2 size={17} /> {busy === 'delete' ? 'Deleting…' : 'Delete product'}
                    </button>
                </section>
            </div>}
        </div>
        {confirmDelete && <Confirm title="Delete this product?" text={`${product?.name} will be removed from the catalog.`}
            action="Delete product" cancel={() => setConfirmDelete(false)} confirm={() => void remove()} />}
    </>;
}

export function AdminCategories() {
    const { page, setPage } = usePage();
    const client = useQueryClient();
    const [error, setError] = useState<unknown>();
    const [busy, setBusy] = useState(false);
    const [success, setSuccess] = useState('');
    const result = useQuery({ queryKey: [...categoryKeys, page], queryFn: ({ signal }) => api.categories(page, signal) });

    return <>
        <PageHead title="Categories" subtitle="Create and review store categories." />
        <div className="editor-grid">
            <div>
                {result.isPending && <Loading text="Loading categories…" />}
                {result.isError && <Problem error={result.error} retry={() => void result.refetch()} />}
                {result.data && <>
                    {result.data.data.length ? <div className="panel table-wrap" role="region" aria-label="Categories" tabIndex={0}><table>
                        <thead><tr><th scope="col">Name</th><th scope="col">Description</th></tr></thead>
                        <tbody>{result.data.data.map((category) => <tr key={category.id}><th scope="row">{category.name}</th>
                            <td>{category.description || 'None'}</td></tr>)}</tbody>
                    </table></div> : <Empty title="No categories on this page" />}
                    <Pagination meta={result.data.meta} onPage={setPage} />
                </>}
            </div>
            <section className="panel order-panel">
                <h2>Add category</h2>
                <form className="form" onSubmit={async (event) => {
                    event.preventDefault();
                    if (busy) return;
                    const form = event.currentTarget;
                    const fields = new FormData(form);
                    setBusy(true);
                    setError(undefined);
                    setSuccess('');
                    try {
                        const category = await api.createCategory({
                            name: String(fields.get('name')).trim(), description: String(fields.get('description')).trim() || null,
                        });
                        form.reset();
                        await client.invalidateQueries({ queryKey: categoryKeys });
                        setSuccess(`${category.name} was created.`);
                    } catch (failure) {
                        setError(failure);
                    } finally {
                        setBusy(false);
                    }
                }}>
                    <Field label="Category name" name="name" error={error}><input id="name" name="name" required maxLength={255} /></Field>
                    <Field label="Description" name="description" error={error}><textarea id="description" name="description" rows={3} /></Field>
                    <FormError error={error} fields={['name', 'description']} />
                    {success && <Message tone="success">{success}</Message>}
                    <button className="button primary" disabled={busy}>{busy ? 'Creating…' : 'Create category'}</button>
                </form>
            </section>
        </div>
    </>;
}

export function EmailFailures() {
    const { user } = useSession();
    const { params, page, setPage, update } = usePage();
    const orderId = positiveInt(params.get('order_id'));
    const result = useQuery({
        queryKey: user ? [...privateKeys.failures(user), page, orderId] : ['disabled-failures'],
        queryFn: ({ signal }) => api.emailFailures(page, orderId, signal), enabled: !!user,
    });

    return <>
        <PageHead title="Order email failures" subtitle="Review delivery failures and their recorded attempts." />
        <form className="toolbar inline-form" key={orderId ?? ''} onSubmit={(event) => {
            event.preventDefault();
            const input = new FormData(event.currentTarget);
            update({ order_id: positiveInt(String(input.get('order_id'))), page: undefined });
        }}>
            <div className="filter-control"><label htmlFor="order_id">Order ID</label>
                <input type="number" inputMode="numeric" id="order_id" name="order_id" min={1} step={1} placeholder="Any order" defaultValue={orderId} /></div>
            <button className="button secondary">Apply filter</button>
            {orderId && <button className="text-link" type="button" onClick={() => update({ order_id: undefined, page: undefined })}>Clear filter</button>}
        </form>
        {result.isPending && <Loading text="Loading email failures…" />}
        {result.isError && <Problem error={result.error} retry={() => void result.refetch()} />}
        {result.data && <>
            {result.data.data.length ? <div className="panel table-wrap" role="region" aria-label="Email failures" tabIndex={0}><table>
                <thead><tr><th scope="col">Order</th><th scope="col">Recipient</th><th scope="col">Attempts</th>
                    <th scope="col">Failed at</th><th scope="col">Failure</th></tr></thead>
                <tbody>{result.data.data.map((failure) => <tr key={failure.id}>
                    <th scope="row"><Link className="text-link" to={`/admin/orders/${failure.order_id}`}>#{failure.order_id}</Link></th>
                    <td>{failure.recipient_email}</td><td>{failure.attempts}</td><td>{date(failure.failed_at)}</td>
                    <td><Link className="text-link" to={`/admin/order-email-failures/${failure.id}`}>{failure.failure_message} <ArrowRight size={14} /></Link></td>
                </tr>)}</tbody>
            </table></div> : <Empty title="No email failures on this page" text={orderId ? 'No failures match that order.' : 'Failed order emails will appear here.'} />}
            <Pagination meta={result.data.meta} onPage={setPage} />
        </>}
    </>;
}

export function EmailFailureDetails() {
    const { id = '' } = useParams();
    const { user } = useSession();
    const result = useQuery({
        queryKey: user ? [...privateKeys.failures(user), 'detail', id] : ['disabled-email-failure'],
        queryFn: ({ signal }) => api.emailFailure(id, signal), enabled: !!user && !!id,
    });
    const failure = result.data;
    return <>
        <PageHead title={`Email failure #${id}`} action={<Link className="text-link" to="/admin/order-email-failures">Back to email failures</Link>} />
        {result.isPending && <Loading text="Loading failure details…" />}
        {result.isError && <Problem error={result.error} retry={() => void result.refetch()} />}
        {failure && <section className="panel order-panel">
            <h2>Delivery details</h2>
            <dl className="details-list">
                <div><dt>Order</dt><dd><Link className="text-link" to={`/admin/orders/${failure.order_id}`}>#{failure.order_id}</Link></dd></div>
                <div><dt>Recipient</dt><dd>{failure.recipient_email}</dd></div>
                <div><dt>Attempts</dt><dd>{failure.attempts}</dd></div>
                <div><dt>Failed at</dt><dd>{date(failure.failed_at)}</dd></div>
            </dl>
            <Message tone="info">{failure.failure_message}</Message>
        </section>}
    </>;
}
