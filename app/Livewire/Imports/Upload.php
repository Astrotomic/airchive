<?php

namespace App\Livewire\Imports;

use App\Actions\Imports\DetectImportFormat;
use App\Enums\ImportBatchStatus;
use App\Jobs\ImportConversationJob;
use App\Models\ImportBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app', ['title' => 'Import'])]
class Upload extends Component
{
    use WithFileUploads;

    public TemporaryUploadedFile|null $upload = null;

    public function save(): void
    {
        $this->validate([
            'upload' => [
                'required',
                'file',
                'max:'.Config::integer('imports.max_upload_kilobytes'),
                'extensions:json,jsonl,zip,txt',
            ],
        ], [
            'upload.extensions' => 'Upload a ChatGPT or Cursor export file (.json, .jsonl, or .zip).',
        ]);

        $originalName = $this->upload->getClientOriginalName();
        $path = $this->upload->store('imports');

        try {
            $format = DetectImportFormat::make()->execute(Storage::path($path), $originalName);
        } catch (InvalidArgumentException $exception) {
            Storage::delete($path);
            $this->addError('upload', $exception->getMessage());

            return;
        }

        $batch = ImportBatch::query()->create([
            'user_id' => Auth::id(),
            'status' => ImportBatchStatus::Pending,
            'file_path' => $path,
            'detected_format' => $format,
        ]);

        Queue::push(new ImportConversationJob($batch->id));

        $this->reset('upload');
        Session::flash('status', 'Import queued. Refresh this page to check progress.');
    }

    public function render(): View
    {
        return view('livewire.imports.upload', [
            'batches' => ImportBatch::query()
                ->where('user_id', Auth::id())
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
