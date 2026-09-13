<?php
/** @var list<array<string, mixed>> $families */

$filterBarangay = trim((string) ($filterBarangay ?? ''));
$filterField = trim((string) ($filterField ?? ''));
$keyword = trim((string) ($keyword ?? ''));
$perPage = max(1, (int) ($perPage ?? 25));
?>
<?= view('components/table_controls', [
    'showSearch' => false,
    'sizeId' => 'cardReadinessPerPage',
    'sizeAction' => site_url('records/completeness'),
    'sizeHidden' => ['q' => $keyword, 'field' => $filterField, 'barangay' => $filterBarangay],
    'perPage' => $perPage,
]) ?>
<div class="table-responsive">
    <table class="table manage-record-table align-middle w-100 mb-0" id="completenessTable">
        <thead class="table-light">
        <tr>
            <th class="fw-semibold small text-nowrap">CONTROL NUMBER</th>
            <th class="fw-semibold small">HEAD</th>
            <th class="fw-semibold small">ADDRESS</th>
            <th class="fw-semibold small text-nowrap">BIRTHDAY</th>
            <th class="fw-semibold small text-nowrap">CONTACT</th>
            <th class="fw-semibold small">BARANGAY</th>
            <th class="fw-semibold small">MISSING CARD FIELDS</th>
            <th class="fw-semibold small text-nowrap">EDIT FAMILY</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($families as $family): ?>
            <?php $head = trim(implode(' ', array_filter([
                trim((string) ($family['firstname'] ?? '')),
                trim((string) ($family['lastname'] ?? '')),
                trim((string) ($family['suffix'] ?? '')),
            ], static fn (string $value): bool => $value !== ''))); ?>
            <tr>
                <td class="text-nowrap"><?= esc((string) ($family['control_no'] ?? 'MISSING')) ?></td>
                <td><?= esc($head !== '' ? $head : 'MISSING') ?></td>
                <td><?= esc((string) ($family['address'] ?? '')) ?></td>
                <td class="text-nowrap"><?= esc((string) ($family['birthday'] ?? '')) ?></td>
                <td class="text-nowrap"><?= esc((string) ($family['contactnumber'] ?? '')) ?></td>
                <td><?= esc((string) ($family['barangay'] ?? '')) ?></td>
                <td>
                    <?php foreach ((array) ($family['missing'] ?? []) as $missing): ?>
                        <span class="badge rounded-pill text-bg-danger me-1 mb-1"><?= esc((string) $missing) ?></span>
                    <?php endforeach; ?>
                </td>
                <td><a class="<?= btn('save') ?> btn-sm" href="<?= esc(site_url('records/' . (int) ($family['memberID'] ?? 0) . '/edit'), 'attr') ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($families === []): ?>
            <tr><td colspan="8" class="text-muted">No heads need card-readiness follow-up.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
