<?php

namespace Tests\Unit\Enums;

use App\Enums\AttachmentType;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class AttachmentTypeTest extends UnitTestCase
{
    #[DataProvider('mimeTypes')]
    public function test_it_resolves_a_type_from_a_mime_type(?string $mimeType, AttachmentType $expected): void
    {
        Assert::assertSame($expected, AttachmentType::fromMimeType($mimeType));
    }

    /** @return iterable<string, array{string|null, AttachmentType}> */
    public static function mimeTypes(): iterable
    {
        yield 'image' => ['image/png', AttachmentType::Image];
        yield 'audio' => ['audio/mpeg', AttachmentType::Audio];
        yield 'video' => ['video/mp4', AttachmentType::Video];
        yield 'other MIME type' => ['application/pdf', AttachmentType::File];
        yield 'missing MIME type' => [null, AttachmentType::File];
    }

    #[DataProvider('types')]
    public function test_it_identifies_artifact_types(
        AttachmentType $type,
        string $value,
        bool $isArtifact,
    ): void {
        Assert::assertSame($value, $type->value);
        Assert::assertSame($isArtifact, $type->isArtifact());
    }

    /** @return iterable<string, array{AttachmentType, string, bool}> */
    public static function types(): iterable
    {
        yield 'file' => [AttachmentType::File, 'file', false];
        yield 'image' => [AttachmentType::Image, 'image', false];
        yield 'audio' => [AttachmentType::Audio, 'audio', false];
        yield 'video' => [AttachmentType::Video, 'video', false];
        yield 'canvas' => [AttachmentType::Canvas, 'canvas', true];
        yield 'agent tool' => [AttachmentType::AgentTool, 'agent_tool', true];
        yield 'terminal' => [AttachmentType::Terminal, 'terminal', true];
    }
}
