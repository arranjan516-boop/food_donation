<?php
// includes/location-picker.php
// Hybrid location picker:
//   - Google Maps for the map + Places Autocomplete (visual + search)
//   - OpenStreetMap Nominatim for reverse geocoding (free, no quota, always works)
//
// Requires: Google Maps JavaScript API + Places API enabled on the key.

if (!defined('GOOGLE_MAPS_KEY')) {
    define('GOOGLE_MAPS_KEY', 'PASTE_YOUR_DEMO_KEY_HERE');
}

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
            <input type="text" id="lp-search-<?= $uid ?>" class="form-control"
                   placeholder="🔍 Search for area, street, landmark…"
                   style="margin-bottom:8px">
            <div id="lp-map-<?= $uid ?>" style="width:100%;height:360px;border-radius:12px;background:#eef"></div>
            <p class="text-muted" style="font-size:12px;margin-top:6px">
                🖱️ Click anywhere on the map or drag the pin to fine-tune.
            </p>
        </div>

        <input type="hidden" name="latitude"  id="lp-lat-<?= $uid ?>" value="<?= $lat ?>">
        <input type="hidden" name="longitude" id="lp-lng-<?= $uid ?>" value="<?= $lng ?>">
    </div>

    <script>
    // ---- Global registries (shared across multiple pickers) ----
    window.lpMaps         = window.lpMaps         || {};
    window.lpMarkers      = window.lpMarkers      || {};
    window.lpPlaces       = window.lpPlaces       || {};
    window.lpAutocomplete = window.lpAutocomplete || {};

    // ---- Load Google Maps JS once ----
    (function() {
        if (window.lpLoaded) return;
        window.lpLoaded = true;
        window.lpReadyCallbacks = [];

        var s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=<?= GOOGLE_MAPS_KEY ?>&libraries=places&callback=lpReady';
        s.async = true;
        s.defer = true;
        document.head.appendChild(s);

        window.lpReady = function() {
            window.lpReadyCallbacks.forEach(function(cb) { cb(); });
            window.lpReadyCallbacks = [];
        };
    })();

    function lpOnReady(cb) {
        if (window.google && google.maps) cb();
        else window.lpReadyCallbacks.push(cb);
    }

    // ================================================================
    // CORE: reverse geocode using OpenStreetMap Nominatim (always works)
    // ================================================================
    function lpReverseGeocode(lat, lng, uid, forceOverwrite) {
        var status = document.getElementById('lp-status-' + uid);
        var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' +
                  encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng) +
                  '&zoom=18&addressdetails=1&accept-language=en';

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.address) {
                    if (forceOverwrite) {
                        status.textContent = '📍 Coordinates saved. Address lookup failed — please fill fields manually.';
                    }
                    return;
                }
                lpApplyNominatim(data, uid, forceOverwrite);
            })
            .catch(function() {
                if (forceOverwrite) {
                    status.textContent = '📍 Coordinates saved. Network issue — please fill fields manually.';
                }
            });
    }

    function lpApplyNominatim(data, uid, forceOverwrite) {
        var status = document.getElementById('lp-status-' + uid);
        var a = data.address;

        // ---- CITY ----
        var city = a.city || a.town || a.municipality || a.village ||
                   a.county || a.state_district || '';

        // ---- AREA (wide fallback chain for India) ----
        var area = a.suburb || a.neighbourhood || a.quarter || a.residential ||
                   a.hamlet || a.village || a.city_district || a.district || '';

        // If still empty, try parsing display_name:
        // Typical: "Road, Area, City, District, State, Pincode, Country"
        if (!area && data.display_name) {
            var parts = data.display_name.split(',').map(function(x) { return x.trim(); });
            for (var i = 1; i < Math.min(parts.length, 4); i++) {
                var candidate = parts[i];
                if (candidate && !/^\d+$/.test(candidate) && candidate !== city) {
                    area = candidate;
                    break;
                }
            }
        }

        // If AREA still empty, use road name as last resort
        if (!area && a.road) area = a.road;

        // ---- PINCODE ----
        var pin = a.postcode || '';

        // ---- FULL ADDRESS ----
        var addr = data.display_name || '';

        // If city is missing but pincode exists, try to infer city from address parts
        if (!city && parts && parts.length >= 3) {
            city = parts[2] || '';
        }

        var cityField = document.getElementById('city')    || document.querySelector('input[name="city"]');
        var areaField = document.getElementById('area')    || document.querySelector('input[name="area"]');
        var pinField  = document.getElementById('pincode') || document.querySelector('input[name="pincode"]');
        var addrField = document.getElementById('address') || document.querySelector('textarea[name="address"]');

        if (forceOverwrite) {
            if (cityField && city) cityField.value = city;
            if (areaField && area) areaField.value = area;
            if (pinField  && pin)  pinField.value  = pin;
            if (addrField && addr) addrField.value = addr;

            var label = (area ? area : '') + (city ? ', ' + city : '');
            status.textContent = '✅ Location set: ' + (label || (lat + ', ' + lng));
        } else {
            if (cityField && !cityField.value && city) cityField.value = city;
            if (areaField && !areaField.value && area) areaField.value = area;
            if (pinField  && !pinField.value  && pin)  pinField.value  = pin;
            if (addrField && !addrField.value && addr) addrField.value = addr;
            status.textContent = '✅ Marker moved';
        }
    }

    // ================================================================
    // 1. USE MY CURRENT LOCATION
    // ================================================================
    function lpUseGPS(uid) {
        var status = document.getElementById('lp-status-' + uid);
        if (!navigator.geolocation) {
            status.textContent = '❌ Geolocation not supported by this browser.';
            return;
        }

        var startedAt = Date.now();
        status.textContent = '📡 Detecting your location…';
        var tick = setInterval(function() {
            var s = Math.round((Date.now() - startedAt) / 1000);
            status.textContent = '📡 Detecting your location… (' + s + 's)';
        }, 1000);

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                clearInterval(tick);
                var lat = pos.coords.latitude.toFixed(6);
                var lng = pos.coords.longitude.toFixed(6);
                var acc = Math.round(pos.coords.accuracy || 0);

                document.getElementById('lp-lat-' + uid).value = lat;
                document.getElementById('lp-lng-' + uid).value = lng;

                status.textContent = '📍 Coordinates captured (±' + acc + 'm). Looking up address…';

                // If map is open, re-center + zoom in
                if (window.lpMaps[uid] && window.lpMarkers[uid]) {
                    var posObj = { lat: parseFloat(lat), lng: parseFloat(lng) };
                    window.lpMaps[uid].setCenter(posObj);
                    window.lpMaps[uid].setZoom(17);
                    window.lpMarkers[uid].setPosition(posObj);
                }

                lpReverseGeocode(lat, lng, uid, true);
            },
            function(err) {
                clearInterval(tick);
                var msg = '❌ Location error';
                if (err.code === 1) msg = '❌ You denied location permission. Allow it in the browser, or use the map.';
                else if (err.code === 2) msg = '❌ Location unavailable. Turn on Windows Location Services, or use the map.';
                else if (err.code === 3) msg = '❌ Location request timed out. Try again, or use the map.';
                status.textContent = msg;
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    }

    // ================================================================
    // 2. MAP PICKER (Google Map + Places Autocomplete + Nominatim)
    // ================================================================
    function lpOpenMap(uid) {
        var wrap = document.getElementById('lp-map-wrap-' + uid);
        wrap.style.display = 'block';

        lpOnReady(function() {
            // If map already exists, refresh + recenter
            if (window.lpMaps[uid]) {
                var lat0 = parseFloat(document.getElementById('lp-lat-' + uid).value);
                var lng0 = parseFloat(document.getElementById('lp-lng-' + uid).value);
                if (!isNaN(lat0) && !isNaN(lng0) && window.lpMarkers[uid]) {
                    var p = { lat: lat0, lng: lng0 };
                    window.lpMaps[uid].setCenter(p);
                    window.lpMaps[uid].setZoom(17);
                    window.lpMarkers[uid].setPosition(p);
                }
                return;
            }

            var startLat = parseFloat(document.getElementById('lp-lat-' + uid).value);
            var startLng = parseFloat(document.getElementById('lp-lng-' + uid).value);
            var hasStart = !isNaN(startLat) && !isNaN(startLng) && startLat !== 0 && startLng !== 0;

            function initMap(lat, lng, zoom) {
                var map = new google.maps.Map(document.getElementById('lp-map-' + uid), {
                    center: { lat: lat, lng: lng },
                    zoom: zoom,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true
                });

                var marker = new google.maps.Marker({
                    position: { lat: lat, lng: lng },
                    map: map,
                    draggable: true,
                    animation: google.maps.Animation.DROP
                });

                window.lpMaps[uid]    = map;
                window.lpMarkers[uid] = marker;

                function apply(lat, lng) {
                    document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
                    document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);
                    lpReverseGeocode(lat.toFixed(6), lng.toFixed(6), uid, true);
                }

                // Marker drag
                marker.addListener('dragend', function(e) {
                    apply(e.latLng.lat(), e.latLng.lng());
                });

                // Map click
                map.addListener('click', function(e) {
                    marker.setPosition(e.latLng);
                    apply(e.latLng.lat(), e.latLng.lng());
                });

                // Save coordinates immediately (so form always has them)
                document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
                document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);

                // ---- Places Autocomplete on the search box ----
                var searchBox = document.getElementById('lp-search-' + uid);
                if (searchBox && google.maps.places) {
                    var ac = new google.maps.places.Autocomplete(searchBox, {
                        componentRestrictions: { country: 'in' },
                        fields: ['geometry', 'formatted_address', 'address_components', 'name']
                    });
                    window.lpAutocomplete[uid] = ac;

                    ac.addListener('place_changed', function() {
                        var place = ac.getPlace();
                        if (!place.geometry || !place.geometry.location) return;
                        var lat = place.geometry.location.lat();
                        var lng = place.geometry.location.lng();
                        map.setCenter({ lat: lat, lng: lng });
                        map.setZoom(17);
                        marker.setPosition({ lat: lat, lng: lng });
                        document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
                        document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);

                        // Use the autocomplete result's address components directly (they're accurate)
                        if (place.address_components) {
                            lpApplyGoogleComponents(place.address_components, place.formatted_address, uid);
                        } else {
                            lpReverseGeocode(lat.toFixed(6), lng.toFixed(6), uid, true);
                        }
                    });
                }
            }

            // Determine starting position
            if (hasStart) {
                initMap(startLat, startLng, 17);
            } else if (navigator.geolocation) {
                var status = document.getElementById('lp-status-' + uid);
                status.textContent = '📡 Getting your location to center the map…';
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        var lat = parseFloat(pos.coords.latitude.toFixed(6));
                        var lng = parseFloat(pos.coords.longitude.toFixed(6));
                        document.getElementById('lp-lat-' + uid).value = lat;
                        document.getElementById('lp-lng-' + uid).value = lng;
                        initMap(lat, lng, 17);
                        status.textContent = '📍 Map centered on your location.';
                    },
                    function() {
                        initMap(13.3379, 77.1173, 14); // Tumkur fallback
                        var status = document.getElementById('lp-status-' + uid);
                        status.textContent = '⚠️ No GPS. Search above or click on the map.';
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            } else {
                initMap(13.3379, 77.1173, 14); // Tumkur fallback
            }
        });
    }

    // ================================================================
    // Apply Google's address_components (used by autocomplete)
    // ================================================================
    function lpApplyGoogleComponents(comps, formattedAddr, uid) {
        var status = document.getElementById('lp-status-' + uid);
        var city = '', area = '', pin = '';

        for (var i = 0; i < comps.length; i++) {
            var c = comps[i];
            var types = c.types;

            if (!area && types.indexOf('sublocality_level_1') !== -1) area = c.long_name;
            if (!area && types.indexOf('sublocality') !== -1)         area = c.long_name;
            if (!area && types.indexOf('neighborhood') !== -1)        area = c.long_name;

            if (!city && types.indexOf('locality') !== -1)            city = c.long_name;
            if (!city && types.indexOf('administrative_area_level_2') !== -1) city = c.long_name;

            if (!pin && types.indexOf('postal_code') !== -1)          pin = c.long_name;
        }
        if (!area && city) area = city;

        var cityField = document.getElementById('city')    || document.querySelector('input[name="city"]');
        var areaField = document.getElementById('area')    || document.querySelector('input[name="area"]');
        var pinField  = document.getElementById('pincode') || document.querySelector('input[name="pincode"]');
        var addrField = document.getElementById('address') || document.querySelector('textarea[name="address"]');

        if (cityField && city) cityField.value = city;
        if (areaField && area) areaField.value = area;
        if (pinField  && pin)  pinField.value  = pin;
        if (addrField && formattedAddr) addrField.value = formattedAddr;

        status.textContent = '✅ Location set: ' + (area ? area + ', ' : '') + (city || '');
    }
    </script>
    <?php
}
