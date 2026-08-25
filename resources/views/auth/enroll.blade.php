@extends ('layouts.auth')

@section ('title', 'Enroll device — '.config('app.name'))

@section ('content')
    <div class="rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
        <h1 class="mb-2 text-2xl font-semibold">Pair a new device</h1>
        <p class="mb-6 text-sm text-gray-600">Confirm your identity with your authenticator app to register a passkey on this device.</p>

        <form
            method="POST"
            class="space-y-4"
        >
            @csrf

            <div>
                <label
                    for="email"
                    class="mb-1 block text-sm font-medium text-gray-700"
                    >Email</label
                >
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email', $user->email) }}"
                    required
                    autocomplete="email"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 @error('email') border-red-500 @enderror"
                />
                @error ('email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label
                    for="code"
                    class="mb-1 block text-sm font-medium text-gray-700"
                    >Authentication code</label
                >
                <input
                    id="code"
                    type="text"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    required
                    autofocus
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 @error('code') border-red-500 @enderror"
                />
                @error ('code')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800"
            >
                Continue
            </button>
        </form>
    </div>
@endsection
