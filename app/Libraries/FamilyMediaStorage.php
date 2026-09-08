<?php

namespace App\Libraries;

use App\Models\Families\FamilyMediaModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\FamilyMediaSettings;
use InvalidArgumentException;
use RuntimeException;

/**
 * The fail-closed boundary around the office-managed, private family-media directory.
 *
 * The root holds two zones. `inbox/` is the office drop zone: canonical
 * control-number filenames land there and nothing else. `store/NN/{headID}/`
 * is the system-owned archive the reconciler moves accepted files into, one
 * folder per family head for the life of the family, sharded by the first
 * digits of the head ID so no directory ever holds a six-figure entry count.
 *
 * Files are deliberately never accepted from a project directory: the configured
 * root must resolve to an existing directory outside FCPATH, WRITEPATH, and
 * ROOTPATH.
 */
class FamilyMediaStorage
{
    private const FILENAME_PATTERN = '/^(?<control>\d{6,})\.(?<kind>photo|signature)\.(?<extension>jpg|png)$/';

    private ?string $root;
    private ?string $inbox = null;
    private ?string $store = null;

    public function __construct(?FamilyMediaSettings $settings = null)
    {
        /** @var FamilyMediaSettings $settings */
        $settings = $settings ?? config('FamilyMediaSettings');
        $this->settings = $settings;
        $this->root = $this->resolveRoot($settings->root);
    }

    private FamilyMediaSettings $settings;

    /**
     * The resolved, fail-closed root, or null when the configuration is unusable
     * (unset, missing, or project-confined). Callers that must fail loudly on a
     * bad deployment check this before enumerating.
     */
    public function root(): ?string
    {
        return $this->root;
    }

    /**
     * The office drop zone, created on demand. Canonical control-number files
     * are placed here by the office or by storeUpload(); the reconciler moves
     * them into the store once they link to a family.
     */
    public function inboxDir(): string
    {
        if ($this->inbox === null) {
            $this->inbox = $this->ensureDir($this->root . DIRECTORY_SEPARATOR . 'inbox');
        }

        return $this->inbox;
    }

    /**
     * Lists the inbox files the registry may reconcile: canonical media
     * filenames that are real regular files. Subdirectories, upload temporary
     * files, dotfiles, and anything that is a link are ignored here rather than
     * judged invalid, so an in-progress atomic write never spuriously fails a
     * scan. Files are never opened.
     *
     * @return list<string>
     */
    public function candidateFilenames(): array
    {
        $filenames = @scandir($this->inboxDir());
        if ($filenames === false) {
            // A transient directory read failure must fail the whole run so the
            // worker records a retry. Silently returning an empty candidate list
            // would make the reconciler treat every pending intake as deleted.
            throw new RuntimeException('The family media inbox cannot be listed: ' . $this->inboxDir());
        }

        $candidates = [];
        foreach ($filenames as $filename) {
            if ($filename === '.' || $filename === '..'
                || $this->parseFilename($filename) === null
                || $this->childPath($this->inboxDir(), $filename) === null) {
                continue;
            }

            $candidates[] = $filename;
        }

        return $candidates;
    }

