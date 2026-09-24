// wcma-calculator/js/photo-upload.js
// Uploads/deletes inspection photos via inspection.php. One photo per request so a
// weak connection loses at most one photo. fetch and resize are injectable for tests.
(function (root) {
    'use strict';

    const GENERIC_ERROR = 'Upload failed. Please try again.';
    const NETWORK_ERROR = 'Upload failed. Check your connection and try again.';

    function createClient(deps) {
        const fetchFn = deps.fetchFn;
        const resizeFn = deps.resizeFn;
        const csrfToken = deps.csrfToken;
        const endpoint = deps.endpoint || 'inspection.php';

        async function post(action, formData) {
            let res;
            try {
                res = await fetchFn(endpoint + '?action=' + action, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                });
            } catch (e) {
                throw new Error(NETWORK_ERROR);
            }
            let data;
            try {
                data = await res.json();
            } catch (e) {
                throw new Error(GENERIC_ERROR);
            }
            if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || GENERIC_ERROR);
            return data;
        }

        async function upload(opts) {
            const blob = await resizeFn(opts.file);
            const form = new FormData();
            form.append('csrf_token', csrfToken);
            form.append('subject_type', opts.subjectType);
            form.append('subject_id', String(opts.subjectId));
            form.append('requirement_key', opts.requirementKey);
            const typed = opts.typed || {};
            Object.keys(typed).forEach(function (name) {
                if (typed[name] === undefined || typed[name] === null) return;
                form.append('typed[' + name + ']', typed[name]);
            });
            form.append('photo', blob, opts.requirementKey + '.jpg');
            return (await post('upload', form)).photo;
        }

        async function remove(opts) {
            const form = new FormData();
            form.append('csrf_token', csrfToken);
            form.append('id', String(opts.id));
            await post('delete', form);
        }

        return { upload: upload, remove: remove };
    }

    const api = {
        createClient: createClient,
        // Browser convenience: real fetch and the canvas resizer.
        browserClient: function (csrfToken) {
            return createClient({
                fetchFn: root.fetch.bind(root),
                resizeFn: root.WcmaPhotoResize.resizeToJpeg,
                csrfToken: csrfToken,
            });
        },
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaPhotoUpload = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
