<?php

namespace App\Models\Families;

use App\Libraries\SectorIds;
use App\Models\Concerns\MemberQueryFilters;
use App\Models\Concerns\NormalizesIds;
use App\Models\Concerns\RecordStatus;
use App\Models\Concerns\ResolvesSectorNames;
use App\Support\ContactNumber;
use App\Support\MemberFieldNormalizer;
use CodeIgniter\Model;

/**
 * Manages family heads and family member records.
 */
class MemberModel extends Model
{
    use MemberQueryFilters;
    use NormalizesIds;
    use ResolvesSectorNames;

    public const VALIDATION_RULES = [
        'firstname' => 'required|max_length[100]',
        'lastname' => 'required|max_length[100]',
        'middlename' => 'permit_empty|max_length[50]',
        'suffix' => 'permit_empty|max_length[20]',
        'birthday' => 'permit_empty|valid_date[Y-m-d]|not_future_date',
        'civilstatus' => 'permit_empty|min_length[2]|max_length[100]|not_numeric_only',
        'sex' => 'permit_empty|in_list[MALE,FEMALE]',
        'education' => 'permit_empty|min_length[2]|max_length[150]|not_numeric_only',
        'job' => 'permit_empty|min_length[2]|max_length[150]|not_numeric_only',
        'contactnumber' => 'permit_empty|max_length[30]',
        'religion' => 'permit_empty|min_length[2]|max_length[100]|not_numeric_only',
        'address' => 'permit_empty|max_length[255]',
        'barangay' => 'permit_empty|max_length[100]',
        'barangayID' => 'permit_empty|is_natural_no_zero',
    ];

    protected $table = 'member';
    protected $primaryKey = 'memberID';
    protected $returnType = 'array';
    protected $allowedFields = [
        'memberID',
        'lastname',
        'firstname',
        'middlename',
        'suffix',
        'birthday',
        'civilstatus',
        'sex',
        'education',
        'job',
        'salary',
        'contactnumber',
        'religion',
        'address',
        'barangay',
        'barangayID',
        'relationship',
        'headID',
    ];
    protected $useTimestamps = false;
    protected $validationRules = self::VALIDATION_RULES;

    /** True if the `member` table exists; callers guard queries with this. */
    public function hasTable(): bool
    {
        return $this->db->tableExists($this->table);
    }

