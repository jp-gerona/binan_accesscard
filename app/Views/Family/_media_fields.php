<?php
/** Optional private media uploads for a family head. */
?>
<div class="row g-3 mt-1" data-family-media-fields>
    <div class="col-12 mb-2">
        <div class="alert alert-info py-2 mb-0 small">
            <i class="bi bi-info-circle me-2"></i><strong>Optional:</strong> Media uploads are not required. If an uploaded file violates the rules, clear the file input and leave it blank to successfully save the record.
        </div>
    </div>
    <div class="col-md-6" data-family-media-field>
        <label class="form-label">Portrait photo</label>
        <div class="input-group">
            <input class="form-control" type="file" name="head_photo" accept="image/jpeg,image/jpg" aria-label="Portrait photo" data-family-media-input>
            <button type="button" class="btn btn-outline-secondary" data-family-media-clear>Clear</button>
        </div>
        <div class="form-text">JPEG only (max 5MB).</div>
        <img class="img-thumbnail mt-2 d-none family-media-preview family-media-preview-photo" data-family-media-preview alt="Portrait preview">
    </div>
    <div class="col-md-6" data-family-media-field>
        <label class="form-label">Signature</label>
        <div class="input-group">
            <input class="form-control" type="file" name="head_signature" accept="image/png" aria-label="Signature" data-family-media-input>
            <button type="button" class="btn btn-outline-secondary" data-family-media-clear>Clear</button>
        </div>
        <div class="form-text">PNG only (max 5MB).</div>
        <img class="img-thumbnail mt-2 d-none family-media-preview family-media-preview-signature" data-family-media-preview alt="Signature preview">
    </div>
</div>
