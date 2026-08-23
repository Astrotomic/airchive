<?php

namespace App\Http\Controllers;

use App\Http\Responses\AttachmentFileResponse;
use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviewAttachmentController
{
    public function __invoke(
        Attachment $attachment,
        AttachmentFileResponse $response,
    ): StreamedResponse|RedirectResponse {
        return $response->preview($attachment);
    }
}
