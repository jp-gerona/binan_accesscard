<?php
/**
 * Family Profile page in read view (`records/{id}`): the record printed, not the
 * record in a form. Every field is a small label above a bold value, the way a
 * fare ticket prints one, so nothing on the page can be typed into or submitted.
 * Sectors and programs print as a plain list. Editing lives on its own page
 * (`records/{id}/edit`), reached from the Edit button here.
 *
 * Data source: FamilyController::profile(), shaped by
 * App\Libraries\FamilyRecordSummary and documented as family_record_view_data()
 * in app/Helpers/dashboard_view_helper.php.
 */

$head = (array) ($head ?? []);
$members = (array) ($members ?? []);
$canEdit = (bool) ($canEdit ?? false);
$headId = (int) ($headId ?? 0);
$media = (array) ($media ?? []);
$mediaPhoto = is_string($media['photo'] ?? null) ? $media['photo'] : null;
$mediaSignature = is_string($media['signature'] ?? null) ? $media['signature'] : null;
$hasMedia = $mediaPhoto !== null || $mediaSignature !== null;
$mediaName = (string) ($head['name'] ?? '');

/** Prints one label/value pair as a ticket-style column. */
$field = static function (array $entry): string {
    return '<div class="col-6 col-md-4 col-xl-3 mb-2">'
        . '<div class="small text-muted text-uppercase fw-semibold mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">' . esc((string) $entry['label']) . '</div>'
        . '<div class="fw-bold fs-6 text-dark">' . esc((string) $entry['value']) . '</div>'
        . '</div>';
};

/** Prints a plain list of sector or program labels, or a dash when there are none. */
$list = static function (string $title, array $items): string {
    $body = $items === []
        ? '<div class="fw-bold fs-6 text-dark">-</div>'
        : '<ul class="list-unstyled mb-0 d-flex flex-column gap-2">'
            . implode('', array_map(static function (string $item): string {
                $parts = explode(' - ', $item, 2);
                if (count($parts) === 2) {
                    return '<li><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle me-2">' . esc($parts[0]) . '</span><span class="text-dark fw-medium">' . esc($parts[1]) . '</span></li>';
                }
                return '<li><span class="text-dark fw-medium">' . esc($item) . '</span></li>';
            }, $items))
            . '</ul>';

    return '<div class="col-12 col-md-6"><div class="small text-muted text-uppercase fw-semibold mb-2" style="font-size: 0.75rem; letter-spacing: 0.5px;">' . esc($title) . '</div>' . $body . '</div>';
};
?>
<div class="pb-4">
    <div class="d-flex flex-wrap justify-content-end align-items-center mb-4 gap-3">
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-secondary" href="<?= esc(site_url('records'), 'attr') ?>">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Records
            </a>
            <?php if ($canEdit): ?>
            <a class="<?= btn('save') ?> px-4" href="<?= esc(site_url('records/' . $headId . '/edit'), 'attr') ?>">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($hasMedia): ?>
    <div class="card shadow-none border mb-4">
        <div class="card-header bg-white border-bottom py-3">
            <h3 class="h6 mb-0 fw-bold text-dark"><i class="bi bi-images me-2 text-muted"></i>Family Media</h3>
        </div>
        <div class="card-body p-4">
            <div class="d-flex flex-wrap align-items-start gap-4">
                <?php if ($mediaPhoto !== null): ?>
                    <figure class="mb-0">
                        <img src="<?= esc(site_url($mediaPhoto), 'attr') ?>"
                             alt="<?= esc($mediaName === '' ? 'Head portrait' : 'Portrait of ' . $mediaName, 'attr') ?>"
                             class="img-thumbnail img-fluid">
                    </figure>
                <?php endif; ?>
                <?php if ($mediaSignature !== null): ?>
                    <figure class="mb-0">
                        <img src="<?= esc(site_url($mediaSignature), 'attr') ?>"
                             alt=""
                             class="img-thumbnail img-fluid">
                    </figure>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-none border mb-4">
        <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap align-items-center gap-3">
            <h3 class="h5 mb-0 fw-bold text-dark"><?= esc((string) ($head['name'] ?? '')) ?></h3>
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1">Head</span>
        </div>
        <div class="card-body p-4">
            <div class="row g-4">
                <?php foreach ((array) ($head['fields'] ?? []) as $entry): ?>
                    <?= $field((array) $entry) ?>
                <?php endforeach; ?>
            </div>
            <hr class="my-4 text-secondary opacity-25">
            <div class="row g-4">
                <?= $list('Sectors', (array) ($head['sectors'] ?? [])) ?>
                <?= $list('Services and Programs', (array) ($head['services'] ?? [])) ?>
            </div>
        </div>
    </div>

    <div class="card shadow-none border">
        <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h3 class="h6 mb-0 fw-bold text-dark"><i class="bi bi-people-fill me-2 text-muted"></i>Family Members</h3>
            <span class="badge rounded-pill text-bg-light border px-3 py-2"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body p-0">
            <?php if ($members === []): ?>
                <div class="p-4 text-center">
                    <p class="text-body-secondary fst-italic mb-0">No other members recorded in this family.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($members as $index => $member): ?>
                <?php $member = (array) $member; ?>
                <div class="p-4 <?= $index > 0 ? 'border-top' : '' ?> bg-white">
                    <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
                        <h4 class="h5 mb-0 fw-bold text-dark"><?= esc((string) ($member['name'] ?? '')) ?></h4>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle px-2 py-1"><?= esc((string) ($member['relationship'] ?? '')) ?></span>
                    </div>
                    <div class="row g-4">
                        <?php foreach ((array) ($member['fields'] ?? []) as $entry): ?>
                            <?= $field((array) $entry) ?>
                        <?php endforeach; ?>
                    </div>
                    <hr class="my-4 text-secondary opacity-25">
                    <div class="row g-4">
                        <?= $list('Sectors', (array) ($member['sectors'] ?? [])) ?>
                        <?= $list('Services and Programs', (array) ($member['services'] ?? [])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
