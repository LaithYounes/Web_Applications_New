import { createContext, useContext, useEffect, useRef, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { advanceSession, ApiError, api, type Id, type Role, type User, sessionExpiredEvent } from './api';
import { Empty, Loading, Problem, useTitle } from './ui';

const sessionKey = ['session'];
const Session = createContext<{
    user?: User | null;
    loading: boolean;
    error: unknown;
    retry: () => void;
    setUser: (user: User | null) => void;
} | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
    const client = useQueryClient();
    const previous = useRef<User | null | undefined>(undefined);
    const session = useQuery({
        queryKey: sessionKey,
        queryFn: async ({ signal }) => {
            try {
                return (await api.me(signal)).user;
            } catch (error) {
                if (error instanceof ApiError && error.status === 401) return null;
                throw error;
            }
        },
        staleTime: 15_000,
    });

    useEffect(() => {
        const expire = () => {
            advanceSession();
            previous.current = null;
            client.setQueryData(sessionKey, null);
            client.removeQueries({ queryKey: ['private'] });
            client.getMutationCache().clear();
        };
        window.addEventListener(sessionExpiredEvent, expire);
        return () => window.removeEventListener(sessionExpiredEvent, expire);
    }, [client]);

    useEffect(() => {
        const before = previous.current;
        previous.current = session.data;
        if (before && (before.id !== session.data?.id || before.role !== session.data?.role)) {
            advanceSession();
            client.removeQueries({ queryKey: privateKeys.account(before) });
            client.getMutationCache().clear();
        }
    }, [client, session.data]);

    const setUser = (user: User | null) => {
        advanceSession();
        previous.current = user;
        void client.cancelQueries({ queryKey: sessionKey });
        client.removeQueries({ queryKey: ['private'] });
        client.getMutationCache().clear();
        client.setQueryData(sessionKey, user);
    };

    return <Session.Provider value={{
        user: session.data, loading: session.isPending, error: session.error, retry: () => void session.refetch(), setUser,
    }}>{children}</Session.Provider>;
}

export function useSession() {
    const session = useContext(Session);
    if (!session) throw new Error('Session provider is missing.');
    return session;
}

const returnPath = /^\/(?:cart|checkout|account|notifications|orders(?:\/\d+)?|products\/\d+|admin(?:\/products(?:\/(?:new|\d+))?|\/categories|\/orders(?:\/\d+)?|\/order-email-failures(?:\/\d+)?)?)$/;

export function safeReturn(value: string | null, role: Role) {
    const defaultPath = role === 'admin' ? '/admin' : '/account';
    if (!value?.startsWith('/') || value.startsWith('//')) return defaultPath;
    try {
        const parsed = new URL(value, location.origin);
        if (parsed.origin === location.origin && returnPath.test(parsed.pathname)
            && (role === 'admin' ? parsed.pathname.startsWith('/admin') || parsed.pathname === '/account' || parsed.pathname.startsWith('/products/')
                : !parsed.pathname.startsWith('/admin'))) {
            return parsed.pathname + parsed.search + parsed.hash;
        }
    } catch {
        return defaultPath;
    }
    return defaultPath;
}

export const loginPath = (returnTo: string) => `/login?returnTo=${encodeURIComponent(returnTo)}`;

function AccessDenied() {
    useTitle('Access denied');
    return <Empty title="Access denied" text="This page is unavailable for your account." action="Back to your account" to="/account" />;
}

export function RequireRole({ role }: { role?: Role }) {
    const session = useSession();
    const { pathname, search } = useLocation();
    if (session.loading) return <Loading text="Checking your session…" />;
    if (!session.user && session.error) return <Problem error={session.error} retry={session.retry} />;
    if (!session.user) return <Navigate replace to={loginPath(pathname + search)} />;
    if (role && session.user.role !== role) {
        return <AccessDenied />;
    }
    return <Outlet />;
}

export const privateKeys = {
    account: (user: User) => ['private', String(user.id), user.role],
    cart: (user: User) => [...privateKeys.account(user), 'cart'],
    orders: (user: User) => [...privateKeys.account(user), 'orders'],
    order: (user: User, id: Id) => [...privateKeys.account(user), 'order', String(id)],
    notices: (user: User) => [...privateKeys.account(user), 'notices'],
    unread: (user: User) => [...privateKeys.account(user), 'unread'],
    failures: (user: User) => [...privateKeys.account(user), 'email-failures'],
};

export function useCart() {
    const { user } = useSession();
    return useQuery({
        queryKey: user ? privateKeys.cart(user) : ['disabled-cart'],
        queryFn: ({ signal }) => api.cart(signal),
        enabled: user?.role === 'customer',
        refetchInterval: (query) => query.state.data?.checkout_pending ? 15_000 : false,
    });
}

export function useUnread() {
    const { user } = useSession();
    return useQuery({
        queryKey: user ? privateKeys.unread(user) : ['disabled-unread'],
        queryFn: ({ signal }) => api.unreadCount(signal),
        enabled: user?.role === 'customer',
        refetchInterval: 45_000,
    });
}
