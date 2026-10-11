<?php
// includes/location-picker.php
// Reusable location picker: GPS + Map (OpenStreetMap + Nominatim).
// Usage: include this file, then call render_location_picker($old);

function render_location_picker($old = [], $prefix = '') {
    $city    = htmlspecialchars($old['city']    ?? '', ENT_QUOTES);
    $area    = htmlspecialchars($old['area']    ?? '', ENT_QUOTES);
    $pincode = htmlspecialchars($old['pincode'] ?? '', ENT_QUOTES);
    $lat     = htmlspecialchars($old['latitude']  ?? '', ENT_QUOTES);
    $lng     = htmlspecialchars($old['longitude'] ?? '', ENT_QUOTES);
    $uid     = $prefix . uniqid();
    ?>
    <div class="location-picker" id="lp-<?= $uid ?>">
        <div class="lp-buttons">
            <button type="button" class="btn btn-outline btn-sm" onclick="lpUseGPS('<?= $uid ?>')">
                📍 Use my current location
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="lpOpenMap('<?= $uid ?>')">
                🗺️ Pick location on map
            </button>
            <span class="text-muted" id="lp-status-<?= $uid ?>" style="margin-left:8px;font-size:13px"></span>
        </div>

        <div class="lp-map-wrap" id="lp-map-wrap-<?= $uid ?>" style="display:none;margin-top:12px">
            <div id="lp-map-<?= $uid ?>" style="width:100%;height:340px;border-radius:12px;background:#eef"></div>
            <p class="text-muted" style="font-size:12px;margin-top:6px">
                🖱️ Click anywhere on the map to drop the pin, or drag the pin to fine-tune.
            </p>
        </div>

        <input type="hidden" name="latitude"  id="lp-lat-<?= $uid ?>" value="<?= $lat ?>">
        <input type="hidden" name="longitude" id="lp-lng-<?= $uid ?>" value="<?= $lng ?>">
    </div>

    <script>
    // ---- Load Leaflet once ----
    (function() {
        if (!document.getElementById('leaflet-css')) {
            var l = document.createElement('link');
            l.id = 'leaflet-css';
            l.rel = 'stylesheet';
            l.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(l);
        }
        if (!document.getElementById('leaflet-js')) {
            var s = document.createElement('script');
            s.id = 'leaflet-js';
            s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            document.head.appendChild(s);
        }
    })();

    window.lpMaps = window.lpMaps || {};
    window.lpMarkers = window.lpMarkers || {};

    // ============================================================
    // 1. USE MY CURRENT LOCATION
    // ============================================================
    function lpUseGPS(uid) {
        var status = document.getElementById('lp-status-' + uid);
        if (!navigator.geolocation) {
            status.textContent = '❌ Geolocation is not supported by this browser.';
            return;
        }
        status.textContent = '📡 Detecting your location…';
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                var lat = pos.coords.latitude.toFixed(6);
                var lng = pos.coords.longitude.toFixed(6);
                var acc = Math.round(pos.coords.accuracy || 0);

                // Set hidden coordinates
                document.getElementById('lp-lat-' + uid).value = lat;
                document.getElementById('lp-lng-' + uid).value = lng;

                status.textContent = '📍 Coordinates captured (accuracy ±' + acc + 'm). Looking up address…';

                // If a map is already open, move its marker to the new coordinates
                if (window.lpMaps[uid] && window.lpMarkers[uid]) {
                    window.lpMaps[uid].setView([lat, lng], 16);
                    window.lpMarkers[uid].setLatLng([lat, lng]);
                }

                // Reverse geocode and force-update the visible fields
                lpReverseGeocode(lat, lng, uid, true);
            },
            function(err) {
                var msg = '❌ Location error';
                if (err.code === 1) msg = '❌ You denied location permission. Please allow access or pick manually on the map.';
                else if (err.code === 2) msg = '❌ Location unavailable. Try again or pick manually on the map.';
                else if (err.code === 3) msg = '❌ Location request timed out. Try again or pick manually on the map.';
                status.textContent = msg;
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    }

    // ============================================================
    // 2. REVERSE GEOCODE (Nominatim)
    // ============================================================
    function lpReverseGeocode(lat, lng, uid, forceOverwrite) {
        var status = document.getElementById('lp-status-' + uid);
        var url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat +
                  '&lon=' + lng + '&zoom=18&addressdetails=1';

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.address) {
                    if (forceOverwrite) {
                        status.textContent = '📍 Coordinates captured. Address lookup failed — please fill address fields manually.';
                    }
                    return;
                }
                var a = data.address;

                // Best-effort extraction of city / town / village
                var city = a.city || a.town || a.municipality || a.village ||
                           a.county || a.state_district || a.state || '';
                // Best-effort extraction of area / suburb / neighbourhood
                var area = a.suburb || a.neighbourhood || a.quarter ||
                           a.city_district || a.hamlet || a.village || '';
                var pin  = a.postcode || '';
                // Full formatted address
                var addr = data.display_name || '';

                var cityField = document.getElementById('city')       || document.querySelector('input[name="city"]');
                var areaField = document.getElementById('area')       || document.querySelector('input[name="area"]');
                var pinField  = document.getElementById('pincode')    || document.querySelector('input[name="pincode"]');
                var addrField = document.getElementById('address')    || document.querySelector('textarea[name="address"]');

                // FORCE overwrite — user clicked the button, they want the update
                if (forceOverwrite) {
                    if (cityField && city) cityField.value = city;
                    if (areaField && area) areaField.value = area;
                    if (pinField  && pin)  pinField.value  = pin;
                    if (addrField && addr) addrField.value = addr;
                    status.textContent = '✅ Location set: ' + (area ? area + ', ' : '') + (city || 'coordinates saved');
                } else {
                    // Non-forced: only fill empty fields (map marker moved)
                    if (cityField && !cityField.value && city) cityField.value = city;
                    if (areaField && !areaField.value && area) areaField.value = area;
                    if (pinField  && !pinField.value  && pin)  pinField.value  = pin;
                    if (addrField && !addrField.value && addr) addrField.value = addr;
                    status.textContent = '✅ Marker moved to ' + lat + ', ' + lng;
                }
            })
            .catch(function() {
                if (forceOverwrite) {
                    status.textContent = '📍 Coordinates captured. Address lookup failed — please fill address fields manually.';
                }
            });
    }

    // ============================================================
    // 3. MAP PICKER
    // ============================================================
    function lpOpenMap(uid) {
        var wrap = document.getElementById('lp-map-wrap-' + uid);
        wrap.style.display = 'block';

        // If Leaflet hasn't loaded yet, retry in a moment
        if (typeof L === 'undefined') {
            setTimeout(function() { lpOpenMap(uid); }, 200);
            return;
        }

        // If map already exists, just refresh its size and re-center on latest GPS coords
        if (window.lpMaps[uid]) {
            var lat0 = parseFloat(document.getElementById('lp-lat-' + uid).value);
            var lng0 = parseFloat(document.getElementById('lp-lng-' + uid).value);
            window.lpMaps[uid].invalidateSize();
            if (!isNaN(lat0) && !isNaN(lng0) && window.lpMarkers[uid]) {
                window.lpMaps[uid].setView([lat0, lng0], 15);
                window.lpMarkers[uid].setLatLng([lat0, lng0]);
            }
            return;
        }

        // Initial position: existing coords or a neutral world view
        var startLat = parseFloat(document.getElementById('lp-lat-' + uid).value);
        var startLng = parseFloat(document.getElementById('lp-lng-' + uid).value);
        var hasStart = !isNaN(startLat) && !isNaN(startLng) && startLat !== 0 && startLng !== 0;

        // Neutral default (India center) — will be overwritten if coords exist
        var center = hasStart ? [startLat, startLng] : [20.5937, 78.9629];
        var zoom   = hasStart ? 15 : 5;

        var map = L.map('lp-map-' + uid, { scrollWheelZoom: true }).setView(center, zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        var marker = L.marker(center, { draggable: true }).addTo(map);
        window.lpMaps[uid]    = map;
        window.lpMarkers[uid] = marker;

        function apply(lat, lng) {
            document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
            document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);
            lpReverseGeocode(lat.toFixed(6), lng.toFixed(6), uid, true);
        }

        // Marker drag → update coords + address
        marker.on('dragend', function() {
            var p = marker.getLatLng();
            apply(p.lat, p.lng);
        });

        // Map click → move marker and update coords + address
        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            apply(e.latlng.lat, e.latlng.lng);
        });

        // Leaflet sometimes needs a size refresh after the container becomes visible
        setTimeout(function() { map.invalidateSize(); }, 200);
    }
    </script>
    <?php
}
