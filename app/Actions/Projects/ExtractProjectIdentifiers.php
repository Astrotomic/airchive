<?php

namespace App\Actions\Projects;

use App\Actions\Action;
use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use App\ValueObjects\ProjectIdentifier;
use Illuminate\Support\Str;

class ExtractProjectIdentifiers extends Action
{
    /**
     * @return array<int, ProjectIdentifier>
     */
    public function execute(SourcePlatform $sourcePlatform, mixed $data): array
    {
        return match ($sourcePlatform) {
            SourcePlatform::ChatGpt => $this->chatGpt($data),
            SourcePlatform::Cursor => $this->cursorWorkspace($data),
            SourcePlatform::Codex => $this->codex($data),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, ProjectIdentifier>
     */
    private function chatGpt(array $data): array
    {
        $identifiers = [];

        foreach (['conversation_template_id', 'gizmo_id', 'project_id', 'workspace_id'] as $key) {
            $value = $data[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);
            $type = match (true) {
                str_starts_with($value, 'g-p-'), $key === 'project_id' => ProjectIdentifierType::ChatGptProject,
                str_starts_with($value, 'g-'), $key === 'gizmo_id' => ProjectIdentifierType::ChatGptGpt,
                $key === 'workspace_id' => ProjectIdentifierType::ChatGptWorkspace,
                default => ProjectIdentifierType::ChatGptTemplate,
            };
            $name = match ($type) {
                ProjectIdentifierType::ChatGptProject => 'ChatGPT Project · '.substr($value, 4, 8),
                ProjectIdentifierType::ChatGptGpt => 'GPT · '.Str::limit($value, 18, ''),
                ProjectIdentifierType::ChatGptWorkspace => 'ChatGPT Workspace · '.Str::limit($value, 12, ''),
                default => 'ChatGPT Group · '.Str::limit($value, 12, ''),
            };

            $identifiers[] = new ProjectIdentifier(
                sourcePlatform: SourcePlatform::ChatGpt,
                identifierType: $type,
                sourceIdentifier: $value,
                suggestedName: $name,
                metadata: ['source_field' => $key],
            );
        }

        return $identifiers;
    }

    /** @return array<int, ProjectIdentifier> */
    private function cursorWorkspace(mixed $workspace): array
    {
        if (! is_string($workspace) || trim($workspace) === '') {
            return [];
        }

        $workspace = trim($workspace);

        return [new ProjectIdentifier(
            sourcePlatform: SourcePlatform::Cursor,
            identifierType: ProjectIdentifierType::CursorWorkspace,
            sourceIdentifier: $workspace,
            suggestedName: $workspace === 'empty-window' ? 'Cursor · Empty Window' : 'Cursor · '.$workspace,
            metadata: ['export_workspace' => $workspace],
        )];
    }

    /**
     * @return array<int, ProjectIdentifier>
     */
    private function codex(mixed $data): array
    {
        $repoIds = [];
        $walk = function (mixed $value, ?string $key = null) use (&$walk, &$repoIds): void {
            if ($key === 'repo_id' && (is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                $repoIds[trim((string) $value)] = true;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $childKey => $child) {
                $walk($child, is_string($childKey) ? $childKey : null);
            }
        };
        $walk($data);

        return array_map(static fn (string $repoId): ProjectIdentifier => new ProjectIdentifier(
            sourcePlatform: SourcePlatform::Codex,
            identifierType: ProjectIdentifierType::CodexRepository,
            sourceIdentifier: $repoId,
            suggestedName: 'Repository · '.$repoId,
        ), array_keys($repoIds));
    }
}
