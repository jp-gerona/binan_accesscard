<?php

namespace App\Libraries;

use App\Models\Families\FamilyMediaModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\FamilyMediaSettings;
use InvalidArgumentException;

/**
 * The fail-closed boundary around the office-managed, private family-media directory.
 *
 * Files are deliberately never accepted from a project directory: the configured root
 * must resolve to an existing directory outside FCPATH, WRITEPATH, and ROOTPATH.
 */
class FamilyMediaStorage
{
    private const FILENAME_PATTERN = '/^(?<control>\d{6,})\.(?<kind>photo|signature)\.(?<extension>jpg|png)$/';

    private ?string $root;

    public function __construct(?FamilyMediaSettings $settings = null)
    {
        /** @var FamilyMediaSettings $settings */
        $settings = $settings ?? config('FamilyMediaSettings');
        $this->settings = $settings;
        $this->root = $this->resolveRoot($settings->root);
    }

    private FamilyMediaSettings $settings;

    /**
     * Inspects every valid media file directly inside the configured root.
     *
     * @return iterable<array{source_control_no:int,source_filename:string,kind:string,content_sha256:string,byte_size:int,source_modified_at:string,mime:string,width:int,height:int}>
     */
    public function scan(): iterable
    {
        if ($this->root === null) {
            return;
        }

        $filenames = scandir($this->root);
        if ($filenames === false) {
            return;
        }

        foreach ($filenames as $filename) {
            $inspection = $this->inspect($filename);
            if ($inspection !== null) {
                yield $inspection;
            }
        }
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

        $path = $this->rootPathFor($filename);
        if ($path === null) {
            return null;
        }

        return $this->inspectPath($path, $filename, $parsed);
    }

    /**
     * Copies, validates, then atomically replaces the canonical destination for an upload.
     * An empty array represents a rejected upload.
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

        $temporary = @tempnam($this->root, '.' . $filename . '.tmp-');
        if ($temporary === false) {
            return [];
        }

        try {
            if (! @copy($source, $temporary)) {
                return [];
            }

            $temporaryPath = $this->rootPathFor(basename($temporary), false);
            if ($temporaryPath === null) {
                return [];
            }

            $inspection = $this->inspectPath($temporaryPath, $filename, $parsed);
            if ($inspection === null) {
                return [];
            }

            $destination = $this->root . DIRECTORY_SEPARATOR . $filename;
            if (! @rename($temporary, $destination)) {
                // Windows rename() cannot replace an existing file. This is the same
                // fallback used by ImportStagingStore; POSIX keeps its atomic replace.
                if (DIRECTORY_SEPARATOR !== '\\' || ! is_file($destination)
                    || ! @unlink($destination) || ! @rename($temporary, $destination)) {
                    return [];
                }
            }

            $stored = $this->inspect($filename);

            return $stored ?? [];
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** Returns the trusted absolute path for a canonical file, or null when unavailable. */
    public function pathFor(string $filename): ?string
    {
        if ($this->parseFilename($filename) === null) {
            return null;
        }

        return $this->rootPathFor($filename);
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
     * Validates real image bytes at an already-rooted path using the same checks as inspect().
     *
     * @param array{control:int,kind:string,mime:string} $parsed
     * @return array{source_control_no:int,source_filename:string,kind:string,content_sha256:string,byte_size:int,source_modified_at:string,mime:string,width:int,height:int}|null
     */
    private function inspectPath(string $path, string $filename, array $parsed): ?array
    {
        if (! is_readable($path)) {
            return null;
        }

        $size = @filesize($path);
        $mtime = @filemtime($path);
        $limit = $parsed['kind'] === FamilyMediaModel::KIND_PHOTO
            ? $this->settings->photoMaxBytes
            : $this->settings->signatureMaxBytes;
        if ($size === false || $size <= 0 || $size > $limit || $limit <= 0 || $mtime === false) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $image = @getimagesize($path);
        if ($mime !== $parsed['mime'] || ! is_array($image) || ($image['mime'] ?? null) !== $parsed['mime']) {
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
            'source_control_no' => $parsed['control'],
            'source_filename'   => $filename,
            'kind'              => $parsed['kind'],
            'content_sha256'    => $hash,
            'byte_size'         => $size,
            'source_modified_at' => date('Y-m-d H:i:s', $mtime),
            'mime'              => $mime,
            'width'             => $width,
            'height'            => $height,
        ];
    }

    /**
     * Resolves a direct child of the configured root and rejects every link.
     * $canonical requires the public filename grammar; upload temporary files do not.
     */
    private function rootPathFor(string $filename, bool $canonical = true): ?string
    {
        if ($this->root === null || ($canonical && $this->parseFilename($filename) === null)
            || basename($filename) !== $filename) {
            return null;
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . $filename;
        if (is_link($candidate) || ! is_file($candidate)) {
            return null;
        }

        $path = realpath($candidate);
        if ($path === false || ! $this->isWithin($path, $this->root)) {
            return null;
        }

        return $path;
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
