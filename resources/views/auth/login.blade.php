@extends ('layouts.auth')

@section ('title', 'Sign in — '.config('app.name'))

@section ('content')
    <div class="rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
        <h1 class="mb-2 text-2xl font-semibold">Sign in</h1>
        <p class="mb-6 text-sm text-gray-600">Use your email and passkey to access your archive.</p>

        <div class="space-y-4">
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
                    autocomplete="username webauthn"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-gray-900 focus:outline-none"
                    placeholder="you@example.com"
                />
            </div>

            <button
                id="passkey-login"
                type="button"
                class="w-full rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
            >
                Sign in with passkey
            </button>

            <p id="login-error" class="hidden text-sm text-red-600"></p>
        </div>
    </div>

    <script type="module">
        const button = document.getElementById('passkey-login');
        const errorEl = document.getElementById('login-error');

        button?.addEventListener('click', async () => {
            errorEl.classList.add('hidden');
            button.disabled = true;

            try {
                const response = await window.Passkeys.verify();

                if (response.redirect) {
                    window.location.href = response.redirect;
                }
            } catch (error) {
                errorEl.textContent = error?.message ?? 'Passkey sign-in failed.';
                errorEl.classList.remove('hidden');
            } finally {
                button.disabled = false;
            }
        });
    </script>
@endsection
