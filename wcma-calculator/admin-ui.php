<?php
// wcma-calculator/admin-ui.php
//
// Shared pieces of the admin tabs (admin desktop UX spec 2026-09-29): page shell, chips, the edit
// modal and where a flash message goes. Pure except adminRedirect() and adminRenderPage().

/** POST/redirect/GET: send the browser to $url and stop. */
function adminRedirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function adminCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/** A status chip. $kind: ok, fail, pending, info or ink. */
function adminChip(string $text, string $kind): string {
    return '<span class="admin-chip admin-chip--' . h($kind) . '">' . h($text) . '</span>';
}

/** Which modal to reopen: ?edit=<id|code|MSR id|new> (1–40 letters, digits or dashes), else null. */
function adminEditTarget(array $get): ?string {
    $v = $get['edit'] ?? null;
    return is_string($v) && preg_match('/^[A-Za-z0-9-]{1,40}$/', $v) ? $v : null;
}

/**
 * Where the flash goes: an error sent back from a modal that is on this page ($edit is one of
 * $dialogKeys) shows inside it; everything else at the top of the page.
 * @return array{top: ?array, dialog: ?array}
 */
function adminFlashPlacement(?array $flash, ?string $edit, array $dialogKeys): array {
    if ($flash !== null && $edit !== null && ($flash['type'] ?? '') === 'error'
        && in_array($edit, array_map('strval', $dialogKeys), true)) {
        return ['top' => null, 'dialog' => $flash];
    }
    return ['top' => $flash, 'dialog' => null];
}

function adminFlashHtml(?array $flash): string {
    if ($flash === null) return '';
    return '<div class="form-messages show ' . h((string)$flash['type']) . '" role="alert">' . h((string)$flash['message']) . '</div>';
}

/** The row's Edit button; $name tells screen readers what it edits. */
function adminEditButton(string $dialogId, string $name): string {
    return '<button type="button" class="btn btn-secondary admin-edit" data-dialog-open="' . h($dialogId)
        . '" aria-label="Edit ' . h($name) . '">Edit</button>';
}

/** The primary "Add …" button above a list; opens the add modal. */
function adminAddButton(string $dialogId, string $label): string {
    return '<button type="button" class="btn btn-primary admin-add" data-dialog-open="' . h($dialogId) . '">' . h($label) . '</button>';
}

/** Cancel + primary submit at the foot of a modal form. */
function adminDialogActions(string $submitLabel): string {
    return '<div class="admin-dialog-actions admin-form-wide">'
        . '<button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>'
        . '<button type="submit" class="btn btn-primary">' . h($submitLabel) . '</button></div>';
}

/** The separate section under a modal's form for Deactivate / Reactivate. $formHtml is the whole <form>. */
function adminDangerHtml(string $heading, string $text, string $formHtml): string {
    return '<section class="admin-dialog-danger"><h3>' . h($heading) . '</h3><p>' . h($text) . '</p>' . $formHtml . '</section>';
}

/**
 * One modal. $bodyHtml is trusted markup (its forms). A $flash (an error sent back from this modal)
 * shows above the form, and the modal opens as the page loads.
 */
function adminDialogHtml(string $id, string $title, string $bodyHtml, ?array $flash = null, string $subtitle = ''): string {
    return '<dialog class="admin-dialog" id="' . h($id) . '" aria-labelledby="' . h($id) . '-title"'
        . ($flash !== null ? ' data-open-on-load' : '') . '>'
        . '<div class="admin-dialog-body">'
        . '<h2 id="' . h($id) . '-title">' . h($title) . '</h2>'
        . ($subtitle !== '' ? '<p class="admin-dialog-sub">' . h($subtitle) . '</p>' : '')
        . adminFlashHtml($flash) . $bodyHtml . '</div></dialog>';
}

/** A labelled control in an .admin-form grid; $wide spans the whole row. */
function adminField(string $id, string $label, string $inputHtml, bool $wide = false): string {
    return '<div class="admin-field' . ($wide ? ' admin-form-wide' : '') . '"><label for="' . h($id) . '">'
        . h($label) . '</label>' . $inputHtml . '</div>';
}

/** The Events tab's MotorsportReg count, set once per request by admin.php. */
function adminEventsBadge(?int $set = null): int {
    static $n = 0;
    if ($set !== null) $n = $set;
    return $n;
}

/** Every admin tab's page: hub layout with the Admin tabs, the title, the body, the modal scripts. */
function adminRenderPage(string $title, string $tab, string $bodyHtml, ?array $topFlash, string $extraScripts = ''): void {
    renderPageStart($title, 'admin', ['subnav' => adminSubnavHtml($tab, adminEventsBadge()), 'flash' => $topFlash, 'bodyClass' => 'admin']);
    echo '<h1 class="hub-page-title">' . h($title) . '</h1>' . $bodyHtml;
    $scripts = '';
    foreach (['js/admin-dialog.js', 'js/confirm-modal.js', 'js/form-feedback.js'] as $src) {
        $scripts .= '<script src="' . hubAsset($src) . '"></script>';
    }
    renderPageEnd(['scripts' => $scripts . $extraScripts]);
}
