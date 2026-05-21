document.addEventListener('DOMContentLoaded', function () {

    // Select All Paket (Manajemen Rute)
    var selectAll = document.getElementById('select-all-paket');
    if (selectAll) {
        var checkboxes = document.querySelectorAll('.list-paket input[name="paket_dipilih[]"]');
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
        });
        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                selectAll.checked = Array.from(checkboxes).every(function (c) { return c.checked; });
            });
        });
    }

    // Toggle Zona Baru (Manajemen Paket)
    var zonaSelect = document.getElementById('zona_select');
    if (zonaSelect) {
        zonaSelect.addEventListener('change', function () {
            var inp = document.getElementById('zona_baru');
            if (inp) {
                inp.style.display = this.value === '__new__' ? 'block' : 'none';
                inp.required = this.value === '__new__';
            }
        });
    }

});
