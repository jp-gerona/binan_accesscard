// Smoke test for public/assets/js/dashboard/import-review.js: the review table
// must paint one row per fetched person with a Row column carrying the sheet
// row, each collapsed Issues badge must read "cell · label" so the operator can
// Ctrl+G in Excel without expanding anything, and the empty-state and editor
// panel colSpans must cover the new column. This repo has no JS test harness
// (no package.json, no jsdom) - see tests/js/batch-heatmap.smoke.mjs for the
// pattern this follows and the notes at its top about how it runs. Rather than
// add a dependency, this builds a minimal DOM by hand, just enough to exercise
// the module.
//
// import-review.js delegates its clicks from the #importReview root and reads
// its payload from two <template> islands (importReviewSummary,
// importReviewFieldOptions), so the fixture mirrors that shape and stubs
// window.fetch to serve the rows endpoint payload one call at a time.
//
// Run with: node tests/js/import-review.smoke.mjs
// Exits non-zero (and prints the failure) if the script throws during load, if
// the Row column does not render the sheet row, if the Issues badge loses the
// cell reference or the label, or if the empty-state or editor-panel colSpan
// does not cover the new column.

import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

function makeClassList(node) {
    return {
        contains: (c) => node._classes.has(c),
        add: (...cs) => cs.forEach((c) => node._classes.add(c)),
        remove: (...cs) => cs.forEach((c) => node._classes.delete(c)),
        toggle: (c, force) => {
            const has = node._classes.has(c);
            const next = force === undefined ? !has : force;
            if (next) { node._classes.add(c); } else { node._classes.delete(c); }
            return next;
        },
    };
}

// Hand-rolled rather than regex: attribute values can carry their own brackets
// (selector attribute matching follows entry-page-gate.smoke.mjs, whose notes
// explain the scan below).
function parseSimpleSelector(part) {
    const s = part.trim();
    let i = 0;
    let tag = null;
    let id = null;
    let requireChecked = false;
    const classes = [];
    const attrs = [];

    const tagMatch = s.slice(i).match(/^[A-Za-z][\w-]*/);
    if (tagMatch) {
        tag = tagMatch[0];
        i += tagMatch[0].length;
    }

    while (i < s.length) {
        if (s[i] === '#') {
            const m = s.slice(i + 1).match(/^[\w-]+/);
            id = m[0];
            i += 1 + m[0].length;
        } else if (s[i] === '.') {
            const m = s.slice(i + 1).match(/^[\w-]+/);
            classes.push(m[0]);
            i += 1 + m[0].length;
        } else if (s[i] === '[') {
            let close = -1;
            let inQuotes = false;
            for (let j = i + 1; j < s.length; j++) {
                if (s[j] === '"') {
                    inQuotes = !inQuotes;
                } else if (s[j] === ']' && !inQuotes) {
                    close = j;
                    break;
                }
            }
            if (close === -1) {
                throw new Error('Unterminated [attr] in selector: ' + part);
            }
            const inner = s.slice(i + 1, close);
            const eq = inner.indexOf('=');
            if (eq === -1) {
                attrs.push([inner, null]);
            } else {
                attrs.push([inner.slice(0, eq), inner.slice(eq + 1).replace(/^"|"$/g, '')]);
            }
            i = close + 1;
        } else if (s.slice(i) === ':checked') {
            requireChecked = true;
            i = s.length;
        } else {
            throw new Error('Unsupported selector fragment: ' + part);
        }
    }

    return { tag: tag ? tag.toUpperCase() : null, id, classes, attrs, requireChecked };
}

function matchesSimple(node, parsed) {
    if (node.nodeType !== 1) {
        return false;
    }
    if (parsed.tag && node.tagName !== parsed.tag) {
        return false;
    }
    if (parsed.id && node.id !== parsed.id) {
        return false;
    }
    for (const c of parsed.classes) {
        if (!node._classes.has(c)) {
            return false;
        }
    }
    for (const [name, value] of parsed.attrs) {
        if (name.startsWith('data-')) {
            const key = name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            if (!(key in node.dataset)) {
                return false;
            }
            if (value !== null && String(node.dataset[key]) !== value) {
                return false;
            }
        } else {
            if (!(name in node._attrs)) {
                return false;
            }
            if (value !== null && node._attrs[name] !== value) {
                return false;
            }
        }
    }
    if (parsed.requireChecked && !node.checked) {
        return false;
    }
    return true;
}

