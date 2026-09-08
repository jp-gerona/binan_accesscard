<?php

namespace App\Libraries;

use App\Jobs\JobReporter;
use App\Models\Audit\AuditTrailsModel;
use App\Models\Families\FamilyMediaModel;
use App\Models\Scanner\QrControlModel;
use RuntimeException;

/**
 * Background policy that reconciles the office-managed media folder into the
 * family_media registry.
 *
 * The folder has two zones. `inbox/` holds the office's drops, named by the
 * current control number; each accepted file is MOVED into its permanent
 * `store/{shard}/{headID}/` folder the moment it links to a family, so exactly
 * one copy exists and the inbox stays small. The store is then verified every
 * run: an office edit directly in a family's store folder is re-validated and
 * fingerprinted, a deleted store file marks the row missing, and a restored or
 * corrected file links again through the row's retained head, which survives
 * card replacement because retired control numbers never resolve again.
 *
 * The reconciler owns the policy, never the query builder. Folder enumeration
 * and per-file validation come from FamilyMediaStorage, and every family_media
 * or qr_control read/write goes through FamilyMediaModel and QrControlModel.
 */
class FamilyMediaReconciler
{
    /** Files processed per progress checkpoint flush. */
    private int $batch = 25;

    private FamilyMediaStorage $storage;
    private FamilyMediaModel $media;
    private AuditTrailsModel $audit;
    private QrControlModel $qrControl;

    public function __construct(
        ?FamilyMediaStorage $storage = null,
        ?FamilyMediaModel $media = null,
    ) {
        $this->storage = $storage ?? new FamilyMediaStorage();
        $this->media = $media ?? new FamilyMediaModel();
        $this->audit = new AuditTrailsModel();
        $this->qrControl = new QrControlModel();
    }

    /**
     * Scans the inbox once, moves accepted files into the store, and verifies
     * every attached registry row against its stored file.
     *
     * A root or database failure throws so the generic worker records a
     * retry/failure instead of treating the folder as empty. Individual invalid
     * files are counted and left in the inbox for the office to correct.
     *
     * @return array{seen:int,linked:int,pending:int,invalid:int,missing:int,replaced:int}
     */
    public function run(JobReporter $reporter): array
    {
        $counts = $this->emptyCounts();

        if ($this->storage->root() === null) {
            // Missing, unconfigured, or project-confined root: fail loudly so the
            // generic worker records a retry/failure instead of silently
            // succeeding on an empty folder.
            throw new RuntimeException('The family media root is not an accessible directory outside the project.');
        }

        // A deployment upgrading from the flat layout may still hold accepted
        // files directly in the root; adopt them through the normal intake path.
        $this->storage->adoptLegacyRootFiles();

        $filenames = $this->storage->candidateFilenames();
        $total = count($filenames);
        $reporter->setTotal($total);

        $known = $this->knownByFilename();
        $inboxSeen = [];
        $done = 0;

        foreach ($filenames as $filename) {
            $row = $known[$filename] ?? null;
            $inboxSeen[$filename] = true;

            if ($row !== null && $this->unchanged($row, $filename)) {
                if ((string) $row['state'] === FamilyMediaModel::STATE_LINKED && (int) ($row['headID'] ?? 0) > 0) {
                    // Already linked with matching bytes: the only reason its
                    // file is still in the inbox is a move that failed last run
                    // (or an office copy). File it into the store and move on;
                    // the store verification pass handles everything else.
                    $this->storage->moveToStore($filename, (int) $row['headID'], (string) $row['kind']);
                } else {
                    // A pending, invalid, or missing row gets re-evaluated,
                    // because its family may have arrived or its file returned.
                    $this->resolve($this->fromRow($row), $row, $counts);
                }
            } else {
                $inspection = $this->storage->inspect($filename);

                if ($inspection === null) {
                    $counts['invalid']++;
                } else {
                    $this->resolve($inspection, $row ?? null, $counts);
                }
            }

            $done++;

            if ($done % $this->batch === 0) {
                $reporter->checkpoint($done, $done, $counts);
                $reporter->pause();
            }
        }

        $counts['seen'] = $total;

        // A never-linked row whose inbox file was removed is dead: the office
        // deleted an unaccepted drop. Nothing was ever served, so no audit.
        foreach ($known as $filename => $row) {
            if (! isset($inboxSeen[$filename])
                && in_array((string) $row['state'], [FamilyMediaModel::STATE_PENDING, FamilyMediaModel::STATE_INVALID], true)
                && (int) ($row['headID'] ?? 0) === 0) {
                $this->media->deleteRow((int) $row['mediaID']);
            }
        }

        $this->verifyStore($counts);

        $reporter->checkpoint($total, $total, $counts);

        return $counts;
    }

