// wcma-calculator/js/photo-resize.js
// Shrinks a phone photo to a ~1600px JPEG in the browser before upload. Re-encoding
// through a canvas also strips EXIF/GPS data. Loadable as a classic script
// (window.WcmaPhotoResize) or via require() for tests.
(function (root) {
    'use strict';

    function computeTargetSize(width, height, maxEdge) {
        const longEdge = Math.max(width, height);
        if (longEdge <= maxEdge) return { width: width, height: height };
        const scale = maxEdge / longEdge;
        return { width: Math.round(width * scale), height: Math.round(height * scale) };
    }

    // Decode honouring EXIF orientation. createImageBitmap's option is unsupported
    // on older Safari, so fall back to an <img> (which applies orientation itself).
    async function decode(file) {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            return { source: bitmap, width: bitmap.width, height: bitmap.height, close: function () { bitmap.close(); } };
        } catch (e) {
            const url = URL.createObjectURL(file);
            try {
                const img = await new Promise(function (resolve, reject) {
                    const el = new Image();
                    el.onload = function () { resolve(el); };
                    el.onerror = function () { reject(new Error('Could not read that photo')); };
                    el.src = url;
                });
                return { source: img, width: img.naturalWidth, height: img.naturalHeight, close: function () {} };
            } finally {
                URL.revokeObjectURL(url);
            }
        }
    }

    async function resizeToJpeg(file, options) {
        const maxEdge = (options && options.maxEdge) || 1600;
        const quality = (options && options.quality) || 0.8;

        const decoded = await decode(file);
        const size = computeTargetSize(decoded.width, decoded.height, maxEdge);
        const canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;
        canvas.getContext('2d').drawImage(decoded.source, 0, 0, size.width, size.height);
        decoded.close();

        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob); else reject(new Error('Could not prepare that photo'));
            }, 'image/jpeg', quality);
        });
    }

    const api = { computeTargetSize: computeTargetSize, resizeToJpeg: resizeToJpeg };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaPhotoResize = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
