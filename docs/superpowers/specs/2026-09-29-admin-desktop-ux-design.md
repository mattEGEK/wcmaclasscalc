# Admin Desktop UX — Design Spec
**Date:** 2026-09-29
**Project:** WCMA Hub (221racing.com)
**Status:** Proposed in conversation (2026-09-29); edits in a modal chosen by the user.

The admin tabs (Users & roles, Events, Clubs, Season links, Settings) were made phone-safe in the
mobile UX pass (`2026-09-28-mobile-ux-older-users-design.md`), but on a desktop browser they are
crowded and odd. A review at 1440px and 390px found that the cause is layout, not control size:
every field is full width, every action is its own form, and every button has the same weight.
The 44px/48px tap targets and 16px text stay; the structure changes.

---

## 1. Shared fixes (every admin tab)

1. **Page shell.** The five pages move from `renderSiteHeader()` inside `.container` to
   `renderPageStart()` / `renderPageEnd()` (section `admin`, `subnav` = `adminSubnavHtml(...)`), like
   Home and Garage. This stops the header squeeze ("Class Calculator" on two lines, "Sign out" on its
   own line) and spaces the title from the tabs. Page `<h1>` stays the tab name.
2. **Status chips in a span.** Status and role chips go inside the cell
   (`<td><span class="badge-ok">Active</span></td>`), never as the cell's class, so rows keep their
   lines and the chip sits on the row's middle. The Role column shows a chip: Admin (ink),
   Inspector (info), User (plain text); Media staff shows as a second chip.
3. **Web-address fields.** `input[type="url"]` joins the B2 field rule in `hub.css`
   (48px tall, 17px text, padding).
4. **Admin form grid.** New `.admin-form`: a CSS grid, `repeat(auto-fit, minmax(260px, 1fr))`,
   `max-width: 880px`, labels above fields, one column on phones with no media query. A field may
   span the full row (`.admin-form-wide`).
5. **Button rules.** One solid primary button per form. Row actions are one secondary outline
   **Edit** button. Destructive actions (Deactivate, Remove) never sit beside Save: in a modal they
   are in a separate section under a rule, as a secondary button; they keep their `data-confirm`.
6. **Card headings.** `.detail-card h2` inside admin pages: 22px narrow bold, no underline rule.
7. **Radio rows.** `fieldset.radio-row`: no browser border, legend styled like a label, options
   side by side as 44px label rows.
8. **Tables on phones.** Below 700px, admin tables (`.admin-table`) show each row as a stacked card:
   each cell on its own line with its column name before it (`data-label`), the Edit button last and
   full width. No sideways scrolling, so Edit is always visible.
9. **Help text.** Grey 14px intro text becomes 16px `--hub-ink-2`.

## 2. Edit modal (shared component)

- A native `<dialog class="admin-dialog">` per row, rendered server-side next to its row with the
  form pre-filled. The row's **Edit** button (`type="button"`, `data-dialog-open="<dialog id>"`)
  calls `showModal()`. New script `js/admin-dialog.js` wires the open buttons and a **Cancel** button
  (`data-dialog-close`); Esc works natively. A click on the backdrop does **not** close it (a stray
  click must not throw away an admin's edits). Focus goes to the first
  field on open and back to the Edit button on close.
- Layout: a title ("Edit Jordan Lee"), the `.admin-form` grid, then a footer with **Cancel**
  (secondary) and **Save** (primary) at the right. A destructive action, where there is one, sits
  below in its own section ("Deactivate this account" + one-line consequence + secondary button).
- The dialogs are rendered after the table, not inside rows, so table search does not match text
  inside them.
- A `data-confirm` form inside a modal (Deactivate) shows its confirm box inside the open dialog:
  `confirm-modal.js` attaches its overlay to the dialog, because the page behind a modal dialog
  cannot be clicked.
- Width: `min(640px, 100vw - 32px)`, scrolls inside on short screens; on phones it fills the width.
- **Errors reopen the modal.** On a validation error the handler redirects to the list with
  `&edit=<id>`. The page renders the error message inside that row's dialog (not at the top of the
  page) and marks it `data-open-on-load`; the script opens it. Success redirects to the list with a
  normal flash at the top.
