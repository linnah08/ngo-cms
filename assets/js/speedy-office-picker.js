/**
 * SpeedyOfficePicker — reusable city-search + office-list widget for Speedy.
 *
 * Usage:
 *   var picker = SpeedyOfficePicker({
 *     cityInput:       'myCityInput',
 *     citySuggestions: 'myCitySuggestions',
 *     officeLoading:   'myOfficeLoading',
 *     officeWrap:      'myOfficeWrap',
 *     officeSearch:    'myOfficeSearch',
 *     officeSelect:    'myOfficeSelect',
 *     officeSelected:  'myOfficeSelected',
 *     officeCode:      'myOfficeCode',   // hidden input
 *     officeName:      'myOfficeName',   // hidden input
 *     officeCity:      'myOfficeCity',   // hidden input
 *     getType:         function() { return document.getElementById('...').value; }
 *   });
 *
 *   Wire HTML: oninput="picker.onCityInput(this.value)"
 *              oninput="picker.filterOffices(this.value)"
 *              onchange="picker.selectOffice(this)"
 */
function SpeedyOfficePicker(ids) {
    var allOffices = [];
    var cityTimer  = null;

    function g(key) { return document.getElementById(ids[key]); }

    function getType() {
        return ids.getType ? ids.getType() : 'office';
    }

    function clearOffice() {
        g('officeWrap').style.display    = 'none';
        g('officeLoading').style.display = 'none';
        g('officeSelected').textContent  = '';
        g('officeCode').value = '';
        g('officeName').value = '';
        g('officeCity').value = '';
        g('officeSearch').value = '';
        allOffices = [];
    }

    function onCityInput(val) {
        var q = val.trim();
        clearTimeout(cityTimer);
        g('citySuggestions').style.display = 'none';
        if (!q) { clearOffice(); return; }
        if ((g('officeCity').value || '').toLowerCase() !== q.toLowerCase()) clearOffice();
        if (q.length < 2) return;
        cityTimer = setTimeout(function() {
            fetch('/api/cities.php?courier=speedy&q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(cities) {
                    if (g('cityInput').value.trim() !== q) return;
                    renderCities(cities, q.toLowerCase());
                })
                .catch(function() {});
        }, 300);
    }

    function renderCities(cities, qLow) {
        var sugg = g('citySuggestions');
        sugg.innerHTML = '';
        cities.forEach(function(city) {
            var div = document.createElement('div');
            div.textContent = city;
            div.style.cssText = 'padding:.45rem .75rem;cursor:pointer;font-size:.9rem;border-bottom:1px solid #f0f0f0;';
            div.addEventListener('mousedown', function() { pickCity(city); });
            div.addEventListener('mouseover', function() { div.style.background = '#e4f0f5'; });
            div.addEventListener('mouseout',  function() { div.style.background = ''; });
            sugg.appendChild(div);
        });
        sugg.style.display = cities.length ? '' : 'none';
        var exact = cities.find(function(c) { return c.toLowerCase() === qLow; });
        if (exact) cityTimer = setTimeout(function() { pickCity(exact); }, 400);
    }

    function pickCity(city) {
        g('cityInput').value = city;
        g('citySuggestions').style.display = 'none';
        g('officeCity').value = city;
        loadOffices(city, null);
    }

    function loadOffices(city, restoreCode) {
        var loading = g('officeLoading');
        var wrap    = g('officeWrap');
        loading.textContent   = 'Зарежда офиси…';
        loading.style.display = '';
        wrap.style.display    = 'none';
        g('officeSearch').value = '';
        allOffices = [];
        fetch('/api/offices.php?courier=speedy&city=' + encodeURIComponent(city))
            .then(function(r) { return r.json(); })
            .then(function(all) {
                var type = getType();
                allOffices = all.filter(function(o) { return o.type === type; });
                if (!allOffices.length) {
                    loading.textContent = 'Няма намерени офиси за този град.';
                    return;
                }
                renderOfficeList(allOffices, restoreCode);
                loading.style.display = 'none';
                wrap.style.display    = '';
            })
            .catch(function() {
                loading.textContent = 'Грешка при зареждане на офиси. Опитайте отново.';
            });
    }

    function filterOffices(q) {
        var low = q.trim().toLowerCase();
        var filtered = low
            ? allOffices.filter(function(o) {
                return o.name.toLowerCase().includes(low) ||
                       (o.address || '').toLowerCase().includes(low);
              })
            : allOffices;
        renderOfficeList(filtered, null);
    }

    function renderOfficeList(data, restoreCode) {
        var sel = g('officeSelect');
        sel.innerHTML = '';
        data.forEach(function(o) {
            var opt = document.createElement('option');
            opt.value        = o.code;
            opt.dataset.name = o.name;
            opt.textContent  = o.name;
            sel.appendChild(opt);
        });
        if (restoreCode) sel.value = restoreCode;
        if (!sel.value && sel.options.length) sel.selectedIndex = 0;
        if (sel.options.length) selectOffice(sel);
    }

    function selectOffice(sel) {
        var opt = sel.options[sel.selectedIndex];
        if (!opt) return;
        g('officeCode').value = opt.value;
        g('officeName').value = opt.dataset.name;
        g('officeSelected').textContent = '✓ ' + opt.dataset.name;
    }

    // Reload offices when delivery type changes (office ↔ apt)
    function onTypeChange() {
        var city = g('officeCity').value;
        if (city) loadOffices(city, null);
        else clearOffice();
    }

    // Hide suggestions when clicking outside the city input
    document.addEventListener('click', function(e) {
        var ci = g('cityInput');
        if (ci && !ci.contains(e.target) && e.target !== ci) {
            g('citySuggestions').style.display = 'none';
        }
    });

    return {
        onCityInput:   onCityInput,
        filterOffices: filterOffices,
        selectOffice:  selectOffice,
        pickCity:      pickCity,
        loadOffices:   loadOffices,
        clearOffice:   clearOffice,
        onTypeChange:  onTypeChange,
    };
}
