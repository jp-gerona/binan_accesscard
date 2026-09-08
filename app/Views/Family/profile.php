<?php
/**
 * Edit page for an existing family record (`records/{id}/edit`). Reading a record
 * is a different page, Family/profile-view, which prints it with no controls at
 * all; this one is the form. Data source: FamilyController::edit(), documented as
 * family_profile_view_data() in app/Helpers/dashboard_view_helper.php.
 *
 * The head is the outer card and the members are nested inside it, which is the
 * containment the paper profiling form implies. Fields render as controls with
 * one Save at the bottom; the records-edit manifest key keeps read-only roles off
 * this page entirely, so $readOnly is only the shared partial's default.
 *
 * `data-family-entry-form` on the outer container is the same marker
 * manage-family-modal.js's initFamilyEntryModal() looks for on the Data Entry
 * page, so the shared JS (member row toggle/add/remove, Other-select reveal,
 * AJAX submit) wires up here too - this page has no control-number gate, so it
 * skips only that one entry-page-specific step.
 */

use App\Libraries\Qr\ControlNumber;

$readOnly = (bool) ($readOnly ?? false);
$headId = (int) ($head['memberID'] ?? 0);
$formOptions = (array) ($formOptions ?? []);
$qrDataUri = (string) ($qrDataUri ?? '');
$controlNumberLabel = ControlNumber::format((int) ($controlNumber ?? 0));
?>
<div class="pb-5 mb-5" data-family-entry-form>
    <form method="post" action="<?= esc(site_url('records/' . $headId . '/update'), 'attr') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="form_mode" value="update">

        <div class="card shadow-none border" data-head-card>
            <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap align-items-center gap-3">
                <h2 class="h5 mb-0 fw-bold text-dark">Primary Information</h2>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1">Head</span>
            </div>
            <div class="card-body">
                <?= view('Family/_fields', [
                    'head'        => $head,
                    'members'     => $members,
                    'readOnly'    => $readOnly,
                    'sectors'     => $sectors,
                    'services'    => $services,
                    'categories'  => $categories,
                    'formOptions' => $formOptions,
                    'qrDataUri'   => $qrDataUri ?? '',
                ]) ?>
                <?php if (! $readOnly): ?>
                    <?= view('Family/_media_fields') ?>
                <?php endif; ?>
            </div>

        </div>

        <?php /* Truncation sentinel - MUST stay the last named field in the form. A
                 POST clipped by PHP's max_input_vars drops trailing vars first, so if
                 this does not arrive the server knows member data was cut and refuses
                 to save (FamilyController::submissionWasTruncated()). */ ?>
        <input type="hidden" name="members_meta_count" value="0" data-members-count>
        <input type="hidden" name="_form_end" value="1">

        <?php if (! $readOnly): ?>
        <div class="position-fixed bottom-0 start-0 w-100 bg-white border-top p-3 z-3 d-flex flex-wrap justify-content-end align-items-center gap-2 shadow-sm" style="margin-bottom: 0;">
            <a href="<?= esc(site_url('records/' . $headId), 'attr') ?>" class="btn btn-outline-secondary">Cancel</a>
            <button class="<?= btn('save') ?> px-4" type="submit" data-family-save>
                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Save Changes
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>
