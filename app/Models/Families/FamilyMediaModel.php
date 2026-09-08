<?php

namespace App\Models\Families;

use CodeIgniter\Model;

/** Owns registry queries for photos and signatures discovered outside the application. */
class FamilyMediaModel extends Model
{
    public const KIND_PHOTO = 'photo';
    public const KIND_SIGNATURE = 'signature';

    public const STATE_PENDING = 'pending';
    public const STATE_LINKED = 'linked';
    public const STATE_INVALID = 'invalid';
    public const STATE_MISSING = 'missing';

    protected $table = 'family_media';
    protected $primaryKey = 'mediaID';
    protected $returnType = 'array';
    protected $allowedFields = [
        'headID',
        'kind',
        'source_control_no',
        'source_filename',
        'media_url',
        'content_sha256',
        'byte_size',
        'source_modified_at',
        'state',
        'last_seen_at',
    ];
    protected $useTimestamps = false;

    /** Returns the current linked media rows for one family head. */
    public function findLinkedForHead(int $headId): array
    {
        if ($headId <= 0) {
            return [];
        }

        return $this->where('headID', $headId)
            ->where('state', self::STATE_LINKED)
            ->orderBy('kind', 'ASC')
            ->findAll();
    }

    /** Returns the current linked media row of one kind for a family head. */
    public function findLinked(int $headId, string $kind): ?array
    {
        if ($headId <= 0 || ! $this->isKind($kind)) {
            return null;
        }

        return $this->where('headID', $headId)
            ->where('kind', $kind)
            ->where('state', self::STATE_LINKED)
            ->first();
    }

    /** Returns the registry row for a source filename, if the source has been seen. */
    public function findBySourceFilename(string $filename): ?array
    {
        if ($filename === '') {
            return null;
        }

        return $this->where('source_filename', $filename)->first();
    }

    /**
     * Inserts a newly discovered source file, or refreshes its existing registry row.
     *
     * @param array<string, mixed> $file
     */
    public function upsertPending(array $file): int
    {
        $filename = $file['source_filename'] ?? null;
        $kind = $file['kind'] ?? null;
        $controlNo = $file['source_control_no'] ?? null;

        if (! is_string($filename) || $filename === '' || ! is_string($kind) || ! $this->isKind($kind)
            || ! is_numeric($controlNo) || (int) $controlNo <= 0) {
            return 0;
        }

        $payload = $this->payload($file);
        // A source scan may not bypass link()'s self-referencing-head check.
        unset($payload['headID']);
        if (isset($payload['state']) && ! $this->isState((string) $payload['state'])) {
            return 0;
        }
        $payload['kind'] = $kind;
        $payload['source_filename'] = $filename;
        $payload['source_control_no'] = (int) $controlNo;
        $payload['last_seen_at'] = date('Y-m-d H:i:s');

        $existing = $this->findBySourceFilename($filename);
        if ($existing !== null) {
            if (! $this->update((int) $existing['mediaID'], $payload)) {
                return 0;
            }

            return (int) $existing['mediaID'];
        }

        $payload['state'] ??= self::STATE_PENDING;
        if (! $this->isState((string) $payload['state'])) {
            return 0;
        }

        if (! $this->insert($payload)) {
            return 0;
        }

        return (int) $this->getInsertID();
    }

    /**
     * Links a registry row to a family head after proving the member is its own head.
     *
     * @param array<string, mixed> $fingerprint
     */
    public function link(int $mediaId, int $headId, string $url, array $fingerprint): bool
    {
        if ($mediaId <= 0 || $headId <= 0 || ! $this->isHead($headId)) {
            return false;
        }

        $media = $this->find($mediaId);
        if ($media === null || ! $this->isKind((string) $media['kind'])) {
            return false;
        }

        $other = $this->where('headID', $headId)
            ->where('kind', $media['kind'])
            ->where('mediaID !=', $mediaId)
            ->first();
        if ($other !== null) {
            return false;
        }

        $payload = [
            'headID' => $headId,
            'media_url' => $url,
            'state' => self::STATE_LINKED,
        ];
        foreach (['content_sha256', 'byte_size', 'source_modified_at'] as $field) {
            if (array_key_exists($field, $fingerprint)) {
                $payload[$field] = $fingerprint[$field];
            }
        }

        return $this->update($mediaId, $payload);
    }

    /**
     * Replaces a head's current file of one kind, retaining the displaced source row as pending.
     *
     * @param array<string, mixed> $file
     */
    public function replaceForHead(int $headId, string $kind, array $file): int
    {
        $filename = $file['source_filename'] ?? null;
        if ($headId <= 0 || ! $this->isKind($kind) || ! is_string($filename) || $filename === '') {
            return 0;
        }

        $source = $this->findBySourceFilename($filename);
        if ($source !== null && ((string) $source['kind'] !== $kind
            || ($source['headID'] !== null && (int) $source['headID'] !== $headId))) {
            return 0;
        }

        $this->db->transStart();

        $mediaId = $this->upsertPending(array_merge($file, ['kind' => $kind]));
        if ($mediaId === 0) {
            $this->db->transRollback();

            return 0;
        }

        $current = $this->where('headID', $headId)
            ->where('kind', $kind)
            ->where('mediaID !=', $mediaId)
            ->first();
        if ($current !== null && ! $this->update((int) $current['mediaID'], [
            'headID' => null,
            'state' => self::STATE_PENDING,
        ])) {
            $this->db->transRollback();

            return 0;
        }

        $fingerprint = [];
        foreach (['content_sha256', 'byte_size', 'source_modified_at'] as $field) {
            if (array_key_exists($field, $file)) {
                $fingerprint[$field] = $file[$field];
            }
        }
        $linked = $this->link($mediaId, $headId, (string) ($file['media_url'] ?? ''), $fingerprint);

        $this->db->transComplete();

        return $linked && $this->db->transStatus() ? $mediaId : 0;
    }

    /**
     * Marks registry rows absent from the latest source scan as missing.
     *
     * @param string[] $seenFilenames
     * @return array<int, array<string, mixed>> rows newly marked missing
     */
    public function markMissingExcept(array $seenFilenames): array
    {
        $seen = array_values(array_unique(array_filter(
            $seenFilenames,
            static fn (mixed $filename): bool => is_string($filename) && $filename !== '',
        )));

        $builder = $this->db->table($this->table)->where('state !=', self::STATE_MISSING);
        if ($seen !== []) {
            $builder->whereNotIn('source_filename', $seen);
        }

        $rows = $builder->get()->getResultArray();
        foreach ($rows as $row) {
            $this->update((int) $row['mediaID'], ['state' => self::STATE_MISSING]);
        }

        return $rows;
    }

    /** True when an ID names a self-referencing member row, the schema's definition of a head. */
    private function isHead(int $headId): bool
    {
        return $this->db->table('member')
            ->where('memberID', $headId)
            ->where('headID', $headId)
            ->countAllResults() === 1;
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    private function payload(array $file): array
    {
        return array_intersect_key($file, array_flip($this->allowedFields));
    }

    private function isKind(string $kind): bool
    {
        return in_array($kind, [self::KIND_PHOTO, self::KIND_SIGNATURE], true);
    }

    private function isState(string $state): bool
    {
        return in_array($state, [
            self::STATE_PENDING,
            self::STATE_LINKED,
            self::STATE_INVALID,
            self::STATE_MISSING,
        ], true);
    }
}