    /**
     * Confirms every table the family-save flow needs exists. FamilyController::store()
     * calls this up front and aborts with a clear message if the schema is incomplete.
     */
    public function hasRequiredFamilyTables(): bool
    {
        foreach (['member', 'sector', 'member_sectors', 'services', 'member_services', 'audit_trails'] as $table) {
            if (! $this->db->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    // Transaction wrappers used by FamilyController::store() so the head, members,
    // service links, and audit row all commit together or roll back as one unit.

    /** Opens a managed DB transaction. */
    public function beginTransaction(): void
    {
        $this->db->transStart();
    }

    /** Rolls back the current transaction after a save failure. */
    public function rollbackTransaction(): void
    {
        $this->db->transRollback();
    }

    /** Commits the current transaction (or rolls back if any query failed). */
    public function completeTransaction(): void
    {
        $this->db->transComplete();
    }

    /** Whether the just-completed transaction succeeded. */
    public function transactionStatus(): bool
    {
        return $this->db->transStatus();
    }

    /**
     * Inserts a head-of-family row, where headID points to its own memberID.
     * Called by FamilyController::store(); returns the new memberID or false.
     */
    public function createHead(array $data): int|false
    {
        $data['memberID'] = $this->nextAutoIncrementId();
        $data['headID'] = $data['memberID'];
        // Not a fallback: this row IS the head, whatever the caller passed.
        $data['relationship'] = 'HEAD';
        $data = $this->memberColumnPayload($data);

        if (! $this->insert($data)) {
            return false;
        }

        return (int) $data['memberID'];
    }

    /**
     * True when an ACTIVE head-of-family with the same name + birthday is already
     * on file. The Excel importer calls this to SKIP families that already exist
     * rather than inserting a duplicate. Comparison is case-insensitive on the
     * (already Title-cased) first/last name and exact on the stored Y-m-d birthday.
     *
     * Only live heads block a re-import: an archived (retired) family is treated as
     * gone, so re-importing it re-creates the record - matching the archive
     * grandfather semantics used elsewhere.
     */
    public function activeHeadExists(string $firstname, string $lastname, ?string $birthday): bool
    {
        if (! $this->hasTable()) {
            return false;
        }

        // Escaping is off on these conditions so the LOWER() calls survive, which
        // also means the builder never applies DBPrefix to the qualifier. Writing
        // the prefixed name is what keeps a prefixed connection working.
        $member = $this->db->prefixTable($this->table);

        $builder = $this->db->table($this->table)
            ->where($member . '.memberID = ' . $member . '.headID', null, false)
            ->where('LOWER(' . $member . '.firstname) = ' . $this->db->escape(mb_strtolower(trim($firstname))), null, false)
            ->where('LOWER(' . $member . '.lastname) = ' . $this->db->escape(mb_strtolower(trim($lastname))), null, false);

        if ($birthday !== null && trim($birthday) !== '') {
            $builder->where($member . '.birthday', $birthday);
        } else {
            $builder->where($member . '.birthday IS NULL', null, false);
        }

        if ($this->db->fieldExists('dt_deleted', $this->table)) {
            $builder->where($member . '.dt_deleted IS NULL', null, false);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Bulk [headID => stored record] for a set of head IDs. The importer needs the whole
     * person, not just a name: an existing QR only proves *a* family is on file, and the
     * review has to compare the incoming head against the stored one to tell "the same
     * family again" (skip) from "a mistyped QR that landed on someone else's family".
     *
     * @param int[] $headIds
     * @return array<int, array<string, string|null>>
     */
    public function identitiesForHeads(array $headIds): array
    {
        $headIds = array_values(array_unique(array_filter(
            array_map('intval', $headIds),
            static fn (int $id): bool => $id > 0,
        )));

        if (! $this->hasTable() || $headIds === []) {
            return [];
        }

        $map = [];

        $member = $this->db->prefixTable($this->table);

        foreach (array_chunk($headIds, 1000) as $chunk) {
            $rows = $this->db->table($this->table)
                ->select('memberID, firstname, middlename, lastname, suffix, birthday, sex, civilstatus, contactnumber, religion, address, barangayID')
                ->where($member . '.memberID = ' . $member . '.headID', null, false)
                ->whereIn('memberID', $chunk)
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $map[(int) $row['memberID']] = $row;
            }
        }

        return $map;
    }

    /**
     * ACTIVE people already on file whose surname appears in the batch - the candidate set
     * for "this person is already in the system". Filtering by surname (instead of one
     * query per row) keeps a 10k-row import to a handful of queries; the importer does the
     * exact first+last+birthday match on the result in PHP.
     *
     * @param string[] $lastnames
     * @return list<array<string, string|null>> memberID, headID, firstname, middlename, lastname, suffix, birthday
     */
    public function activePeopleByLastname(array $lastnames): array
    {
        $names = [];

        foreach ($lastnames as $lastname) {
            $clean = mb_strtolower(trim((string) $lastname));

            if ($clean !== '') {
                $names[$clean] = true;
            }
        }

        $names = array_keys($names);

        if (! $this->hasTable() || $names === []) {
            return [];
        }

        $soft = $this->db->fieldExists('dt_deleted', $this->table);
        $out  = [];

        foreach (array_chunk($names, 500) as $chunk) {
            $builder = $this->db->table($this->table)
                ->select('memberID, headID, firstname, middlename, lastname, suffix, birthday')
                ->whereIn('LOWER(lastname)', $chunk);

            if ($soft) {
                $builder->where('dt_deleted IS NULL', null, false);
            }

            foreach ($builder->get()->getResultArray() as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * True when an ACTIVE member with this name + birthday already sits under the head.
     * Guards the "add member to existing family" import path against inserting the same
     * person twice.
     */
    public function memberExistsUnderHead(int $headId, string $firstname, string $lastname, ?string $birthday): bool
    {
        if (! $this->hasTable() || $headId <= 0) {
            return false;
        }

        $member = $this->db->prefixTable($this->table);

        $builder = $this->db->table($this->table)
            ->where($member . '.headID', $headId)
            ->where('LOWER(' . $member . '.firstname) = ' . $this->db->escape(mb_strtolower(trim($firstname))), null, false)
            ->where('LOWER(' . $member . '.lastname) = ' . $this->db->escape(mb_strtolower(trim($lastname))), null, false);

        if ($birthday !== null && trim($birthday) !== '') {
            $builder->where($member . '.birthday', $birthday);
        }

        if ($this->db->fieldExists('dt_deleted', $this->table)) {
            $builder->where($member . '.dt_deleted IS NULL', null, false);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Inserts a relative under an existing head (validating the head exists).
     * Called per member by FamilyController::store(); returns the new memberID
     * or false.
     */
    public function addFamilyMember(int $headId, array $data): int|false
    {
        $head = $this->find($headId);

        if ($head === null || (int) ($head['headID'] ?? 0) !== $headId) {
            return false;
        }

        $data['headID'] = $headId;
        $data['relationship'] = $data['relationship'] ?? 'Member';
        $data = $this->memberColumnPayload($data);

        if (! $this->insert($data)) {
            return false;
        }

        return (int) $this->getInsertID();
    }

    /**
     * Returns all members of one family (by head ID) with sector names resolved.
     * $status filters active/archived/all. Frontend: the family view/edit
     * screens via DashboardPageBuilder.
     */
    public function getFamilyMembers(int $headId, string $status = RecordStatus::ACTIVE): array
    {
        if (! $this->hasTable()) {
            return [];
        }

        $rows = $this->memberDashboardBuilder($status)
            ->where('member.headID', $headId)
            ->orderBy('member.memberID', 'ASC')
            ->get()
            ->getResultArray();

        return $this->withSectorNames($rows);
    }

    /**
     * FIRST (quick) search bar of the Manage Records tab. Lists family HEADS only;
     * an exact QR number also resolves to its mapped head. $filters carries the
     * Manage Records filter controls (sectorID, barangay, date); see
     * App\Libraries\DashboardPageBuilder::buildMemberListData() which supplies them.
     *
     * $orderKey/$orderDirection are an optional, append-only addition used by the
     * server-side DataTables endpoint (FamilyController::dataTable) for column
     * sorting. When $orderKey is null the original ordering (newest first, by
     * memberID DESC) is preserved, so existing callers are unaffected.
     */
    public function searchFamilies(?string $keyword = null, int $limit = 50, int $offset = 0, string $status = RecordStatus::ALL, array $filters = [], ?string $orderKey = null, string $orderDirection = 'asc'): array
    {
        if (! $this->hasTable()) {
            return [];
        }

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $builder = $this->familySearchBuilder($keyword, $status, $filters);
        $this->applyMemberOrder($builder, $orderKey, $orderDirection);
        $builder->limit($limit, $offset);

        return $this->withSectorNames($builder->get()->getResultArray());
    }

    /**
     * Applies a DataTables column sort to a member query, or the default
     * newest-first ordering when $orderKey is null/unrecognized. Column keys map
     * to the visible Manage Records columns: qr (qr_control join, no-control
     * heads last), name (lastname, firstname), address. Used only by the
     * server-side DataTables path.
     */
    private function applyMemberOrder($builder, ?string $orderKey, string $orderDirection): void
    {
        $direction = strtolower(trim($orderDirection)) === 'desc' ? 'DESC' : 'ASC';

        switch ($orderKey) {
            case 'qr':
                // qr_control holds one row per family head. Heads without a
                // control number sort last in either direction.
                $builder->join('qr_control qc_sort', 'qc_sort.headID = member.memberID', 'left')
                    ->orderBy('qc_sort.control_no IS NULL', 'ASC', false)
                    ->orderBy('qc_sort.control_no', $direction)
                    // memberID breaks ties among heads without a control number
                    // so pagination stays stable.
                    ->orderBy('member.memberID', $direction);
                return;
            case 'name':
                $builder->orderBy('member.lastname', $direction)
                    ->orderBy('member.firstname', $direction);
                return;
            case 'address':
                if ($this->memberFieldExists('address')) {
                    $builder->orderBy('member.address', $direction);
                    return;
                }
                break;
            case 'birthday':
                $builder->orderBy('member.birthday', $direction);
                return;
        }

        $builder->orderBy('member.memberID', 'DESC');
    }

    /**
     * Household size for each of the given heads, in one grouped query rather than a
     * count per row. Counts the same set of people the caller is listing: on the
     * active tab archived members are excluded, but on the archived tab they are the
     * rows on screen, so excluding them there would show every household as empty.
     *
     * @param  list<int>        $headIds
     * @param  string           $status  Which rows the list is showing (RecordStatus::*)
     * @return array<int, int>  headID => member rows, heads with no rows omitted
     */
    public function memberCountsForHeads(array $headIds, string $status = RecordStatus::ACTIVE): array
    {
        $headIds = array_values(array_unique(array_filter(array_map('intval', $headIds))));

        // whereIn() on an empty array is not valid SQL, and there is nothing to count.
        if ($headIds === [] || ! $this->hasTable()) {
            return [];
        }

        $member = $this->db->prefixTable($this->table);

        $builder = $this->db->table($this->table)
            ->select('headID, COUNT(*) AS total')
            ->whereIn('headID', $headIds)
            ->groupBy('headID');

        if ($this->db->fieldExists('dt_deleted', $this->table)) {
            if ($status === RecordStatus::ARCHIVED) {
                $builder->where($member . '.dt_deleted IS NOT NULL', null, false);
            } elseif ($status !== RecordStatus::ALL) {
                $builder->where($member . '.dt_deleted IS NULL', null, false);
            }
        }

        return array_map('intval', array_column($builder->get()->getResultArray(), 'total', 'headID'));
    }

    /**
     * Total count for the same query as searchFamilies(), used to drive the Manage
     * Records pagination controls on the frontend.
     */
    public function countSearchFamilies(?string $keyword = null, string $status = RecordStatus::ALL, array $filters = []): int
    {
        if (! $this->hasTable()) {
            return 0;
        }

        return $this->familySearchBuilder($keyword, $status, $filters)->countAllResults();
    }

    /**
     * Builds the head-only records query. $filters (optional) applies the Manage Records
     * filter controls: 'sectorID' (matched through member_sectors), 'barangay'
     * (matched on member.barangayID), and 'date' (single-day match on
     * member.dt_created). Empty $filters = original behavior unchanged.
     */
    private function familySearchBuilder(?string $keyword = null, string $status = RecordStatus::ALL, array $filters = [])
    {
        if ($status === '1') {
            $status = RecordStatus::ARCHIVED;
        } elseif ($status === '') {
            $status = RecordStatus::ACTIVE;
        }

        $status = in_array($status, [RecordStatus::ACTIVE, RecordStatus::ARCHIVED, RecordStatus::ALL], true) ? $status : RecordStatus::ALL;
        $member = $this->db->prefixTable($this->table);
        $builder = $this->memberDashboardBuilder($status)
            ->where($member . '.memberID = ' . $member . '.headID', null, false);

        if ($keyword !== null && trim($keyword) !== '') {
            $keyword = trim($keyword);
            $this->applyMemberKeyword(
                $builder,
                $keyword,
                'member.',
                ['religion', 'address'],
                [],
                $this->headIdsForQrKeyword($keyword)
            );
        }

        $this->applyRecordFilters($builder, $filters);

        return $builder;
    }

    /**
     * Applies the Manage Records filter controls to a member query builder.
     * Connects to: family-list.php filter form -> DashboardPageBuilder -> here.
     */
    private function applyRecordFilters($builder, array $filters): void
    {
        $this->applySectorIdFilter($builder, $filters['sectorID'] ?? [], 'member.memberID');
        $this->applyBarangayFilter($builder, $filters['barangay'] ?? [], 'member.memberID');
        $this->applyDateRange($builder, 'member.dt_created', $filters);
    }

    /**
     * Returns the member IDs belonging to a family, used when re-syncing a
     * family's service assignments during an edit.
     */
    public function getFamilyMemberIds(int $headId): array
    {
        if (! $this->hasTable()) {
            return [];
        }

        $rows = $this->select('memberID')
            ->where('headID', $headId)
            ->findAll();

        return array_values(array_map(static fn (array $row): int => (int) ($row['memberID'] ?? 0), $rows));
    }

    /**
     * Active heads of family (headID = memberID, not archived) for QR card
     * generation. Each row: memberID, control number, a display fullname, and
     * barangay. Optional $filter narrows by 'memberID' (int), 'barangay'
     * (string), 'controlFrom'/'controlTo' (int, inclusive control-number bounds),
     * 'keyword' (string, name match), 'sectorID' (int), and pages with
     * 'limit' (int) + 'offset' (int).
     *
     * @return list<array{memberID:int, controlNo:int, fullname:string, barangay:string}>
     */
    public function headsForCards(array $filter = []): array
    {
        $builder = $this->headsForCardsBuilder($filter);

        $builder->orderBy('qc.control_no IS NULL', 'asc', false)
            ->orderBy('qc.control_no', 'asc')
            ->orderBy('member.memberID', 'asc');

        if (isset($filter['limit']) && (int) $filter['limit'] > 0) {
            $builder->limit((int) $filter['limit'], max(0, (int) ($filter['offset'] ?? 0)));
        }

        $rows = $builder->get()->getResultArray();

        return array_map(static function (array $row): array {
            $name = trim(sprintf(
                '%s, %s %s %s',
                $row['lastname'] ?? '',
                $row['firstname'] ?? '',
                $row['middlename'] ?? '',
                $row['suffix'] ?? ''
            ));

            // The barangay comes from the barangayID join (headsForCardsBuilder),
            // so a card prints the canonical name rather than whatever spelling
            // reached the address field.
            $barangay = trim((string) ($row['barangay'] ?? ''));

            return [
                'memberID'  => (int) $row['memberID'],
                'controlNo' => (int) $row['control_no'],
                'fullname'  => preg_replace('/\s+/', ' ', $name),
                'barangay'  => $barangay,
            ];
        }, $rows);
    }

    /**
     * Active heads whose required access-card fields need follow-up. A grouped
     * control-number join keeps malformed legacy mappings to one head row.
     *
     * @return list<array{memberID: int|string, control_no: int|string|null, firstname: string|null, lastname: string|null, suffix: string|null, sex: string|null, birthday: string|null, address: string|null, contactnumber: string|null, barangay: string|null, missing: list<string>}>
     */
    public function cardReadinessRows(): array
    {
        $member = $this->db->prefixTable('member');
        $qrControl = $this->db->prefixTable('qr_control');
        $barangay = $this->db->prefixTable('barangay');

        $rows = $this->db->table($member . ' member')
            ->select('member.memberID, MIN(qc.control_no) AS control_no, member.firstname, member.lastname, member.suffix, member.sex, member.birthday, member.address, member.contactnumber, barangay.name AS barangay', false)
            ->join($qrControl . ' qc', 'qc.headID = member.memberID', 'left')
            ->join($barangay . ' barangay', 'barangay.barangayID = member.barangayID AND barangay.dt_deleted IS NULL', 'left', false)
            ->where('member.headID = member.memberID', null, false)
            ->where('member.dt_deleted IS NULL', null, false)
            ->groupBy('member.memberID, member.firstname, member.lastname, member.suffix, member.sex, member.birthday, member.address, member.contactnumber, barangay.name')
            ->orderBy('control_no IS NOT NULL', 'asc', false)
            ->orderBy('control_no', 'asc')
            ->orderBy('member.memberID', 'asc')
            ->get()
            ->getResultArray();

        return array_values(array_filter(array_map(static function (array $row): array {
            $missing = [];
            $controlNo = trim((string) ($row['control_no'] ?? ''));
            $birthday = trim((string) ($row['birthday'] ?? ''));
            $birthdayDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthday);
            $contact = ContactNumber::parse($row['contactnumber'] ?? null);

            if (preg_match('/^[1-9]\d{0,6}$/', $controlNo) !== 1) {
                $missing[] = 'Control Number';
            }
            if (trim((string) ($row['firstname'] ?? '')) === '') {
                $missing[] = 'First Name';
            }
            if (trim((string) ($row['lastname'] ?? '')) === '') {
                $missing[] = 'Last Name';
            }
            if (! in_array((string) ($row['sex'] ?? ''), ['MALE', 'FEMALE'], true)) {
                $missing[] = 'Sex';
            }
            if ($birthdayDate === false || $birthdayDate->format('Y-m-d') !== $birthday || $birthdayDate > new \DateTimeImmutable('today')) {
                $missing[] = 'Birthday';
            }
            if (trim((string) ($row['address'] ?? '')) === '') {
                $missing[] = 'Address';
            }
            if (! $contact['supplied'] || ! $contact['valid']) {
                $missing[] = 'Contact Number';
            }
            if (trim((string) ($row['barangay'] ?? '')) === '') {
                $missing[] = 'Barangay';
            }

            $row['missing'] = $missing;

            return $row;
        }, $rows), static fn (array $row): bool => $row['missing'] !== []));
    }

    /**
     * Total heads matching $filter, ignoring any 'limit'. Backs the Control
     * Numbers preview header ("N cards will be generated") so the count is always
     * the full selection even when only the first rows are rendered.
     */
    public function countHeadsForCards(array $filter = []): int
    {
        unset($filter['limit']);

        return $this->headsForCardsBuilder($filter, true)->countAllResults();
    }

    /**
     * Shared SELECT for headsForCards()/countHeadsForCards(). Active heads with a
     * qr_control mapping, filtered by memberID, barangay, control-number range,
     * name keyword, and sector. $forCount omits the display columns so the count
     * query stays lean.
     */
    private function headsForCardsBuilder(array $filter = [], bool $forCount = false): \CodeIgniter\Database\BaseBuilder
    {
        $member = $this->db->prefixTable('member');

        $columns = $forCount
            ? 'member.memberID'
            : 'member.memberID, member.lastname, member.firstname, member.middlename, member.suffix, member.address, barangay.name AS barangay, qc.control_no';

        $builder = $this->db->table('member')
            ->select($columns)
            ->join('qr_control qc', 'qc.headID = member.memberID', 'left')
            ->join('barangay', 'barangay.barangayID = member.barangayID', 'left')
            ->where($member . '.headID = ' . $member . '.memberID', null, false);

        // qr_control is the single source of truth for control numbers: a head with
        // no mapping cannot be scanned, so it is excluded from card generation
        // rather than printed with a memberID that the scanner would reject.
        $builder->where('qc.control_no IS NOT NULL', null, false);

        if ($this->db->fieldExists('dt_deleted', 'member')) {
            $builder->where($member . '.dt_deleted IS NULL', null, false);
        }

        if (isset($filter['memberID']) && (int) $filter['memberID'] > 0) {
            $builder->where('member.memberID', (int) $filter['memberID']);
        }

        if (! empty($filter['barangay'])) {
            $builder->where('barangay.name', $filter['barangay']);
        }

        if (isset($filter['controlFrom']) && (int) $filter['controlFrom'] > 0) {
            $builder->where('qc.control_no >=', (int) $filter['controlFrom']);
        }
        if (isset($filter['controlTo']) && (int) $filter['controlTo'] > 0) {
            $builder->where('qc.control_no <=', (int) $filter['controlTo']);
        }

        if (isset($filter['keyword']) && trim((string) $filter['keyword']) !== '') {
            $keyword = trim((string) $filter['keyword']);
            $builder->groupStart()
                ->like('member.lastname', $keyword)
                ->orLike('member.firstname', $keyword)
                ->orLike('member.middlename', $keyword)
                ->groupEnd();
        }

        if (! empty($filter['sectorID'])) {
            $builder->where($this->sectorMembershipCondition([(int) $filter['sectorID']], 'member.memberID'), null, false);
        }

        return $builder;
    }

    /**
     * Resolves the family head's id for any active member. For a head this is
     * its own memberID (headID == memberID); for a non-head member it is their
     * headID. Returns null when $memberID is not an active member. Drives the QR
     * scan-lookup so scanning any member's id lands on the family (head) record.
     */
    public function familyHeadIdFor(int $memberID): ?int
    {
        if ($memberID <= 0) {
            return null;
        }

        $builder = $this->db->table('member')->where('memberID', $memberID);
        if ($this->db->fieldExists('dt_deleted', 'member')) {
            $builder->where($this->db->prefixTable('member') . '.dt_deleted IS NULL', null, false);
        }

        $row = $builder->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        $headId = (int) ($row['headID'] ?? 0);

        return $headId > 0 ? $headId : null;
    }

    /**
     * Returns the active head row for $memberID, or null when it is not an
     * active head (headID != memberID, archived, or missing). Drives scan-lookup.
     */
    public function findHead(int $memberID): ?array
    {
        if ($memberID <= 0) {
            return null;
        }

        $member = $this->db->prefixTable('member');

        $builder = $this->db->table('member')
            ->select('member.*, barangay.name AS barangay')
            ->join('barangay', 'barangay.barangayID = member.barangayID', 'left')
            ->where('memberID', $memberID)
            ->where($member . '.headID = ' . $member . '.memberID', null, false);

        if ($this->db->fieldExists('dt_deleted', 'member')) {
            $builder->where($member . '.dt_deleted IS NULL', null, false);
        }

        $row = $builder->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Reference-data badges per member for the kiosk family panel: sector
     * shortcodes (member_sectors), then category names, then service
     * shortcodes (member_services -> services). Returns memberID => list of
     * badge labels; empty map on empty input or any DB error.
     *
     * @param list<int> $memberIds
     * @return array<int, list<string>>
     */
    public function referenceBadges(array $memberIds): array
    {
        $memberIds = array_values(array_filter(array_map('intval', $memberIds), static fn (int $id): bool => $id > 0));
        if ($memberIds === []) {
            return [];
        }

        try {
            // Sector shortcodes keyed by sectorID, resolved once for the family.
            $sectorCodes = [];
            foreach ($this->db->table('sector')->select('sectorID, shortcode')->get()->getResultArray() as $s) {
                $sectorCodes[(int) $s['sectorID']] = (string) $s['shortcode'];
            }

            $sectorIdsByMember = (new MemberSectorModel())->getSectorIdsByMemberIds($memberIds);

            $memberServices = $this->db->prefixTable('member_services');
            $services       = $this->db->prefixTable('services');
            $category       = $this->db->prefixTable('category');

            $serviceRows = $this->db->table('member_services')
                // service_sector is the join alias below, not a real table, so it stays unprefixed.
                ->select($memberServices . '.memberID, ' . $services . '.shortcode, COALESCE(' . $category . '.name, service_sector.name) AS category', false)
                ->join('services', 'services.serviceID = member_services.serviceID')
                ->join('category', 'category.categoryID = services.categoryID', 'left')
                ->join('sector service_sector', 'service_sector.sectorID = services.sectorID', 'left')
                ->whereIn('member_services.memberID', $memberIds)
                ->where($this->db->prefixTable('services') . '.dt_deleted IS NULL', null, false)
                ->get()->getResultArray();

            $badges = [];
            foreach ($memberIds as $id) {
                $sectors = [];
                foreach ($sectorIdsByMember[$id] ?? [] as $sid) {
                    if (isset($sectorCodes[$sid])) {
                        $sectors[] = $sectorCodes[$sid];
                    }
                }
                $categories = [];
                $services   = [];
                foreach ($serviceRows as $r) {
                    if ((int) $r['memberID'] !== $id) {
                        continue;
                    }
                    $cat = trim((string) ($r['category'] ?? ''));
                    if ($cat !== '' && ! in_array($cat, $categories, true)) {
                        $categories[] = $cat;
                    }
                    $code = trim((string) ($r['shortcode'] ?? ''));
                    if ($code !== '') {
                        $services[] = $code;
                    }
                }
                $badges[$id] = array_merge($sectors, $categories, $services);
            }

            return $badges;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Same data as referenceBadges(), kept as two separate lists instead of
     * one merged one: sector shortcodes, and "services and programs" (service
     * category names + service shortcodes together, same as referenceBadges()
     * folds them). Drives the scan kiosk's "View all" page, which filters
     * Sectors and Services/Programs separately.
     *
     * @param list<int> $memberIds
     * @return array<int, array{sectors: list<string>, servicesPrograms: list<string>}>
     */
    public function referenceBadgesSplit(array $memberIds): array
    {
        $memberIds = array_values(array_filter(array_map('intval', $memberIds), static fn (int $id): bool => $id > 0));
        if ($memberIds === []) {
            return [];
        }

        try {
            $sectorCodes = [];
            foreach ($this->db->table('sector')->select('sectorID, shortcode')->get()->getResultArray() as $s) {
                $sectorCodes[(int) $s['sectorID']] = (string) $s['shortcode'];
            }

            $sectorIdsByMember = (new MemberSectorModel())->getSectorIdsByMemberIds($memberIds);

            $memberServices = $this->db->prefixTable('member_services');
            $services       = $this->db->prefixTable('services');
            $category       = $this->db->prefixTable('category');

            $serviceRows = $this->db->table('member_services')
                // service_sector is the join alias below, not a real table, so it stays unprefixed.
                ->select($memberServices . '.memberID, ' . $services . '.shortcode, COALESCE(' . $category . '.name, service_sector.name) AS category', false)
                ->join('services', 'services.serviceID = member_services.serviceID')
                ->join('category', 'category.categoryID = services.categoryID', 'left')
                ->join('sector service_sector', 'service_sector.sectorID = services.sectorID', 'left')
                ->whereIn('member_services.memberID', $memberIds)
                ->where($this->db->prefixTable('services') . '.dt_deleted IS NULL', null, false)
                ->get()->getResultArray();

            $split = [];
            foreach ($memberIds as $id) {
                $sectors = [];
                foreach ($sectorIdsByMember[$id] ?? [] as $sid) {
                    if (isset($sectorCodes[$sid])) {
                        $sectors[] = $sectorCodes[$sid];
                    }
                }
                $servicesPrograms = [];
                foreach ($serviceRows as $r) {
                    if ((int) $r['memberID'] !== $id) {
                        continue;
                    }
                    $cat = trim((string) ($r['category'] ?? ''));
                    if ($cat !== '' && ! in_array($cat, $servicesPrograms, true)) {
                        $servicesPrograms[] = $cat;
                    }
                    $code = trim((string) ($r['shortcode'] ?? ''));
                    if ($code !== '') {
                        $servicesPrograms[] = $code;
                    }
                }
                $split[$id] = ['sectors' => $sectors, 'servicesPrograms' => $servicesPrograms];
            }

            return $split;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * All active members of a family (head + relatives), head first. Drives
     * the kiosk family panel. Returns [] for a non-positive id.
     */
    public function familyMembers(int $headId): array
    {
        if ($headId <= 0) {
            return [];
        }

        $builder = $this->db->table('member')
            ->select('memberID, firstname, lastname, suffix, relationship, birthday, sex, civilstatus, contactnumber, job, address')
            ->where('headID', $headId);

        if ($this->db->fieldExists('dt_deleted', 'member')) {
            $builder->where($this->db->prefixTable('member') . '.dt_deleted IS NULL', null, false);
        }

        // Head (memberID == headId) sorts first, then the rest by memberID.
        $builder->orderBy('CASE WHEN memberID = ' . $headId . ' THEN 0 ELSE 1 END', 'ASC', false)
            ->orderBy('memberID', 'ASC');

        return $builder->get()->getResultArray();
    }

    /** Updates the head-of-family row during a family edit submission. */
    public function updateHead(int $headId, array $data): bool
    {
        $data['headID'] = $headId;
        $data['relationship'] = 'HEAD';
        $data = $this->memberColumnPayload($data);

        return $this->update($headId, $data) !== false;
    }

    /**
     * Hard-deletes all relatives of a family but keeps the head. Used when an edit
     * replaces the member list before re-inserting the submitted members.
     */
    public function deleteFamilyMembersExceptHead(int $headId): bool
    {
        return $this->where('headID', $headId)
            ->where('memberID !=', $headId)
            ->delete() !== false;
    }

    /** Soft-archives an entire family (Manage Records "archive" action). */
    public function archiveFamily(int $headId): bool
    {
        return $this->markFamilyDeleted($headId);
    }

    /** Restores a soft-archived family by clearing dt_deleted on all its rows. */
    public function restoreFamily(int $headId): bool
    {
        if (! $this->hasTable() || ! $this->db->fieldExists('dt_deleted', $this->table)) {
            return false;
        }

        return (bool) $this->db->table($this->table)
            ->where('headID', $headId)
            ->where('dt_deleted IS NOT NULL', null, false)
            ->update(['dt_deleted' => null]);
    }

    /** Shared soft-delete: stamps dt_deleted on a family's active rows. */
    private function markFamilyDeleted(int $headId): bool
    {
        if (! $this->hasTable() || ! $this->db->fieldExists('dt_deleted', $this->table)) {
            return false;
        }

        return (bool) $this->db->table($this->table)
            ->where('headID', $headId)
            ->where('dt_deleted IS NULL', null, false)
            ->update(['dt_deleted' => date('Y-m-d H:i:s')]);
    }

    /**
     * Reads the table's next AUTO_INCREMENT so a head can set its own memberID and
     * headID to the same value in one insert (head points at itself).
     */
    private function nextAutoIncrementId(): int
    {
        $row = $this->db->query("\n            SELECT AUTO_INCREMENT\n            FROM information_schema.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = 'member'\n        ")->getRowArray();

        return (int) ($row['AUTO_INCREMENT'] ?? 1);
    }

    /**
     * Central query builder for member listings: selects the display columns,
     * left-joins the head's name, and applies the active/archived/all status
     * filter. Shared by the search, family, and detail queries.
     */
    private function memberDashboardBuilder(string $status = RecordStatus::ACTIVE)
    {
        $select = [
            'member.memberID',
            'member.lastname',
            'member.firstname',
            'member.middlename',
            'member.suffix',
            'member.birthday',
            'member.civilstatus',
            'member.sex',
            'member.education',
            'member.job',
            'member.salary',
            'member.contactnumber',
            'member.relationship',
            'member.dt_created',
            'member.dt_updated',
            'member.dt_deleted',
            'member.headID',
            'head.firstname AS head_firstname',
            'head.lastname AS head_lastname',
            'barangay.name AS barangay',
        ];

        foreach (['religion', 'address'] as $field) {
            if ($this->memberFieldExists($field)) {
                $select[] = 'member.' . $field;
            }
        }

        $builder = $this->db->table('member')
            ->select($select)
            ->join('member head', 'head.memberID = member.headID', 'left')
            ->join('barangay', 'barangay.barangayID = member.barangayID', 'left');

        // The member's sectors as a comma list, so a listing stays one row per
        // member: joining member_sectors would multiply rows by sector count and
        // break every count and paginator downstream. Selected unescaped (and so
        // spelled with the prefix by hand) because the builder cannot prefix a
        // subquery it did not build.
        $builder->select(
            '(SELECT GROUP_CONCAT(ms.sectorID) FROM ' . $this->db->prefixTable('member_sectors')
                . ' ms WHERE ms.memberID = ' . $this->db->prefixTable('member') . '.memberID) AS sector_ids',
            false
        );

        if ($this->db->fieldExists('dt_deleted', 'member')) {
            $member = $this->db->prefixTable('member');

            if ($status === RecordStatus::ARCHIVED) {
                $builder->where($member . '.dt_deleted IS NOT NULL', null, false);
            } elseif ($status !== RecordStatus::ALL) {
                $builder->where($member . '.dt_deleted IS NULL', null, false);
            }
        }

        return $builder;
    }

    /**
     * Drops any keys that aren't real `member` columns before an insert/update, so
     * the model tolerates schema differences between SQL-dump versions.
     */
    private function memberColumnPayload(array $data): array
    {
        if (! $this->hasTable()) {
            return $data;
        }

        return array_filter(
            $data,
            fn (mixed $value, string $field): bool => $this->memberFieldExists($field),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** True if a given column exists on the `member` table (schema-tolerance helper). */
    private function memberFieldExists(string $field): bool
    {
        return $this->db->fieldExists($field, $this->table);
    }
}