// Plain descendant combinator only ("A B"), which covers the one lookup this
// test makes on the rendered rows (.import-review-issues .badge).
function matchesCombinatorPart(node, part) {
    const segments = part.trim().split(/\s+/).filter(Boolean);

    if (segments.length <= 1) {
        return matchesSimple(node, parseSimpleSelector(part));
    }

    if (!matchesSimple(node, parseSimpleSelector(segments[segments.length - 1]))) {
        return false;
    }

    let ancestor = node.parentNode;
    let segIndex = segments.length - 2;

    while (ancestor && segIndex >= 0) {
        if (matchesSimple(ancestor, parseSimpleSelector(segments[segIndex]))) {
            segIndex--;
        }
        ancestor = ancestor.parentNode;
    }

    return segIndex < 0;
}

function matchesSelector(node, selector) {
    return selector.split(',').some((part) => matchesCombinatorPart(node, part));
}

function walk(node, cb) {
    for (const child of node.children) {
        cb(child);
        walk(child, cb);
    }
}

class FakeNode {
    constructor(tag) {
        this.tagName = tag ? tag.toUpperCase() : null;
        this.nodeType = 1;
        this._attrs = {};
        this._classes = new Set();
        this.dataset = {};
        this.children = [];
        this.parentNode = null;
        this._listeners = {};
        this.value = '';
        this.disabled = false;
        this.checked = false;
        this._text = '';
    }

    get classList() { return makeClassList(this); }

    get className() { return [...this._classes].join(' '); }
    set className(v) { this._classes = new Set(String(v).split(/\s+/).filter(Boolean)); }

    get id() { return this._attrs.id ?? null; }
    set id(v) { this._attrs.id = String(v); }

    get textContent() { return this._text; }
    set textContent(v) { this._text = String(v); this.children = []; }

    setAttribute(name, value) {
        this._attrs[name] = String(value);
        if (name === 'class') {
            this._classes = new Set(String(value).split(/\s+/).filter(Boolean));
        }
        if (name.startsWith('data-')) {
            const key = name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            this.dataset[key] = String(value);
        }
    }

    getAttribute(name) { return name in this._attrs ? this._attrs[name] : null; }

    removeAttribute(name) {
        delete this._attrs[name];
        if (name.startsWith('data-')) {
            const key = name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            delete this.dataset[key];
        }
    }

    hasAttribute(name) { return name in this._attrs; }

    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    removeChild(child) {
        const at = this.children.indexOf(child);
        if (at !== -1) { this.children.splice(at, 1); }
        child.parentNode = null;
        return child;
    }

    get firstChild() { return this.children[0] || null; }

    remove() {
        if (this.parentNode) { this.parentNode.removeChild(this); }
    }

    after(node) {
        if (!this.parentNode) { return; }
        const at = this.parentNode.children.indexOf(this);
        node.parentNode = this.parentNode;
        this.parentNode.children.splice(at + 1, 0, node);
    }

    querySelectorAll(selector) {
        const out = [];
        walk(this, (node) => {
            if (matchesSelector(node, selector)) { out.push(node); }
        });
        return out;
    }

    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }

    matches(selector) { return matchesSelector(this, selector); }

    closest(selector) {
        let node = this;
        while (node && node.nodeType === 1) {
            if (matchesSelector(node, selector)) { return node; }
            node = node.parentNode;
        }
        return null;
    }

    addEventListener(type, handler) { (this._listeners[type] ||= []).push(handler); }

    removeEventListener(type, handler) {
        const list = this._listeners[type];
        if (list) { this._listeners[type] = list.filter((h) => h !== handler); }
    }

    // Bubbles the same way batch-heatmap.smoke.mjs's fixture does, so the
    // #importReview root's delegated click handlers fire for a click on a
    // button deep inside the table.
    dispatch(type, init = {}) {
        const event = { type, target: this, preventDefault() {}, ...init };
        let node = this;
        while (node) {
            for (const handler of node._listeners[type] || []) { handler(event); }
            node = node.parentNode || node._parentDocument || null;
        }
    }
}

function el(tag, attrs = {}, children = []) {
    const node = new FakeNode(tag);
    for (const [k, v] of Object.entries(attrs)) { node.setAttribute(k, v); }
    children.forEach((c) => node.appendChild(c));
    return node;
}

function jsonResponse(data) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(data) });
}

