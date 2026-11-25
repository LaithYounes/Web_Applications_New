import { ArrowRight, ChevronRight, Headphones, Laptop, PanelsTopLeft, ShoppingBag, Smartphone, Watch, CookingPot } from 'lucide-react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api, ApiError, errorText, money, type Category, type Id, type Product } from './api';
import { demoPhotoProducts } from './photoCredits';
import { loginPath, privateKeys, useSession } from './state';
import { Empty, Loading, Message, PageHead, Pagination, Problem, ProductImage, Quantity, positiveInt, usePage, useTitle, validQuantity } from './ui';

export const categoryKeys = ['catalog', 'categories'];
export const productKeys = ['catalog', 'products'];
export const productKey = (id: Id) => ['catalog', 'product', String(id)];

export function useCategories() {
    return useQuery({
        queryKey: [...categoryKeys, 'all'],
        queryFn: async ({ signal }) => {
            const first = await api.categories(1, signal);
            const categories = [...first.data];
            for (let page = 2; page <= first.meta.last_page; page++) {
                categories.push(...(await api.categories(page, signal)).data);
            }
            return categories;
        },
        staleTime: 30_000,
    });
}

export function useCatalog(page: number, category?: string, perPage = 12) {
    return useQuery({
        queryKey: [...productKeys, page, category, perPage],
        queryFn: ({ signal }) => api.products(page, category, signal, perPage),
    });
}

export function useProduct(id: string) {
    const client = useQueryClient();
    const result = useQuery({ queryKey: productKey(id), queryFn: ({ signal }) => api.product(id, signal), enabled: !!id });
    const catalogItem = id && (!result.isError || (result.error instanceof ApiError && result.error.status >= 500))
        ? client.getQueriesData<{ data: Product[] }>({ queryKey: productKeys })
            .flatMap(([, page]) => page?.data ?? []).find((product) => String(product.id) === id)
        : undefined;
    const missing = result.error instanceof ApiError && result.error.status === 404;
    return { ...result, data: missing ? undefined : result.data ?? catalogItem, liveStock: result.isError ? undefined : result.data?.available_quantity };
}

export function CategoryPicker({ categories, value, change }: {
    categories: Category[]; value?: string; change: (category?: string) => void;
}) {
    return <div className="filter-control">
        <label htmlFor="catalog-category">Category</label>
        <select id="catalog-category" value={value ?? ''} onChange={(event) => change(event.target.value || undefined)}>
            <option value="">All categories</option>
            {value && !categories.some(({ id }) => String(id) === value) && <option value={value}>Selected category</option>}
            {categories.map((category) => <option value={category.id} key={category.id}>{category.name}</option>)}
        </select>
    </div>;
}

function CategoryIcon({ name }: { name: string }) {
    const matchingIcons = [
        [/audio|headphones|speaker/i, Headphones],
        [/watch|fitness/i, Watch],
        [/phone|mobile/i, Smartphone],
        [/computer|laptop/i, Laptop],
        [/kitchen|home|appliance/i, CookingPot],
    ] as const;
    const Icon = matchingIcons.find(([pattern]) => pattern.test(name))?.[1] ?? PanelsTopLeft;
    return <Icon size={23} aria-hidden="true" />;
}

function Grid({ products }: { products: Product[] }) {
    return <ul className="product-grid">
        {products.map((product) => <li className="product-card" key={product.id}>
            <Link to={`/products/${product.id}`} className="product-link" aria-label={`View ${product.name}`}>
                <div className="product-media"><ProductImage image={product.image} /></div>
                <div className="product-card-content">
                    <p className="product-category">{product.category?.name}</p>
                    <h3>{product.name}</h3>
                    <span className="product-price">{money(product.price)}</span>
                    <span className="product-arrow" aria-hidden="true"><ArrowRight size={17} /></span>
                </div>
            </Link>
        </li>)}
    </ul>;
}

export function Home() {
    useTitle('Electronics & home essentials');
    const catalog = useCatalog(1, undefined, 24);
    const categories = useCategories();
    const products = catalog.data?.data ?? [];
    const hero = products.find((product) => /sony wh|headphones/i.test(product.name)) ?? products[0];
    const selection = [...products.filter(({ name }) => demoPhotoProducts.has(name)),
        ...products.filter(({ name }) => !demoPhotoProducts.has(name))];
    const categoryIds = new Set<Id | undefined>();
    const featured = selection.filter(({ category }) => {
        if (categoryIds.has(category?.id)) return false;
        categoryIds.add(category?.id);
        return true;
    });
    const featuredIds = new Set(featured.map(({ id }) => id));
    featured.push(...products.filter(({ id }) => !featuredIds.has(id)));
    return <>
        <section className="hero" aria-labelledby="hero-title">
            <div className="hero-copy">
                <p className="eyebrow">Electronics & home essentials</p>
                <h1 id="hero-title">Good tech.<br />Better every day.</h1>
                <p>Find the electronics and home essentials you’ll reach for every day.</p>
                <Link className="button primary" to="/shop">Shop all products <ArrowRight size={18} /></Link>
            </div>
            {hero && <Link to={`/products/${hero.id}`} className="hero-feature">
                <div className="hero-image"><ProductImage image={hero.image} eager /></div>
                <span>{hero.name} <ArrowRight size={15} /></span>
            </Link>}
        </section>
        <section className="store-section" aria-labelledby="categories-heading">
            <div className="section-head"><h2 id="categories-heading">Shop by category</h2></div>
            {categories.isPending && <Loading text="Loading categories…" />}
            {categories.isError && <Problem error={categories.error} retry={() => void categories.refetch()} />}
            {categories.data?.length === 0 && <Empty title="No categories yet" />}
            {!!categories.data?.length && <ul className="category-grid">
                {categories.data.map((category) => <li key={category.id}>
                    <Link to={`/shop?category=${category.id}`} className="category-tile">
                        <span className="category-icon"><CategoryIcon name={category.name} /></span>
                        <span>{category.name}</span><ChevronRight size={15} aria-hidden="true" />
                    </Link>
                </li>)}
            </ul>}
        </section>
        <section className="store-section" aria-labelledby="featured-heading">
            <div className="section-head"><h2 id="featured-heading">Featured products</h2>
                <Link className="text-link" to="/shop">View all products <ArrowRight size={16} /></Link></div>
            {catalog.isPending && <Loading text="Loading products…" />}
            {catalog.isError && <Problem error={catalog.error} retry={() => void catalog.refetch()} />}
            {catalog.isSuccess && (featured.length ? <Grid products={featured.slice(0, 8)} /> : <Empty title="No products yet" />)}
        </section>
    </>;
}

