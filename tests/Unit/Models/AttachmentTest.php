<?php

namespace Tests\Unit\Models;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use Astrotomic\PhpunitAssertions\PathAssertions;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachmentTest extends TestCase
{
    #[DataProvider('byteSizes')]
    public function test_it_formats_its_byte_size_for_humans(?int $bytes, string $expected): void
    {
        $attachment = new Attachment(['byte_size' => $bytes]);

        Assert::assertSame($expected, $attachment->human_size);
    }

    /** @return iterable<string, array{int|null, string}> */
    public static function byteSizes(): iterable
    {
        yield 'unknown' => [null, 'Unknown size'];
        yield 'bytes' => [27, '27 B'];
        yield 'kilobytes' => [1234, '1.23 kB'];
        yield 'megabytes' => [1_000_000, '1 MB'];
    }

    #[DataProvider('categories')]
    public function test_it_resolves_its_category(array $attributes, AttachmentCategory $expected): void
    {
        $attachment = new Attachment($attributes);

        Assert::assertSame($expected, $attachment->category);
    }

    /** @return iterable<string, array{array<string, string>, AttachmentCategory}> */
    public static function categories(): iterable
    {
        yield 'artifact' => [['attachment_type' => 'canvas'], AttachmentCategory::Artifact];
        yield 'image attachment' => [['attachment_type' => 'image'], AttachmentCategory::Image];
        yield 'image MIME type' => [['mime_type' => 'image/png'], AttachmentCategory::Image];
        yield 'audio' => [['mime_type' => 'audio/mpeg'], AttachmentCategory::Audio];
        yield 'video' => [['mime_type' => 'video/mp4'], AttachmentCategory::Video];
        yield 'PDF extension' => [['filename' => 'document.pdf'], AttachmentCategory::Pdf];
        yield 'text MIME type' => [['mime_type' => 'application/json'], AttachmentCategory::Text];
        yield 'archive extension' => [['filename' => 'export.zip'], AttachmentCategory::Archive];
        yield 'document MIME type' => [['mime_type' => 'application/vnd.ms-excel'], AttachmentCategory::Document];
        yield 'generic file' => [['filename' => 'binary.dat'], AttachmentCategory::File];
    }

    public function test_categories_define_their_labels(): void
    {
        Assert::assertSame('PDF', AttachmentCategory::Pdf->label());
        Assert::assertSame('Artifact', AttachmentCategory::Artifact->label());
    }

    public function test_it_resolves_its_extension_label(): void
    {
        $attachment = new Attachment(['filename' => 'data.json']);

        PathAssertions::assertExtension('json', $attachment->filename);
        Assert::assertSame('JSON', $attachment->extension_label);
        Assert::assertSame('File', (new Attachment)->extension_label);
    }

    public function test_it_resolves_whether_its_contents_are_available(): void
    {
        $stored = new Attachment(['storage_path' => 'attachments/file.txt']);
        $external = new Attachment(['external_url' => 'https://example.com/file.txt']);

        PathAssertions::assertExtension('txt', $stored->storage_path);
        Assert::assertTrue($stored->is_available);
        UrlAssertions::assertValidLoose($external->external_url);
        Assert::assertTrue($external->is_available);
        Assert::assertFalse((new Attachment(['external_url' => 'javascript:alert(1)']))->is_available);
        Assert::assertFalse((new Attachment)->is_available);
    }
}
