<?php

namespace App\View\Components;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class AttachmentPreview extends Component
{
    public readonly AttachmentCategory $category;

    public readonly string $previewUrl;

    public readonly string $downloadUrl;

    public readonly bool $isImage;

    public readonly bool $isVideo;

    public readonly bool $isAudio;

    public function __construct(public readonly Attachment $attachment)
    {
        $this->category = $attachment->category;
        $this->previewUrl = route('library.preview', $attachment);
        $this->downloadUrl = route('library.download', $attachment);
        $this->isImage = $this->category === AttachmentCategory::Image;
        $this->isVideo = $this->category === AttachmentCategory::Video;
        $this->isAudio = $this->category === AttachmentCategory::Audio;
    }

    public function render(): View
    {
        return view('components.attachment-preview');
    }
}
