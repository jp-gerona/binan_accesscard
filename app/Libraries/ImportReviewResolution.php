<?php

namespace App\Libraries;

use InvalidArgumentException;

/** Pure discarded-row state transitions for the import review. */
final class ImportReviewResolution
{
    /**
     * @param array<int, array{keptRow:int,reason:string}> $discarded
     * @return list<array>
     */
    public static function activeRows(array $rows, array $discarded): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($discarded): bool {
            return ! isset($discarded[(int) ($row['sheetRow'] ?? 0)]);
        }));
    }

    /**
     * Discards every other row in the duplicate group containing $keepRow. Selecting a
     * different keeper for an already-resolved group replaces that group's old decision.
     *
     * @param array<int, array{keptRow:int,reason:string}> $discarded
     * @param list<array{rows:list<int>,qr:string}> $duplicateGroups
     * @return array<int, array{keptRow:int,reason:string}>
     */
    public static function discardGroup(array $discarded, array $duplicateGroups, int $keepRow): array
    {
        foreach ($duplicateGroups as $group) {
            $rows = $group['rows'] ?? [];

            if (! in_array($keepRow, $rows, true)) {
                continue;
            }

            foreach ($rows as $sheetRow) {
                $sheetRow = (int) $sheetRow;
                unset($discarded[$sheetRow]);

                if ($sheetRow !== $keepRow) {
                    $discarded[$sheetRow] = ['keptRow' => $keepRow, 'reason' => 'duplicate'];
                }
            }

            return $discarded;
        }

        throw new InvalidArgumentException('The selected row is not in a duplicate candidate group.');
    }

    /**
     * @param array<int, array{keptRow:int,reason:string}> $discarded
     * @return array<int, array{keptRow:int,reason:string}>
     */
    public static function restore(array $discarded, int $sheetRow): array
    {
        $resolution = $discarded[$sheetRow] ?? null;

        if (($resolution['reason'] ?? null) !== 'duplicate') {
            unset($discarded[$sheetRow]);

            return $discarded;
        }

        foreach ($discarded as $row => $entry) {
            if (($entry['reason'] ?? null) === 'duplicate' && ($entry['keptRow'] ?? null) === $resolution['keptRow']) {
                unset($discarded[$row]);
            }
        }

        return $discarded;
    }
}
