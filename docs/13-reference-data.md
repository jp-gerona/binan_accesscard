# Reference data

Five lookup tables sit behind the record forms and the distribution targeting.
They change rarely, they are seeded from the dump, and getting them wrong ripples
outward, because everything else references them.

Four of them live on one page, `reference-data`, switched by `?tab=`: Sectors and
Services and Programs for every staff role, plus Categories and Subsidy Types for
Developer and Admin. `barangay` has no tab. It is seeded from the dump and read
everywhere through `BarangayModel`, and there is no screen for editing it.

## The five

**`barangay`** is the list of Biñan's barangays. Every member has one, and
distributions target by it. It is effectively fixed: barangays are an
administrative fact, not a preference. Import matching against this list is
deliberately tolerant, accepting `Sto.` for `Santo`, `n` for `ñ`, and a
parenthesised alias, because survey spreadsheets spell them every possible way.
Anything still off-list raises the `BRGY` warning rather than blocking, so a
family is never lost to a spelling argument.

**`sector`** is the classification list: senior citizen (`SC`), person with
disability (`PWD`), solo parent (`SP`), breastfeeding (`B`), LGBT, overseas
Filipino worker (`OFW`), indigenous people (`IP`), internally displaced person
(`IDP`), person deprived of liberty (`PDL`), Informal Worker (`IW`), and Other
Sectors (`OTHER`). A member can belong to several, through the `member_sectors`
junction. Sectors do double duty: they describe a member, and they target a
distribution batch.

**`category`** groups services.

**`services`** is what a member can be recorded as receiving or being enrolled
in. Each service belongs to one category and is scoped to one sector, which is
what lets the family form show only the services relevant to a member's sectors
rather than the entire list.

**`subsidy`** is the subsidy types: rice, cash aid, whatever the city is handing
out. Managed separately from the rest, under
`reference-data/subsidy-types/*`, by `app/Controllers/Admin/SubsidyTypesController.php`.

## Subsidy types are not services

This is the distinction the domain turns on, and it is worth being explicit about
because the two look similar in a table.

A **service** describes what a member *is* or *is enrolled in*. It is a property
of the person, recorded once, part of their profile.

A **subsidy type** describes what gets *handed to them at an event*. It belongs
to a distribution batch, not to a person.

A senior citizen enrolled in a health service is a `member_services` row. That
same senior receiving a sack of rice at a distribution is a
`subsidy_distribution` row against a batch whose `subsidy_type_id` is rice. Do
not model one as the other.

## How the CRUD works

The three lookup controllers, `SectorController`, `ServiceController`, and
`CategoryController`, share their behaviour through
`app/Controllers/Concerns/LookupControllerTrait.php`. It provides three things:

- the Developer and Admin role guard for mutations,
- the typed flash-redirect back to the right tab, since each controller posts to
  a different path, and
- the audit writer for lookup changes.

Lookup audit rows have no affected member, which is why `audit_trails.memberID`
is nullable. A change to the sector list is a change to the system, not to a
person's record.

Records are archived and restored rather than deleted, the same as families. A
sector that has been used by a member cannot simply vanish without orphaning that
member's history.

## Uppercase storage

Names and reference values are stored uppercase. That came in with the v18 and
v22 patches, and it exists so that matching on import is a comparison rather than
a guessing game. If you are writing a query that compares a user-supplied string
against stored data, normalise the case; the data is already normalised on its
side.

## Adding a reference table

If a sixth lookup ever appears, the shape to follow is: a table in the dump, a
model under `app/Models/Lookups/`, a controller using `LookupControllerTrait`, a
tab body view under `app/Views/Lookups/`, and a route. There is no plugin
mechanism and no registry; it is five ordinary files.
