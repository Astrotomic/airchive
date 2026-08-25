@extends ('layouts.auth')

@section ('title', 'Register passkey — '.config('app.name'))

@section ('content')
    <div class="rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
        <h1 class="mb-2 text-2xl font-semibold">Register your passkey</h1>
        <p class="mb-6 text-sm text-gray-600">Create a passkey on this device so you can sign in without passwords.</p>

        <div class="space-y-4">
            <div>
                <label
                    for="passkey-name"
                    class="mb-1 block text-sm font-medium text-gray-700"
                    >Device name</label
                >
                <input
                    id="passkey-name"
                    type="text"
                    value="New device"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-gray-900 focus:outline-none"
                />
            </div>

            <button
                id="register-passkey"
                type="button"
                class="w-full rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
            >
                Register passkey
            </button>

            <p id="register-error" class="hidden text-sm text-red-600"></p>
            <p id="register-success" class="hidden text-sm text-green-700"></p>
        </div>
    </div>

    <script type="module">
        const button = document.getElementById('register-passkey');
        const nameInput = document.getElementById('passkey-name');
        const errorEl = document.getElementById('register-error');
        const successEl = document.getElementById('register-success');

        button?.addEventListener('click', async () => {
            errorEl.classList.add('hidden');
            successEl.classList.add('hidden');
            button.disabled = true;

            try {
                await window.Passkeys.register({ name: nameInput?.value || 'Device' });
                successEl.textContent = 'Passkey registered. Redirecting…';
                successEl.classList.remove('hidden');
                window.location.href = @json (config('passkeys.redirect'));
            } catch (error) {
                errorEl.textContent = error?.message ?? 'Passkey registration failed.';
                errorEl.classList.remove('hidden');
            } finally {
                button.disabled = false;
            }
        });
    </script>
@endsection
