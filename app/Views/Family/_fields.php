<?php
/**
 * Shared field set for a family record: the Head card, the Household Members
 * list, and the sector/service checkbox pickers. Included by the Data Entry
 * page (Family/entry) and the Family Profile page (Family/profile) - never
 * rendered on its own.
 *
 * Its contract is a head row ([] for a new record), the member rows, a
 * read-only flag that disables every control instead of hiding it, the active
 * sector/service/category lookups, and $formOptions - the sorted sex/suffix/
 * civil-status/barangay/relationship/education/job/religion/income lists,
 * from FamilyModalDataBuilder::staticOptionLists() (a library, so the model
 * call stays out of this view - "controllers decide, libraries build"). The
 * controller is the one that calls it; this partial only reads the result.
 * The shape is documented on family_entry_view_data() and
 * family_profile_view_data() in app/Helpers/dashboard_view_helper.php.
 *
 * $part selects which piece to render: 'head', 'members', or 'all' (the
 * default). The Data Entry spine renders the head and the member list as
 * separate steps, so it asks for one part per call; the edit page (Family/
 * profile.php) renders both together and never passes $part.
 */

helper('family_modal');

$head = (array) ($head ?? []);
$members = (array) ($members ?? []);
$readOnly = (bool) ($readOnly ?? false);
$sectors = (array) ($sectors ?? []);
$services = (array) ($services ?? []);
$categories = (array) ($categories ?? []);
$formOptions = (array) ($formOptions ?? []);
$qrDataUri = (string) ($qrDataUri ?? '');

// The Data Entry spine renders the head and the member list as separate steps;
// the edit page renders both in one card, which is what 'all' keeps doing.
$part = (string) ($part ?? 'all');
$part = in_array($part, ['head', 'members', 'all'], true) ? $part : 'all';
$showMemberHeading = (bool) ($showMemberHeading ?? ($part === 'all'));

// A head row carries headID == memberID, so either identifies it. Reading only
// headID meant a caller that passed the head row without it (Family/profile
// derives its own id from memberID) rendered as a new record: no control number
// and no code.
$headId = (int) ($head['headID'] ?? $head['memberID'] ?? 0);
$fieldPrefix = $headId > 0 ? 'family-update' : 'family-add';
$qrLocked = ! empty($head['qr_locked'] ?? false);

// Sectors are a flat classification (no per-category headings), so the grid
// iterates one group. See SectorModel::getSectorCatalog(), which this mirrors.
$sectorCatalog = $sectors === [] ? [] : [$sectors];

// Every known category renders even with no services yet (a category like
// Financial Assistance applies regardless of sector), then each service files
// under its own category. Uncategorised services file under "Other", matching
// FamilyFormOptionsModel::groupServicesByCategory()'s fallback.
$servicesByCategory = [];
foreach ($categories as $category) {
    $categoryName = is_array($category) ? (string) ($category['name'] ?? '') : (string) $category;

    if ($categoryName !== '') {
        $servicesByCategory[$categoryName] = [];
    }
}
foreach ($services as $service) {
    $servicesByCategory[(string) ($service['category'] ?? 'Other')][] = $service;
}

// $formOptions (suffix, civil status, barangay, relationship, education, job,
// religion, income, sex) is the caller's - see this file's docblock - so it is
// merged in here rather than fetched again.
extract(family_modal_prepare(array_merge([
    'headId' => $headId,
    'fieldPrefix' => $fieldPrefix,
    'formValues' => array_combine(
        array_map(static fn (string $key): string => 'head_' . $key, array_keys($head)),
        array_values($head)
    ),
    'selectedSectorIds' => $head['sector_ids'] ?? [],
    'selectedServiceIds' => $head['service_ids'] ?? [],
    'sectorCatalog' => $sectorCatalog,
    'servicesByCategory' => $servicesByCategory,
], $formOptions)), EXTR_OVERWRITE);

$personFieldOptions = compact(
    'suffixOptions',
    'sexOptions',
    'civilOptions',
    'religionOptions',
    'educationOptions',
    'jobOptions',
    'incomeOptions'
);

