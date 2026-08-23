<?php

namespace Tests\Unit\ValueObjects;

use App\Enums\AttachmentType;
use App\ValueObjects\CanonicalAttachment;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class CanonicalAttachmentTest extends UnitTestCase
{
    public function test_it_stores_attachment_data(): void
    {
        $attachment = new CanonicalAttachment(
            attachmentType: AttachmentType::Image,
            sourceAttachmentId: 'attachment-1',
            filename: 'photo.png',
            mimeType: 'image/png',
            byteSize: 1234,
            checksum: 'checksum',
            storagePath: 'attachments/photo.png',
            externalUrl: 'https://example.com/photo.png',
            sourceRef: ['asset_pointer' => 'file-service://attachment-1'],
        );

        Assert::assertSame(AttachmentType::Image, $attachment->attachmentType);
        Assert::assertSame('attachment-1', $attachment->sourceAttachmentId);
        Assert::assertSame('photo.png', $attachment->filename);
        Assert::assertSame('image/png', $attachment->mimeType);
        Assert::assertSame(1234, $attachment->byteSize);
        Assert::assertSame('checksum', $attachment->checksum);
        Assert::assertSame('attachments/photo.png', $attachment->storagePath);
        Assert::assertSame('https://example.com/photo.png', $attachment->externalUrl);
        Assert::assertSame(['asset_pointer' => 'file-service://attachment-1'], $attachment->sourceRef);
    }

    public function test_optional_attachment_data_defaults_to_null(): void
    {
        $attachment = new CanonicalAttachment(AttachmentType::File);

        Assert::assertNull($attachment->sourceAttachmentId);
        Assert::assertNull($attachment->filename);
        Assert::assertNull($attachment->mimeType);
        Assert::assertNull($attachment->byteSize);
        Assert::assertNull($attachment->checksum);
        Assert::assertNull($attachment->storagePath);
        Assert::assertNull($attachment->externalUrl);
        Assert::assertNull($attachment->sourceRef);
    }
}
