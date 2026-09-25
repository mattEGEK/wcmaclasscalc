// wcma-calculator/js/car-picker.js
//
// "Which car is this for?" on the calculator, for signed-in competitors. Every class declaration
// belongs to a car. Classic script (not a module) so it can be unit tested with node --test.
(function () {
    function carIdFromQuery(search) {
        const m = /[?&]car=(\d+)(?:&|$)/.exec(search || '');
        return m ? m[1] : '';
    }

    function pickerOptions(cars) {
        return cars.map(c => ({
            value: String(c.id),
            label: c.label + (c.current_class ? ' (' + c.current_class + ')' : ''),
        })).concat([{ value: 'new', label: 'A new car' }]);
    }

    function initialCarValue(cars, search) {
        if (!cars.length) return 'new';
        const wanted = carIdFromQuery(search);
        return cars.some(c => String(c.id) === wanted) ? wanted : '';
    }

    function applyChoice(doc, cars, value) {
        doc.getElementById('car-number-group').hidden = value !== 'new';
        const car = cars.find(c => String(c.id) === value);
        if (!car) return;
        const fields = { year: car.year, make: car.make, model: car.model };
        Object.keys(fields).forEach(id => {
            const el = doc.getElementById(id);
            if (el && fields[id] != null) el.value = fields[id];
        });
    }

    async function init(doc, win) {
        try {
            const status = await (await fetch('session-status.php', { credentials: 'same-origin' })).json();
            if (!status.loggedIn) return;   // signed out: the server asks them to sign in on submit
            const data = await (await fetch('cars.php?action=list', { credentials: 'same-origin' })).json();
            if (!data.success) return;

            const select = doc.getElementById('car-id');
            select.textContent = '';
            const placeholder = doc.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- Choose your car --';
            select.appendChild(placeholder);
            pickerOptions(data.cars).forEach(o => {
                const opt = doc.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                select.appendChild(opt);
            });

            select.value = initialCarValue(data.cars, win.location.search);
            doc.getElementById('car-picker-group').hidden = false;
            applyChoice(doc, data.cars, select.value);
            select.addEventListener('change', () => applyChoice(doc, data.cars, select.value));
        } catch (e) {
            // Leave the picker hidden; the server still validates the car on submit.
        }
    }

    const api = { carIdFromQuery, pickerOptions, initialCarValue };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.WcmaCarPicker = api;
        document.addEventListener('DOMContentLoaded', () => init(document, window));
    }
})();