/** Renders the age bounds of an age-restricted choice as data attributes, or nothing. */
$ageBoundsAttrs = static function (?array $bounds): string {
    if ($bounds === null) {
        return '';
    }

    return ($bounds['min'] !== null ? ' data-min-age="' . (int) $bounds['min'] . '"' : '')
        . ($bounds['max'] !== null ? ' data-max-age="' . (int) $bounds['max'] . '"' : '');
};

/**
 * Sectors is a fixed list, so it needs no scroll region: a two-column grid shows
 * all of them at once.
 */
$renderSectorGrid = static function (string $fieldName, array $selectedIds, string $idPrefix, bool $disabled) use ($sectorCatalog, $sectorLabel, $ageBoundsAttrs): string {
    ob_start();
    ?>
    <div class="row row-cols-1 row-cols-sm-2 g-1" data-family-sector-grid>
        <?php if ($sectorCatalog === []): ?>
            <div class="col"><p class="text-muted mb-0">No sectors available.</p></div>
        <?php endif; ?>
        <?php foreach ($sectorCatalog as $sectorGroup): ?>
            <?php foreach (array_values((array) $sectorGroup) as $sector): ?>
                <?php
                $sector = (array) $sector;
                $sectorId = (string) ($sector['sectorID'] ?? $sector['id'] ?? '');
                $label = $sectorLabel($sector);

                if ($sectorId === '' || $label === '') {
                    continue;
                }

                $isArchived = ! empty($sector['is_archived']);
                $choiceId = $idPrefix . 'Sector' . $sectorId;
                ?>
                <div class="col">
                    <div class="form-check family-choice<?= $isArchived ? ' family-choice--archived' : '' ?>">
                        <input class="form-check-input" type="checkbox" id="<?= esc($choiceId, 'attr') ?>" name="<?= esc($fieldName, 'attr') ?>" value="<?= esc($sectorId, 'attr') ?>" data-label="<?= esc($label, 'attr') ?>" data-sector-code="<?= esc((string) ($sector['shortcode'] ?? $sector['code'] ?? ''), 'attr') ?>" data-sector-name="<?= esc((string) ($sector['name'] ?? ''), 'attr') ?>"<?= $isArchived ? ' data-archived="1"' : '' ?><?= $ageBoundsAttrs(\App\Support\FamilyAgeEligibility::sectorAgeBounds((string) ($sector['shortcode'] ?? $sector['code'] ?? ''))) ?> <?= in_array($sectorId, $selectedIds, true) ? 'checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                        <label class="form-check-label" for="<?= esc($choiceId, 'attr') ?>"><?= esc($label) ?><?php if ($isArchived): ?> <span class="family-choice-badge">Archived</span><?php endif; ?></label>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

/**
 * Services span several categories, so each category is an accordion item.
 * data-bs-parent is deliberately omitted so more than one can stay open;
 * manage-family-modal.js opens the categories that match the ticked sectors or
 * already hold a tick, and closes them again when that stops being true. Nothing
 * is hidden: a category matching no sector stays collapsed but present, because
 * programs like Financial Assistance apply regardless of sector.
 */
$renderServiceAccordion = static function (string $fieldName, array $selectedIds, string $accordionId, string $idPrefix, bool $disabled) use ($servicesByCategory, $serviceLabel, $ageBoundsAttrs): string {
    ob_start();
    ?>
    <div>
        <div class="mb-2">
            <input type="search" class="form-control form-control-sm" placeholder="Filter programs..." aria-label="Filter programs" data-family-service-filter<?= $disabled ? ' disabled' : '' ?>>
        </div>
        <div class="accordion" id="<?= esc($accordionId, 'attr') ?>" data-family-service-accordion>
            <?php if ($servicesByCategory === []): ?>
                <p class="text-muted mb-0">No services available.</p>
            <?php endif; ?>
            <?php $categoryIndex = 0; ?>
            <?php foreach ($servicesByCategory as $category => $categoryServices): ?>
                <?php
                $categoryIndex++;
                $panelId = $accordionId . 'Panel' . $categoryIndex;
                ?>
                <div class="accordion-item" data-family-service-item>
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?= esc($panelId, 'attr') ?>" aria-expanded="false" aria-controls="<?= esc($panelId, 'attr') ?>"><?= esc((string) $category) ?></button>
                    </h2>
                    <div id="<?= esc($panelId, 'attr') ?>" class="accordion-collapse collapse" data-family-service-panel data-service-category="<?= esc((string) $category, 'attr') ?>"<?= $ageBoundsAttrs(\App\Support\FamilyAgeEligibility::serviceCategoryAgeBounds((string) $category)) ?>>
                        <div class="accordion-body py-2">
                            <div class="row row-cols-1 row-cols-sm-2 g-1">
                                <?php foreach ((array) $categoryServices as $service): ?>
                                    <?php
                                    $service = (array) $service;
                                    $serviceId = (string) ($service['serviceID'] ?? $service['id'] ?? '');
                                    $label = $serviceLabel($service);

                                    if ($serviceId === '' || $label === '') {
                                        continue;
                                    }

                                    $isArchived = ! empty($service['is_archived']);
                                    $choiceId = $idPrefix . 'Service' . $serviceId;
                                    ?>
                                    <div class="col">
                                        <div class="form-check family-choice<?= $isArchived ? ' family-choice--archived' : '' ?>">
                                            <input class="form-check-input" type="checkbox" id="<?= esc($choiceId, 'attr') ?>" name="<?= esc($fieldName, 'attr') ?>" value="<?= esc($serviceId, 'attr') ?>" data-label="<?= esc($label, 'attr') ?>"<?= $isArchived ? ' data-archived="1"' : '' ?> <?= in_array($serviceId, $selectedIds, true) ? 'checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                                            <label class="form-check-label" for="<?= esc($choiceId, 'attr') ?>"><?= esc($label) ?><?php if ($isArchived): ?> <span class="family-choice-badge">Archived</span><?php endif; ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};

/**
 * The sectors-and-programs block, identical for the head and for every member.
 */
$renderChoicesBlock = static function (
    string $blockId,
    string $sectorFieldName,
    array $selectedSectors,
    string $serviceFieldName,
    array $selectedServices,
    string $idPrefix,
    bool $open,
    bool $disabled
) use ($renderSectorGrid, $renderServiceAccordion): string {
    ob_start();
    ?>
    <div class="family-choices" data-family-choices-block>
        <button class="family-choices-toggle" type="button"
                data-bs-toggle="collapse" data-bs-target="#<?= esc($blockId, 'attr') ?>"
                aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= esc($blockId, 'attr') ?>"<?= $disabled ? ' disabled' : '' ?>>
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
            <span data-family-choices-summary>Sectors and programs</span>
        </button>
        <div class="collapse<?= $open ? ' show' : '' ?>" id="<?= esc($blockId, 'attr') ?>" data-family-choices>
            <div class="row g-3 pt-2">
                <div class="col-12 col-lg-5">
                    <h5 class="family-column-title">Sectors</h5>
                    <?= $renderSectorGrid($sectorFieldName, $selectedSectors, $idPrefix, $disabled) ?>
                </div>
                <div class="col-12 col-lg-7">
                    <h5 class="family-column-title">Services and Programs</h5>
                    <?= $renderServiceAccordion($serviceFieldName, $selectedServices, $blockId . 'Services', $idPrefix, $disabled) ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};

/**
 * The editable field set for one member row. It is mounted into the row shell:
 * inline for a row that opens with the form, or by manage-family-modal.js when the
 * worker opens a closed row. $index is an int for an existing member, or the
 * literal '__INDEX__' placeholder inside the <template>, which the JS swaps for the
 * row's own index (names and ids alike).
 * Field names post as members[$index][...] to match FamilyController::store()/update().
 */
$renderMemberEditor = static function ($index, array $m = []) use (
    $personFields,
    $personFieldOptions,
    $selectOptions,
    $relationshipOptions,
    $renderChoicesBlock,
    $fieldPrefix,
    $readOnly
): string {
    $i = (string) $index;
    $field = static fn (string $name): string => 'members[' . $i . '][' . $name . ']';
    $val = static fn (string $key): string => (string) ($m[$key] ?? '');
    $idPrefix = $fieldPrefix . 'Member' . $i;
    $selectedSectors = array_map('strval', (array) ($m['sector_ids'] ?? []));
    $selectedServices = array_map('strval', (array) ($m['service_ids'] ?? []));

    ob_start();
    ?>
    <div class="row g-3">
        <?= family_modal_render_person_fields([
            'personFields' => $personFields,
            'optionsByKey' => $personFieldOptions,
            'selectOptions' => $selectOptions,
            'field' => $field,
            'value' => $val,
            // Members require the same personal fields as the head (Address/Barangay are
            // head-only, members inherit them, so they are not part of this component).
            'idPrefix' => $idPrefix,
            'required' => true,
            'disabled' => $readOnly,
        ]) ?>
        <div class="col-12 col-md-6 col-xl-3">
            <label class="form-label" for="<?= esc($idPrefix . 'Relationship', 'attr') ?>">Relationship <span class="account-required-marker text-danger" aria-hidden="true">*</span></label>
            <select id="<?= esc($idPrefix . 'Relationship', 'attr') ?>" class="form-select js-other-select" data-other-field="relationship<?= esc($i, 'attr') ?>" data-initial-value="<?= esc($val('relationship'), 'attr') ?>" name="<?= esc($field('relationship'), 'attr') ?>" aria-describedby="<?= esc($idPrefix . 'RelationshipFeedback', 'attr') ?>" required<?= $readOnly ? ' disabled' : '' ?>><?= $selectOptions($relationshipOptions, $val('relationship'), 'Select') ?></select>
            <input class="form-control mt-2 js-other-input d-none" data-other-for="relationship<?= esc($i, 'attr') ?>" placeholder="Enter relationship" aria-label="Other relationship"<?= $readOnly ? ' disabled' : '' ?>>
            <div class="invalid-feedback" id="<?= esc($idPrefix . 'RelationshipFeedback', 'attr') ?>" data-family-field-error></div>
        </div>
    </div>

    <?= $renderChoicesBlock(
        'familyMember' . $i . 'Choices',
        $field('sector_ids') . '[]',
        $selectedSectors,
        $field('service_ids') . '[]',
        $selectedServices,
        $idPrefix,
        true,
        $readOnly
    ) ?>
    <?php
    return (string) ob_get_clean();
};

/**
 * The hidden inputs that stand in for a closed row's editor. Same names as the
 * controls they replace, so FormData and every [name$="[key]"] lookup are unaffected.
 * Sector ids carry their full name so the row summary can name them without
 * re-rendering the checkbox list.
 */
$renderMemberValues = static function ($index, array $m) use ($sectorCatalog): string {
    $i = (string) $index;
    $scalarKeys = ['lastname', 'firstname', 'middlename', 'suffix', 'birthday', 'sex',
        'civilstatus', 'contactnumber', 'religion', 'education', 'job', 'salary', 'relationship'];
    $sectorCodes = [];

    foreach ($sectorCatalog as $sectorGroup) {
        foreach (array_values((array) $sectorGroup) as $sector) {
            $sector = (array) $sector;
            $sectorCodes[(string) ($sector['sectorID'] ?? $sector['id'] ?? '')] = (string) ($sector['shortcode'] ?? $sector['code'] ?? $sector['name'] ?? $sector['sector_name'] ?? '');
        }
    }

    ob_start();
    foreach ($scalarKeys as $key): ?>
        <input type="hidden" name="<?= esc('members[' . $i . '][' . $key . ']', 'attr') ?>" value="<?= esc((string) ($m[$key] ?? ''), 'attr') ?>">
    <?php endforeach; ?>
    <?php foreach (array_map('strval', (array) ($m['sector_ids'] ?? [])) as $sectorId): ?>
        <input type="hidden" name="<?= esc('members[' . $i . '][sector_ids][]', 'attr') ?>" value="<?= esc($sectorId, 'attr') ?>" data-sector-code="<?= esc($sectorCodes[$sectorId] ?? '', 'attr') ?>">
    <?php endforeach; ?>
    <?php foreach (array_map('strval', (array) ($m['service_ids'] ?? [])) as $serviceId): ?>
        <input type="hidden" name="<?= esc('members[' . $i . '][service_ids][]', 'attr') ?>" value="<?= esc($serviceId, 'attr') ?>">
    <?php endforeach; ?>
    <?php
    return (string) ob_get_clean();
};

/**
 * The persistent shell of one member row: header, summary line, and the two mount
 * points. The editor is rendered inline when the row opens with the form; a closed
 * row leaves the mount empty and carries its values as hidden inputs instead.
 */
$renderMemberRow = static function ($index, array $m = [], bool $open = true) use ($renderMemberEditor, $renderMemberValues, $readOnly): string {
    ob_start();
    ?>
    <div class="list-group-item p-0<?= $open ? ' bg-body-tertiary' : '' ?>" data-family-member-row data-family-member-open="<?= $open ? '1' : '0' ?>">
        <div class="d-flex align-items-center">
            <button class="list-group-item-action btn btn-link text-decoration-none text-reset text-start d-flex align-items-center gap-3 px-3 py-2 border-0 rounded-0 flex-grow-1 overflow-hidden" type="button" data-family-member-toggle aria-expanded="<?= $open ? 'true' : 'false' ?>">
                <span class="small fw-semibold text-body-secondary font-monospace" data-family-member-title></span>
                <span class="d-flex flex-column overflow-hidden" data-family-member-summary></span>
                <i class="bi bi-chevron-<?= $open ? 'up' : 'down' ?> ms-auto text-body-secondary" aria-hidden="true" data-family-member-chevron></i>
            </button>
            <div class="dropdown pe-2">
                <button class="btn btn-link btn-sm text-body-secondary actions-menu-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Member actions"<?= $readOnly ? ' disabled' : '' ?>>
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item" type="button" data-family-set-head<?= $readOnly ? ' disabled' : '' ?>>Set as head</button></li>
                    <li><button class="dropdown-item text-danger" type="button" data-family-member-remove<?= $readOnly ? ' disabled' : '' ?>>Remove</button></li>
                </ul>
            </div>
        </div>
        <div class="<?= $open ? 'px-3 pb-3' : '' ?>" data-family-member-editor><?= $open ? $renderMemberEditor($index, $m) : '' ?></div>
        <div data-family-member-values><?= $open ? '' : $renderMemberValues($index, $m) ?></div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<?php if ($part !== 'members'): ?>
<?php /* No heading of its own: both callers already name this section outside the
         card, the entry spine on its step link and the edit page on its card header. */ ?>
<div<?= $part === 'all' ? ' id="section-head"' : '' ?> class="family-person-card">
    <div class="row g-3">
        <div class="col-12">
            <?php if ($headId > 0): ?>
                <?php /* An existing record's control number identifies it, so it is shown
                         readonly here rather than re-typed; entry.php's own control-number
                         gate owns the create-mode field instead. */ ?>
                <?php if ($qrDataUri !== ''): ?>
                    <?php /* Decorative: alt="" so a screen reader does not read the number
                             twice, the Control Number field beside it already carries it. */ ?>
                    <div class="mb-3">
                        <img src="<?= esc($qrDataUri) ?>" width="64" height="64" alt="">
                    </div>
                <?php endif; ?>
                <label class="form-label" for="<?= esc($fieldPrefix, 'attr') ?>HeadQr">Control Number</label>
                <?php /* readonly, not disabled: the number identifies the record and has to
                         post with the form, and a disabled control submits nothing - which
                         update() then rejects as a missing QR Number. One input, so the
                         posted value is the one on screen. */ ?>
                <input id="<?= esc($fieldPrefix, 'attr') ?>HeadQr" name="qr_control_no" class="form-control text-muted fw-semibold" type="text"
                    value="<?= esc((string) ($head['qr_control_no'] ?? ''), 'attr') ?>" readonly>
                <?php if ($qrLocked): ?>
                    <small class="text-muted">Locked: subsidy already recorded under this number.</small>
                <?php endif; ?>
            <?php else: ?>
                <?php /* Filled by the entry page's control-number gate once the number checks
                         out available; this field posts it, the gate itself does not. */ ?>
                <input type="hidden" name="qr_control_no" value="" data-entry-control-number>
            <?php endif; ?>
        </div>

        <?= family_modal_render_person_fields([
            'personFields' => $personFields,
            'optionsByKey' => $personFieldOptions,
            'selectOptions' => $selectOptions,
            'field' => static fn (string $name): string => 'head_' . $name,
            'value' => static fn (string $name): string => $oldValue('head_' . $name),
            'idPrefix' => $fieldPrefix . 'Head',
            'summary' => true,
            'required' => true,
            'disabled' => $readOnly,
        ]) ?>

        <div class="w-100 d-none d-xl-block"></div>
        <div class="col-12 col-xl-8">
            <label class="form-label" for="<?= esc($fieldPrefix, 'attr') ?>HeadAddress">Address <span class="account-required-marker text-danger" aria-hidden="true">*</span></label>
            <input id="<?= esc($fieldPrefix, 'attr') ?>HeadAddress" name="head_address" class="form-control" type="text" value="<?= esc($oldValue('head_address'), 'attr') ?>" aria-describedby="<?= esc($fieldPrefix, 'attr') ?>HeadAddressFeedback" minlength="2" required<?= $readOnly ? ' disabled' : '' ?>>
            <div class="invalid-feedback" id="<?= esc($fieldPrefix, 'attr') ?>HeadAddressFeedback" data-family-field-error></div>
        </div>
        <div class="col-12 col-xl-4">
            <label class="form-label" for="<?= esc($fieldPrefix, 'attr') ?>HeadBarangay">Barangay <span class="account-required-marker text-danger" aria-hidden="true">*</span></label>
            <select id="<?= esc($fieldPrefix, 'attr') ?>HeadBarangay" name="head_barangay" class="form-select" aria-describedby="<?= esc($fieldPrefix, 'attr') ?>HeadBarangayFeedback" required<?= $readOnly ? ' disabled' : '' ?>>
                <?= $selectOptions($barangayOptions, $oldValue('head_barangay'), 'Barangay') ?>
            </select>
            <div class="invalid-feedback" id="<?= esc($fieldPrefix, 'attr') ?>HeadBarangayFeedback" data-family-field-error></div>
        </div>
    </div>

    <?= $renderChoicesBlock(
        $fieldPrefix . 'HeadChoices',
        'sector_ids[]',
        array_map('strval', (array) $selectedSectorIds),
        'service_ids[]',
        array_map('strval', (array) $selectedServiceIds),
        $fieldPrefix . 'Head',
        true,
        $readOnly
    ) ?>
</div>
<?php endif; ?>

<?php if ($part !== 'head'): ?>
<section<?= ($part === 'all' || $showMemberHeading) ? ' id="section-members"' : '' ?> class="family-members-section family-person-card">
    <?php /* The entry spine names this section on its step link, so the title here
             would be the same words twice; the edit page has no such label and
             keeps it. The count rides along in both. */ ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-4 pb-3 border-bottom mt-4 pt-2">
        <?php if ($showMemberHeading): ?>
            <h3 class="h6 mb-0 fw-bold text-dark"><i class="bi bi-people-fill me-2 text-muted"></i>Family Members</h3>
        <?php endif; ?>
        <span class="badge rounded-pill text-bg-light border px-3 py-2" data-family-members-count>0 members</span>
    </div>

    <div class="row g-3" data-family-members>
        <?php foreach (array_values($members) as $i => $member): ?>
            <div class="col-12" data-member-card>
                <?= $renderMemberRow($i, (array) $member, false) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="small text-body-secondary fst-italic border rounded py-3 text-center mb-0<?= $members === [] ? '' : ' d-none' ?>" data-family-members-empty>No members added yet.</p>

    <template data-family-member-template>
        <?= $renderMemberRow('__INDEX__', [], false) ?>
    </template>

    <template data-family-member-editor-template>
        <?= $renderMemberEditor('__INDEX__') ?>
    </template>

    <div class="family-member-toolbar">
        <?php /* Blue, not the add-green of a toolbar: this adds a row inside the form,
                 the form's own green Save is the page's one committing action. */ ?>
        <button class="btn btn-primary" type="button" data-family-add-member data-next-index="<?= esc((string) count($members), 'attr') ?>"<?= $readOnly ? ' disabled' : '' ?>>
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Member
        </button>
    </div>
</section>
<?php endif; ?>
<?php
// $part (and the rest of this partial's data) would otherwise leak into the
// next unrelated view() call the way components/card.php documents in full:
// CI4's view() defaults to $saveData = true, so the next render that omits
// $part would silently inherit whichever part this call asked for.
service('renderer')->resetData();
?>
