# Access cards

The access card is the physical object a family keeps: a printed card carrying a
control number and a QR code. It is how a family identifies itself at a
distribution, and it is issued once per family head.

## Control numbers

A control number belongs to a family head, one to one. The `qr_control` table
holds the mapping: `control_no` as the primary key, `headID` as the family, plus
`card_generated_at` and `card_generated_by` recording when a card was actually
produced and by whom.

Because `control_no` is the primary key, a number belongs to exactly one family
and there is no such thing as reassigning it casually. That constraint is the
reason `QR-TAKEN` is a blocking import error rather than a warning, as chapter 12
explains: the import can neither skip a taken number nor insert against it.

A row can exist with `card_generated_at` still null. That means a number has been
allocated to a family but no card has been printed for them yet, which is a normal
intermediate state, not an error.

`app/Libraries/Qr/ControlNumber.php` handles the string form. It is a bijection
between the head's `memberID` and the printed number: `format()` and `parse()`
are pure string-to-integer transforms with no width-based ceiling. Width is
configurable through `QrCardSettings::$controlNumberWidth`, and at the default of
1 the printed number is the bare id with no leading zeros.

One thing to be careful about: `ControlNumber::parse()` tells you a number is
well-formed, not that it belongs to a head. Whether a parsed id is actually a
head is the caller's check, and the card lookup does it through `QrControlModel`,
which is the same source the scanner uses. That shared source is why a scanned
card always resolves to the right family.

## Generating cards

`app/Controllers/Cards/QrCardController.php` has four entry points.

**`batch()`** generates cards in bulk, filtered by barangay or by a control
number range, and is restricted to Developer and Admin. This is the normal path:
a barangay's worth of cards printed in one run. It refuses with a 400 if the
filter matches no heads, rather than producing an empty PDF.

**`card()`** generates a single card for one head, used for a reprint.

**`heads()`** feeds the page's list of eligible heads.

**`lookup()`** resolves a scanned control number to the family. Scanning a head's
own id or any non-head member's id both land on the family head's page; an
unknown or inactive member gives a 404.

Both generating paths call `markGenerated()`, which stamps `card_generated_at`
and `card_generated_by`. A reprint additionally writes an audit row, so a card
reissued after a family lost theirs is traceable.

## Card Readiness

Card Readiness is the Head-only follow-up list for households missing a control
number, first name, last name, sex, birthday, address, contact number, or
barangay. It is derived from the current active Head data, so a later family edit
updates the result without a stored `card_ready` flag. Suffix is optional.

Future printing work will consume this shared readiness predicate before it
selects Heads to print. Printing behavior is unchanged in this work: Card
Readiness identifies follow-up, but it does not alter the current batch, single
card, reprint, or QR lookup paths.

## What the QR encodes

The QR encodes a URL: `QrCardSettings::$qrUrlPrefix` followed by the control
number. The prefix is empty by default, which makes the QR a bare number, and it
is overridable through `.env`:

```ini
qrcardsettings.qrUrlPrefix = "https://app.binan.gov.ph/cards/lookup/"
```

Set the prefix and a phone camera pointed at the card opens the family's page
directly. Leave it empty and the QR is just the number, which is what the venue
scanner reads.

This interacts with `app.baseURL` in the way chapter 04 warns about: a QR
generated while the prefix pointed at `localhost` is useless on any device that
is not the machine that made it, and unlike a broken link on a page, it has been
printed and handed out.

## Generation settings

`app/Config/QrCardSettings.php` holds the knobs, all overridable from `.env`:

| Setting | Default | What it controls |
|---|---|---|
| `qrUrlPrefix` | empty | what the QR encodes before the number |
| `controlNumberWidth` | 1 | zero-padding on the printed number |
| `cellsPerPage` | 12 | cards per PDF page |
| `cardsPerChunk` | 1000 | cards per PDF file in a large run |
| `maxQuantity` | 25000 | the ceiling on a single batch |

A run larger than `cardsPerChunk` produces several PDFs delivered as a ZIP, named
by the chunk patterns in the same config. That chunking exists because a
25,000-card PDF is not a thing a laptop opens.

## The PDF

`app/Libraries/Qr/QrCardPdfGenerator.php` lays out the cards,
`QrImageGenerator.php` and `QrPngOutput.php` produce the codes themselves, and
the card partials under `app/Views/Cards/pdf/` hold the markup. Those partials are
props-only views: they are called by the generator rather than by a controller, so
their headers list their props directly, which is the documented exception to the
no-variable-list rule in the comment standard.

Chapter 15 picks up from here: what happens when one of these cards is scanned at
a distribution.
