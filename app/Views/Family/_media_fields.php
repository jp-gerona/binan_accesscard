<?php
/** Optional private media uploads for a family head. */
?>
<div class="row g-3 mt-1" data-family-media-fields>
    <div class="col-12 mb-2">
        <div class="alert alert-info py-2 mb-0 small">
            <i class="bi bi-info-circle me-2"></i><strong>Optional:</strong> Media uploads are not required. If an uploaded file violates the rules, clear the file input and leave it blank to successfully save the record.
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="headPhoto">Portrait photo</label>
        <div class="input-group">
            <input class="form-control" type="file" id="headPhoto" name="head_photo" accept="image/jpeg,image/jpg" onchange="previewMedia(this, 'photoPreview')">
            <button type="button" class="btn btn-outline-secondary" onclick="clearMedia('headPhoto', 'photoPreview')">Clear</button>
        </div>
        <div class="form-text">JPEG only (max 5MB).</div>
        <img id="photoPreview" class="img-thumbnail mt-2 d-none" style="max-height: 150px; width: auto; object-fit: contain;" alt="Portrait preview">
    </div>
    <div class="col-md-6">
        <label class="form-label" for="headSignature">Signature</label>
        <div class="input-group">
            <input class="form-control" type="file" id="headSignature" name="head_signature" accept="image/png" onchange="previewMedia(this, 'signaturePreview')">
            <button type="button" class="btn btn-outline-secondary" onclick="clearMedia('headSignature', 'signaturePreview')">Clear</button>
        </div>
        <div class="form-text">PNG only (max 5MB).</div>
        <img id="signaturePreview" class="img-thumbnail mt-2 d-none" style="max-height: 80px; width: auto; object-fit: contain;" alt="Signature preview">
    </div>
</div>

<script>
function previewMedia(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
        }
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = "";
        preview.classList.add('d-none');
    }
}

function clearMedia(inputId, previewId) {
    const input = document.getElementById(inputId);
    input.value = "";
    previewMedia(input, previewId);
}
</script>
