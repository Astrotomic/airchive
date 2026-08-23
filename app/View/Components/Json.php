<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Json extends Component
{
    public readonly string $json;

    public function __construct(mixed $value)
    {
        $payload = $value;

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            } else {
                $this->json = $payload;

                return;
            }
        }

        $this->json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '';
    }

    public function render(): View
    {
        return view('components.json');
    }
}
