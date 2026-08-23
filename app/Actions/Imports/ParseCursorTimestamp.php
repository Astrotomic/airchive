<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use Carbon\Carbon;
use Throwable;

class ParseCursorTimestamp extends Action
{
    public function execute(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timezone = null;

        if (preg_match('/\s*\((UTC[+-]\d{1,2}(?::\d{2})?)\)\s*$/i', $value, $matches) === 1) {
            $timezone = $this->timezoneToOffset($matches[1]);
            $value = trim(substr($value, 0, -strlen($matches[0])));
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function timezoneToOffset(string $timezone): string
    {
        if (preg_match('/^UTC([+-])(\d{1,2})(?::(\d{2}))?$/i', $timezone, $matches) !== 1) {
            return 'UTC';
        }

        $hours = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $minutes = $matches[3] ?? '00';

        return $matches[1].$hours.':'.$minutes;
    }
}
