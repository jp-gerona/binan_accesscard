<?php
/** Optional private media uploads for a family head. */
?>
<div class="row g-3 mt-1" data-family-media-fields>
    <div class="col-md-6">
        <label class="form-label" for="headPhoto">Portrait photo <span class="text-muted">(optional)</span></label>
        <input class="form-control" type="file" id="headPhoto" name="head_photo" accept="image/jpeg">
        <div class="form-text">JPEG only.</div>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="headSignature">Signature <span class="text-muted">(optional)</span></label>
        <input class="form-control" type="file" id="headSignature" name="head_signature" accept="image/png">
        <div class="form-text">PNG only.</div>
    </div>
</div>
