// wcma-calculator/js/signature-pad.js
// Minimal canvas-based signature capture — no external library.
// Pointer Events cover mouse, touch, and pen in one code path.
window.WcmaSignaturePad = (function () {
    function attach(canvas) {
        const ctx = canvas.getContext('2d');
        let drawing = false;
        let hasInk = false;
        let lastX = 0, lastY = 0;

        function resizeForDPR() {
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            ctx.scale(dpr, dpr);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#2c3e50';
        }
        resizeForDPR();

        function pos(e) {
            const rect = canvas.getBoundingClientRect();
            return { x: e.clientX - rect.left, y: e.clientY - rect.top };
        }

        canvas.addEventListener('pointerdown', function (e) {
            drawing = true;
            hasInk = true;
            const p = pos(e);
            lastX = p.x; lastY = p.y;
            canvas.setPointerCapture(e.pointerId);
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!drawing) return;
            const p = pos(e);
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            lastX = p.x; lastY = p.y;
        });

        function stop() { drawing = false; }
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        canvas.addEventListener('pointerleave', stop);

        return {
            clear: function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasInk = false;
            },
            isEmpty: function () { return !hasInk; },
            toPNGDataURL: function () { return canvas.toDataURL('image/png'); },
        };
    }

    return { attach: attach };
})();
