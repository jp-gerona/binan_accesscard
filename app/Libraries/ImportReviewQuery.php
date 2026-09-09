<?php

namespace App\Libraries;

/**
 * The import review table's query: which page, how many rows, and how they are
 * narrowed. Built by FamilyImportController from the request and handed to
 * ImportReviewPresenter::page(), so the presenter shapes rows without touching
 * the request.
 *
 * Every value here arrives from a URL an operator can edit, so each is clamped
 * to a known-good value rather than trusted.
 */
final class ImportReviewQuery
{
    /** Page sizes the table offers. Anything else falls back to the first. */
    public const PER_PAGE = [25, 50, 100];

    /** Row filters. 'problems' is any active flag; the other values are exact. */
    public const SEVERITIES = ['all', 'problems', 'blocking', 'warning', 'discarded'];

    private function __construct(
        public readonly int $page,
        public readonly int $per,
        public readonly string $severity,
        public readonly array $code,
        public readonly string $q,
    ) {
    }

    /** @param array<string, mixed> $query typically $request->getGet() */
    public static function fromArray(array $query): self
    {
        $per = (int) ($query['per'] ?? 0);
        $code = $query['code'] ?? [];
        if (is_string($code)) {
            $code = explode(',', $code);
        }
        $code = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) $code),
            static fn (string $value): bool => $value !== '',
        ));

        return new self(
            max(1, (int) ($query['page'] ?? 1)),
            in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0],
            in_array((string) ($query['severity'] ?? ''), self::SEVERITIES, true)
                ? (string) $query['severity']
                : 'all',
            $code,
            trim((string) ($query['q'] ?? '')),
        );
    }

    /** Rows to skip before this page starts. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->per;
    }
}
