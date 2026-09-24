// wcma-calculator/js/pretech-progress.js
// Which photos are still missing from a pre-tech set. Mirrors photoSetMissingRequired() in
// photo-requirements.php: every 'required' photo, plus each 'conditional' photo the competitor
// marked as applying; 'recommended' photos never count. Loadable as a classic script
// (window.WcmaPretechProgress) or via require() for tests.
(function (root) {
    'use strict';

    function computeMissing(requirements, presentKeys, applicableKeys) {
        const present = new Set(presentKeys);
        const applicable = new Set(applicableKeys);
        return requirements
            .filter(function (req) {
                const needed = req.tier === 'required' || (req.tier === 'conditional' && applicable.has(req.key));
                return needed && !present.has(req.key);
            })
            .map(function (req) { return req.key; });
    }

    const api = { computeMissing: computeMissing };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaPretechProgress = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
