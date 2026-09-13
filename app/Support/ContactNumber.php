<?php

namespace App\Support;

/**
 * Normalizes supplied contact values for the import review workflow.
 */
final class ContactNumber
{
    /**
     * @return array{value:?string, supplied:bool, valid:bool}
     */
    public static function parse(mixed $value): array
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return ['value' => null, 'supplied' => false, 'valid' => true];
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';
        $valid = preg_match('/^(?:09\d{9}|(?:049)?\d{7,8})$/', $digits) === 1;

        return ['value' => $valid ? $digits : null, 'supplied' => true, 'valid' => $valid];
    }
}