- **Add** forms use the same modal: an **Add …** primary button above the table opens a blank dialog.
  An add error reopens it with `&edit=new`. The user's typed values are not kept after an error
  (plain POST/redirect); the message says what to fix.

## 3. Users & roles

- Toolbar: search and role filter on one line (filter at its natural width).
- Table columns: **Name** (email under it in `--hub-ink-2`), **Role** (chips per §1.2, plus
  "Needs first & last name" chip), **Sign-in** ("Password · Google"), **Submissions**, **Status**,
  **Joined**, and **Edit**. Sorting and search keep working (`table-tools.js`; Name sorts by name).
- Modal: Name, Role (radios: User / Inspector / Admin), Media staff (checkbox), one **Save**.
  Section below: **Deactivate** (with confirm) or **Reactivate**.
- New action `user-save` (POST, CSRF) applies name, role and media in one go. It keeps every rule of
  the three old handlers: name 1–100 chars; not demoting the last admin; staff roles need a first and
  last name (checked against the *new* name). Nothing is saved when any rule fails.
  `set-role`, `set-name` and `set-media` are removed; `deactivate` / `activate` stay.

## 4. Events

- **Add event** button above the table opens the add modal. The form: Name (wide), Date, Location,
  Host club, Discipline (Summer / Ice radios). Hint: "Ice events need NASCC or WSCC."
- Table columns: **Date**, **Name** (+ "Ice" chip for ice events, no club repeated), **Location**,
  **Host club** (code, or "—"), **Going**, **Status**, **Edit**.
- Edit modal: the same fields, pre-filled, saved through the existing `event-update` handler (which
  today has no page using it — this adds editing name, date and location). Section below:
  **Deactivate** / **Reactivate**.
- The inline host-club select and `event-club` action are removed (the modal covers it).

## 5. Clubs

- Intro card stays. **Add club** button opens the add modal: Short code, Name, MotorsportReg link.
- Table: **Code**, **Name**, **MotorsportReg** (a link, "Open ↗", or "—"), **Status**
  ("Active" / "Inactive" chip), **Edit**.
- Edit modal: Name, MotorsportReg link, **Active — can host new events** checkbox (was "Shown").
  The code shows as read-only text in the title ("Edit NASCC"). Saved through `club-save`.

## 6. Season links

In-table editing stays: admins update every link together each season.

- Columns: **Order** (narrow, 5rem), **Label**, **Web address** (fills the rest), **Shown**,
  and actions: **Save** as a small secondary button, **Remove** as a quiet text link separated from
  Save by space, with its confirm.
- "Add a link" becomes a compact `.admin-form` (Label wide, Web address wide, Order narrow) with
  **Add link** — a card under the table, not a modal (it is used once a season).

## 7. Settings

- Three fieldsets — **Class calculator**, **Tech sheets**, **Feedback** — each with Email and Name
  side by side (`.admin-form`), capped at 720px. Labels drop the "— Email" prefix.
- One **Save** at the bottom. Intro text per §1.9.

## Out of scope

Feedback tab layout (already a list + detail page; it only gets the §1.1 page shell so the header
does not jump when switching tabs); Inspector tabs; any change to what admins can do other
than adding event editing and combining the three user saves.

## Testing

- PHPUnit: `user-save` rules (last admin, staff name, bad name, success sets all three); the
  `set-role`/`set-name`/`set-media` routes are gone; each admin page uses `renderPageStart`;
  Users/Events/Clubs render one dialog per row plus an add dialog; `edit=<id>` marks that dialog
  `data-open-on-load` and puts the flash inside it; chips are spans, not cell classes;
  Deactivate is never in the same form as Save.
- JS: `admin-dialog.js` open/close/open-on-load with `node --test` and a small DOM stub.
- Visual: screenshots of the five tabs at 1440px and 390px (headless Edge on a scratch copy), and
  the phone audit passes.