    /**
     * Resolves specific newly uploaded files immediately, bypassing the full inbox
     * scan and the store verification pass. Called during data entry/edit so the
     * UI can update without waiting for the cron job.
     *
     * @param list<string> $filenames
     * @return array<string, int> The resulting counts
     */
    public function resolveInboxFiles(array $filenames): array
    {
        $counts = $this->emptyCounts();

        if ($this->storage->root() === null) {
            return $counts;
        }

        $known = $this->knownByFilename();

        foreach ($filenames as $filename) {
            $row = $known[$filename] ?? null;

            if ($row !== null && $this->unchanged($row, $filename)) {
                if ((string) $row['state'] === FamilyMediaModel::STATE_LINKED && (int) ($row['headID'] ?? 0) > 0) {
                    $this->storage->moveToStore($filename, (int) $row['headID'], (string) $row['kind']);
                } else {
                    $this->resolve($this->fromRow($row), $row, $counts);
                }
            } else {
                $inspection = $this->storage->inspect($filename);

                if ($inspection === null) {
                    $counts['invalid']++;
                } else {
                    $this->resolve($inspection, $row ?? null, $counts);
                }
            }
        }

        return $counts;
    }

    /**
     * Reconciles one inbox file into the registry. A known source filename
     * always wins over the current control lookup, so an already-linked file
     * keeps its head even after the mapping is retired. Every successful link
     * or replacement moves the file out of the inbox into the store.
     *
     * @param array<string, mixed>      $file
     * @param array<string, mixed>|null $row
     * @param array<string, int>        $counts
     */
    private function resolve(array $file, ?array $row, array &$counts): void
    {
        $filename = (string) $file['source_filename'];
        $kind = (string) $file['kind'];
        $controlNo = (int) ($file['source_control_no'] ?? 0);

        if ($row !== null && (string) $row['state'] === FamilyMediaModel::STATE_LINKED) {
            $headId = (int) ($row['headID'] ?? 0);
            $mediaId = (int) ($row['mediaID'] ?? 0);

            if ($headId > 0 && $mediaId > 0 && $this->media->link(
                $mediaId,
                $headId,
                (string) ($row['media_url'] ?? ''),
                $this->fingerprint($file),
            )) {
                $this->storage->moveToStore($filename, $headId, $kind);
                $counts['replaced']++;
                $this->audit('MEDIA_REPLACED', $headId, $kind, $filename);
            }

            return;
        }

        // A row marked missing keeps its headID so an already-linked file stays
        // linked to its original head. When such a file reappears, re-link to the
        // retained head before the current control lookup, which may no longer
        // exist or may map the number elsewhere. The model link path re-validates
        // that the member is still a head; when the head is gone, fall through to
        // the control lookup exactly as an unknown file would.
        if ($row !== null && (string) $row['state'] === FamilyMediaModel::STATE_MISSING
            && (int) ($row['headID'] ?? 0) > 0) {
            $retainedHeadId = (int) $row['headID'];

            if ($this->media->link(
                (int) ($row['mediaID'] ?? 0),
                $retainedHeadId,
                $this->urlFor($retainedHeadId, $kind),
                $this->fingerprint($file),
            )) {
                $this->storage->moveToStore($filename, $retainedHeadId, $kind);
                $counts['linked']++;
                $this->audit('MEDIA_ADDED', $retainedHeadId, $kind, $filename);

                return;
            }
        }

        $headId = $this->qrControl->headForControl($controlNo);

        if ($headId === null) {
            $this->media->upsertPending($this->pendingFile($file));
            $counts['pending']++;

            return;
        }

        $ownId = $row !== null ? (int) ($row['mediaID'] ?? 0) : 0;
        $current = $this->media->findLinked($headId, $kind);

        // Another source already fills the head's one slot of this kind: displace it.
        if ($current !== null && (int) ($current['mediaID'] ?? 0) !== $ownId) {
            $mediaId = $this->media->replaceForHead(
                $headId,
                $kind,
                $this->fileForModel($file, $this->urlFor($headId, $kind)),
            );

            if ($mediaId > 0) {
                $this->storage->moveToStore($filename, $headId, $kind);
                $counts['replaced']++;
                $this->audit('MEDIA_REPLACED', $headId, $kind, $filename);
            }

            return;
        }

        if ($ownId === 0) {
            $ownId = $this->media->upsertPending($this->pendingFile($file));
        }

        if ($ownId > 0 && $this->media->link($ownId, $headId, $this->urlFor($headId, $kind), $this->fingerprint($file))) {
            $this->storage->moveToStore($filename, $headId, $kind);
            $counts['linked']++;
            $this->audit('MEDIA_ADDED', $headId, $kind, $filename);
        } else {
            // The mapped member is not a family head, or the link was refused: stay pending.
            $counts['pending']++;
        }
    }

