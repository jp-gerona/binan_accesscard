<?php

namespace App\Controllers\Families;

use App\Controllers\BaseController;
use App\Libraries\DashboardPageBuilder;
use App\Libraries\FamilyMediaStorage;
use App\Libraries\FamilyModalDataBuilder;
use App\Libraries\FamilyRecordSummary;
use App\Libraries\FamilyRecordWriteException;
use App\Libraries\FamilyRecordWriter;
use App\Libraries\Qr\ControlNumber;
use App\Libraries\Qr\QrImageGenerator;
use App\Libraries\RoleAccess;
use App\Libraries\SectorIds;
use App\Models\Audit\AuditTrailsModel;
use App\Models\Families\FamilyFormOptionsModel;
use App\Models\Families\FamilyMediaModel;
use App\Models\Families\MemberModel;
use App\Models\Families\MemberSectorModel;
use App\Models\Families\MemberServiceModel;
use App\Models\Lookups\BarangayModel;
use App\Models\Lookups\SectorModel;
use App\Models\Lookups\ServiceModel;
use App\Support\FamilyAgeEligibility;
use App\Support\MemberFieldNormalizer;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Navigation;
use Throwable;

/**
 * Family records for the admin and employee Manage Family screens: creating,
 * viewing, editing, archiving, restoring, and deleting.
 *
 * Validates the request, then hands creation to FamilyRecordWriter, which is the
 * single write path the Excel importer also goes through. The remaining screens
 * work through MemberModel and MemberServiceModel directly.
 *
 * createFamily() renders the Data Entry page (create only); profile() renders an
 * existing record read-only and edit() renders the same record as a form; the
 * archive, restore, and delete forms in `Family/list` post here and redirect back
 * to the list.
 */
class FamilyController extends BaseController
{
    use FamilyRequestContext;

    /** Folded barangay name to barangayID, loaded once per request by barangayIdMap(). */
    private ?array $barangayIdMap = null;

    /**
     * Saves a family registration submitted to POST `families` from the family
     * form (admin or employee). Runs in one DB transaction: creates the head in
     * `member`, adds each family member, links chosen services in
     * `member_services`, and logs a FAMILY_CREATED audit row. Frontend: the Data
     * Entry page posts via fetch(), so success/error come back as JSON (with a
     * fresh CSRF hash); a success response also carries `redirect` to `/records`,
     * which is how the page ends - see manage-family-modal.js's submit handler.
     * A non-AJAX fallback redirects back with the same flash message.
     */
    public function store()
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            if ($this->request->isAJAX()) {
                return $this->response
                    ->setStatusCode(403)
                    ->setJSON([
                        'status' => 'error',
                        'message' => 'You do not have permission to add family records.',
                        'csrf' => csrf_hash(),
                    ]);
            }

