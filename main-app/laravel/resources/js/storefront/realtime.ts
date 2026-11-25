import { useEffect } from 'react';
import { api, config, type Id } from './api';

export function useOrderEvents(userId: Id | undefined, channelName: string | undefined, eventName: string, refetch: () => void) {
    useEffect(() => {
        const { key, url } = config.reverb;
        if (!userId || !channelName || !key || !url) return;

        let stop: (() => void) | undefined;
        let active = true;
        const seen = new Set<string>();

        async function connect() {
            const endpoint = new URL(url!);
            const secure = ['wss:', 'https:'].includes(endpoint.protocol);
            const port = Number(endpoint.port || (secure ? 443 : 80));
            const { default: Pusher } = await import('pusher-js');
            if (!active) return;

            const pusher = new Pusher(key!, {
                cluster: 'mt1',
                wsHost: endpoint.hostname,
                wsPort: port,
                wssPort: port,
                wsPath: endpoint.pathname === '/' ? '' : endpoint.pathname.replace(/\/$/, ''),
                forceTLS: secure,
                enabledTransports: ['ws', 'wss'],
                enableStats: false,
                channelAuthorization: {
                    customHandler: (params, callback) => {
                        void api.broadcastAuth(params.socketId, params.channelName)
                            .then((auth) => callback(null, auth))
                            .catch((error: Error) => callback(error, null));
                    },
                },
            });
            const channel = pusher.subscribe(`private-${channelName}`);
            channel.bind('pusher:subscription_succeeded', refetch);
            channel.bind(eventName, (event: { event_id?: string | number } | null) => {
                if (event?.event_id == null) return;
                const id = `${channelName}:${event.event_id}`;
                if (seen.has(id)) return;
                seen.add(id);
                if (seen.size > 128) {
                    const oldest = seen.values().next().value;
                    if (oldest !== undefined) seen.delete(oldest);
                }
                refetch();
            });
            stop = () => {
                channel.unbind_all();
                pusher.unsubscribe(channel.name);
                pusher.disconnect();
            };
        }

        void connect().catch(() => undefined);
        return () => {
            active = false;
            stop?.();
        };
    }, [userId, channelName, eventName, refetch]);
}
