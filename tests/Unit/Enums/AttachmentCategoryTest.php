<?php

namespace Tests\Unit\Enums;

use App\Enums\AttachmentCategory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class AttachmentCategoryTest extends UnitTestCase
{
    #[DataProvider('categories')]
    public function test_it_defines_values_and_labels(
        AttachmentCategory $category,
        string $value,
        string $label,
    ): void {
        Assert::assertSame($value, $category->value);
        Assert::assertSame($label, $category->label());
    }

    /** @return iterable<string, array{AttachmentCategory, string, string}> */
    public static function categories(): iterable
    {
        yield 'image' => [AttachmentCategory::Image, 'image', 'Image'];
        yield 'audio' => [AttachmentCategory::Audio, 'audio', 'Audio'];
        yield 'video' => [AttachmentCategory::Video, 'video', 'Video'];
        yield 'PDF' => [AttachmentCategory::Pdf, 'pdf', 'PDF'];
        yield 'text' => [AttachmentCategory::Text, 'text', 'Text'];
        yield 'archive' => [AttachmentCategory::Archive, 'archive', 'Archive'];
        yield 'document' => [AttachmentCategory::Document, 'document', 'Document'];
        yield 'artifact' => [AttachmentCategory::Artifact, 'artifact', 'Artifact'];
        yield 'file' => [AttachmentCategory::File, 'file', 'File'];
    }
}
