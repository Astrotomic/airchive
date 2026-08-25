<div>
    <h1 class="mb-6 text-2xl font-semibold">Account</h1>

    <div class="space-y-6">
        <section class="rounded-lg border border-zinc-200 bg-white p-6">
            <h2 class="text-lg font-medium">Profile</h2>
            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex gap-3">
                    <dt class="w-24 text-zinc-500">Name</dt>
                    <dd>{{ auth()->user()->name }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-24 text-zinc-500">Email</dt>
                    <dd>{{ auth()->user()->email }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-zinc-200 bg-white p-6">
            <h2 class="text-lg font-medium">Multi-factor authentication</h2>

            @if (auth()->user()->two_factor_secret)
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex gap-3">
                        <dt class="w-36 text-zinc-500">Status</dt>
                        <dd>Enabled</dd>
                    </div>
                    @if (auth()->user()->two_factor_confirmed_at)
                        <div class="flex gap-3">
                            <dt class="w-36 text-zinc-500">Confirmed</dt>
                            <dd>{{ auth()->user()->two_factor_confirmed_at->toDayDateTimeString() }}</dd>
                        </div>
                    @endif
                    <div class="flex gap-3">
                        <dt class="w-36 text-zinc-500">Recovery codes</dt>
                        <dd>{{ auth()->user()->two_factor_recovery_codes ? 'Saved' : 'Not saved' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-zinc-500">Your authenticator app is required when signing in from a new browser session.</p>
            @else
                <p class="mt-2 text-sm text-zinc-600">Not configured.</p>
            @endif
        </section>

        <section class="rounded-lg border border-zinc-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-medium">Passkeys</h2>
                    <p class="mt-1 text-sm text-zinc-500">Devices that can sign in without a password.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a
                        href="{{ route('enroll.register') }}"
                        class="rounded-md border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50"
                    >
                        Register on this browser
                    </a>
                    <form
                        method="POST"
                        action="{{ route('account.devices.pair') }}"
                    >
                        @csrf
                        <button
                            type="submit"
                            class="rounded-md border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50"
                        >
                            Pair another device
                        </button>
                    </form>
                </div>
            </div>

            @if ($securityNotice)
                <p class="mt-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900">{{ $securityNotice }}</p>
            @endif

            @if ($securityError)
                <p class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $securityError }}</p>
            @endif

            @if (session('device_pairing_url'))
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm">
                    <p class="font-medium text-amber-900">Pairing URL (valid for 15 minutes)</p>
                    <p class="mt-2 break-all text-amber-800">{{ session('device_pairing_url') }}</p>
                </div>
            @endif

            @if ($passkeys->isEmpty())
                <p class="mt-4 text-sm text-zinc-500">No passkeys registered yet.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Device name</th>
                                <th class="py-2 pr-4 font-medium">Authenticator</th>
                                <th class="py-2 pr-4 font-medium">Registered</th>
                                <th class="py-2 pr-4 font-medium">Last used</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($passkeys as $passkey)
                                <tr wire:key="passkey-{{ $passkey->id }}">
                                    <td class="py-3 pr-4 font-medium">{{ $passkey->name }}</td>
                                    <td class="py-3 pr-4 text-zinc-600">{{ $passkey->authenticator ?: 'Unknown authenticator' }}</td>
                                    <td class="py-3 pr-4 text-zinc-600">{{ $passkey->created_at?->toDayDateTimeString() ?: '—' }}</td>
                                    <td class="py-3 pr-4 text-zinc-600">{{ $passkey->last_used_at?->diffForHumans() ?: 'Never' }}</td>
                                    <td class="py-3 text-right">
                                        <button
                                            type="button"
                                            wire:click="deletePasskey({{ $passkey->id }})"
                                            wire:confirm="Remove the passkey &quot;{{ $passkey->name }}&quot;? This device will no longer be able to sign in."
                                            class="text-sm text-red-700 hover:text-red-900"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-zinc-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-medium">Active sessions</h2>
                    <p class="mt-1 text-sm text-zinc-500">Browsers currently signed in to your account.</p>
                </div>
                @if ($sessions->count() > 1)
                    <button
                        type="button"
                        wire:click="revokeOtherSessions"
                        wire:confirm="End every other browser session?"
                        class="rounded-md border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50"
                    >
                        End other sessions
                    </button>
                @endif
            </div>

            @if ($sessionDriver !== 'database')
                <p class="mt-4 text-sm text-amber-800">Session details are only available when <code class="rounded bg-amber-100 px-1">SESSION_DRIVER=database</code>. Current driver: {{ $sessionDriver }}.</p>
            @elseif ($sessions->isEmpty())
                <p class="mt-4 text-sm text-zinc-500">No session records found.</p>
            @else
                <div class="mt-4 space-y-3">
                    @foreach ($sessions as $session)
                        @php
                            $ipInfo = $session->ip_info;
                            $countryFlag = strlen((string) $ipInfo?->countryCode) === 2
                                ? \Spatie\Emoji\Emoji::countryFlag($ipInfo->countryCode)
                                : null;
                            $locality = implode(', ', array_filter([$ipInfo?->city, $ipInfo?->regionCode]));
                            $estimatedLocation = trim($locality.' '.($ipInfo?->zip ?? ''));
                        @endphp
                        <div
                            wire:key="session-{{ $session->id }}"
                            class="rounded-md border border-zinc-200 p-4"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium">
                                        {{ $session->parsed_user_agent->ua->family === 'Other' ? 'Unknown browser' : $session->parsed_user_agent->ua->toString() }}
                                        @if ($session->parsed_user_agent->os->family !== 'Other')
                                            on {{ $session->parsed_user_agent->os->toString() }}
                                        @endif
                                        @if ($session->isCurrent())
                                            <span class="ml-2 rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700">This device</span>
                                        @endif
                                    </p>
                                    <dl class="mt-2 space-y-1 text-sm text-zinc-600">
                                        <div class="flex gap-2">
                                            <dt class="text-zinc-500">IP</dt>
                                            <dd>
                                                {{ $session->ip_address ?: 'Unknown' }}
                                                @if ($countryFlag)
                                                    <span
                                                        title="{{ $ipInfo->countryCode }}"
                                                        aria-label="{{ $ipInfo->countryCode }}"
                                                        >{{ $countryFlag }}</span
                                                    >
                                                @endif
                                            </dd>
                                        </div>
                                        @if ($estimatedLocation !== '')
                                            <div class="flex gap-2">
                                                <dt class="text-zinc-500">Estimated location</dt>
                                                <dd>{{ $estimatedLocation }}</dd>
                                            </div>
                                        @endif
                                        <div class="flex gap-2">
                                            <dt class="text-zinc-500">Last active</dt>
                                            <dd>{{ $session->last_activity->diffForHumans() }} ({{ $session->last_activity->toDayDateTimeString() }})</dd>
                                        </div>
                                        @if ($session->user_agent)
                                            <div class="flex gap-2">
                                                <dt class="shrink-0 text-zinc-500">User agent</dt>
                                                <dd class="break-all">{{ $session->user_agent }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </div>
                                <button
                                    type="button"
                                    wire:click="revokeSession(@js($session->id))"
                                    wire:confirm="{{ $session->isCurrent() ? 'End this session? You will be signed out.' : 'End this session?' }}"
                                    class="text-sm text-red-700 hover:text-red-900"
                                >
                                    End session
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
