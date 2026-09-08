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
 * The reconciler owns the policy, never the query builder. Folder enumeration
 * and per-file validation come from FamilyMediaStorage, and every family_media
 * or qr_control read/write goes through FamilyMediaModel and QrControlModel.
 *
 * The scan is kept cheap for a large folder: a known file whose byte size and
 * stored mtime still match is reused without opening its content, and image
 * bytes are read only for new or changed files. An invalid source is counted
 * but never recorded, so staff can correct the folder without the application
 * deleting evidence.
 */
class FamilyMediaReconciler
{
    /** Files processed per progress checkpoint flush. */
    private int $batch = 25;

    private FamilyMediaStorage $storage;
    private FamilyMediaModel $media;
    private AuditTrailsModel $audit;
    private QrControlModel $qrControl;
    private string $root = '';

    public function __construct(
        ?FamilyMediaStorage $storage = null,
        ?FamilyMediaModel $media = null,
        ?string $root = null,
    ) {
        $this->storage = $storage ?? new FamilyMediaStorage();
        $this->media = $media ?? new FamilyMediaModel();
        $this->audit = new AuditTrailsModel();
        $this->qrControl = new QrControlModel();
        $this->root = $root ?? (string) config('FamilyMediaSettings')->root;
    }

    /**
     * Scans the managed folder once and updates the registry.
     *
     * A root or database failure throws so the generic worker records a
     * retry/failure instead of treating the folder as empty. Individual invalid
     * files are counted and never recorded.
     *
     * @return array{seen:int,linked:int,pending:int,invalid:int,missing:int,replaced:int}
     */
    public function run(JobReporter $reporter): array
    {
        $counts = $this->emptyCounts();

        if ($this->root === '') {
            $reporter->setTotal(0);

            return $counts;
        }

        $filenames = $this->filenames();
        $total = count($filenames);
        $reporter->setTotal($total);

        $known = $this->knownByFilename();
        $seen = [];
        $done = 0;

        foreach ($filenames as $filename) {
            $row = $known[$filename] ?? null;

            if ($row !== null && $this->unchanged($row, $filename)) {
                // Known and unchanged: never open the file. A pending/missing row
                // gets re-evaluated anyway, because its family may have arrived.
                $seen[$filename] = true;

                if ((string) $row['state'] !== FamilyMediaModel::STATE_LINKED) {
                    $this->resolve($this->fromRow($row), $row, $counts);
                }
            } else {
                $inspection = $this->storage->inspect($filename);

                if ($inspection === null) {
                    $counts['invalid']++;
                } else {
                    $seen[$inspection['source_filename']] = true;
                    $this->resolve($inspection, $row ?? null, $counts);
                }
            }

            $done++;

            if ($done % $this->batch === 0) {
                $reporter->checkpoint($done, $done, $counts);
                $reporter->pause();
            }
        }

        $counts['seen'] = count($seen);

        $missing = $this->media->markMissingExcept(array_keys($seen));

        foreach ($missing as $row) {
            if ((int) ($row['headID'] ?? 0) > 0) {
                $this->audit(
                    'MEDIA_REMOVED',
                    (int) $row['headID'],
                    (string) ($row['kind'] ?? ''),
                    (string) ($row['source_filename'] ?? ''),
                );
            }
        }

        $counts['missing'] = count($missing);
        $reporter->checkpoint($total, $total, $counts);

        return $counts;
    }

    /**
     * Resolves one valid inspection into a registry state. A known source
     * filename always wins over the current control lookup, so an already-linked
     * file keeps its head even after the mapping is retired.
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
                $counts['replaced']++;
                $this->audit('MEDIA_REPLACED', $headId, $kind, $filename);
            }

            return;
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
                $counts['replaced']++;
                $this->audit('MEDIA_REPLACED', $headId, $kind, $filename);
            }

            return;
        }

        if ($ownId === 0) {
            $ownId = $this->media->upsertPending($this->pendingFile($file));
        }

        if ($ownId > 0 && $this->media->link($ownId, $headId, $this->urlFor($headId, $kind), $this->fingerprint($file))) {
            $counts['linked']++;
            $this->audit('MEDIA_ADDED', $headId, $kind, $filename);
        } else {
            // The mapped member is not a family head, or the link was refused: stay pending.
            $counts['pending']++;
        }
    }

    /** @param array<string, mixed> $row True when the stored fingerprint still matches the file. */
    private function unchanged(array $row, string $filename): bool
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        $size = @filesize($path);
        $mtime = @filemtime($path);

        if ($size === false || $mtime === false) {
            return false;
        }

        return (int) ($row['byte_size'] ?? 0) === $size
            && (string) ($row['source_modified_at'] ?? '') === date('Y-m-d H:i:s', $mtime);
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

    /** @return string[] Direct children of the media root, dot entries excluded. */
    private function filenames(): array
    {
        if ($this->root === '' || ! is_dir($this->root)) {
            throw new RuntimeException('The family media root is not an accessible directory: ' . $this->root);
        }

        $items = @scandir($this->root);

        if ($items === false) {
            throw new RuntimeException('The family media root could not be listed: ' . $this->root);
        }

        return array_values(array_filter(
            $items,
            static fn (string $f): bool => $f !== '.' && $f !== '..',
        ));
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