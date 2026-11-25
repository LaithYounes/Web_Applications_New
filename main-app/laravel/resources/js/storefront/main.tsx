import { lazy, Suspense, useEffect, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { focusManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Bell, ChevronRight, LayoutGrid, ShoppingBag, UserRound } from 'lucide-react';
import { BrowserRouter, Link, NavLink, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { ApiError, config } from './api';
import { Home, Shop, ProductPage } from './catalog';
import { PhotoCredits } from './photoCredits';
import { RequireRole, SessionProvider, useCart, useSession, useUnread } from './state';
import { Empty, Loading, useTitle } from './ui';

const AuthPage = lazy(() => import('./customer').then((module) => ({ default: module.AuthPage })));
const Account = lazy(() => import('./customer').then((module) => ({ default: module.Account })));
const CartPage = lazy(() => import('./customer').then((module) => ({ default: module.CartPage })));
const Checkout = lazy(() => import('./customer').then((module) => ({ default: module.Checkout })));
const Notifications = lazy(() => import('./customer').then((module) => ({ default: module.Notifications })));
const Orders = lazy(() => import('./orders').then((module) => ({ default: module.Orders })));
const OrderDetails = lazy(() => import('./orders').then((module) => ({ default: module.OrderDetails })));
const AdminHome = lazy(() => import('./admin').then((module) => ({ default: module.AdminHome })));
const AdminProducts = lazy(() => import('./admin').then((module) => ({ default: module.AdminProducts })));
const AdminProductPage = lazy(() => import('./admin').then((module) => ({ default: module.AdminProductPage })));
const AdminCategories = lazy(() => import('./admin').then((module) => ({ default: module.AdminCategories })));
const EmailFailures = lazy(() => import('./admin').then((module) => ({ default: module.EmailFailures })));
const EmailFailureDetails = lazy(() => import('./admin').then((module) => ({ default: module.EmailFailureDetails })));

focusManager.setEventListener((onFocus) => {
    const update = () => onFocus();
    window.addEventListener('focus', update);
    document.addEventListener('visibilitychange', update);
    return () => {
        window.removeEventListener('focus', update);
        document.removeEventListener('visibilitychange', update);
    };
});

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 5_000,
            refetchOnWindowFocus: 'always',
            retry: (count, error) => count === 0 && error instanceof ApiError && (error.status === 0 || error.status >= 500),
        },
        mutations: { retry: false },
    },
});

function Brand() {
    return <Link className="wordmark" to="/"><span><ShoppingBag size={19} strokeWidth={2.1} aria-hidden="true" /></span>{config.brand}</Link>;
}

function Header() {
    const { user, loading } = useSession();
    const cart = useCart();
    const unread = useUnread();
    const count = cart.data?.items.reduce((total, item) => total + item.quantity, 0) ?? 0;
    const unreadCount = unread.data?.unread_count ?? 0;
    const admin = user?.role === 'admin';
    return <header className="site-header">
        <div className="wrap header-inner">
            <Brand />
            <nav className="store-nav" aria-label="Store navigation">
                <NavLink to="/" end>Home</NavLink><NavLink to="/shop">Shop</NavLink>
                {user?.role === 'customer' && <NavLink to="/orders">My orders</NavLink>}
                {admin && <NavLink to="/admin"><LayoutGrid size={15} /> Admin</NavLink>}
            </nav>
            <nav className="header-actions" aria-label="Account navigation">
                {!loading && <Link className="header-action" to={user ? '/account' : '/login'} aria-label={user ? 'Account' : 'Sign in'}>
                    <UserRound size={19} aria-hidden="true" /><span>{user ? 'Account' : 'Sign in'}</span></Link>}
                {user?.role === 'customer' && <Link className="icon-button header-bell" to="/notifications"
                    aria-label={unreadCount ? `Notifications, ${unreadCount} unread` : 'Notifications'}>
                    <Bell size={19} aria-hidden="true" />{!!unreadCount && <span className="unread-count" aria-hidden="true">{unreadCount > 99 ? '99+' : unreadCount}</span>}
                </Link>}
                {!admin && <Link className="header-action cart-action" to="/cart" aria-label={count ? `Cart, ${count} items` : 'Cart'}>
                    <ShoppingBag size={18} aria-hidden="true" /><span>Cart</span>
                    <span className="cart-count" aria-hidden="true">{count > 99 ? '99+' : count}</span>
                </Link>}
            </nav>
        </div>
    </header>;
}

