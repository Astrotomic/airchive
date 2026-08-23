@extends('layouts.auth')

@section('title', 'Two-factor authentication — '.config('app.name'))

@section('content')
    <div class="bg-white shadow-sm rounded-lg p-8 border border-gray-200">
        <h1 class="text-2xl font-semibold mb-2">Two-factor authentication</h1>
        <p class="text-sm text-gray-600 mb-6">Enter the code from your authenticator app to continue.</p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Authentication code</label>
                <input
                    id="code"
                    type="text"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    autofocus
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 @error('code') border-red-500 @enderror"
                >
                @error('code')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800"
            >
                Verify
            </button>
        </form>

        <details class="mt-6">
            <summary class="text-sm text-gray-600 cursor-pointer">Use a recovery code</summary>
            <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="recovery_code" class="block text-sm font-medium text-gray-700 mb-1">Recovery code</label>
                    <input
                        id="recovery_code"
                        type="text"
                        name="recovery_code"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900"
                    >
                </div>
                <button
                    type="submit"
                    class="w-full rounded-md border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-900 hover:bg-gray-50"
                >
                    Use recovery code
                </button>
            </form>
        </details>
    </div>
@endsection