class FakeFormData {
    constructor() { this.entries = []; }
    append(key, value) { this.entries.push([key, value]); }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

// --- Fixture: the ids import-review.js binds and the two <template> islands
// it reads on load. Rows arrive only through window.fetch. ---

const searchEl = el('input', { type: 'search', id: 'importReviewSearch' });
const perPageEl = el('select', { id: 'importReviewPerPage' });
const codeFilterEl = el('select', { id: 'importReviewCodeFilter' });
codeFilterEl.value = '';

const tbody = el('tbody');
const table = el('table', { id: 'importReviewTable' }, [tbody]);

const pagerEl = el('ul', { id: 'importReviewPager' });
const countEl = el('span', { id: 'importReviewCount' });
const statusEl = el('span', { id: 'importReviewStatus' });
const confirmBtn = el('button', { id: 'importReviewConfirm' });
const cancelBtn = el('button', { id: 'importReviewCancel' });

const summaryTemplate = el('template', { id: 'importReviewSummary' });
summaryTemplate.content = { textContent: JSON.stringify({ file: 'import.xlsx', counts: {}, codes: [] }) };

const fieldOptionsTemplate = el('template', { id: 'importReviewFieldOptions' });
fieldOptionsTemplate.content = { textContent: '{}' };

const root = el('div', {
    id: 'importReview',
    'data-rows-url': 'http://localhost/records/import/review/5/rows',
    'data-apply-url': 'http://localhost/records/import/review/5/apply',
    'data-resolve-duplicate-url': 'http://localhost/records/import/review/5/resolve-duplicate',
    'data-restore-url': 'http://localhost/records/import/review/5/restore',
    'data-commit-url': 'http://localhost/records/import/review/5/commit',
    'data-cancel-url': 'http://localhost/records/import/review/5/cancel',
    'data-redirect-url': 'http://localhost/records',
}, [
    searchEl, perPageEl, codeFilterEl, table, pagerEl, countEl, statusEl,
    confirmBtn, cancelBtn, summaryTemplate, fieldOptionsTemplate,
]);

const documentNode = {
    nodeType: 9,
    children: [root],
    _listeners: {},
    addEventListener(type, handler) { (this._listeners[type] ||= []).push(handler); },
    createElement(tag) { return new FakeNode(tag); },
    querySelectorAll(selector) {
        const out = [];
        for (const top of this.children) {
            if (matchesSelector(top, selector)) { out.push(top); }
            walk(top, (node) => { if (matchesSelector(node, selector)) { out.push(node); } });
        }
        return out;
    },
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
    getElementById(id) {
        for (const top of this.children) {
            if (top.id === id) { return top; }
            const found = (function find(node) {
                for (const c of node.children) {
                    if (c.id === id) { return c; }
                    const deeper = find(c);
                    if (deeper) { return deeper; }
                }
                return null;
            }(top));
            if (found) { return found; }
        }
        return null;
    },
};

for (const top of documentNode.children) {
    (function attach(node) {
        node._parentDocument = documentNode;
        node.children.forEach(attach);
    }(top));
}

const rowPage = {
    rows: [{
        sheetRow: 42,
        qr: '6001',
        family: 'CRUZ',
        role: 'Head',
        values: { lastname: 'Cruz', firstname: 'Juan', birthday: '05-14-1980', sex: 'Male' },
        severity: 'warning',
        issues: [{
            code: 'INCOMPLETE',
            label: 'Missing value (imports blank)',
            severity: 'warning',
            message: 'Monthly Income is blank.',
            cell: 'N42',
        }, {
            code: 'DUP-ROW',
            label: 'Duplicate Row',
            severity: 'blocking',
            message: 'This row is an exact duplicate.',
            cell: '',
        }],
        fields: [{
            field: 'monthlyincome',
            label: 'MonthlyIncome',
            cell: 'N42',
            value: '',
            severity: 'warning',
            message: 'Monthly Income is blank.',
        }],
        duplicateGroup: {
            rows: [42, 43],
            qr: '6001',
            candidates: [
                { sheetRow: 42, role: 'Head', values: { lastname: 'Cruz', firstname: 'Juan' } },
                { sheetRow: 43, role: 'Head', values: { lastname: 'Cruz', firstname: 'Juan' } },
            ],
        },
        discarded: false,
        discardedReason: null,
    }],
    total: 1,
    filtered: 1,
    page: 1,
    per: 25,
};

const emptyPage = { rows: [], total: 0, filtered: 0, page: 1, per: 25 };
const discardedPage = {
    rows: [{
        ...rowPage.rows[0],
        sheetRow: 43,
        severity: '',
        issues: [{ code: 'DISCARDED', label: 'Discarded as duplicate of row 42', severity: 'warning', message: '', cell: '' }],
        fields: [],
        duplicateGroup: null,
        discarded: true,
        discardedReason: 'duplicate',
    }],
    total: 2,
    filtered: 1,
    page: 1,
    per: 25,
};

let currentPayload = rowPage;
const fetchCalls = [];

const fakeWindow = {};
fakeWindow.window = fakeWindow;
fakeWindow.document = documentNode;
fakeWindow.FormData = FakeFormData;
fakeWindow.fetch = (url, opts) => {
    fetchCalls.push({ url, opts });
    return jsonResponse(currentPayload);
};

const script = readFileSync(new URL('../../public/assets/js/dashboard/import-review.js', import.meta.url), 'utf8');

vm.runInNewContext(script, fakeWindow, { filename: 'import-review.js' });

await tick();

// --- One page row renders the sheet row in the new Row column, between Role
// and Last Name. ---
assert.equal(fetchCalls.length, 1, 'import-review.js must fetch the first page on load.');
const firstRow = tbody.querySelectorAll('tr')[0];
assert.ok(firstRow, 'the fetched row must render a table row.');
const rowCell = firstRow.querySelectorAll('td')[1];
assert.equal(rowCell.textContent, '42', 'the Row column must show the sheet row.');

// --- The collapsed Issues badge names both the cell and the problem, so no
// editor needs opening to Ctrl+G in Excel. ---
const badge = tbody.querySelectorAll('.import-review-issues .badge')[0];
assert.ok(badge, 'the Issues cell must render a badge.');
assert.ok(badge.textContent.includes('N42'), 'badge names the cell');
assert.ok(badge.textContent.includes('Missing value'), 'badge keeps the label');

// --- Empty state colSpan covers the new column. ---
currentPayload = emptyPage;
codeFilterEl.dispatch('change');
await tick();

const emptyCell = tbody.querySelectorAll('td')[0];
assert.ok(emptyCell, 'an empty page must render the empty-state row.');
assert.equal(emptyCell.colSpan, 9, 'the empty-state cell must span all 9 columns.');

// --- Editor panel colSpan covers the new column too. ---
currentPayload = rowPage;
codeFilterEl.dispatch('change');
await tick();

const openButton = tbody.querySelector('.js-import-open');
assert.ok(openButton, 'a row with fields must offer an editor toggle.');
openButton.dispatch('click');

const panel = tbody.querySelector('.js-import-panel');
assert.ok(panel, 'clicking the toggle must open the editor panel.');
const panelTd = panel.querySelectorAll('td')[0];
assert.equal(panelTd.colSpan, 9, 'the editor panel must span all 9 columns.');

// --- An active Duplicate Row offers a focused comparison with one Keep action
// per candidate. Resolving posts only the chosen keep_row and refetches this page. ---
const keepButtons = panel.querySelectorAll('.js-import-keep-duplicate');
assert.equal(keepButtons.length, 2, 'a duplicate comparison must offer one Keep button per candidate.');
keepButtons[0].dispatch('click');
await tick();

const resolve = fetchCalls.find((call) => call.url.includes('/resolve-duplicate'));
assert.ok(resolve, 'keeping a candidate must post to the duplicate resolver endpoint.');
assert.equal(resolve.opts.body.entries[0][0], 'keep_row', 'resolver names the keep_row field.');
assert.equal(String(resolve.opts.body.entries[0][1]), '42', 'resolver posts the selected keep_row.');

// --- Discarded rows stay in All as muted resolution rows with Restore only. ---
currentPayload = discardedPage;
codeFilterEl.dispatch('change');
await tick();

const discardedRow = tbody.querySelector('tr[data-row="43"]');
assert.ok(discardedRow.classList.contains('table-secondary'), 'discarded rows render in a muted table state.');
const restoreButton = discardedRow.querySelector('.js-import-restore');
assert.ok(restoreButton, 'a discarded row must offer Restore.');
restoreButton.dispatch('click');
await tick();

const restore = fetchCalls.find((call) => call.url.includes('/restore'));
assert.ok(restore, 'restoring must post to the restore endpoint.');
assert.equal(restore.opts.body.entries[0][0], 'import_row', 'restore names the import_row field.');
assert.equal(String(restore.opts.body.entries[0][1]), '43', 'restore posts the discarded import_row.');

console.log('OK: import-review.js renders rows, duplicate resolver/restore actions, and 9-column empty/editor states.');
