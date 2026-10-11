<?php
// includes/location-picker.php
// Google Maps–powered location picker (Demo Key — no card, no billing).
// Requires: Google Maps JavaScript API + Geocoding API + Places API enabled on the key.
//
// ⚠️ PUT YOUR GOOGLE MAPS DEMO KEY BELOW ⚠️
if (!defined('GOOGLE_MAPS_KEY')) {
    define('GOOGLE_MAPS_KEY', 'AIzaSyAFG9DP2R9rNYiwzI5zcJJy1kPekHAz5nY');
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
            <button type="button" class="btn btn-outline btn-sm" onclick="gmUseGPS('<?= $uid ?>')">
                📍 Use my current location
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="gmOpenMap('<?= $uid ?>')">
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
    window.gmMaps    = window.gmMaps    || {};
    window.gmMarkers = window.gmMarkers || {};
    window.gmGeocoder = window.gmGeocoder || null;
    window.gmAutocomplete = window.gmAutocomplete || {};

    // ---- Load Google Maps JS once ----
    (function() {
        if (window.gmLoaded) return;
        window.gmLoaded = true;
        window.gmReadyCallbacks = [];

        var s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=<?= GOOGLE_MAPS_KEY ?>&libraries=places&callback=gmReady';
        s.async = true;
        s.defer = true;
        document.head.appendChild(s);

        window.gmReady = function() {
            window.gmGeocoder = new google.maps.Geocoder();
            window.gmReadyCallbacks.forEach(function(cb) { cb(); });
            window.gmReadyCallbacks = [];
        };
    })();

    function gmOnReady(cb) {
        if (window.gmGeocoder) cb();
        else window.gmReadyCallbacks.push(cb);
    }

    // ============================================================
    // 1. USE MY CURRENT LOCATION
    // ============================================================
    function gmUseGPS(uid) {
        var status = document.getElementById('lp-status-' + uid);
        if (!navigator.geolocation) {
            status.textContent = '❌ Geolocation not supported by this browser.';
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

                status.textContent = '📍 Coordinates captured (±' + acc + 'm). Looking up address…';

                if (window.gmMaps[uid] && window.gmMarkers[uid]) {
                    var posObj = { lat: parseFloat(lat), lng: parseFloat(lng) };
                    window.gmMaps[uid].setCenter(posObj);
                    window.gmMaps[uid].setZoom(17);
                    window.gmMarkers[uid].setPosition(posObj);
                }

                gmReverseGeocode(lat, lng, uid, true);
            },
            function(err) {
                var msg = '❌ Location error';
                if (err.code === 1) msg = '❌ You denied location permission. Please allow or pick manually on the map.';
                else if (err.code === 2) msg = '❌ Location unavailable. Try again or pick manually.';
                else if (err.code === 3) msg = '❌ Location request timed out. Try again or pick manually.';
                status.textContent = msg;
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    }

    // ============================================================
    // 2. REVERSE GEOCODE via Google Geocoding API
    // ============================================================
    function gmReverseGeocode(lat, lng, uid, forceOverwrite) {
        gmOnReady(function() {
            var status = document.getElementById('lp-status-' + uid);
            var latlng = { lat: parseFloat(lat), lng: parseFloat(lng) };

            window.gmGeocoder.geocode({ location: latlng }, function(results, statusCode) {
                if (statusCode !== 'OK' || !results || !results[0]) {
                    status.textContent = '📍 Coordinates captured. Address lookup failed — please fill fields manually.';
                    return;
                }

                var best = results[0];
                var comps = best.address_components;

                var city = '';
                var area = '';
                var pin  = '';
                var addr = best.formatted_address || '';

                // Mapping (India-focused):
                //   area  ← sublocality_level_1 (colony/area) → sublocality → neighborhood
                //   city  ← locality (city/town) → administrative_area_level_2 (district)
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

                if (forceOverwrite) {
                    if (cityField && city) cityField.value = city;
                    if (areaField && area) areaField.value = area;
                    if (pinField  && pin)  pinField.value  = pin;
                    if (addrField && addr) addrField.value = addr;
                    status.textContent = '✅ Location set: ' + (area ? area + ', ' : '') + (city || lat + ', ' + lng);
                } else {
                    if (cityField && !cityField.value && city) cityField.value = city;
                    if (areaField && !areaField.value && area) areaField.value = area;
                    if (pinField  && !pinField.value  && pin)  pinField.value  = pin;
                    if (addrField && !addrField.value && addr) addrField.value = addr;
                    status.textContent = '✅ Marker moved';
                }
            });
        });
    }

    // ============================================================
    // 3. MAP PICKER (Google Map + Places Autocomplete)
    // ============================================================
    function gmOpenMap(uid) {
        var wrap = document.getElementById('lp-map-wrap-' + uid);
        wrap.style.display = 'block';

        gmOnReady(function() {
            if (window.gmMaps[uid]) {
                var lat0 = parseFloat(document.getElementById('lp-lat-' + uid).value);
                var lng0 = parseFloat(document.getElementById('lp-lng-' + uid).value);
                if (!isNaN(lat0) && !isNaN(lng0) && window.gmMarkers[uid]) {
                    var p = { lat: lat0, lng: lng0 };
                    window.gmMaps[uid].setCenter(p);
                    window.gmMaps[uid].setZoom(17);
                    window.gmMarkers[uid].setPosition(p);
                }
                return;
            }

            var startLat = parseFloat(document.getElementById('lp-lat-' + uid).value);
            var startLng = parseFloat(document.getElementById('lp-lng-' + uid).value);
            var hasStart = !isNaN(startLat) && !isNaN(startLng) && startLat !== 0 && startLng !== 0;

            function initGoogleMap(lat, lng, zoom) {
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

                window.gmMaps[uid]    = map;
                window.gmMarkers[uid] = marker;

                function apply(lat, lng) {
                    document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
                    document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);
                    gmReverseGeocode(lat.toFixed(6), lng.toFixed(6), uid, true);
                }

                marker.addListener('dragend', function(e) {
                    apply(e.latLng.lat(), e.latLng.lng());
                });

                map.addListener('click', function(e) {
                    marker.setPosition(e.latLng);
                    apply(e.latLng.lat(), e.latLng.lng());
                });

                apply(lat, lng);

                // Places autocomplete on the search box
                var searchBox = document.getElementById('lp-search-' + uid);
                if (searchBox && google.maps.places) {
                    var ac = new google.maps.places.Autocomplete(searchBox, {
                        componentRestrictions: { country: 'in' },
                        fields: ['geometry', 'formatted_address', 'address_components']
                    });
                    window.gmAutocomplete[uid] = ac;

                    ac.addListener('place_changed', function() {
                        var place = ac.getPlace();
                        if (!place.geometry || !place.geometry.location) return;
                        var lat = place.geometry.location.lat();
                        var lng = place.geometry.location.lng();
                        map.setCenter({ lat: lat, lng: lng });
                        map.setZoom(17);
                        marker.setPosition({ lat: lat, lng: lng });
                        apply(lat, lng);
                    });
                }
            }

            if (hasStart) {
                initGoogleMap(startLat, startLng, 17);
            } else if (navigator.geolocation) {
                var status = document.getElementById('lp-status-' + uid);
                status.textContent = '📡 Getting your location to center the map…';
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        var lat = parseFloat(pos.coords.latitude.toFixed(6));
                        var lng = parseFloat(pos.coords.longitude.toFixed(6));
                        document.getElementById('lp-lat-' + uid).value = lat;
                        document.getElementById('lp-lng-' + uid).value = lng;
                        initGoogleMap(lat, lng, 17);
                        status.textContent = '📍 Map centered on your location.';
                    },
                    function() {
                        initGoogleMap(12.9716, 77.5946, 12); // Bengaluru fallback
                        var status = document.getElementById('lp-status-' + uid);
                        status.textContent = '⚠️ No GPS. Search above or click on the map.';
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            } else {
                initGoogleMap(12.9716, 77.5946, 12); // Bengaluru fallback
            }
        });
    }
    </script>
    <?php
}