            return $guard;
        }

        $memberModel = new MemberModel();
        $memberServiceModel = new MemberServiceModel();
        $sectorModel = new SectorModel();
        $serviceModel = new ServiceModel();
        $auditModel = new AuditTrailsModel();

        if (! $memberModel->hasRequiredFamilyTables()) {
            $message = 'The accesscard database is missing required tables. Import the newest accesscardV*.sql from the project root.';

            if ($this->request->isAJAX()) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'status' => 'error',
                        'message' => $message,
                        'csrf' => csrf_hash(),
                    ]);
            }

            return redirect()->back()->withInput()->with('error', $message);
        }

        if ($this->submissionWasTruncated()) {
            $message = 'The form was too large and some member data was cut off before it reached the server, so nothing was saved. Please add fewer members at a time (or ask an administrator to raise the server\'s max_input_vars) and try again.';

            if ($this->request->isAJAX()) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'status' => 'error',
                        'message' => $message,
                        'code' => 'FORM_TRUNCATED',
                        'csrf' => csrf_hash(),
                    ]);
            }

            return redirect()->back()->withInput()->with('error', $message);
        }

        $entryType = $this->entryType();
        $rules = $this->rulesForEntryType($entryType);

        if (! $this->validate($rules)) {
            $message = implode(' ', $this->validator->getErrors());

            if ($this->request->isAJAX()) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'status' => 'error',
                        'message' => $message,
                        'csrf' => csrf_hash(),
                    ]);
            }

            return redirect()->back()
                ->withInput()
                ->with('error', $message);
        }

        $serviceIds = $this->request->getPost('service_ids');

        if (! is_array($serviceIds)) {
            $serviceIds = [];
        }

        $members = $this->request->getPost('members');

        if (! is_array($members)) {
            $members = [];
        }

        if ($incomplete = $this->firstIncompleteMember($members)) {
            return $this->storeError($incomplete);
        }

        if ($eligibilityError = $this->firstAgeEligibilityError($members, $sectorModel, $serviceModel)) {
            return $this->storeError($eligibilityError);
        }

        $userId = (int) session()->get('user_id');
        $successMessage = 'Family record saved successfully.';

        $controlNo = (int) $this->request->getPost('qr_control_no');

        if (model(\App\Models\Scanner\QrControlModel::class)->takenByOtherHead($controlNo, 0)) {
            return $this->storeError('QR Number ' . $controlNo . ' already exists in the records and is assigned to another family.');
        }

        // Shape the additional members (skipping the form's empty rows) into the
        // [payload + serviceIds] entries FamilyRecordWriter expects.
        $memberPayloads = [];

        foreach ($members as $member) {
            if (! is_array($member) || ! $this->hasMemberData($member)) {
                continue;
            }

            $memberServiceIds = $member['service_ids'] ?? [];

            $memberPayloads[] = [
                'payload' => $this->memberPayloadFromArray($member),
                'serviceIds' => is_array($memberServiceIds) ? array_map('intval', $memberServiceIds) : [],
            ];
        }

        // One family = one transaction. The persistence itself lives in
        // FamilyRecordWriter so the Excel importer reuses the exact same write path.
        $writer = new FamilyRecordWriter($memberModel, $memberServiceModel, $serviceModel, $auditModel);

        $memberModel->beginTransaction();

        try {
            $headId = $writer->persistFamily(
                $this->memberPayload('head_'),
                $memberPayloads,
                array_map('intval', $serviceIds),
                $userId,
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString(),
                '',
                $controlNo
            );
        } catch (Throwable $exception) {
            $memberModel->rollbackTransaction();

            // persistFamily can also throw beyond FamilyRecordWriteException (QR
            // assignment, audit, or an unexpected DB error). Catch them all so the
            // transaction is always rolled back and the request fails gracefully.
            if (! $exception instanceof FamilyRecordWriteException) {
                // Unexpected failure - record it like import()/changeFamilyState()
                // do, so silent write failures surface on the audit page.
                $this->auditSystemError('saving a family record', $exception);
            }

            return $this->storeError(
                $exception instanceof FamilyRecordWriteException
                    ? $exception->getMessage()
                    : 'The family record was not saved.'
            );
        }

        $memberModel->completeTransaction();

        if (! $memberModel->transactionStatus()) {
            return $this->storeError('The family form was not saved.', 500);
        }

        $this->storeOptionalMedia($headId, $controlNo);

        // Set on a new record save only, never on edit/update.
        session()->setFlashdata('family_record_saved', '1');
        session()->setFlashdata('success', $successMessage);

        if ($this->request->isAJAX()) {
            // The Data Entry page always posts through fetch(), so this is the only
            // response it ever sees: it navigates itself to `redirect` on success
            // rather than staying on a spent form (manage-family-modal.js).
            return $this->response->setJSON([
                'status'   => 'success',
                'message'  => $successMessage,
                'redirect' => site_url('records'),
                'csrf'     => csrf_hash(),
            ]);
        }

        return redirect()->back();
    }

    /**
     * GET `records/{id}`: the Family Profile page, read-only. It prints the record
     * as label/value pairs - no controls, no form, nothing to submit - so a reader
     * cannot edit by accident whatever their role. Editing is its own page,
     * `records/{id}/edit`, linked from here for the roles that may reach it.
     */
    public function profile(int $headId): string|RedirectResponse
    {
        $context = $this->familyProfileContext($headId);

        if ($context instanceof RedirectResponse) {
            return $context;
        }

        $summary = new FamilyRecordSummary($context['sectors'], $context['services'], $context['incomeLabels']);
        $role = RoleAccess::normalizeRole((string) session()->get('role'));

        helper('dashboard_view_helper');

        $media = $this->familyMediaForProfile($role, $headId);

        return view('layout', DashboardPageBuilder::shellAccountData() + [
            'activePage' => 'records-profile',
            'role'       => $role,
            'bodyView'   => 'Family/profile-view',
            'bodyData'   => family_record_view_data([
                'headId'  => $headId,
                'head'    => $summary->head($context['head'], $context['controlNumber']),
                'members' => $summary->members($context['members']),
                'canEdit' => in_array($role, Navigation::pageRoles('records-edit'), true),
                'media'   => $media,
            ]),
        ]);
    }

    /**
     * The private media URLs for a record's read profile, or two nulls when the
     * session's role may not view family media. URLs come from the linked
     * registry rows (`media_url`) and stay relative; the view turns them into
     * usable src attributes. Members who are not heads already have no linked
     * rows, so a head-id check is implicit in the query.
     *
     * @return array{photo: ?string, signature: ?string}
     */
    private function familyMediaForProfile(string $role, int $headId): array
    {
        if (! in_array($role, Navigation::pageRoles('records-media'), true)) {
            return ['photo' => null, 'signature' => null];
        }

        $media = new FamilyMediaModel();

        return [
            'photo'     => $this->linkedMediaUrl($media->findLinked($headId, FamilyMediaModel::KIND_PHOTO)),
            'signature' => $this->linkedMediaUrl($media->findLinked($headId, FamilyMediaModel::KIND_SIGNATURE)),
        ];
    }

    /** The private relative URL a linked media row stores, or null when blank. */
    private function linkedMediaUrl(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }

        $url = trim((string) ($row['media_url'] ?? ''));

        return $url === '' ? null : $url;
    }

    /**
     * GET `records/{id}/edit`: the editable form for an existing record, the same
     * Family/_fields partial the Data Entry page renders. The manifest keeps
     * read-only roles off this route entirely, so the page it renders is always
     * editable and always carries its Save button.
     */
    public function edit(int $headId): string|RedirectResponse
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $context = $this->familyProfileContext($headId);

        if ($context instanceof RedirectResponse) {
            return $context;
        }

        helper('dashboard_view_helper');

        return view('layout', DashboardPageBuilder::shellAccountData() + [
            'activePage' => 'records-edit',
            'role'       => RoleAccess::normalizeRole((string) session()->get('role')),
            'bodyView'   => 'Family/profile',
            'bodyData'   => family_profile_view_data([
                'head'          => $context['head'],
                'members'       => $context['members'],
                'controlNumber' => $context['controlNumber'],
                'readOnly'      => false,
                'sectors'       => $context['sectors'],
                'services'      => $context['services'],
                'categories'    => $context['categories'],
                'formOptions'   => $context['formOptions'],
            ]),
        ]);
    }

    /**
     * Loads one family and shapes it for either profile page: the head row with its
     * address split and sector/service ids attached, the shaped member rows, the QR
     * control number, and the sector/service/category lookups (which grandfather in
     * anything archived but still assigned). Returns a redirect when the caller may
     * not view records or the head does not exist.
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function familyProfileContext(int $headId): array|RedirectResponse
    {
        $guard = $this->requireFamilyViewAccess();

        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $memberModel = new MemberModel();
        $rows = $memberModel->getFamilyMembers($headId, 'all');
        [$head, $members] = $this->splitHeadAndMembers($rows, $headId);

        if ($head === null) {
            return redirect()->to(site_url('records'))->with('error', 'That family record could not be found. It may have been removed.');
        }

        $memberServiceModel = new MemberServiceModel();
        $serviceIdsByMember = $memberServiceModel
            ->getServiceIdsByMemberIds(array_map(static fn (array $row): int => (int) $row['memberID'], $rows));

        $modalData = new FamilyModalDataBuilder();
        $qrModel = model(\App\Models\Scanner\QrControlModel::class);
        $controlNumber = (int) ($qrModel->controlForHead($headId) ?? 0);

        $headData = array_merge($head, [
            // Both come straight off the row now: the address column holds the
            // address alone, and the barangay name arrives from the barangayID
            // join, so the form prefills from stored values rather than from a
            // string split that had to guess where the barangay began.
            'address'       => (string) ($head['address'] ?? ''),
            'barangay'      => (string) ($head['barangay'] ?? ''),
            'salary'        => MemberFieldNormalizer::salaryOptionValue($head['salary'] ?? null),
            'qr_control_no' => (string) $controlNumber,
            'sector_ids'    => array_map('strval', SectorIds::normalize($head['sector_ids'] ?? null)),
            'service_ids'   => array_map('strval', $serviceIdsByMember[$headId] ?? []),
            'qr_locked'     => $controlNumber > 0
                && model(\App\Models\Scanner\SubsidyDistributionModel::class)->hasClaims($controlNumber),
        ]);

        // Every sector/service ID assigned anywhere in the family (head or any
        // member), so getViewDataForEdit() can grandfather in anything since
        // archived - otherwise it would render unchecked, post unchecked, and
        // update() would delete the assignment on save.
        $assignedSectorIds = SectorIds::normalize($head['sector_ids'] ?? null);
        $assignedServiceIds = $serviceIdsByMember[$headId] ?? [];

        foreach ($members as $member) {
            $assignedSectorIds = array_merge($assignedSectorIds, SectorIds::normalize($member['sector_ids'] ?? null));
            $assignedServiceIds = array_merge($assignedServiceIds, $serviceIdsByMember[(int) ($member['memberID'] ?? 0)] ?? []);
        }

        $options = (new FamilyFormOptionsModel())->getViewDataForEdit(
            array_values(array_unique(array_map('intval', $assignedSectorIds))),
            array_values(array_unique(array_map('intval', $assignedServiceIds)))
        );
        return [
            'head'          => $headData,
            'members'       => $modalData->shapeMembers($members, $serviceIdsByMember),
            'controlNumber' => $controlNumber,
            'sectors'       => $options['sectorOptions'],
            'services'      => $options['serviceOptions'],
            'categories'    => array_keys($options['servicesByCategory']),
            'formOptions'   => $modalData->staticOptionLists(),
            'incomeLabels'  => $modalData->incomeLabelMap(),
        ];
    }

    /**
     * POST `records/{id}/update`: saves edits to a family.
     * Runs in one transaction: updates the head, replaces the member list, re-syncs
     * service assignments, and logs a FAMILY_UPDATED audit row. Mirrors store()'s
     * AJAX/non-AJAX response handling.
     */
    public function update(int $headId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->request->isAJAX()
                ? $this->jsonError('You do not have permission to edit family records.', 403)
                : $guard;
        }

        $memberModel = new MemberModel();
        $memberServiceModel = new MemberServiceModel();
        $sectorModel = new SectorModel();
        $serviceModel = new ServiceModel();
        $auditModel = new AuditTrailsModel();

        if (! $memberModel->hasRequiredFamilyTables()) {
            return $this->failUpdate('The accesscard database is missing required tables. Import the newest accesscardV*.sql from the project root.', 422);
        }

        if ($this->submissionWasTruncated()) {
            return $this->failUpdate('The form was too large and some member data was cut off before it reached the server, so nothing was saved. Please edit fewer members at a time (or ask an administrator to raise the server\'s max_input_vars) and try again.', 422, 'FORM_TRUNCATED');
        }

        $existingHead = $memberModel->find($headId);

        if ($existingHead === null || (int) ($existingHead['headID'] ?? 0) !== $headId) {
            return $this->failUpdate('That family record no longer exists.', 404);
        }

        $rules = $this->rulesForEntryType('head');

        if (! $this->validate($rules)) {
            return $this->failUpdate(implode(' ', $this->validator->getErrors()), 422);
        }

        $serviceIds = $this->request->getPost('service_ids');
        $serviceIds = is_array($serviceIds) ? $serviceIds : [];
        $members = $this->request->getPost('members');
        $members = is_array($members) ? $members : [];

        if ($incomplete = $this->firstIncompleteMember($members)) {
            return $this->failUpdate($incomplete, 422);
        }

        if ($eligibilityError = $this->firstAgeEligibilityError($members, $sectorModel, $serviceModel)) {
            return $this->failUpdate($eligibilityError, 422);
        }

        $userId = (int) session()->get('user_id');

        $qrModel        = model(\App\Models\Scanner\QrControlModel::class);
        $currentControl = $qrModel->controlForHead($headId);
        $locked         = $currentControl !== null
            && model(\App\Models\Scanner\SubsidyDistributionModel::class)->hasClaims($currentControl);

        // Locked heads keep their number: ignore any submitted change (defense in
        // depth in case the readonly field was tampered with).
        $controlNo = $locked ? (int) $currentControl : (int) $this->request->getPost('qr_control_no');

        if (! $locked) {
            if ($controlNo <= 0) {
                return $this->failUpdate('QR Number is required.', 422);
            }
            if ($qrModel->takenByOtherHead($controlNo, $headId)) {
                return $this->failUpdate('QR Number ' . $controlNo . ' already exists in the records and is assigned to another family.', 422);
            }
        }

        $memberModel->beginTransaction();

        // Snapshot the family's current service IDs before clearing, so archived-but-
        // already-assigned services survive the re-save (they fail the active-only
        // existsById() check, so they must be grandfathered through assignServices()).
        $familyMemberIds = $memberModel->getFamilyMemberIds($headId);
        $grandfatheredServiceIds = $this->collectAssignedServiceIds($memberServiceModel, $familyMemberIds);

        // Clear the family's existing service links and relatives, then rebuild both
        // from the submission so the edit fully replaces the prior member list.
        $memberServiceModel->deleteByMemberIds($familyMemberIds);

        // Sector links are cleared with the service links: the relatives are about
        // to be deleted and re-inserted, and member_sectors carries a foreign key
        // to member, so rows left behind would block that delete.
        $memberSectorModel = new MemberSectorModel();
        $memberSectorModel->deleteByMemberIds($familyMemberIds);

        $headPayload    = $this->memberPayload('head_');
        $headSectorIds  = $headPayload['sector_ids'] ?? [];
        unset($headPayload['sector_ids']);

        if (! $memberModel->updateHead($headId, $headPayload)) {
            $memberModel->rollbackTransaction();

            return $this->failUpdate('Head of family could not be updated. Please check required fields.', 422);
        }

        if (! $memberSectorModel->replaceForMember($headId, $this->existingSectorIds($sectorModel, $headSectorIds))) {
            $memberModel->rollbackTransaction();

            return $this->failUpdate('The head of family\'s sectors could not be saved.', 422);
        }

        if (! $locked) {
            try {
                $qrModel->upsertForHead($controlNo, $headId);
            } catch (\Throwable $e) {
                $memberModel->rollbackTransaction();

                return $this->failUpdate($e->getMessage(), 422);
            }
        }

        $memberModel->deleteFamilyMembersExceptHead($headId);

        foreach ($members as $member) {
            if (! is_array($member) || ! $this->hasMemberData($member)) {
                continue;
            }

            $memberPayload   = $this->memberPayloadFromArray($member);
            $memberSectorIds = $memberPayload['sector_ids'] ?? [];
            unset($memberPayload['sector_ids']);

            $memberId = $memberModel->addFamilyMember($headId, $memberPayload);

            if ($memberId === false) {
                $memberModel->rollbackTransaction();

                return $this->failUpdate('One family member could not be saved.', 422);
            }

            if (! $memberSectorModel->replaceForMember($memberId, $this->existingSectorIds($sectorModel, $memberSectorIds))) {
                $memberModel->rollbackTransaction();

                return $this->failUpdate('One family member\'s sectors could not be saved.', 422);
            }

            if (! $this->assignServices($memberServiceModel, $serviceModel, $memberId, $member['service_ids'] ?? [], $grandfatheredServiceIds)) {
                $memberModel->rollbackTransaction();

                return $this->failUpdate('A selected service could not be assigned to one family member.', 422);
            }
        }

        if (! $this->assignServices($memberServiceModel, $serviceModel, $headId, $serviceIds, $grandfatheredServiceIds)) {
            $memberModel->rollbackTransaction();

            return $this->failUpdate('A selected service could not be assigned to the head of family.', 422);
        }

        if ($auditModel->hasTable()) {
            $headName = trim(trim((string) $this->request->getPost('head_firstname')) . ' ' . trim((string) $this->request->getPost('head_lastname')));
            $memberCount = is_array($members) ? count($members) : 0;
            $serviceCount = is_array($serviceIds) ? count($serviceIds) : 0;
            $auditModel->logAction(
                $userId,
                $headId,
                'FAMILY_UPDATED',
                'Updated family profile for ' . $headName . '.',
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString(),
                'Head of family: ' . $headName . '; ' . $memberCount . ' member(s) in household; '
                    . $serviceCount . ' service(s) on the head after update'
            );
        }

        $memberModel->completeTransaction();

        if (! $memberModel->transactionStatus()) {
            return $this->failUpdate('The family record was not updated.', 500);
        }

        $this->storeOptionalMedia($headId, $controlNo);
        $successMessage = 'Family record updated successfully.';

        session()->setFlashdata('success', $successMessage);

        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'status'   => 'success',
                'message'  => $successMessage,
                'redirect' => site_url('records/' . $headId),
                'csrf'     => csrf_hash(),
            ]);
        }

        return redirect()->to(site_url('records/' . $headId));
    }

    /**
     * POST `records/{id}/archive`: soft-archives an entire
     * family (Developer/Admin/Employee) and audits it. Frontend: the "Archive"
     * action in the records list; redirects back with a flash message.
     */
    public function archive(int $headId): RedirectResponse
    {
        return $this->changeFamilyState(
            $headId,
            ['Developer', 'Admin'],
            static fn (MemberModel $model): bool => $model->archiveFamily($headId),
            'FAMILY_ARCHIVE',
            'Archived',
            'Record archived successfully.',
            'Unable to archive record.'
        );
    }

    /**
     * POST `records/{id}/restore`: restores a soft-archived
     * family (Developer/Admin/Employee) and audits it. Frontend: the "Restore"
     * action on the archived records view.
     */
    public function restore(int $headId): RedirectResponse
    {
        return $this->changeFamilyState(
            $headId,
            ['Developer', 'Admin'],
            static fn (MemberModel $model): bool => $model->restoreFamily($headId),
            'FAMILY_RESTORE',
            'Restored',
            'Record restored successfully.',
            'Unable to restore record.'
        );
    }

    /**
     * Shared flow for the archive/restore/delete actions: role-guards the request,
     * runs the supplied state change on MemberModel, audits it, and redirects back
     * with a success/error flash message.
     *
     * @param list<string> $roles
     */
    private function changeFamilyState(int $headId, array $roles, callable $action, string $auditAction, string $auditVerb, string $successMessage, string $errorMessage): RedirectResponse
    {
        $guard = RoleAccess::requireRole($roles);

        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $model = new MemberModel();

        if (! $model->hasTable()) {
            return redirect()->to($this->listUrlWithoutDeepSearch())->with('error', 'The family records table is not available.');
        }

        $name = $this->familyHeadName($model, $headId);

        try {
            $changed = $action($model);
        } catch (Throwable $exception) {
            $this->auditSystemError(strtolower($auditVerb) . ' family record #' . $headId, $exception);

            return redirect()->to($this->listUrlWithoutDeepSearch())->with('error', $errorMessage);
        }

        if (! $changed) {
            return redirect()->to($this->listUrlWithoutDeepSearch())->with('error', $errorMessage);
        }

        $auditModel = new AuditTrailsModel();

        if ($auditModel->hasTable()) {
            $auditModel->logAction(
                (int) session()->get('user_id'),
                $headId,
                $auditAction,
                $auditVerb . ' family record ' . $name . ' #' . $headId . '.',
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString(),
                $auditVerb . ' the entire family record headed by ' . $name . ' (head #' . $headId . ')'
            );
        }

        return redirect()->to($this->listUrlWithoutDeepSearch())->with('success', $successMessage);
    }

    /**
     * Builds a redirect URL from the HTTP Referer but strips the deep-search
     * parameters (`search_scope`, `deep_q`, `deep_page`) so that archiving or
     * restoring a record never lands back on the database-search results panel.
     * Falls back to the clean manage-records page when the Referer is absent or
     * points to a different host.
     */
    private function listUrlWithoutDeepSearch(): string
    {
        $fallback = site_url('records');
        $referer  = (string) ($this->request->getServer('HTTP_REFERER') ?? '');

        if ($referer === '') {
            return $fallback;
        }

        $parsed = parse_url($referer);

        if (($parsed['host'] ?? '') !== (string) ($this->request->getServer('HTTP_HOST') ?? '')) {
            return $fallback;
        }

        parse_str($parsed['query'] ?? '', $params);
        unset($params['search_scope'], $params['deep_q'], $params['deep_page']);

        $query = http_build_query($params);

        return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '/') . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Splits a family's rows (head + relatives) into [head, members]. The head is
     * the row whose memberID equals its headID; everything else is a relative.
     *
     * @return array{0: ?array, 1: list<array>}
     */
    private function splitHeadAndMembers(array $rows, int $headId): array
    {
        $head = null;
        $members = [];

        foreach ($rows as $row) {
            if ((int) ($row['memberID'] ?? 0) === $headId) {
                $head = $row;
            } else {
                $members[] = $row;
            }
        }

        return [$head, $members];
    }

    /**
     * Keeps only the submitted sector ids that still have a sector row, archived
     * or not. member_sectors carries a foreign key, so a stale id from a tampered
     * or long-open form would otherwise fail the whole save; an archived sector a
     * family already holds is kept, matching how assignServices() grandfathers
     * archived services.
     *
     * @param int[] $sectorIds
     * @return int[]
     */
    private function existingSectorIds(SectorModel $sectorModel, array $sectorIds): array
    {
        return array_values(array_filter(
            SectorIds::normalize($sectorIds),
            static fn (int $sectorId): bool => $sectorModel->existsById($sectorId)
        ));
    }

    /**
     * Validates and links a set of selected service IDs to one member inside the
     * update transaction. A service is accepted when it is an active service, OR it
     * is in $grandfatheredServiceIds - the set the family already held before this
     * edit - so archived-but-assigned services are preserved rather than dropped.
     * Other invalid/non-existent services are skipped; returns false only when a
     * valid service fails to link (so the caller can roll back).
     *
     * @param list<int> $grandfatheredServiceIds
     */
    private function assignServices(MemberServiceModel $memberServiceModel, ServiceModel $serviceModel, int $memberId, mixed $serviceIds, array $grandfatheredServiceIds = []): bool
    {
        if (! is_array($serviceIds)) {
            return true;
        }

        $grandfathered = array_flip(array_map('intval', $grandfatheredServiceIds));

        foreach ($serviceIds as $serviceId) {
            $serviceId = (int) $serviceId;

            if ($serviceId < 0) {
                continue;
            }

            if (! isset($grandfathered[$serviceId]) && ! $serviceModel->existsById($serviceId)) {
                continue;
            }

            if ($memberServiceModel->assignService($memberId, $serviceId) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Flat list of distinct service IDs currently assigned across the given members.
     * Used to grandfather archived-but-assigned services through an update re-save.
     *
     * @param list<int> $memberIds
     * @return list<int>
     */
    private function collectAssignedServiceIds(MemberServiceModel $memberServiceModel, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $ids = [];

        foreach ($memberServiceModel->getServiceIdsByMemberIds($memberIds) as $serviceIds) {
            foreach ($serviceIds as $serviceId) {
                $ids[] = (int) $serviceId;
            }
        }

        return array_values(array_unique($ids));
    }

    /** First + last name of a family head, for audit descriptions ('record' if missing). */
    private function familyHeadName(MemberModel $model, int $headId): string
    {
        $head = $model->find($headId);

        if ($head === null) {
            return 'record';
        }

        $name = trim((string) ($head['firstname'] ?? '') . ' ' . (string) ($head['lastname'] ?? ''));

        return $name === '' ? 'record' : $name;
    }

    /**
     * Update-failure response: JSON error for AJAX, otherwise a redirect back with
     * the input preserved and an error flash. Used throughout update(). Optional
     * $code is forwarded to the JSON body for the AJAX path.
     */
    private function failUpdate(string $message, int $statusCode, ?string $code = null)
    {
        if ($this->request->isAJAX()) {
            return $this->jsonError($message, $statusCode, $code);
        }

        return redirect()->back()->withInput()->with('error', $message);
    }

    /**
     * Create-failure response for store(): JSON error for AJAX (status defaults to
     * 422), otherwise a redirect back with input preserved and an error flash.
     * Mirrors the original per-step error handling that lived inline in store().
     */
    private function storeError(string $message, int $statusCode = 422)
    {
        if ($this->request->isAJAX()) {
            return $this->jsonError($message, $statusCode);
        }

        return redirect()->back()->withInput()->with('error', $message);
    }

    /**
     * Stores optional head media only after the family transaction commits, then
     * resolves the canonical files immediately. Media errors are warnings because
     * the family save has already succeeded and must not be rolled back.
     *
     * @return list<string>
     */
    private function storeOptionalMedia(int $headId, int $controlNo): array
    {
        if ($headId <= 0 || $controlNo <= 0) {
            return ['The optional media could not be uploaded.'];
        }

        $uploads = [
            'head_photo' => [FamilyMediaModel::KIND_PHOTO, 'portrait photo'],
            'head_signature' => [FamilyMediaModel::KIND_SIGNATURE, 'signature'],
        ];
        $storage = new FamilyMediaStorage();
        $warnings = [];
        $storedFilenames = [];

        foreach ($uploads as $field => [$kind, $label]) {
            $file = $this->request->getFile($field);

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            try {
                $inspection = $storage->storeUpload($file, $controlNo, $kind);
                if ($inspection === []) {
                    $warnings[] = 'The optional ' . $label . ' could not be uploaded.';

                    continue;
                }

                $storedFilenames[] = $inspection['source_filename'];
            } catch (Throwable) {
                $warnings[] = 'The optional ' . $label . ' could not be uploaded.';
            }
        }

        if ($storedFilenames === []) {
            return $warnings;
        }

        try {
            // Reconcile immediately so the UI reflects the change on refresh.
            $reconciler = new \App\Libraries\FamilyMediaReconciler($storage);
            $reconciler->resolveInboxFiles($storedFilenames);
        } catch (Throwable) {
            $warnings[] = 'The optional media was saved but could not be linked instantly.';
        }

        return $warnings;
    }

    /**
     * Builds a `member` table row from prefixed POST fields (e.g. `head_`),
     * normalizing money, optional text, and the multi-select sector IDs. Used for
     * the head of family. Maps form field names to DB column names.
     */
    private function memberPayload(string $prefix): array
    {
        return [
            'firstname' => $this->cleanName($this->request->getPost($prefix . 'firstname')),
            'middlename' => $this->cleanName($this->request->getPost($prefix . 'middlename')),
            'lastname' => $this->cleanName($this->request->getPost($prefix . 'lastname')),
            'suffix' => $this->nullableText($this->request->getPost($prefix . 'suffix')),
            'birthday' => $this->request->getPost($prefix . 'birthday'),
            'civilstatus' => $this->nullableUpperText($this->request->getPost($prefix . 'civilstatus')),
            'sex' => $this->nullableText($this->request->getPost($prefix . 'sex')),
            'education' => $this->nullableUpperText($this->request->getPost($prefix . 'education')),
            'job' => $this->nullableUpperText($this->request->getPost($prefix . 'job')),
            'salary' => $this->moneyOrNull($this->request->getPost($prefix . 'salary')),
            'contactnumber' => $this->nullableText($this->request->getPost($prefix . 'contactnumber')),
            'religion' => $this->nullableUpperText($this->request->getPost($prefix . 'religion')),
            'address' => $this->nullableText($this->cleanAddress($this->request->getPost($prefix . 'address'))),
            'barangayID' => $this->resolveBarangayId($this->request->getPost($prefix . 'barangay')),
            'relationship' => $prefix === 'head_' ? 'HEAD' : $this->nullableUpperText($this->request->getPost($prefix . 'relationship')),
            'sector_ids' => SectorIds::normalize($this->request->getPost('sector_ids')),
        ];
    }

    /**
     * Resolves a Barangay select's text value to its `barangay.barangayID`, matching
     * case-insensitively and tolerant of the ñ/Sto./Sta. spelling variants the form
     * and the Excel importer's dropdown both allow (MemberFieldNormalizer::
     * barangayKey()). Null on a blank or unrecognised value - the record still
     * saves, it just groups under "Unassigned" in the barangay rollup until
     * corrected. The map is loaded once per request, not once per member.
     */
    private function resolveBarangayId(mixed $barangayText): ?int
    {
        $text = trim((string) $barangayText);

        if ($text === '') {
            return null;
        }

        return $this->barangayIdMap()[MemberFieldNormalizer::barangayKey($text)] ?? null;
    }

    /** Folded barangay name to barangayID, memoized for the life of the request. */
    private function barangayIdMap(): array
    {
        return $this->barangayIdMap ??= (new BarangayModel())->idByNormalizedName();
    }

    /**
     * Detects a POST silently truncated by PHP's max_input_vars. The family form
     * posts a trailing `_form_end` sentinel (the first field dropped when the limit
     * is hit, since it is last in the body) and an early `members_meta_count` the
     * client sets to its live member-row count. If the sentinel is missing, or fewer
     * member rows arrived than the client promised, the submission was cut short -
     * the caller must reject it so no partial family record is ever saved.
     */
    private function submissionWasTruncated(): bool
    {
        if (strtolower((string) $this->request->getMethod()) !== 'post') {
            return false;
        }

        if ((string) $this->request->getPost('_form_end') !== '1') {
            return true;
        }

        $expected = (int) $this->request->getPost('members_meta_count');
        $members = $this->request->getPost('members');
        $received = is_array($members) ? count($members) : 0;

        return $received < $expected;
    }

    /**
     * Reads the `entry_type` POST flag to decide whether this submission is a new
     * head ('head') or an added member ('member'); drives which rules apply.
     */
    private function entryType(): string
    {
        return (string) $this->request->getPost('entry_type') === 'member' ? 'member' : 'head';
    }

    /**
     * Returns the validation ruleset for the given entry type. Members require a
     * parent head id and member name fields; heads require name/birthday/sex plus
     * civil status, education, job, monthly income, address and barangay. Sectors are
     * optional, but any IDs supplied must be well-formed.
     */
    private function rulesForEntryType(string $entryType): array
    {
        $rules = [
            'sector_ids' => 'permit_empty|valid_sector_array',
        ];

        if ($entryType === 'member') {
            return $rules + [
                'family_head_id' => 'required|is_natural_no_zero',
                'member_firstname' => 'required|max_length[100]',
                'member_lastname' => 'required|max_length[100]',
                'member_middlename' => 'permit_empty|max_length[50]',
                'member_birthday' => 'permit_empty|valid_date[Y-m-d]',
                'member_sex' => 'permit_empty|in_list[MALE,FEMALE]',
            ];
        }

        return $rules + [
            'head_firstname' => 'required|max_length[100]',
            'head_middlename' => 'permit_empty|max_length[50]',
            'head_lastname' => 'required|max_length[100]',
            'head_birthday' => 'required|valid_date[Y-m-d]|not_future_date',
            'head_sex' => 'required|in_list[MALE,FEMALE]',
            'head_civilstatus' => 'required|min_length[2]',
            'head_education' => 'required|min_length[2]',
            'head_job' => 'required|min_length[2]',
            'head_salary' => 'required',
            'head_address' => 'required|min_length[2]|max_length[255]',
            'head_barangay' => 'required|max_length[100]',
            'qr_control_no' => 'required|is_natural_no_zero|less_than_equal_to[9999999]',
            'head_photo' => 'permit_empty|is_image[head_photo]|ext_in[head_photo,jpg,jpeg]|max_size[head_photo,5120]',
            'head_signature' => 'permit_empty|is_image[head_signature]|ext_in[head_signature,png]|max_size[head_signature,5120]',
        ];
    }

    /**
     * Like memberPayload() but builds a `member` row from one entry of the
     * repeated `members[]` array (additional family members) instead of prefixed
     * POST fields. Each member keeps an independent sector selection.
     */
    private function memberPayloadFromArray(array $member): array
    {
        return [
            'firstname' => $this->cleanName($member['firstname'] ?? ''),
            'middlename' => $this->cleanName($member['middlename'] ?? ''),
            'lastname' => $this->cleanName($member['lastname'] ?? ''),
            'suffix' => $this->nullableText($member['suffix'] ?? null),
            'birthday' => $member['birthday'] ?? null,
            'civilstatus' => $this->nullableUpperText($member['civilstatus'] ?? null),
            'sex' => $this->nullableText($member['sex'] ?? null),
            'education' => $this->nullableUpperText($member['education'] ?? null),
            'job' => $this->nullableUpperText($member['job'] ?? null),
            'salary' => $this->moneyOrNull($member['salary'] ?? null),
            'contactnumber' => $this->nullableText($member['contactnumber'] ?? null),
            'religion' => $this->nullableUpperText($member['religion'] ?? null),
            // Members inherit the head's address and barangay: the form asks for
            // them once, on the head.
            'address' => $this->nullableText($this->cleanAddress($this->request->getPost('head_address'))),
            'barangayID' => $this->resolveBarangayId($this->request->getPost('head_barangay')),
            'relationship' => $this->nullableUpperText($member['relationship'] ?? 'MEMBER'),
            'sector_ids' => SectorIds::normalize($member['sector_ids'] ?? []),
        ];
    }

    /**
     * True only when a `members[]` row has at least a first and last name, so the
     * form's empty extra-member rows are skipped instead of saved.
     */
    private function hasMemberData(array $member): bool
    {
        return trim((string) ($member['firstname'] ?? '')) !== ''
            && trim((string) ($member['lastname'] ?? '')) !== '';
    }

    /**
     * The first "this member is incomplete" message, or null when every real member row
     * carries the required personal fields. Members need the same profile fields as the head
     * (Date of birth, Sex, Civil status, Education, Job, Monthly income); Address/Barangay are
     * the head's and inherited. Empty template rows (no name) are skipped via hasMemberData().
     */
    private function firstIncompleteMember(array $members): ?string
    {
        $required = [
            'birthday'    => 'Date of Birth',
            'sex'         => 'Sex',
            'civilstatus' => 'Civil Status',
            'education'   => 'Education',
            'job'         => 'Job',
            'salary'      => 'Monthly Income',
        ];

        foreach ($members as $member) {
            if (! is_array($member) || ! $this->hasMemberData($member)) {
                continue;
            }

            foreach ($required as $key => $label) {
                if (trim((string) ($member[$key] ?? '')) === '') {
                    $name = trim((string) ($member['firstname'] ?? '') . ' ' . (string) ($member['lastname'] ?? ''));

                    return $label . ' is required for member ' . ($name !== '' ? '"' . $name . '"' : '(unnamed)') . '.';
                }
            }
        }

        return null;
    }

    /** Returns the first age-specific sector/service error across the submitted family. */
    private function firstAgeEligibilityError(array $members, SectorModel $sectorModel, ServiceModel $serviceModel): ?string
    {
        $people = [[
            'label' => 'Head of family',
            'birthday' => $this->request->getPost('head_birthday'),
            'sectorIds' => SectorIds::normalize($this->request->getPost('sector_ids')),
            'serviceIds' => $this->positiveIds($this->request->getPost('service_ids')),
        ]];

        foreach ($members as $member) {
            if (! is_array($member) || ! $this->hasMemberData($member)) {
                continue;
            }

            $name = trim((string) ($member['firstname'] ?? '') . ' ' . (string) ($member['lastname'] ?? ''));
            $people[] = [
                'label' => 'Member' . ($name !== '' ? ' "' . $name . '"' : ''),
                'birthday' => $member['birthday'] ?? null,
                'sectorIds' => SectorIds::normalize($member['sector_ids'] ?? []),
                'serviceIds' => $this->positiveIds($member['service_ids'] ?? []),
            ];
        }

        $sectorIds = [];
        $serviceIds = [];

        foreach ($people as $person) {
            $sectorIds = array_merge($sectorIds, $person['sectorIds']);
            $serviceIds = array_merge($serviceIds, $person['serviceIds']);
        }

        $sectorRows = $sectorModel->getByIdsIncludingArchived(array_values(array_unique($sectorIds)));
        $serviceRows = $serviceModel->getByIdsIncludingArchived(array_values(array_unique($serviceIds)));

        foreach ($people as $person) {
            $error = FamilyAgeEligibility::selectionError(
                $person['birthday'],
                $person['sectorIds'],
                $person['serviceIds'],
                $sectorRows,
                $serviceRows,
            );

            if ($error !== null) {
                return $person['label'] . ': ' . $error;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function positiveIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $value),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * Parses a salary input into a float, stripping thousands separators, or null
     * when blank. Keeps the `salary` column numeric/nullable.
     */
    private function moneyOrNull(mixed $value): ?float
    {
        return MemberFieldNormalizer::moneyOrNull($value);
    }

    /**
     * Trims a value to a string, returning null when empty so optional columns
     * store NULL rather than ''. Used throughout the payload builders.
     */
    private function nullableText(mixed $value): ?string
    {
        return MemberFieldNormalizer::nullableText($value);
    }

    /** Uppercased trimmed value, or null when blank. See MemberFieldNormalizer::nullableUpperText(). */
    private function nullableUpperText(mixed $value): ?string
    {
        return MemberFieldNormalizer::nullableUpperText($value);
    }

    /**
     * Cleans a person-name field on save/update: keeps only letters (incl. ñ/Ñ and
     * accents), spaces and the - ' . punctuation real names use, collapses repeated
     * whitespace, then applies Title Case (first letter of each word capitalized).
     * Workers may type freely; the stored value is normalized here. Used for
     * first/middle/last names of head and members.
     */
    private function cleanName(mixed $value): string
    {
        return MemberFieldNormalizer::cleanName($value);
    }

    /**
     * Cleans an address/barangay field on save/update: address-safe allowlist of
     * letters, digits, spaces and # , . - / ' ( ) & (so house/block numbers survive),
     * collapses repeated whitespace, then applies Title Case. Strips odd symbols such
     * as < > | \ " : ] [.
     */
    private function cleanAddress(mixed $value): string
    {
        return MemberFieldNormalizer::cleanAddress($value);
    }

    /**
     * GET `records/entry`: the Data Entry page for a new family, posting to the
     * existing, untouched store() endpoint. Editing an existing record happens
     * on the Family Profile page (profile()); this method only ever renders a
     * blank Add form.
     */
    public function createFamily(): string|RedirectResponse
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->partialGuard($guard, 'You do not have permission to open the family record form.');
        }

        $options = (new FamilyFormOptionsModel())->getViewData();

        helper('dashboard_view_helper');

        return view('layout', DashboardPageBuilder::shellAccountData() + [
            'activePage' => 'records-entry',
            'role'       => RoleAccess::normalizeRole((string) session()->get('role')),
            'bodyView'   => 'Family/entry',
            // The sorted suffix/civil/barangay/relationship/education/job/religion/
            // income/sex lists Family/_fields.php renders come from
            // FamilyModalDataBuilder (a library), not a model call the view makes
            // itself - see family_entry_view_data()'s contract.
            'bodyData'   => family_entry_view_data([
                'head'        => [],
                'members'     => [],
                'readOnly'    => false,
                'sectors'     => $options['sectorOptions'],
                'services'    => $options['serviceOptions'],
                'categories'  => array_keys($options['servicesByCategory']),
                'formOptions' => (new FamilyModalDataBuilder())->staticOptionLists(),
            ]),
        ]);
    }

    /** Returns whether a QR number is available to the current Add/Update form. */
    public function qrAvailability(): ResponseInterface
    {
        if ($this->requireFamilyEntryAccess() instanceof RedirectResponse) {
            return $this->response->setStatusCode(403)->setJSON([
                'available' => false,
                'message' => 'You do not have permission to validate QR numbers.',
            ]);
        }

        $rawControlNo = (string) $this->request->getGet('control_no');
        $rule = $this->rulesForEntryType('head')['qr_control_no'];

        if (! service('validation')->check($rawControlNo, $rule)) {
            return $this->response->setStatusCode(422)->setJSON([
                'available' => false,
                'message' => 'Enter a QR number from 1 to 9999999.',
            ]);
        }

        $controlNo = (int) $rawControlNo;
        $headId = max(0, (int) $this->request->getGet('head_id'));
        $exists = model(\App\Models\Scanner\QrControlModel::class)->takenByOtherHead($controlNo, $headId);

        if ($exists) {
            return $this->response->setJSON([
                'available' => false,
                'message' => 'QR Number ' . $controlNo . ' already exists in the records and is assigned to another family.',
            ]);
        }

        // ControlNumber::payload() is the same call QrCardPdfGenerator makes, so
        // the preview on the entry page and the code on the card are guaranteed
        // to encode the same string. Only an available number gets one: a taken
        // number may already have a card.
        return $this->response->setJSON([
            'available' => true,
            'message' => '',
            'qr' => (new QrImageGenerator())->dataUri(ControlNumber::payload($controlNo)),
            'control_no_label' => ControlNumber::format($controlNo),
        ]);
    }

}
