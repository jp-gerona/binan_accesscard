# Introduction

The Biñan Access Card system is how the City Social Welfare and Development
office of Biñan keeps track of the families it serves and the assistance it
hands out to them. Staff record a family once, the system issues that family a
QR access card, and when the city runs a distribution the card is scanned at the
venue instead of a name being looked up on a printed list.

It is a CodeIgniter 4 application backed by MySQL, run on a laptop or a small
server inside the office, and sometimes carried to a distribution venue.

## What it actually does

Four things, in the order a family passes through them.

**Records.** A family is entered once, either through the entry form or by
importing a filled spreadsheet. The record holds the head of the family and
every member under them, their address, and which sectors and services each
member belongs to.

**Access cards.** Each family head gets a control number and a QR code. That is
the access card. It is printed and given to the family, and it is what identifies
them for the rest of the system's life.

**Distribution.** The city plots a distribution batch: what is being handed out,
which barangays and sectors it covers, and when it opens. The system works out
who is eligible. At the venue, a scanner reads each card, the system checks the
family against the batch's eligibility list, and records that they received it.

**Audit trails.** Every change to a family record is written down: who changed
it, what changed, and when. This is not a nice-to-have. It is what makes the
records defensible when someone asks, months later, why a family's details are
different from what was on the form they signed.

## Who uses it

Five roles. The name on the left is what the application calls the role; the
name in parentheses is what the `users.account_level` column stores, on the rare
occasion those differ.

**Developer.** Full access, including account creation and the reference-data
tables nobody else should be editing. Held by whoever maintains the system.

**Admin** (`administrator`). Everything an Encoder can do, plus account
management, audit trails, and running distributions.

**Encoder** (`encoder`). The day-to-day role: entering families, importing
spreadsheets, generating cards. Most staff accounts are Encoders. The word is
`encoder` in the schema, in the code, and on the screen; there is no other
spelling of it anywhere.

**Viewer** (`viewer`). Read-only, plus the distribution pages. For staff who need
to see records and distribution results but must not change them.

**Scanner** (`scanner`). The account a scanning station logs in as at a
distribution venue. It reaches the kiosk and nothing else.

## The shape of the system

One CodeIgniter application, no separate frontend. Controllers and models are
grouped by feature rather than by layer, so everything about families lives
together and everything about the scanner lives together. Pages are server
rendered with Bootstrap and the SB Admin theme.

Two things about it surprise people who know CodeIgniter. First, there are no
migrations: the database schema lives in a SQL dump checked into the repository,
and schema changes are written as patch files that get folded into a new dump.
Second, there is no role in the URL. Every page has exactly one address, and a
manifest decides which roles can reach it.

Chapter 01 covers the layout, chapter 02 covers the database, and chapter 03 gets
you running.

## Glossary

You will meet these terms everywhere in this handbook and in the code. Most map
to something in the schema, named here so you can go looking.

**CSWD.** City Social Welfare and Development office. The department that owns
this system and does the work it supports.

**LGU.** Local Government Unit. The city government. Relevant mostly when
talking about who the interface is designed for: staff of widely varying comfort
with computers.

**Barangay.** The smallest administrative division in the Philippines, roughly a
village or a neighbourhood district. Every family belongs to one, and
distributions are usually targeted by barangay. Table: `barangay`.

**Member.** One person in the system. Table: `member`.

**Head.** The member a family is organised under. A family is the set of `member`
rows that share a `headID`, and the head is the member whose `headID` points at
its own `memberID`. Nearly everything in the system, including cards and
eligibility, keys off the head rather than off individual members.

**Family.** Not a table. A head plus the members whose `headID` points at that
head.

**Sector.** A classification a member can belong to: senior citizen, person with
disability, solo parent, and so on. A member can belong to several. Tables:
`sector`, and `member_sectors` for the membership.

**Service.** Something a member has been recorded as receiving or being enrolled
in. Services are grouped under a category and tied to a sector. Tables:
`services`, and `member_services` for the record.

**Category.** The grouping above services. Table: `category`.

**Subsidy type.** A kind of assistance the city hands out in a distribution:
rice, cash aid, and so on. **A subsidy type is not a service.** Services describe
what a member is; subsidy types describe what gets handed to them at an event.
Confusing the two is the most common mistake in this domain. Table: `subsidy`.

**Distribution batch.** One distribution event: a subsidy type, the barangays and
sectors it targets, a schedule window, and the list of who is eligible. Tables:
`distribution_batch`, with `batch_barangay`, `batch_sector`, and
`batch_eligibility` hanging off it.

**Eligibility.** The precomputed list of family heads a batch will serve, worked
out from the batch's barangay and sector targeting. Table: `batch_eligibility`.

**Control number.** The identifier printed on a family's access card and encoded
in its QR code. One per family head. Table: `qr_control`, column `control_no`.

**Access card.** The printed card carrying the QR code and control number. The
physical object the family keeps.

**Scan.** The act of reading a card at a distribution, and the row it writes.
Table: `subsidy_distribution`.

**Audit trail.** The record of a change to a family record. Table:
`audit_trails`.

**The dump.** `accesscardV23.sql`, the SQL file that is the authoritative
definition of the database schema. When this handbook says "the dump", it means
that file.