    /**
     * Verifies every attached registry row against its stored file, so office
     * edits made directly in a family's store folder are picked up without a
     * re-drop: a changed file is re-validated and fingerprinted, a deleted file
     * marks the row missing, and a restored or corrected file links again.
     *
     * @param array<string, int> $counts
     */
    private function verifyStore(array &$counts): void
    {
        foreach ($this->media->attachedRows() as $row) {
            $mediaId = (int) ($row['mediaID'] ?? 0);
            $headId = (int) ($row['headID'] ?? 0);
            $kind = (string) ($row['kind'] ?? '');
            $filename = (string) ($row['source_filename'] ?? '');
            $state = (string) ($row['state'] ?? '');

            if ($mediaId <= 0 || $headId <= 0) {
                continue;
            }

            $stat = $this->storage->storedMetadata($headId, $kind);

            if ($stat === null) {
                if ($state === FamilyMediaModel::STATE_LINKED) {
                    // The family's file was deleted from the store: stop serving
                    // it and say so on the audit page. headID is retained.
                    $this->media->markMissing($mediaId);
                    $counts['missing']++;
                    $this->audit('MEDIA_REMOVED', $headId, $kind, $filename);
                } elseif ($state === FamilyMediaModel::STATE_INVALID) {
                    $this->media->markMissing($mediaId);
                }

                continue;
            }

            $unchanged = (int) ($row['byte_size'] ?? 0) === $stat['byte_size']
                && (string) ($row['source_modified_at'] ?? '') === $stat['source_modified_at'];

            if ($unchanged && $state === FamilyMediaModel::STATE_LINKED) {
                $this->media->touchSeen($mediaId);

                continue;
            }

            // A changed file, a restored missing file, or a corrected invalid
            // one: re-validate the real bytes before anything is served.
            $inspection = $this->storage->inspectStored($headId, $kind, $filename);

            if ($inspection === null) {
                if ($state === FamilyMediaModel::STATE_LINKED) {
                    // The office replaced a served file with bytes that no longer
                    // pass validation: stop serving it but keep the attachment,
                    // so correcting the file in place links it again.
                    $this->media->markInvalid($mediaId);
                    $counts['invalid']++;
                }

                continue;
            }

            if ($this->media->link($mediaId, $headId, $this->urlFor($headId, $kind), $this->fingerprint($inspection))) {
                $this->media->touchSeen($mediaId);

                if ($state === FamilyMediaModel::STATE_LINKED) {
                    $counts['replaced']++;
                    $this->audit('MEDIA_REPLACED', $headId, $kind, $filename);
                } else {
                    // Restored after deletion, or corrected after an invalid
                    // edit: the family has this media again.
                    $counts['linked']++;
                    $this->audit('MEDIA_ADDED', $headId, $kind, $filename);
                }
            }
        }
    }

