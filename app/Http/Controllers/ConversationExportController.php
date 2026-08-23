<?php

namespace App\Http\Controllers;

use App\Enums\ExportFormat;
use App\Managers\Exports\ConversationExportManager;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationExportController
{
    public function __invoke(
        Request $request,
        Conversation $conversation,
        ConversationExportManager $exports,
    ): StreamedResponse {
        $format = ExportFormat::tryFrom((string) $request->query('format', ExportFormat::Markdown->value));

        abort_if($format === null, 404);

        return $this->streamResponse(
            $exports->export($conversation, $format),
            $format->contentType(),
            $this->filename($conversation, $format),
        );
    }

    private function streamResponse(string $contents, string $contentType, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($contents): void {
            file_put_contents('php://output', $contents);
        }, $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    private function filename(Conversation $conversation, ExportFormat $format): string
    {
        $slug = str($conversation->title ?: 'conversation')
            ->slug()
            ->limit(60, '')
            ->toString();

        return $slug.'.'.$format->value;
    }
}
