/**
 * Driver pickers on the tech sheet form: choose one of your driver profiles, or "+ Add a co-driver"
 * and type a name (the profile is created when the sheet is saved). The pure helpers are exported
 * for node --test; wire() and build() touch the DOM.
 */
(function () {
    const NEW = 'new';

    function driverChoiceOptions(drivers, selected) {
        const sel = selected == null ? '' : String(selected);
        const opts = drivers.map(function (d) {
            return { value: String(d.id), label: d.name + (d.self ? ' (you)' : ''), selected: String(d.id) === sel };
        });
        opts.push({ value: NEW, label: '+ Add a co-driver', selected: sel === NEW });
        return opts;
    }

    function driverChoiceComplete(choice, newName) {
        if (choice === NEW) return String(newName || '').trim() !== '';
        return /^\d+$/.test(String(choice || ''));
    }

    /** Shows (and requires) the name input only while "+ Add a co-driver" is chosen. Returns the sync function. */
    function wire(select, nameInput) {
        function sync() {
            const isNew = select.value === NEW;
            nameInput.hidden = !isNew;
            nameInput.required = isNew && !select.disabled;
            nameInput.disabled = select.disabled;
        }
        select.addEventListener('change', sync);
        sync();
        return sync;
    }

    function build(doc, drivers, selected, newName, number) {
        const select = doc.createElement('select');
        select.setAttribute('aria-label', 'Driver ' + number);
        driverChoiceOptions(drivers, selected).forEach(function (o) {
            const opt = doc.createElement('option');
            opt.value = o.value;
            opt.textContent = o.label;
            opt.selected = o.selected;
            select.appendChild(opt);
        });
        const nameInput = doc.createElement('input');
        nameInput.type = 'text';
        nameInput.maxLength = 100;
        nameInput.placeholder = "Co-driver's name";
        nameInput.setAttribute('aria-label', 'Driver ' + number + ' name');
        nameInput.value = newName || '';
        return { select: select, nameInput: nameInput, sync: wire(select, nameInput) };
    }

    const api = { NEW, driverChoiceOptions, driverChoiceComplete, wire, build };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else window.WcmaDriverChoice = api;
})();