    /**
     * Moves canonical files left directly in the root by the flat-layout
     * deployment into the inbox, so the first run after this layout change
     * adopts them through the normal intake path. Non-canonical entries are
     * left untouched. Returns the number of files moved.
     */
    public function adoptLegacyRootFiles(): int
    {
        if ($this->root === null) {
            return 0;
        }

        $filenames = @scandir($this->root);
        if ($filenames === false) {
            throw new RuntimeException('The family media root cannot be listed: ' . $this->root);
        }

        $moved = 0;
        $inbox = $this->inboxDir();

        foreach ($filenames as $filename) {
            if ($filename === '.' || $filename === '..'
                || $this->parseFilename($filename) === null
                || $this->childPath($this->root, $filename) === null) {
                continue;
            }

            $source = $this->root . DIRECTORY_SEPARATOR . $filename;
            $destination = $inbox . DIRECTORY_SEPARATOR . $filename;

            if ($this->atomicRename($source, $destination)) {
                @chmod($destination, 0640);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * @return array{source_control_no:int,source_filename:string,kind:string,content_sha256:string,byte_size:int,source_modified_at:string,mime:string,width:int,height:int}|null
     */
    public function inspect(string $filename): ?array
    {
        $parsed = $this->parseFilename($filename);
        if ($parsed === null) {
            return null;
        }

        $path = $this->childPath($this->inboxDir(), $filename);
        if ($path === null) {
            return null;
        }

        return $this->inspectPath($path, $parsed['kind'], $filename, $parsed['control']);
    }

    /**
     * Cheap metadata for an already-known inbox file, without reading its
     * bytes. Returns null when the file is gone, is a link, or is unreadable,
     * so an unchanged known row can be reused without re-opening content.
     *
     * @return array{byte_size:int, source_modified_at:string}|null
     */
    public function metadata(string $filename): ?array
    {
        $path = $this->childPath($this->inboxDir(), $filename);
        if ($path === null) {
            return null;
        }

        return $this->statPath($path);
    }

    /**
     * The canonical store path for one head's kind of media:
     * `store/{shard}/{headID}/{kind}.{ext}`, where the shard is the head ID
     * divided by 100. $ensure creates the directory chain for a write; a stat
     * leaves the filesystem untouched.
     */
    public function storePathFor(int $headId, string $kind, bool $ensure = false): ?string
    {
        if ($this->root === null || $headId <= 0 || ! $this->isKind($kind)) {
            return null;
        }

        $shard = (string) intdiv($headId, 100);
        $dir = $this->root . DIRECTORY_SEPARATOR . 'store' . DIRECTORY_SEPARATOR . $shard . DIRECTORY_SEPARATOR . $headId;

        if ($ensure && ! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            return null;
        }

        return $dir . DIRECTORY_SEPARATOR . $kind . '.' . ($kind === FamilyMediaModel::KIND_PHOTO ? 'jpg' : 'png');
    }

    /**
     * Moves an accepted inbox file into its permanent store location. The move
     * is a rename within the same root, so exactly one copy of the file exists
     * at any moment. Returns false when the inbox file is gone or the store
     * directory cannot be created; the next scan retries the move.
     */
    public function moveToStore(string $filename, int $headId, string $kind): bool
    {
        $source = $this->childPath($this->inboxDir(), $filename);
        $destination = $this->storePathFor($headId, $kind, true);
        if ($source === null || $destination === null) {
            return false;
        }

        if (! $this->atomicRename($source, $destination)) {
            return false;
        }

        // Office drops carry whatever mode the copying account gave them; the
        // web account's tempnam drops carry 0600. Group-read keeps the shared
        // worker account able to scan what the web account wrote.
        @chmod($destination, 0640);

        return true;
    }

    /**
     * Cheap metadata for a head's stored file, without reading its bytes.
     * Returns null when the file is gone, is a link, or is unreadable.
     *
     * @return array{byte_size:int, source_modified_at:string}|null
     */
    public function storedMetadata(int $headId, string $kind): ?array
    {
        $path = $this->storePathFor($headId, $kind);
        if ($path === null || ! is_file($path) || is_link($path)) {
            return null;
        }

        return $this->statPath($path);
    }

    /**
     * Full content inspection of a head's stored file, for re-validating an
     * office edit made directly in the store. $sourceFilename is the registry
     * row's intake name, preserved for audit identity; the stored file itself
     * is named only by kind.
     *
     * @return array{source_control_no:int,source_filename:string,kind:string,content_sha256:string,byte_size:int,source_modified_at:string,mime:string,width:int,height:int}|null
     */
    public function inspectStored(int $headId, string $kind, string $sourceFilename): ?array
    {
        $path = $this->storePathFor($headId, $kind);
        if ($path === null || ! is_file($path) || is_link($path)) {
            return null;
        }

        return $this->inspectPath($path, $kind, $sourceFilename, 0);
    }

    /**
     * Copies, validates, then atomically places an upload's canonical file into
     * the inbox, exactly as if the office had dropped it there. An empty array
     * represents a rejected upload.
     *
     * @return array{source_control_no:int,source_filename:string,kind:string,content_sha256:string,byte_size:int,source_modified_at:string,mime:string,width:int,height:int}|array{}
     */
    public function storeUpload(UploadedFile $file, int $controlNo, string $kind): array
    {
        if ($this->root === null || $controlNo <= 0 || ! $this->isKind($kind)
            || ! $file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            return [];
        }

        $source = $file->getTempName();
        $size = @filesize($source);
        if (! is_file($source) || $size === false || $size <= 0) {
            return [];
        }

        $filename = $this->canonicalFilename($controlNo, $kind);
        $parsed = $this->parseFilename($filename);
        if ($parsed === null) {
            return [];
        }

        $inbox = $this->inboxDir();
        $temporary = @tempnam($inbox, '.' . $filename . '.tmp-');
        if ($temporary === false) {
            return [];
        }

        try {
            if (! @copy($source, $temporary)) {
                return [];
            }

            $inspection = $this->inspectPath($temporary, $parsed['kind'], $filename, $parsed['control']);
            if ($inspection === null) {
                return [];
            }

            $destination = $inbox . DIRECTORY_SEPARATOR . $filename;
            if (! $this->atomicRename($temporary, $destination)) {
                return [];
            }

            // The upload is written by the web account but scanned by the worker
            // account, so tempnam()'s private 0600 mode would leave the worker
            // unable to read it. Group-read keeps a shared-group worker in play.
            @chmod($destination, 0640);

            $stored = $this->inspect($filename);

            return $stored ?? [];
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @throws InvalidArgumentException when the control number or kind is invalid. */
    public function canonicalFilename(int $controlNo, string $kind): string
    {
        if ($controlNo <= 0 || ! $this->isKind($kind)) {
            throw new InvalidArgumentException('A positive control number and supported media kind are required.');
        }

        return sprintf('%06d.%s.%s', $controlNo, $kind, $kind === FamilyMediaModel::KIND_PHOTO ? 'jpg' : 'png');
    }

    /** @return array{control:int,kind:string,mime:string}|null */
    private function parseFilename(string $filename): ?array
    {
        if (preg_match(self::FILENAME_PATTERN, $filename, $match) !== 1) {
            return null;
        }

        $kind = $match['kind'];
        $expectedExtension = $kind === FamilyMediaModel::KIND_PHOTO ? 'jpg' : 'png';
        if ($match['extension'] !== $expectedExtension) {
            return null;
        }

        return [
            'control' => (int) $match['control'],
            'kind'    => $kind,
            'mime'    => $kind === FamilyMediaModel::KIND_PHOTO ? 'image/jpeg' : 'image/png',
        ];
    }

    /**
     * Validates real image bytes at an already-rooted path.
     *
     * @return array{source_control_no:int,source_filename:string,kind:string,content_sha256:string,byte_size:int,source_modified_at:string,mime:string,width:int,height:int}|null
     */
    private function inspectPath(string $path, string $kind, string $label, int $controlNo): ?array
    {
        if (! is_readable($path) || is_link($path)) {
            return null;
        }

        $size = @filesize($path);
        $mtime = @filemtime($path);
        $limit = $kind === FamilyMediaModel::KIND_PHOTO
            ? $this->settings->photoMaxBytes
            : $this->settings->signatureMaxBytes;
        if ($size === false || $size <= 0 || $size > $limit || $limit <= 0 || $mtime === false) {
            return null;
        }

        $expectedMime = $kind === FamilyMediaModel::KIND_PHOTO ? 'image/jpeg' : 'image/png';
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $image = @getimagesize($path);
        if ($mime !== $expectedMime || ! is_array($image) || ($image['mime'] ?? null) !== $expectedMime) {
            return null;
        }

        $width = $image[0] ?? 0;
        $height = $image[1] ?? 0;
        if (! is_int($width) || ! is_int($height) || $width <= 0 || $height <= 0
            || $width > $this->settings->maxDimension || $height > $this->settings->maxDimension) {
            return null;
        }

        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            return null;
        }

        return [
            'source_control_no' => $controlNo,
            'source_filename'   => $label,
            'kind'              => $kind,
            'content_sha256'    => $hash,
            'byte_size'         => $size,
            'source_modified_at' => date('Y-m-d H:i:s', $mtime),
            'mime'              => $mime,
            'width'             => $width,
            'height'            => $height,
        ];
    }

    /** @return array{byte_size:int, source_modified_at:string}|null */
    private function statPath(string $path): ?array
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $size = @filesize($path);
        $mtime = @filemtime($path);
        if ($size === false || $size <= 0 || $mtime === false) {
            return null;
        }

        return [
            'byte_size'          => $size,
            'source_modified_at' => date('Y-m-d H:i:s', $mtime),
        ];
    }

    /**
     * Resolves a direct child of a directory under the root and rejects every
     * link. Intake files must carry the canonical filename grammar; upload
     * temporary files do not.
     */
    private function childPath(string $dir, string $filename): ?string
    {
        if ($this->root === null || $this->parseFilename($filename) === null
            || basename($filename) !== $filename) {
            return null;
        }

        $candidate = $dir . DIRECTORY_SEPARATOR . $filename;
        if (is_link($candidate) || ! is_file($candidate)) {
            return null;
        }

        $path = realpath($candidate);
        if ($path === false || ! $this->isWithin($path, $this->root)) {
            return null;
        }

        return $path;
    }

    /**
     * rename() over an existing file is atomic on POSIX, so a reader either
     * sees the old file or the new one. Windows cannot rename onto an existing
     * file, so there it has to go - the same fallback ImportStagingStore uses.
     */
    private function atomicRename(string $source, string $destination): bool
    {
        if (@rename($source, $destination)) {
            return true;
        }

        return DIRECTORY_SEPARATOR === '\\'
            && is_file($destination)
            && @unlink($destination)
            && @rename($source, $destination);
    }

    private function ensureDir(string $dir): string
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new RuntimeException('The family media directory cannot be created: ' . $dir);
        }

        return $dir;
    }

    private function resolveRoot(string $configuredRoot): ?string
    {
        if (trim($configuredRoot) === '') {
            return null;
        }

        $root = realpath($configuredRoot);
        if ($root === false || ! is_dir($root)) {
            return null;
        }

        foreach (['FCPATH', 'WRITEPATH', 'ROOTPATH'] as $constant) {
            if (! defined($constant)) {
                continue;
            }

            $projectPath = realpath((string) constant($constant));
            if ($projectPath !== false && ($this->isWithin($root, $projectPath)
                || $this->isWithin($projectPath, $root))) {
                return null;
            }
        }

        return $root;
    }

    private function isWithin(string $path, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            $directory = DIRECTORY_SEPARATOR;
        }

        if ($directory === DIRECTORY_SEPARATOR) {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }

        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    private function isKind(string $kind): bool
    {
        return in_array($kind, [FamilyMediaModel::KIND_PHOTO, FamilyMediaModel::KIND_SIGNATURE], true);
    }
}
