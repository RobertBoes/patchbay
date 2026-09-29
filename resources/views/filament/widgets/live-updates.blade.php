{{--
    No output of its own. It holds the connection to the server and asks the
    page to re-read itself when a reading arrives, so every figure keeps
    coming from wherever it already came from.
--}}
<div
    wire:ignore
    x-data="{
        pusher: null,
        pending: false,

        async start() {
            try {
                await this.loadPusher()
            } catch {
                return
            }

            const client = @js($client);

            this.pusher = new window.Pusher(client.key, {
                wsHost: client.host,
                wsPort: client.port,
                wssPort: client.port,
                forceTLS: client.tls,
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
                cluster: '',
                authorizer: (channel) => ({
                    authorize: (socketId, callback) => {
                        $wire.authorizeChannel(socketId, channel.name)
                            .then((auth) => auth
                                ? callback(null, auth)
                                : callback(new Error('unauthorized'), null))
                            .catch((error) => callback(error, null))
                    },
                }),
            })

            this.pusher.subscribe(client.channel).bind('patchbay:stats', () => this.refresh())
        },

        {{--
            Readings can arrive faster than a page can re-read itself, and a
            queue of refreshes would be worse than none. One is held at a
            time; the next reading finds it already pending and waits.
        --}}
        refresh() {
            if (this.pending) {
                return
            }

            this.pending = true

            setTimeout(() => {
                this.pending = false
                window.Livewire.dispatch('patchbay-stats-changed')
            }, 250)
        },

        loadPusher() {
            if (window.Pusher) {
                return Promise.resolve()
            }

            return new Promise((resolve, reject) => {
                const script = document.createElement('script')
                script.src = 'https://cdn.jsdelivr.net/npm/pusher-js@8.6.0/dist/web/pusher.min.js'
                script.integrity = 'sha384-yz6oLe92Sren05O3WPhu8DK05E9PEkh7kTQJ819acmwKwcCMwJXGu9Kta5wYhfaJ'
                script.crossOrigin = 'anonymous'
                script.onload = resolve
                script.onerror = reject
                document.head.appendChild(script)
            })
        },

        destroy() {
            this.pusher?.disconnect()
        },
    }"
    x-init="start()"
    style="display: none;"
></div>
