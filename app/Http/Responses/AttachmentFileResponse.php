<?php

namespace App\Http\Responses;

use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentFileResponse
{
    public function preview(Attachment $attachment): StreamedResponse|RedirectResponse
    {
        if ($attachment->storage_path !== null && Storage::exists($attachment->storage_path)) {
            return Storage::response(
                $attachment->storage_path,
                $this->filename($attachment),
                $this->headers($attachment),
            );
        }

        return $this->externalResponse($attachment);
    }

    public function download(Attachment $attachment): StreamedResponse|RedirectResponse
    {
        if ($attachment->storage_path !== null && Storage::exists($attachment->storage_path)) {
            return Storage::download(
                $attachment->storage_path,
                $this->filename($attachment),
                $this->headers($attachment),
            );
        }

        return $this->externalResponse($attachment);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Attachment $attachment): array
    {
        return [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];
    }

    private function filename(Attachment $attachment): string
    {
        $filename = str_replace('\\', '/', $attachment->filename ?? '');
    
        if (($position = strrpos($filename, '/')) !== false) {
            $filename = substr($filename, $position + 1);
        }
    
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? '';
    
        return $filename !== '' ? $filename : 'attachment-'.$attachment->id;
    }

    private function externalResponse(Attachment $attachment): RedirectResponse
    {
        if (in_array(parse_url($attachment->external_url ?? '', PHP_URL_SCHEME), ['http', 'https'], true)) {
            return redirect()->away($attachment->external_url);
        }

        abort(404, 'Attachment content is not available.');
    }
}
