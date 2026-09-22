{{--
    Filament's own components and classes only, with inline layout: a host
    application's panel may have no Tailwind build of its own to supply
    utility classes from.
--}}
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('Debug console')"
        :description="__('Send an event and watch it arrive. Listening holds one of this application\'s connections while the page is open.')"
    >
        @if (! $active)
            <p style="margin: 0; font-size: 0.875rem; color: var(--gray-500);">
                {{ __('Activate the application to use the console.') }}
            </p>
        @else
            <div
                x-data="{
                    channel: $wire.entangle('channel'),
                    state: 'connecting',
                    error: null,
                    events: [],
                    pusher: null,
                    subscribed: null,

                    async start() {
                        try {
                            await this.loadPusher()
                        } catch {
                            this.state = 'failed'
                            this.error = @js(__('Could not load the WebSocket client.'));
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
                        })

                        this.pusher.connection.bind('state_change', ({ current }) => this.state = current)
                        this.pusher.connection.bind('error', (error) => this.error = this.explain(error))

                        this.listen(this.channel)
                        this.$watch('channel', (channel) => this.listen(channel))
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

                    listen(channel) {
                        if (this.subscribed) {
                            this.pusher.unsubscribe(this.subscribed)
                            this.subscribed = null
                        }

                        {{-- Private and presence channels need an auth endpoint the dashboard does not have. --}}
                        if (! channel || /^(private|presence)-/.test(channel)) {
                            return
                        }

                        this.subscribed = channel
                        this.pusher.subscribe(channel).bind_global((event, data) => {
                            if (event.startsWith('pusher:') || event.startsWith('pusher_internal:')) {
                                return
                            }

                            this.events.unshift({
                                id: crypto.randomUUID(),
                                at: new Date().toLocaleTimeString(),
                                channel,
                                event,
                                data: JSON.stringify(data, null, 2),
                            })

                            this.events = this.events.slice(0, 50)
                        })
                    },

                    explain(error) {
                        const messages = {
                            4001: @js(__('The server does not know this application yet. Give it a moment after creating or activating it.')),
                            4004: @js(__('All of this application\'s connections are in use.')),
                            4009: @js(__('This dashboard is not an allowed origin for the application. Add :origin to its allowed origins to listen here.', ['origin' => request()->getHost()])),
                        }

                        const code = error?.error?.data?.code

                        return messages[code] ?? error?.error?.data?.message ?? @js(__('Could not connect to the server.'));
                    },

                    destroy() {
                        this.pusher?.disconnect()
                    },
                }"
                x-init="start()"
                style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr));"
            >
                <form wire:submit="send" style="display: grid; gap: 1rem; align-content: start;">
                    <label style="display: grid; gap: 0.5rem;">
                        <span style="font-size: 0.875rem; font-weight: 500;">{{ __('Channel') }}</span>
                        <x-filament::input.wrapper :valid="! $errors->has('channel')">
                            <x-filament::input type="text" x-model.debounce.400ms="channel" spellcheck="false" />
                        </x-filament::input.wrapper>
                        @error('channel') <span style="font-size: 0.875rem; color: var(--danger-600);">{{ $message }}</span> @enderror
                    </label>

                    <label style="display: grid; gap: 0.5rem;">
                        <span style="font-size: 0.875rem; font-weight: 500;">{{ __('Event') }}</span>
                        <x-filament::input.wrapper :valid="! $errors->has('event')">
                            <x-filament::input type="text" wire:model="event" spellcheck="false" />
                        </x-filament::input.wrapper>
                        @error('event') <span style="font-size: 0.875rem; color: var(--danger-600);">{{ $message }}</span> @enderror
                    </label>

                    <label style="display: grid; gap: 0.5rem;">
                        <span style="font-size: 0.875rem; font-weight: 500;">{{ __('Payload') }}</span>
                        <x-filament::input.wrapper class="fi-fo-textarea-wrp" :valid="! $errors->has('payload')">
                            <textarea
                                class="fi-fo-textarea"
                                wire:model="payload"
                                rows="5"
                                spellcheck="false"
                                style="width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem;"
                            ></textarea>
                        </x-filament::input.wrapper>
                        @error('payload') <span style="font-size: 0.875rem; color: var(--danger-600);">{{ $message }}</span> @enderror
                    </label>

                    <div>
                        <x-filament::button type="submit" icon="heroicon-m-paper-airplane">
                            {{ __('Send event') }}
                        </x-filament::button>
                    </div>
                </form>

                <div style="display: grid; gap: 0.75rem; align-content: start; min-width: 0;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                        <span style="font-size: 0.875rem; font-weight: 500;">
                            <template x-if="subscribed">
                                <span>{{ __('Listening on') }} <code x-text="subscribed"></code></span>
                            </template>
                            <template x-if="! subscribed">
                                <span style="color: var(--gray-500);">{{ __('Private and presence channels can be sent to, not listened on, from here.') }}</span>
                            </template>
                        </span>

                        <span
                            x-text="state"
                            class="fi-badge fi-size-sm"
                            :class="state === 'connected' ? 'fi-color fi-color-success' : (['failed', 'unavailable'].includes(state) || error ? 'fi-color fi-color-danger' : 'fi-color fi-color-gray')"
                        ></span>
                    </div>

                    <template x-if="error">
                        <p x-text="error" style="margin: 0; font-size: 0.875rem; color: var(--danger-600);"></p>
                    </template>

                    <div wire:ignore style="display: grid; gap: 0.5rem; max-height: 22rem; overflow-y: auto;">
                        <template x-if="events.length === 0">
                            <p style="margin: 0; font-size: 0.875rem; color: var(--gray-500);">
                                {{ __('Nothing yet. Send an event, or broadcast from your application.') }}
                            </p>
                        </template>

                        <template x-for="entry in events" :key="entry.id">
                            <div style="border: 1px solid rgb(127 127 127 / 0.25); border-radius: 0.5rem; padding: 0.5rem 0.75rem; min-width: 0;">
                                <div style="display: flex; justify-content: space-between; gap: 0.75rem; font-size: 0.8125rem;">
                                    <strong x-text="entry.event"></strong>
                                    <span x-text="entry.at" style="color: var(--gray-500);"></span>
                                </div>
                                <pre x-text="entry.data" style="margin: 0.25rem 0 0; font-size: 0.75rem; white-space: pre-wrap; word-break: break-word;"></pre>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