export function Shop() {
    const { params, page, update, setPage } = usePage();
    const category = positiveInt(params.get('category'));
    const catalog = useCatalog(page, category);
    const categories = useCategories();
    const selected = categories.data?.find(({ id }) => String(id) === category);
    return <>
        <PageHead title={selected?.name ?? 'Shop all products'} />
        <div className="toolbar">
            <span>{catalog.data ? `${catalog.data.meta.total.toLocaleString('en-US')} products` : 'Catalog'}</span>
            <CategoryPicker categories={categories.data ?? []} value={category} change={(value) => update({ category: value, page: undefined })} />
        </div>
        {categories.isError && <Message tone="info">Category filters are temporarily unavailable. <button className="text-link" onClick={() => void categories.refetch()}>Try again</button></Message>}
        {catalog.isPending && <Loading text="Loading products…" />}
        {catalog.isError && <Problem error={catalog.error} retry={() => void catalog.refetch()} />}
        {catalog.data && <>
            {catalog.data.data.length ? <Grid products={catalog.data.data} /> :
                <Empty title="No products on this page" text="Choose another category or page to continue shopping." />}
            <Pagination meta={catalog.data.meta} onPage={setPage} />
        </>}
    </>;
}

export function ProductPage() {
    const { id = '' } = useParams();
    return <ProductDetails key={id} id={id} />;
}

function ProductDetails({ id }: { id: string }) {
    const result = useProduct(id);
    const { user, loading } = useSession();
    const client = useQueryClient();
    const navigate = useNavigate();
    const { pathname, search } = useLocation();
    const [quantity, setQuantity] = useState('1');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [added, setAdded] = useState(false);
    const product = result.data;
    useTitle(product?.name ?? 'Product details');

    if (result.isError && !product) {
        return result.error instanceof ApiError && result.error.status === 404
            ? <Empty title="Product unavailable" text="This product could not be found." action="Back to shop" to="/shop" />
            : <Problem error={result.error instanceof ApiError && result.error.status === 503
                ? new ApiError(503, 'Product availability is temporarily unavailable.') : result.error} retry={() => void result.refetch()} />;
    }
    if (!product) return <Loading text="Loading product and availability…" />;
    const stock = result.liveStock;
    const max = Math.min(stock ?? 0, 1000);

    async function add() {
        if (busy || !validQuantity(quantity, max) || !product) return;
        if (!user) {
            navigate(loginPath(pathname + search));
            return;
        }
        setBusy(true);
        setMessage('');
        setAdded(false);
        try {
            await client.cancelQueries({ queryKey: privateKeys.cart(user) });
            const cart = await api.addItem(product.id, Number(quantity));
            client.setQueryData(privateKeys.cart(user), cart);
            setAdded(true);
        } catch (error) {
            setMessage(errorText(error));
            await Promise.all([result.refetch(), client.invalidateQueries({ queryKey: privateKeys.cart(user) })]);
        } finally {
            setBusy(false);
        }
    }

    return <>
        <nav className="crumbs" aria-label="Breadcrumb"><Link to="/">Home</Link><ChevronRight size={14} />
            <Link to="/shop">Shop</Link><ChevronRight size={14} /><span>{product.name}</span></nav>
        <div className="product-detail">
            <div className="detail-media"><ProductImage image={product.image} alt={product.name} eager /></div>
            <div className="detail-copy">
                {product.category && <Link className="eyebrow" to={`/shop?category=${product.category.id}`}>{product.category.name}</Link>}
                <h1>{product.name}</h1><p className="detail-price">{money(product.price)}</p>
                {product.description && <p className="description">{product.description}</p>}
                <div className={`stock ${max > 0 ? 'available' : ''}`}>
                    <span aria-hidden="true" />
                    {stock == null ? (result.isPending ? 'Checking availability…' : 'Availability temporarily unavailable')
                        : stock === 0 ? 'Out of stock' : `In stock · ${stock.toLocaleString('en-US')} available`}
                </div>
                {stock == null && !result.isPending && <button className="text-link" disabled={result.isFetching} onClick={() => void result.refetch()}>Check availability</button>}
                {user?.role === 'admin' ? <Link className="button secondary" to={`/admin/products/${id}`}>Manage product</Link> :
                    <form className="purchase" onSubmit={(event) => { event.preventDefault(); void add(); }}>
                        <Quantity id="product-quantity" value={quantity} max={Math.max(1, max)} onChange={setQuantity} disabled={max < 1 || busy} />
                        <button className="button primary" disabled={busy || loading || !validQuantity(quantity, max)}>
                            <ShoppingBag size={18} /> {busy ? 'Adding…' : 'Add to cart'}
                        </button>
                    </form>}
                {added && <Message tone="success">Added to your cart. <Link to="/cart">View cart</Link></Message>}
                {message && <Message>{message}</Message>}
            </div>
        </div>
    </>;
}
