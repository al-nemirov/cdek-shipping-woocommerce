/**
 * СДЭК Доставка: Checkout frontend
 *
 * - Watches for CDEK shipping method selection
 * - Shows "Выбрать ПВЗ" button
 * - Opens PVZ selection modal (Yandex Maps + clustered markers + list)
 * - Stores selected PVZ code in hidden field
 * - Resets selection on city change
 * - Nonce-protected AJAX
 */
(function($) {
    'use strict';

    var state = {
        points:    [],
        selected:  null,
        map:       null,
        mapReady:  false,
        city:      '',
        overlay:   null,
        lastCity:  ''
    };

    /* ─── Init ─── */
    $(document).ready(function() {
        injectPvzButton();

        $(document.body).on('updated_checkout', function() {
            injectPvzButton();
            checkCityChange();
        });
    });

    /* ─── Check city change → reset PVZ ─── */
    function checkCityChange() {
        var city = $('#billing_city').val() || '';
        if (state.lastCity && city && city !== state.lastCity) {
            state.selected = null;
            state.points = [];
            $('#cdek_pvz_code').val('');
            $('#cdek_pvz_address').val('');
            $('.cdek-pvz-selected').remove();
        }
        state.lastCity = city;
    }

    /* ─── Inject PVZ button ─── */
    function injectPvzButton() {
        var $methods = $('input.shipping_method[value*="cdek_pvz"]');
        if (!$methods.length) return;

        $methods.each(function() {
            var $input = $(this);
            var $li = $input.closest('li');

            if ($li.find('.cdek-pvz-btn').length) return;

            var $btn = $('<a href="#" class="cdek-pvz-btn">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>' +
                '<circle cx="12" cy="10" r="3"/></svg> ' +
                'ВЫБРАТЬ ПВЗ</a>');

            // Show selected PVZ if any
            var selectedCode = $('#cdek_pvz_code').val();
            if (selectedCode) {
                var addr = $('#cdek_pvz_address').val() || selectedCode;
                $li.append(
                    '<div class="cdek-pvz-selected">' +
                    '<span class="cdek-pvz-selected-label">✓ ПВЗ:</span> ' +
                    '<span class="cdek-pvz-selected-addr">' + escHtml(addr) + '</span>' +
                    '</div>'
                );
            }

            $btn.on('click', function(e) {
                e.preventDefault();
                $input.prop('checked', true).trigger('change');
                openPvzModal();
            });

            $li.append($btn);
        });
    }

    /* ─── Open PVZ Modal ─── */
    function openPvzModal() {
        var city = $('#billing_city').val() || '';
        var postcode = $('#billing_postcode').val() || '';

        if (!city && !postcode) {
            alert('Укажите город в форме заказа');
            $('#billing_city').focus();
            return;
        }

        state.city = city;
        createOverlay();
        loadPvzPoints(city, postcode);
    }

    /* ─── Create modal overlay ─── */
    function createOverlay() {
        if (state.overlay) {
            state.overlay.show();
            $('body').css('overflow', 'hidden');
            return;
        }

        var html =
            '<div id="cdek-pvz-overlay">' +
                '<div class="cdek-pvz-modal">' +
                    '<div class="cdek-pvz-header">' +
                        '<h3>Пункты выдачи СДЭК</h3>' +
                        '<input type="text" id="cdek-pvz-search" placeholder="Поиск по адресу...">' +
                        '<button class="cdek-pvz-close" type="button" aria-label="Закрыть">&times;</button>' +
                    '</div>' +
                    '<div class="cdek-pvz-content">' +
                        '<div id="cdek-pvz-map"></div>' +
                        '<div id="cdek-pvz-list">' +
                            '<div class="cdek-pvz-loading"><div class="cdek-spinner"></div>Загрузка...</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        $('body').append(html).css('overflow', 'hidden');
        state.overlay = $('#cdek-pvz-overlay');

        // Close button
        state.overlay.on('click', '.cdek-pvz-close', function() {
            closePvzModal();
        });

        // Close on overlay click
        state.overlay.on('click', function(e) {
            if (e.target === this) closePvzModal();
        });

        // ESC key
        $(document).on('keydown.cdekpvz', function(e) {
            if (e.key === 'Escape') closePvzModal();
        });

        // Search filter (debounced)
        var searchTimer;
        $('#cdek-pvz-search').on('input', function() {
            var q = $(this).val().toLowerCase();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                filterPoints(q);
            }, 200);
        });
    }

    function closePvzModal() {
        if (state.overlay) state.overlay.hide();
        $('body').css('overflow', '');
        $(document).off('keydown.cdekpvz');
    }

    /* ─── Load PVZ points via AJAX ─── */
    function loadPvzPoints(city, postcode) {
        $('#cdek-pvz-list').html('<div class="cdek-pvz-loading"><div class="cdek-spinner"></div>Загрузка пунктов выдачи...</div>');

        $.ajax({
            url: cdekShip.ajax_url,
            data: {
                action:   'cdek_ship_pvz',
                nonce:    cdekShip.nonce,
                city:     city,
                postcode: postcode
            },
            success: function(resp) {
                if (!resp.success) {
                    $('#cdek-pvz-list').html('<div class="cdek-pvz-error">' + escHtml(resp.data || 'Ошибка') + '</div>');
                    return;
                }

                state.points = resp.data.points || [];

                if (!state.points.length) {
                    $('#cdek-pvz-list').html('<div class="cdek-pvz-empty">Нет пунктов выдачи в этом городе</div>');
                    return;
                }

                renderPointsList(state.points);
                initMap(state.points);

                // Update search placeholder with count
                $('#cdek-pvz-search').attr('placeholder', 'Поиск среди ' + state.points.length + ' пунктов...');
            },
            error: function() {
                $('#cdek-pvz-list').html('<div class="cdek-pvz-error">Ошибка загрузки. Попробуйте ещё раз.</div>');
            }
        });
    }

    /* ─── Render points list ─── */
    function renderPointsList(points) {
        var $list = $('#cdek-pvz-list');
        $list.empty();

        if (!points.length) {
            $list.html('<div class="cdek-pvz-empty">Ничего не найдено</div>');
            return;
        }

        points.forEach(function(p) {
            var selectedClass = (state.selected && state.selected.code === p.code) ? ' cdek-pvz-item--active' : '';
            var features = [];
            if (p.have_cash) features.push('💵');
            if (p.have_card) features.push('💳');
            if (p.is_dressing) features.push('👔');

            var $item = $(
                '<div class="cdek-pvz-item' + selectedClass + '" data-code="' + p.code + '">' +
                    '<div class="cdek-pvz-item-name">' + escHtml(p.name || p.code) + '</div>' +
                    '<div class="cdek-pvz-item-addr">' + escHtml(p.address) + '</div>' +
                    '<div class="cdek-pvz-item-meta">' +
                        '<span class="cdek-pvz-item-time">' + escHtml(p.work_time) + '</span>' +
                        (features.length ? ' <span class="cdek-pvz-item-features">' + features.join(' ') + '</span>' : '') +
                    '</div>' +
                '</div>'
            );

            $item.on('click', function() {
                selectPoint(p);
            });

            $list.append($item);
        });
    }

    /* ─── Filter points ─── */
    function filterPoints(q) {
        if (!q) {
            renderPointsList(state.points);
            updateMapMarkers(state.points);
            return;
        }
        var filtered = state.points.filter(function(p) {
            return (p.address && p.address.toLowerCase().indexOf(q) >= 0) ||
                   (p.name && p.name.toLowerCase().indexOf(q) >= 0) ||
                   (p.code && p.code.toLowerCase().indexOf(q) >= 0);
        });
        renderPointsList(filtered);
        updateMapMarkers(filtered);
    }

    /* ─── Select a PVZ ─── */
    function selectPoint(point) {
        state.selected = point;

        $('#cdek_pvz_code').val(point.code);
        $('#cdek_pvz_address').val(point.address);

        $('.cdek-pvz-item').removeClass('cdek-pvz-item--active');
        $('.cdek-pvz-item[data-code="' + point.code + '"]').addClass('cdek-pvz-item--active');

        updateCheckoutPvzInfo(point);

        if (state.map && point.lat && point.lng) {
            state.map.setCenter([point.lat, point.lng], 16);
        }

        setTimeout(function() {
            closePvzModal();
        }, 400);

        // Trigger WC recalculation
        $(document.body).trigger('update_checkout');
    }

    /* ─── Update checkout display ─── */
    function updateCheckoutPvzInfo(point) {
        $('.cdek-pvz-selected').remove();

        var $method = $('input.shipping_method[value*="cdek_pvz"]:checked').closest('li');
        if (!$method.length) {
            $method = $('input.shipping_method[value*="cdek_pvz"]').first().closest('li');
        }

        if ($method.length) {
            $method.find('.cdek-pvz-btn').after(
                '<div class="cdek-pvz-selected">' +
                '<span class="cdek-pvz-selected-label">✓ ПВЗ:</span> ' +
                '<span class="cdek-pvz-selected-addr">' + escHtml(point.address) + '</span>' +
                '</div>'
            );
        }
    }

    /* ─── Init Yandex Map ─── */
    function initMap(points) {
        if (typeof ymaps === 'undefined') {
            var apiKey = (cdekShip && cdekShip.ymaps_api_key) || '';
            var src = 'https://api-maps.yandex.ru/2.1/?lang=ru_RU';
            if (apiKey) src += '&apikey=' + apiKey;

            var script = document.createElement('script');
            script.src = src;
            script.onload = function() {
                ymaps.ready(function() {
                    state.mapReady = true;
                    buildMap(points);
                });
            };
            document.head.appendChild(script);
        } else if (state.mapReady) {
            buildMap(points);
        } else {
            ymaps.ready(function() {
                state.mapReady = true;
                buildMap(points);
            });
        }
    }

    var currentClusterer = null;

    function buildMap(points) {
        var container = document.getElementById('cdek-pvz-map');
        if (!container) return;

        if (state.map) {
            state.map.destroy();
            state.map = null;
        }

        var center = [55.751244, 37.618423];
        if (points.length && points[0].lat) {
            center = [points[0].lat, points[0].lng];
        }

        state.map = new ymaps.Map('cdek-pvz-map', {
            center: center,
            zoom: 11,
            controls: ['zoomControl', 'geolocationControl']
        });

        addMarkers(points);
    }

    function addMarkers(points) {
        if (!state.map) return;

        if (currentClusterer) {
            state.map.geoObjects.remove(currentClusterer);
        }

        var clusterer = new ymaps.Clusterer({
            preset: 'islands#redClusterIcons',
            groupByCoordinates: false,
            clusterDisableClickZoom: false
        });

        var placemarks = [];

        points.forEach(function(p) {
            if (!p.lat || !p.lng) return;

            var placemark = new ymaps.Placemark([p.lat, p.lng], {
                balloonContentHeader: '<b>' + escHtml(p.name || p.code) + '</b>',
                balloonContentBody:
                    '<div style="font-size:13px;max-width:250px">' +
                    escHtml(p.address) + '<br>' +
                    '<span style="color:#666">' + escHtml(p.work_time) + '</span><br>' +
                    '<a href="#" onclick="window.cdekSelectPvz(\'' + p.code + '\');return false;" ' +
                    'style="color:#e53935;font-weight:bold;margin-top:6px;display:inline-block;font-size:14px">' +
                    '→ Выбрать</a>' +
                    '</div>',
                hintContent: p.address
            }, {
                preset: 'islands#redDotIcon'
            });

            placemark.events.add('click', function() {
                highlightListItem(p.code);
            });

            placemarks.push(placemark);
        });

        clusterer.add(placemarks);
        state.map.geoObjects.add(clusterer);
        currentClusterer = clusterer;

        if (placemarks.length > 1) {
            state.map.setBounds(clusterer.getBounds(), {
                checkZoomRange: true,
                zoomMargin: 40
            });
        }
    }

    function updateMapMarkers(points) {
        if (!state.map || !state.mapReady) return;
        addMarkers(points);
    }

    function highlightListItem(code) {
        var $item = $('.cdek-pvz-item[data-code="' + code + '"]');
        if ($item.length) {
            var $list = $('#cdek-pvz-list');
            $list.scrollTop(0);
            var pos = $item.position().top + $list.scrollTop();
            $list.animate({ scrollTop: pos - 10 }, 200);
            $item.addClass('cdek-pvz-item--highlight');
            setTimeout(function() { $item.removeClass('cdek-pvz-item--highlight'); }, 1500);
        }
    }

    // Global function for balloon link
    window.cdekSelectPvz = function(code) {
        var point = state.points.find(function(p) { return p.code === code; });
        if (point) selectPoint(point);
    };

    /* ─── Helpers ─── */
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})(jQuery);
