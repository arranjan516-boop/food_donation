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

    window.lpMaps    = window.lpMaps    || {};
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

                document.getElementById('lp-lat-' + uid).value = lat;
                document.getElementById('lp-lng-' + uid).value = lng;

                status.textContent = '📍 Coordinates captured (accuracy ±' + acc + 'm). Looking up address…';

                // If map is open, re-center + zoom in on the new coordinates
                if (window.lpMaps[uid] && window.lpMarkers[uid]) {
                    window.lpMaps[uid].setView([lat, lng], 17);
                    window.lpMarkers[uid].setLatLng([lat, lng]);
                }

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
    // 2. REVERSE GEOCODE (Nominatim) — improved area extraction
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

                // ---------- CITY ----------
                // Try city / town / municipality / village / county / district
                var city = a.city || a.town || a.municipality || a.village ||
                           a.county || a.state_district || a.state || '';

                // ---------- AREA (much broader fallback chain) ----------
                // Includes suburb, neighbourhood, quarter, residential, hamlet,
                // village, city_district, district, road, pedestrian
                var area = a.suburb || a.neighbourhood || a.quarter ||
                           a.residential || a.hamlet || a.village ||
                           a.city_district || a.district || '';

                // If still empty, try to extract "Area" from display_name
                // Typical format: "Road Name, Area Name, City, State, Pincode, Country"
                if (!area && data.display_name) {
                    var parts = data.display_name.split(',').map(function(x) { return x.trim(); });
                    // Usually parts[0] = road/building, parts[1] = area, parts[2] = city
                    if (parts.length >= 2) {
                        // Skip the first part if it looks like a house number or road
                        var candidate = parts[1];
                        if (candidate && !/^\d+$/.test(candidate)) {
                            area = candidate;
                        }
                    }
                }

                // If AREA is still empty, use the road name as a last resort
                if (!area && a.road) {
                    area = a.road;
                }

                // ---------- PINCODE ----------
                var pin = a.postcode || '';

                // ---------- FULL ADDRESS ----------
                var addr = data.display_name || '';

                var cityField = document.getElementById('city')       || document.querySelector('input[name="city"]');
                var areaField = document.getElementById('area')       || document.querySelector('input[name="area"]');
                var pinField  = document.getElementById('pincode')    || document.querySelector('input[name="pincode"]');
                var addrField = document.getElementById('address')    || document.querySelector('textarea[name="address"]');

                if (forceOverwrite) {
                    if (cityField && city) cityField.value = city;
                    if (areaField && area) areaField.value = area;
                    if (pinField  && pin)  pinField.value  = pin;
                    if (addrField && addr) addrField.value = addr;

                    var label = (area ? area + ', ' : '') + (city || 'coordinates saved');
                    status.textContent = '✅ Location set: ' + label;
                } else {
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

        if (typeof L === 'undefined') {
            setTimeout(function() { lpOpenMap(uid); }, 200);
            return;
        }

        // If a map already exists, just refresh size and re-center on latest coords
        if (window.lpMaps[uid]) {
            var lat0 = parseFloat(document.getElementById('lp-lat-' + uid).value);
            var lng0 = parseFloat(document.getElementById('lp-lng-' + uid).value);
            window.lpMaps[uid].invalidateSize();
            if (!isNaN(lat0) && !isNaN(lng0) && window.lpMarkers[uid]) {
                window.lpMaps[uid].setView([lat0, lng0], 17);
                window.lpMarkers[uid].setLatLng([lat0, lng0]);
            }
            return;
        }

        // --- Initial position ---
        // Priority 1: existing coords in hidden fields
        // Priority 2: try to use browser GPS right now
        // Priority 3: last-resort India view (should almost never happen)
        var startLat = parseFloat(document.getElementById('lp-lat-' + uid).value);
        var startLng = parseFloat(document.getElementById('lp-lng-' + uid).value);
        var hasStart = !isNaN(startLat) && !isNaN(startLng) && startLat !== 0 && startLng !== 0;

        function initMap(lat, lng, zoom) {
            var map = L.map('lp-map-' + uid, { scrollWheelZoom: true }).setView([lat, lng], zoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap'
            }).addTo(map);

            var marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            window.lpMaps[uid]    = map;
            window.lpMarkers[uid] = marker;

            function apply(lat, lng) {
                document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
                document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);
                lpReverseGeocode(lat.toFixed(6), lng.toFixed(6), uid, true);
            }

            marker.on('dragend', function() {
                var p = marker.getLatLng();
                apply(p.lat, p.lng);
            });

            map.on('click', function(e) {
                marker.setLatLng(e.latlng);
                apply(e.latlng.lat, e.latlng.lng);
            });

            // After the wrapper became visible, Leaflet needs a size refresh
            setTimeout(function() { map.invalidateSize(); }, 200);

            // If we just got a fresh GPS fix, save it in hidden fields too
            apply(lat, lng);
        }

        if (hasStart) {
            // Case A — we have coordinates from the form (saved profile or previous pick)
            initMap(startLat, startLng, 17);
        } else if (navigator.geolocation) {
            // Case B — no coordinates yet, ask the browser for them right now
            var status = document.getElementById('lp-status-' + uid);
            status.textContent = '📡 Getting your location to center the map…';
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    var lat = parseFloat(pos.coords.latitude.toFixed(6));
                    var lng = parseFloat(pos.coords.longitude.toFixed(6));
                    document.getElementById('lp-lat-' + uid).value = lat;
                    document.getElementById('lp-lng-' + uid).value = lng;
                    initMap(lat, lng, 17);
                    status.textContent = '📍 Map centered on your location. Click or drag to adjust.';
                },
                function() {
                    // GPS denied → fall back to a wide India view with a helpful message
                    initMap(20.5937, 78.9629, 5);
                    var status = document.getElementById('lp-status-' + uid);
                    status.textContent = '⚠️ No saved location and GPS not available. Zoom in and click your location on the map.';
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        } else {
            // Case C — no GPS and no coordinates at all
            initMap(20.5937, 78.9629, 5);
            var status = document.getElementById('lp-status-' + uid);
            status.textContent = '⚠️ Zoom in and click your location on the map.';
        }
    }
    </script>
    <?php
}
