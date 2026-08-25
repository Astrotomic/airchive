<div>
    <h1 class="mb-6 text-2xl font-semibold">Import</h1>

    <form
        wire:submit="save"
        class="mb-10 rounded-lg border border-zinc-200 bg-white p-6"
    >
        <p class="mb-4 text-sm text-zinc-600">Upload ChatGPT JSON/ZIP exports, full Cursor export ZIPs, or individual Cursor transcript JSONL files.</p>

        <input
            type="file"
            wire:model="upload"
            accept=".json,.jsonl,.zip"
            class="block w-full text-sm"
        />

        @error ('upload')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <button
            type="submit"
            class="mt-4 rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800"
            wire:loading.attr="disabled"
        >
            Upload and import
        </button>
    </form>

    <h2 class="mb-3 text-lg font-medium">Recent imports</h2>

    @if ($batches->isEmpty())
        <p class="text-sm text-zinc-500">No imports yet.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Format</th>
                        <th class="px-4 py-3">Started</th>
                        <th class="px-4 py-3">Finished</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($batches as $batch)
                        <tr class="border-t border-zinc-100">
                            <td class="px-4 py-3">{{ $batch->status->label() }}</td>
                            <td class="px-4 py-3">{{ $batch->detected_format?->label() ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $batch->started_at?->diffForHumans() ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $batch->finished_at?->diffForHumans() ?: '—' }}</td>
                        </tr>
                        @if ($batch->error_message)
                            <tr class="border-t border-zinc-100 bg-red-50">
                                <td
                                    colspan="4"
                                    class="px-4 py-3 text-red-700"
                                >
                                    {{ $batch->error_message }}
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
