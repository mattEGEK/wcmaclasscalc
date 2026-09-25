// wcma-calculator/js/declaration-state.js
//
// Keeps a signed-out visitor's calculator entries across sign-in (sessionStorage), so declaring a
// class never loses their work. Classic script; CommonJS-guarded for node --test.
(function () {
    const STASH_KEY = 'wcma-pending-declaration';

    function stashForm(storage, data) {
        try { storage.setItem(STASH_KEY, JSON.stringify(data)); return true; } catch (e) { return false; }
    }

    function takeStashedForm(storage) {
        try {
            const raw = storage.getItem(STASH_KEY);
            storage.removeItem(STASH_KEY);
            if (raw === null) return null;
            const data = JSON.parse(raw);
            return data && typeof data === 'object' ? data : null;
        } catch (e) {
            return null;
        }
    }

    function signInUrlForRestore() {
        return 'auth.php?action=login&redirect=' + encodeURIComponent('calculator.php?restore=1');
    }

    const api = { STASH_KEY, stashForm, takeStashedForm, signInUrlForRestore };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else window.WcmaDeclarationState = api;
})();