function Shell() {
    const { pathname } = useLocation();
    const main = useRef<HTMLElement>(null);
    const previous = useRef(pathname);
    useEffect(() => {
        if (previous.current === pathname) return;
        previous.current = pathname;
        window.scrollTo(0, 0);
        main.current?.focus({ preventScroll: true });
    }, [pathname]);
    return <>
        <a className="skip-link" href="#store-content">Skip to content</a>
        <Header />
        <main className={`wrap site-main ${pathname === '/' ? 'home-main' : ''}`} id="store-content" tabIndex={-1} ref={main}>
            <Suspense fallback={<Loading />}><Outlet /></Suspense>
        </main>
        <footer className="site-footer">
            <div className="wrap footer-inner">
                <Brand /><nav aria-label="Footer navigation"><Link to="/shop">Shop</Link><Link to="/account">My account</Link></nav>
            </div>
            <div className="wrap"><PhotoCredits /></div>
        </footer>
    </>;
}

function AdminLayout() {
    return <div className="admin-layout">
        <aside className="admin-nav" aria-label="Administration">
            <div><LayoutGrid size={17} /> Store admin</div>
            <nav aria-label="Administration navigation">
                <NavLink to="/admin" end>Overview <ChevronRight size={14} /></NavLink>
                <NavLink to="/admin/products">Products <ChevronRight size={14} /></NavLink>
                <NavLink to="/admin/categories">Categories <ChevronRight size={14} /></NavLink>
                <NavLink to="/admin/orders">Orders <ChevronRight size={14} /></NavLink>
                <NavLink to="/admin/order-email-failures">Order email failures <ChevronRight size={14} /></NavLink>
            </nav>
        </aside>
        <div className="admin-content"><Outlet /></div>
    </div>;
}

function MissingPage() {
    useTitle('Page unavailable');
    return <Empty title="Page unavailable" text="Choose a page from the store navigation." action="Go to the storefront" to="/" />;
}

const root = document.getElementById('storefront');
if (root) {
    createRoot(root).render(
        <QueryClientProvider client={queryClient}>
            <SessionProvider>
                <BrowserRouter>
                    <Routes>
                        <Route element={<Shell />}>
                            <Route index element={<Home />} />
                            <Route path="shop" element={<Shop />} />
                            <Route path="products/:id" element={<ProductPage />} />
                            <Route path="login" element={<AuthPage key="login" />} />
                            <Route path="register" element={<AuthPage key="register" register />} />
                            <Route element={<RequireRole />}>
                                <Route path="account" element={<Account />} />
                            </Route>
                            <Route element={<RequireRole role="customer" />}>
                                <Route path="cart" element={<CartPage />} />
                                <Route path="checkout" element={<Checkout />} />
                                <Route path="orders" element={<Orders />} />
                                <Route path="orders/:id" element={<OrderDetails />} />
                                <Route path="notifications" element={<Notifications />} />
                            </Route>
                            <Route element={<RequireRole role="admin" />}>
                                <Route path="admin" element={<AdminLayout />}>
                                    <Route index element={<AdminHome />} />
                                    <Route path="products" element={<AdminProducts />} />
                                    <Route path="products/new" element={<AdminProductPage create />} />
                                    <Route path="products/:id" element={<AdminProductPage />} />
                                    <Route path="categories" element={<AdminCategories />} />
                                    <Route path="orders" element={<Orders admin />} />
                                    <Route path="orders/:id" element={<OrderDetails admin />} />
                                    <Route path="order-email-failures" element={<EmailFailures />} />
                                    <Route path="order-email-failures/:id" element={<EmailFailureDetails />} />
                                </Route>
                            </Route>
                            <Route path="*" element={<MissingPage />} />
                        </Route>
                    </Routes>
                </BrowserRouter>
            </SessionProvider>
        </QueryClientProvider>,
    );
}
