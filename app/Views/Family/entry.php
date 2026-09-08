<?php
/**
 * Family Data Entry page (Family Records > Add Family). Creates a new household.
 *
 * The page is a vertical spine: three numbered steps down the left, each step's
 * content indented beneath its own heading. Navigation is non-linear, since the
 * officer transcribes from a single paper sheet where every part is visible at
 * once, and each member's relationship is defined against the head. The Save
 * button, not the spine, is what enforces completeness.
 *
 * Step 1 gates the rest: the control number is checked against qr_control over
 * the network, and the sections below stay hidden until it comes back available,
 * because a pending or failed check used to wedge a save. The spine's markup is
 * written out here rather than through components/stepper because this page needs
 * each step's content inside its list item; the classes and data-state values are
 * the component's, so both share one stylesheet.
 *
 * Data source: FamilyController::createFamily(), documented as
 * family_entry_view_data() in app/Helpers/dashboard_view_helper.php.
 */
$fieldData = [
    'head'        => $head,
    'members'     => $members,
    'readOnly'    => $readOnly,
    'sectors'     => $sectors,
    'services'    => $services,
    'categories'  => $categories,
    'formOptions' => $formOptions,
];
?>
<?php /* No bottom padding: the action bar below is sticky and full-bleed, so a
         padded container would float it above a strip of page background. */ ?>
<div class="container-fluid px-4 pt-4 has-fixed-action-bar" data-family-entry-form>
    <form id="familyEntryForm" method="post" action="<?= esc(site_url('records'), 'attr') ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <nav class="stepper stepper-vertical" id="entrySpine" aria-label="Record sections">
            <ol class="stepper-steps">
                <li class="stepper-step" data-state="current">
                    <a class="stepper-step-link" href="#section-control" aria-current="step">
                        <span class="stepper-step-indicator" aria-hidden="true">1</span>
                        <span class="stepper-step-label"><span class="visually-hidden" data-step-state-prefix></span>Control Number</span>
                    </a>
                    <div class="stepper-step-content" id="section-control" data-control-number-gate data-qr-check-url="<?= esc(site_url('records/qr-check'), 'attr') ?>">
                        <div class="row">
                            <div class="col-md-6 col-lg-5">
                                <label class="form-label" for="controlNumber">Control Number</label>
                                <?php /* No name attribute: this field sits inside the form now, and the
                                         value posts through the hidden qr_control_no carrier in
                                         Family/_fields.php, not from here. */ ?>
                                <input type="text" class="form-control" id="controlNumber" required
                                       inputmode="numeric" autocomplete="off">
                                <div class="form-text" data-control-number-status role="status" aria-live="polite"></div>
                            </div>
                            <div class="col-md-6">
                                <img class="d-none" data-control-number-qr alt="" width="132" height="132">
                            </div>
                        </div>
                    </div>
                </li>

                <li class="stepper-step" data-state="upcoming">
                    <a class="stepper-step-link" href="#section-head" aria-disabled="true">
                        <span class="stepper-step-indicator" aria-hidden="true">2</span>
                        <span class="stepper-step-label"><span class="visually-hidden" data-step-state-prefix>Locked, </span>Head of Family</span>
                    </a>
                    <div class="stepper-step-content d-none" id="section-head" data-entry-section>
                        <?= view('Family/_fields', $fieldData + ['part' => 'head']) ?>
                        <?= view('Family/_media_fields') ?>
                    </div>
                </li>

                <li class="stepper-step" data-state="upcoming">
                    <a class="stepper-step-link" href="#section-members" aria-disabled="true">
                        <span class="stepper-step-indicator" aria-hidden="true">3</span>
                        <span class="stepper-step-label"><span class="visually-hidden" data-step-state-prefix>Locked, </span>Members of the Family</span>
                    </a>
                    <div class="stepper-step-content d-none" id="section-members" data-entry-section>
                        <?= view('Family/_fields', $fieldData + ['part' => 'members']) ?>
                    </div>
                </li>
            </ol>
        </nav>

        <?php /* Truncation sentinel - MUST stay the last named field in the form. A
                 POST clipped by PHP's max_input_vars drops trailing vars first, so if
                 this does not arrive the server knows member data was cut and refuses
                 to save (FamilyController::submissionWasTruncated()). */ ?>
        <input type="hidden" name="members_meta_count" value="0" data-members-count>
        <input type="hidden" name="_form_end" value="1">

        <div class="position-fixed bottom-0 start-0 w-100 bg-white border-top p-3 z-3 d-flex flex-wrap justify-content-between align-items-center gap-2 shadow-sm" style="margin-bottom: 0;">
            <p class="mb-0 text-muted" data-entry-blocked role="status" aria-live="polite"></p>
            <div class="d-flex gap-2 ms-auto">
                <a class="btn btn-outline-secondary" href="<?= esc(site_url('records'), 'attr') ?>">Cancel</a>
                <?php /* Green and named for what it does: this page creates a record,
                         it does not save an edit to one (that is Family/profile). */ ?>
                <button class="<?= btn('add') ?>" type="submit" data-family-save>
                    Add Record
                </button>
            </div>
        </div>
    </form>
</div>
