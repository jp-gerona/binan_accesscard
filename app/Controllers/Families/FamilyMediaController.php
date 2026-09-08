<?php

namespace App\Controllers\Families;

use App\Controllers\BaseController;
use App\Libraries\FamilyMediaStorage;
use App\Models\Families\FamilyMediaModel;
use App\Models\Families\MemberModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Serves the private photo and signature files linked to a family head.
 *
 * Bytes come from the system-owned store only: the path is derived from the
 * linked registry row's head and kind by FamilyMediaStorage::storePathFor(),
 * so a request can never name a file. Every
 * unknown, absent, malformed, or unauthorised case answers the same empty 404,
 * so the response never reveals whether a person has media. The `records-media`
 * route filter already gated the session's role before this controller runs.
 */
class FamilyMediaController extends BaseController
{
    /**
     * GET `records/(:num)/media/(:alpha)`: turns one linked registry row into
     * image bytes. Accepts only the two registry kinds and first confirms that
     * the requested member is a head, including when a registry row was corruptly
     * linked to a relative.
     */
    public function show(int $headId, string $kind): ResponseInterface
    {
        if ($headId <= 0 || ! in_array($kind, [FamilyMediaModel::KIND_PHOTO, FamilyMediaModel::KIND_SIGNATURE], true)
            || (new MemberModel())->findHead($headId) === null) {
            return $this->notFound();
        }

        $media = (new FamilyMediaModel())->findLinked($headId, $kind);
        if ($media === null) {
            return $this->notFound();
        }

        $path = (new FamilyMediaStorage())->storePathFor($headId, $kind);
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return $this->notFound();
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $image = @getimagesize($path);
        if (! is_string($mime) || $mime !== $this->mimeForKind($kind)
            || ! is_array($image) || ($image['mime'] ?? null) !== $mime) {
            return $this->notFound();
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return $this->notFound();
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $mime)
            ->setBody($bytes);
    }

    /** The canonical MIME the storage grammar guarantees for each kind. */
    private function mimeForKind(string $kind): string
    {
        return $kind === FamilyMediaModel::KIND_PHOTO ? 'image/jpeg' : 'image/png';
    }

    /** An empty 404, identical for every "you may not have this media" case. */
    private function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404);
    }
}
