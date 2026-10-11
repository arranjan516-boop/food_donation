<?php
// includes/location-picker.php
// Google Maps location picker using PLACES API ONLY (no Geocoding API needed).
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
    window.gmPlaces  = window.gmPlaces  || {};
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
            window.gmReadyCallbacks.forEach(function(cb) { cb(); });
            window.gmReadyCallbacks = [];
        };
    })();

    function gmOnReady(cb) {
        if (window.google && google.maps && google.maps.places) cb();
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

                status.textContent = '📍 Coordinates captured (±' + acc + 'm). Looking up area…';

                if (window.gmMaps[uid] && window.gmMarkers[uid]) {
                    var posObj = { lat: parseFloat(lat), lng: parseFloat(lng) };
                    window.gmMaps[uid].setCenter(posObj);
                    window.gmMaps[uid].setZoom(17);
                    window.gmMarkers[uid].setPosition(posObj);
                }

                gmLookupAddress(lat, lng, uid, true);
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

    // ============================================================
    // 2. ADDRESS LOOKUP — PLACES API (no Geocoding API needed)
    // ============================================================
    function gmLookupAddress(lat, lng, uid, forceOverwrite) {
        gmOnReady(function() {
            var status = document.getElementById('lp-status-' + uid);

            // Places service needs a map or a div
            var mapEl = document.getElementById('lp-map-' + uid);
            if (!mapEl) {
                status.textContent = '📍 Coordinates captured. Open the map to get the address.';
                return;
            }

            // Create a hidden map if one doesn't exist yet (Places service needs it)
            if (!window.gmMaps[uid]) {
                window.gmMaps[uid] = new google.maps.Map(mapEl, {
                    center: { lat: parseFloat(lat), lng: parseFloat(lng) },
                    zoom: 16,
                    disableDefaultUI: true
                });
            }

            if (!window.gmPlaces[uid]) {
                window.gmPlaces[uid] = new google.maps.places.PlacesService(window.gmMaps[uid]);
            }

            var service = window.gmPlaces[uid];

            // --- Strategy 1: nearbySearch around the coordinates ---
            service.nearbySearch({
                location: { lat: parseFloat(lat), lng: parseFloat(lng) },
                radius: 100
            }, function(results, statusCode) {
                if (statusCode === google.maps.places.PlacesServiceStatus.OK && results && results.length > 0) {
                    // Try to enrich with full place details for the closest result
                    service.getDetails({
                        placeId: results[0].place_id,
                        fields: ['address_components', 'formatted_address', 'name', 'vicinity']
                    }, function(place, detailStatus) {
                        if (detailStatus === google.maps.places.PlacesServiceStatus.OK && place && place.address_components) {
                            applyAddressComponents(place.address_components, place.formatted_address, uid, forceOverwrite);
                        } else {
                            // Fallback to basic info from nearbySearch
                            fallbackFromNearby(results, uid, forceOverwrite);
                        }
                    });
                } else {
                    // --- Strategy 2: no nearby place found — ask user to type manually ---
                    fallbackFromNearby(null, uid, forceOverwrite);
                }
            });
        });
    }

    function applyAddressComponents(comps, formattedAddr, uid, forceOverwrite) {
        var status = document.getElementById('lp-status-' + uid);
        var city = '', area = '', pin = '';

        // Same priority mapping as Google Geocoding
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
            if (addrField && formattedAddr) addrField.value = formattedAddr;
            status.textContent = '✅ Location set: ' + (area ? area + ', ' : '') + (city || '');
        } else {
            if (cityField && !cityField.value && city) cityField.value = city;
            if (areaField && !areaField.value && area) areaField.value = area;
            if (pinField  && !pinField.value  && pin)  pinField.value  = pin;
            if (addrField && !addrField.value && formattedAddr) addrField.value = formattedAddr;
            status.textContent = '✅ Marker moved';
        }
    }

    function fallbackFromNearby(results, uid, forceOverwrite) {
        var status = document.getElementById('lp-status-' + uid);
        var cityField = document.getElementById('city') || document.querySelector('input[name="city"]');
        var areaField = document.getElementById('area') || document.querySelector('input[name="area"]');
        var addrField = document.getElementById('address') || document.querySelector('textarea[name="address"]');

        if (results && results.length > 0) {
            var place = results[0];
            var guessArea = place.name || place.vicinity || '';
            var guessCity = '';

            // Try to extract city from vicinity string like "Street, Area, City"
            if (place.vicinity) {
                var parts = place.vicinity.split(',').map(function(s) { return s.trim(); });
                if (parts.length >= 2) guessCity = parts[parts.length - 1];
            }

            if (forceOverwrite) {
                if (areaField && guessArea) areaField.value = guessArea;
                if (cityField && guessCity && !cityField.value) cityField.value = guessCity;
                if (addrField && place.vicinity && !addrField.value) addrField.value = place.vicinity;
            }
            status.textContent = '⚠️ Nearby: "' + guessArea + '". Please verify the city and pincode fields.';
        } else {
            status.textContent = '📍 Coordinates captured. No landmarks nearby — please type area, city and pincode.';
        }
    }

    // ============================================================
    // 3. MAP PICKER
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
                window.gmPlaces[uid]  = new google.maps.places.PlacesService(map);

                function apply(lat, lng) {
                    document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
                    document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);
                    gmLookupAddress(lat.toFixed(6), lng.toFixed(6), uid, true);
                }

                marker.addListener('dragend', function(e) {
                    apply(e.latLng.lat(), e.latLng.lng());
                });

                map.addListener('click', function(e) {
                    marker.setPosition(e.latLng);
                    apply(e.latLng.lat(), e.latLng.lng());
                });

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
                        document.getElementById('lp-lat-' + uid).value = lat.toFixed(6);
                        document.getElementById('lp-lng-' + uid).value = lng.toFixed(6);

                        // If the autocomplete returned address_components, use them directly
                        if (place.address_components) {
                            applyAddressComponents(place.address_components, place.formatted_address, uid, true);
                        } else {
                            gmLookupAddress(lat.toFixed(6), lng.toFixed(6), uid, true);
                        }
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
                        initGoogleMap(13.3379, 77.1173, 14); // Tumkur fallback
                        var status = document.getElementById('lp-status-' + uid);
                        status.textContent = '⚠️ No GPS. Search above or click on the map.';
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            } else {
                initGoogleMap(13.3379, 77.1173, 14); // Tumkur fallback
            }
        });
    }
    </script>
    <?php
}