    /** @param array<string, mixed> $row True when the stored fingerprint still matches the file. */
    private function unchanged(array $row, string $filename): bool
    {
        $stat = $this->storage->metadata($filename);
        if ($stat === null) {
            return false;
        }

        return (int) ($row['byte_size'] ?? 0) === $stat['byte_size']
            && (string) ($row['source_modified_at'] ?? '') === $stat['source_modified_at'];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function fromRow(array $row): array
    {
        return [
            'source_control_no' => (int) ($row['source_control_no'] ?? 0),
            'source_filename'   => (string) ($row['source_filename'] ?? ''),
            'kind'              => (string) ($row['kind'] ?? ''),
            'content_sha256'    => (string) ($row['content_sha256'] ?? ''),
            'byte_size'         => (int) ($row['byte_size'] ?? 0),
            'source_modified_at' => (string) ($row['source_modified_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    private function pendingFile(array $file): array
    {
        return [
            'source_control_no' => (int) ($file['source_control_no'] ?? 0),
            'source_filename'   => (string) $file['source_filename'],
            'kind'              => (string) $file['kind'],
            'content_sha256'    => $file['content_sha256'] ?? null,
            'byte_size'         => $file['byte_size'] ?? null,
            'source_modified_at' => $file['source_modified_at'] ?? null,
            'state'             => FamilyMediaModel::STATE_PENDING,
        ];
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    private function fileForModel(array $file, string $url): array
    {
        return $this->pendingFile($file) + ['media_url' => $url];
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    private function fingerprint(array $file): array
    {
        return [
            'content_sha256'     => $file['content_sha256'] ?? null,
            'byte_size'          => $file['byte_size'] ?? null,
            'source_modified_at' => $file['source_modified_at'] ?? null,
        ];
    }

    private function urlFor(int $headId, string $kind): string
    {
        return 'records/' . $headId . '/media/' . $kind;
    }

    /** @return array<string, array<string, mixed>> Registry rows keyed by source filename. */
    private function knownByFilename(): array
    {
        $known = [];

        foreach ($this->media->findAll() as $row) {
            $known[(string) ($row['source_filename'] ?? '')] = $row;
        }

        return $known;
    }

    /** @return array{seen:int,linked:int,pending:int,invalid:int,missing:int,replaced:int} */
    private function emptyCounts(): array
    {
        return [
            'seen'     => 0,
            'linked'   => 0,
            'pending'  => 0,
            'invalid'  => 0,
            'missing'  => 0,
            'replaced' => 0,
        ];
    }

    /** Writes the one family audit row every linked media mutation requires. */
    private function audit(string $action, int $memberId, string $kind, string $filename): void
    {
        if ($memberId <= 0 || ! $this->audit->hasTable()) {
            return;
        }

        $verb = match ($action) {
            'MEDIA_ADDED'    => 'added',
            'MEDIA_REPLACED' => 'replaced',
            'MEDIA_REMOVED'  => 'removed',
            default          => strtolower(str_replace('_', ' ', $action)),
        };

        $this->audit->logAction(
            0,
            $memberId,
            $action,
            'Family media ' . $kind . ' ' . $verb . ' for ' . $filename,
            null,
            null,
            'Media file: ' . $kind . '/' . $filename,
        );
    }
}
