jQuery(function ($) {
  const { __ } = wp.i18n;
  const config = window.BIJAK || {};
  let CITY_CACHE = null;
  let loadingCities = false;
  let updatingCheckout = false;
  let debounce;
  let pickerState = null;
  let pickerIframe = null;
  let pickerLoadTimer = null;
  let citySyncTimer = null;
  let syncingExternalCity = false;
  let externalCityKey = '';
  let manualCityOverrideKey = '';
  let promptedCityKey = '';
  let savedCityId = typeof window.__bijak_saved_city === 'string'
    ? window.__bijak_saved_city
    : String(config.saved_city_id || '');

  function activeBox(preferred) {
    if (preferred && preferred.length) return preferred;
    const $visible = $('.bijak-box:visible');
    return $visible.length ? $visible.last() : $('.bijak-box').last();
  }

  function boxValues(preferred) {
    const $box = activeBox(preferred);
    return {
      $box: $box,
      cityId: $box.find('[name="bijak_dest_city"]').val() || '',
      isDoor: $box.find('[name="bijak_is_door_delivery"][type="checkbox"]').is(':checked'),
    };
  }

  function isBijakChosen() {
    let chosen = false;
    $('input[name^="shipping_method"], select[name^="shipping_method"]').each(function () {
      const $el = $(this);
      if (($el.attr('type') || '').toLowerCase() === 'radio' && !$el.is(':checked')) return;
      const value = $el.val();
      if (typeof value === 'string' && value.indexOf('bijak_pay_at_dest') === 0) {
        chosen = true;
        return false;
      }
    });
    return chosen;
  }

  function reinit($select) {
    if ($.fn.selectWoo) {
      if ($select.hasClass('select2-hidden-accessible')) $select.selectWoo('destroy');
      $select.selectWoo();
      $(document.body).trigger('wc-enhanced-select-init', $select);
    }
  }

  function fetchCities(originId) {
    return $.post(config.ajax_url, {
      action: 'bijak_get_destinations', nonce: config.nonce, origin_city_id: originId, _: Date.now()
    }).then(function (res) {
      const data = res && res.success && res.data && Array.isArray(res.data.data) ? res.data.data : [];
      return data.map(function (city) {
        const cityName = String(city.city_name || '').trim();
        const provinceName = String(city.city_province_name || '').trim();
        const lat = city.city_lat === null || city.city_lat === '' ? NaN : Number(city.city_lat);
        const lng = city.city_lng === null || city.city_lng === '' ? NaN : Number(city.city_lng);
        return {
          id: String(city.city_id),
          text: provinceName ? provinceName + ' ' + cityName : cityName,
          cityName: cityName,
          provinceName: provinceName,
          lat: Number.isFinite(lat) && lat >= -90 && lat <= 90 ? lat : null,
          lng: Number.isFinite(lng) && lng >= -180 && lng <= 180 ? lng : null,
        };
      });
    });
  }

  function normalizeCityValue(value) {
    return String(value || '')
      .replace(/[يىئ]/g, 'ی')
      .replace(/ك/g, 'ک')
      .replace(/[أإٱ]/g, 'ا')
      .replace(/ؤ/g, 'و')
      .replace(/[ةۀ]/g, 'ه')
      .replace(/ء/g, '')
      .replace(/[\u200c\u200f\u200e]/g, ' ')
      .replace(/[ـ]/g, '')
      .replace(/[،,._/\\-]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .toLocaleLowerCase();
  }

  function normalizeComparableCity(value) {
    return normalizeCityValue(value).replace(/\s/g, '');
  }

  function normalizeProvinceValue(value) {
    return normalizeCityValue(value).replace(/^استان\s+/, '');
  }

  function similarityScore(left, right) {
    if (left === right) return 1;
    if (!left || !right) return 0;

    const previous = Array.from({ length: right.length + 1 }, function (_, index) { return index; });
    for (let leftIndex = 1; leftIndex <= left.length; leftIndex += 1) {
      let diagonal = previous[0];
      previous[0] = leftIndex;
      for (let rightIndex = 1; rightIndex <= right.length; rightIndex += 1) {
        const above = previous[rightIndex];
        previous[rightIndex] = Math.min(
          previous[rightIndex] + 1,
          previous[rightIndex - 1] + 1,
          diagonal + (left[leftIndex - 1] === right[rightIndex - 1] ? 0 : 1)
        );
        diagonal = above;
      }
    }

    return 1 - (previous[right.length] / Math.max(left.length, right.length));
  }

  function findDestinationCityOption(options, address) {
    const city = normalizeComparableCity(address.city);
    const province = normalizeProvinceValue(address.province);
    const candidates = options.map(function (option) {
      return {
        option: option,
        city: normalizeComparableCity($(option).attr('data-city-name') || $(option).text()),
        province: normalizeProvinceValue($(option).attr('data-province-name')),
      };
    });

    // A populated checkout province is mandatory: never select an identically
    // named city from a different province.
    const provinceCandidates = province
      ? candidates.filter(function (candidate) {
        return candidate.province === province || similarityScore(candidate.province, province) >= 0.9;
      })
      : candidates;

    const exact = provinceCandidates.filter(function (candidate) {
      return candidate.city === city;
    });
    if (exact.length === 1) return exact[0].option;
    if (exact.length > 1 || city.length < 3) return null;

    const fuzzy = provinceCandidates
      .map(function (candidate) {
        return { option: candidate.option, score: similarityScore(candidate.city, city) };
      })
      .filter(function (candidate) { return candidate.score >= 0.8; })
      .sort(function (left, right) { return right.score - left.score; });

    if (!fuzzy.length) return null;
    if (fuzzy.length > 1 && fuzzy[0].score - fuzzy[1].score < 0.05) return null;
    return fuzzy[0].option;
  }

  function readAddressField(type, field) {
    const $field = $('#' + type + '_' + field);
    if (!$field.length) return '';
    const value = $field.val();
    if (value === undefined || value === null || String(value) === '-1') return '';
    if (field === 'city' && $field.is('select')) {
      const text = $field.find('option:selected').text();
      return String(text || value).trim();
    }
    if (field === 'state' && $field.is('select')) {
      const text = $field.find('option:selected').text();
      return String(text || value).trim();
    }
    return String(value).trim();
  }

  function readCheckoutAddress() {
    const $shipToggle = $('#ship-to-different-address-checkbox');
    const shippingEnabled = !$shipToggle.length || $shipToggle.is(':checked');
    const shippingCity = shippingEnabled ? readAddressField('shipping', 'city') : '';
    const shippingProvince = shippingEnabled ? readAddressField('shipping', 'state') : '';
    const billingCity = readAddressField('billing', 'city');
    const billingProvince = readAddressField('billing', 'state');
    const city = shippingCity || billingCity;
    const province = shippingCity ? shippingProvince : billingProvince;
    return {
      city: city,
      province: province,
      key: normalizeCityValue(city) + '|' + normalizeCityValue(province),
    };
  }

  function showCityMismatch($select, address, shouldPrompt) {
    const key = address.key;
    const $notice = $select.closest('.bijak-box').find('.bijak-destination-city-notice');
    $notice.text(__('The selected checkout city is not available for Bijak. Please choose a Bijak destination city.', 'bijak')).show();
    $select.attr('aria-invalid', 'true');
    if (!shouldPrompt || promptedCityKey === key) return;
    promptedCityKey = key;
    if ($.fn.selectWoo && $select.hasClass('select2-hidden-accessible')) {
      $select.selectWoo('open');
      setTimeout(function () {
        // The city is already known to be unavailable; search only the province
        // so the user can choose a valid Bijak city from that province.
        const searchText = address.province || '';
        const $search = $('.select2-container--open .select2-search__field').first();
        if (!$search.length || !searchText) return;
        $search.val(searchText).trigger('input').trigger('keyup');
      }, 0);
    } else {
      $select.trigger('focus');
    }
  }

  function hideCityMismatch($select) {
    $select.closest('.bijak-box').find('.bijak-destination-city-notice').hide().empty();
    $select.removeAttr('aria-invalid');
  }

  function syncDestinationCity($select, shouldPrompt) {
    if (!$select.length || !$select.children('option[value!=""]').length || !isBijakChosen()) return;

    const address = readCheckoutAddress();
    if (!address.city) {
      externalCityKey = '';
      manualCityOverrideKey = '';
      promptedCityKey = '';
      hideCityMismatch($select);
      return;
    }

    if (externalCityKey !== address.key) {
      externalCityKey = address.key;
      manualCityOverrideKey = '';
      promptedCityKey = '';
    }

    if (manualCityOverrideKey === address.key && $select.val()) {
      hideCityMismatch($select);
      return;
    }

    const options = $select.children('option[value!=""]').toArray();
    const match = findDestinationCityOption(options, address);

    if (!match) {
      if ($select.val()) {
        syncingExternalCity = true;
        $select.val('').trigger('change');
        syncingExternalCity = false;
      }
      showCityMismatch($select, address, shouldPrompt);
      return;
    }

    const matchedId = String($(match).val());
    const cityChanged = String($select.val() || '') !== matchedId;
    if (cityChanged) {
      syncingExternalCity = true;
      $select.val(matchedId).trigger('change');
      syncingExternalCity = false;
    }
    hideCityMismatch($select);
    // Programmatic changes skip the normal city-change handler while syncing
    // checkout fields, so explicitly refresh the Bijak estimate here.
    if (cityChanged && isBijakChosen()) bijakPriceEstimate(true, $select.closest('.bijak-box'));
  }

  function populateCities($select, options) {
    if (!$select.length) return;
    const saved = savedCityId;
    $select.empty().append('<option value=""></option>');
    options.forEach(function (option) {
      const $option = $('<option>', { value: option.id, text: option.text })
        .attr('data-city-name', option.cityName || '')
        .attr('data-province-name', option.provinceName || '');
      if (option.lat !== null && option.lng !== null) {
        $option.attr('data-city-lat', String(option.lat)).attr('data-city-lng', String(option.lng));
      }
      $option.appendTo($select);
    });
    if (saved) $select.val(saved);
    reinit($select);
    syncDestinationCity($select, true);
  }

  function ensureCities(force) {
    const originId = parseInt(config.origin_city_id, 10) || 0;
    const $select = activeBox().find('[name="bijak_dest_city"]');
    if (!$select.length || !originId || loadingCities) return;
    if (!force && $select.children('option[value!=""]').length) return;
    loadingCities = true;
    $select.prop('disabled', true);
    if (!force && CITY_CACHE && CITY_CACHE.originId === originId && CITY_CACHE.options.length) {
      populateCities($select, CITY_CACHE.options);
      loadingCities = false;
      $select.prop('disabled', false);
      return;
    }
    fetchCities(originId).then(function (options) {
      CITY_CACHE = { originId: originId, options: options };
      populateCities($select, options);
    }).always(function () {
      loadingCities = false;
      $select.prop('disabled', false);
    });
  }

  function ensureModal() {
    let $modal = $('#bijak-location-modal');
    if ($modal.length) return $modal;
    $modal = $('<div>', { id: 'bijak-location-modal', class: 'bijak-location-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'bijak-location-modal-title', 'aria-hidden': 'true' });
    $modal.html('<div class="bijak-location-modal__panel"><div class="bijak-location-modal__header"><h2 id="bijak-location-modal-title"></h2><button type="button" class="bijak-location-modal__close" aria-label=""></button></div><div class="bijak-location-modal__body"><p class="bijak-location-modal__loading"></p><p class="bijak-location-modal__error" role="alert"></p><iframe title="" sandbox="allow-scripts allow-forms allow-same-origin" allow="geolocation" referrerpolicy="strict-origin-when-cross-origin"></iframe></div></div>');
    $modal.find('#bijak-location-modal-title').text(__('Select delivery location', 'bijak'));
    $modal.find('.bijak-location-modal__close').html('&times;').attr('aria-label', __('Close', 'bijak'));
    $modal.find('.bijak-location-modal__loading').text(__('Loading location picker...', 'bijak'));
    $modal.find('iframe').attr('title', __('Delivery location picker', 'bijak'));
    $('body').append($modal);
    pickerIframe = $modal.find('iframe')[0];
    $modal.on('click', '.bijak-location-modal__close', closeModal);
    $modal.on('click', function (event) { if (event.target === $modal[0]) closeModal(); });
    // The iframe load event does not bubble, so bind it directly.
    $modal.find('iframe').on('load', function () {
      clearTimeout(pickerLoadTimer);
      $modal.removeClass('is-loading');
      $modal.find('.bijak-location-modal__loading').hide();
    });
    return $modal;
  }

  function showModalError(message) {
    const $modal = ensureModal();
    $modal.addClass('is-open').attr('aria-hidden', 'false');
    $modal.find('.bijak-location-modal__loading').hide();
    $modal.find('.bijak-location-modal__error').text(message).show();
  }

  function openPicker(event) {
    const values = boxValues($(event.currentTarget).closest('.bijak-box'));
    const cityId = parseInt(values.cityId, 10) || 0;
    if (!cityId) { showModalError(__('Please select a destination city first.', 'bijak')); return; }
    if (!config.map_picker_url || !config.map_picker_origin) { showModalError(__('The location picker is not configured.', 'bijak')); return; }
    const $modal = ensureModal();
    const $selectedCity = values.$box.find('[name="bijak_dest_city"] option:selected');
    const cityName = String($selectedCity.attr('data-city-name') || '').trim();
    const provinceName = String($selectedCity.attr('data-province-name') || '').trim();
    const cityLat = $selectedCity.attr('data-city-lat');
    const cityLng = $selectedCity.attr('data-city-lng');
    $modal.addClass('is-open is-loading').attr('aria-hidden', 'false');
    $modal.find('.bijak-location-modal__loading').show().text(__('Creating a secure picker session...', 'bijak'));
    $modal.find('.bijak-location-modal__error').hide().empty();
    $.post(config.ajax_url, {
      action: 'bijak_create_location_picker_session',
      nonce: config.nonce,
      destination_city_id: cityId,
      destination_city_name: cityName,
      destination_province_name: provinceName,
      initial_lat: cityLat || '',
      initial_lng: cityLng || ''
    })
      .done(function (res) {
        if (!res || !res.success || !res.data || !res.data.url || !res.data.state) { showModalError((res && res.data && res.data.message) || __('Unable to open location picker.', 'bijak')); return; }
        pickerState = String(res.data.state);
        $modal.attr('data-picker-state', pickerState);
        pickerIframe.src = String(res.data.url);
        $modal.find('.bijak-location-modal__loading').text(__('Loading location picker...', 'bijak'));
        clearTimeout(pickerLoadTimer);
        pickerLoadTimer = setTimeout(function () {
          if (pickerState && $modal.hasClass('is-loading')) showModalError(__('The location picker could not be loaded.', 'bijak'));
        }, 15000);
      })
      .fail(function (xhr) { showModalError((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || __('Unable to open location picker.', 'bijak')); });
  }

  function closeModal() {
    const $modal = $('#bijak-location-modal');
    pickerState = null;
    clearTimeout(pickerLoadTimer);
    if (pickerIframe) pickerIframe.src = 'about:blank';
    $modal.removeClass('is-open is-loading').attr('aria-hidden', 'true');
    $modal.find('.bijak-location-modal__error').hide().empty();
  }

  function setLocationStatus(selected, address) {
    $('.bijak-location-picker').each(function () {
      const $picker = $(this);
      $picker.attr('data-location-selected', selected ? '1' : '0');
      const $status = $picker.find('.bijak-location-picker__status');
      if (selected) {
        $status.empty().append($('<strong>').text(__('Location selected', 'bijak')));
        if (address) $status.append(document.createTextNode(': ' + address));
      } else {
        $status.text(__('Location not selected', 'bijak'));
      }
    });
  }

  function clearLocation(clearDestination, destinationCityId) {
    return $.post(config.ajax_url, {
      action: 'bijak_clear_destination_location',
      nonce: config.nonce,
      clear_destination: clearDestination ? 1 : 0,
      destination_city_id: destinationCityId || ''
    })
      .always(function () { setLocationStatus(false, ''); });
  }

  function bijakPriceEstimate(triggerUpdate, preferredBox) {
    const values = boxValues(preferredBox);
    const $out = values.$box.find('#bijak_estimate_result')
      .removeClass('is-error is-ready')
      .addClass('is-loading')
      .text(__('Calculating...', 'bijak'));
    return $.post(config.ajax_url, {
      action: 'bijak_price_estimate', nonce: config.nonce,
      dest_city_id: values.cityId,
      is_door_delivery: values.isDoor ? 1 : 0
    }).done(function (res) {
      if (!res || !res.success) {
        $out.removeClass('is-loading is-ready').addClass('is-error')
          .text((res && res.data && res.data.message) || __('Failed to estimate price', 'bijak'));
        return;
      }
      const data = res.data.data || {};
      let html = '<ul>';
      (data.items || []).forEach(function (item) {
        html += '<li>' + $('<div>').text(item.text || '').html() + ' : ' + Number(item.value || 0).toLocaleString('fa-IR') + ' ' + __('Toman', 'bijak') + '</li>';
      });
      html += '</ul><strong>' + __('Total', 'bijak') + ': ' + Number(data.sum || 0).toLocaleString('fa-IR') + ' ' + __('Toman', 'bijak') + '</strong>';
      $out.removeClass('is-loading is-error').addClass('is-ready').html(html);
      if (triggerUpdate) {
        updatingCheckout = true;
        $(document.body).trigger('update_checkout');
        setTimeout(function () { updatingCheckout = false; }, 800);
      }
    }).fail(function (xhr) {
      $out.removeClass('is-loading is-ready').addClass('is-error')
        .text((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || __('Failed to estimate price', 'bijak'));
    });
  }

  function showBox() {
    const chosen = isBijakChosen();
    $('.bijak-box').first().toggle(chosen);
    $('.bijak-box').slice(1).remove();
    if (chosen) {
      ensureCities(false);
      const values = boxValues();
      values.$box.find('.bijak-location-picker').toggle(values.isDoor);
      syncDestinationCity(values.$box.find('[name="bijak_dest_city"]'), true);
    } else {
      $('#bijak_estimate_result').removeClass('is-loading is-error is-ready').empty();
    }
  }

  // Delegated handlers survive WooCommerce checkout fragment replacement.
  $(document).on('change', 'input[name^="shipping_method"], select[name^="shipping_method"]', function () { setTimeout(showBox, 50); });
  $(document).on('click', '.bijak-location-picker__open', openPicker);
  $(document.body).on('updated_checkout updated_wc_div updated_shipping_method wc_fragments_loaded wc_fragments_refreshed', function () { if (!updatingCheckout) setTimeout(showBox, 100); });
  $(document).on('change', '#bijak_dest_city', function () {
    const $box = $(this).closest('.bijak-box');
    const city = $(this).val() || '';
    const oldCity = savedCityId;
    savedCityId = city;
    window.__bijak_saved_city = city;
    if (city !== oldCity) clearLocation(!city, city);
    if (!syncingExternalCity && city) {
      const address = readCheckoutAddress();
      if (address.key) manualCityOverrideKey = address.key;
      hideCityMismatch($(this));
    }
    if (!syncingExternalCity && isBijakChosen() && city) bijakPriceEstimate(true, $box);
    showBox();
  });
  $(document).on('change', '#billing_city, #billing_state, #shipping_city, #shipping_state, #ship-to-different-address-checkbox', function () {
    clearTimeout(citySyncTimer);
    citySyncTimer = setTimeout(function () {
      const values = boxValues();
      if (isBijakChosen()) syncDestinationCity(values.$box.find('[name="bijak_dest_city"]'), true);
    }, 120);
  });
  $(document).on('change', '#bijak_is_door_delivery', function () {
    const $box = $(this).closest('.bijak-box');
    $box.find('.bijak-location-picker').toggle($(this).is(':checked'));
    clearTimeout(debounce);
    debounce = setTimeout(function () {
      if (isBijakChosen() && boxValues($box).cityId) bijakPriceEstimate(true, $box);
    }, 250);
  });

  // One global listener; validate origin, source, protocol and one-time state.
  window.addEventListener('message', function (event) {
    const activeIframe = $('#bijak-location-modal iframe')[0] || pickerIframe;
    let activeState = String($('#bijak-location-modal').attr('data-picker-state') || pickerState || '');
    if (activeIframe && activeIframe.src) {
      try {
        activeState = new URL(activeIframe.src, window.location.href).searchParams.get('state') || activeState;
      } catch (error) {
        // Keep the state captured when the modal was opened.
      }
    }
    if (!activeIframe || !activeState || event.origin !== config.map_picker_origin || event.source !== activeIframe.contentWindow) return;
    const data = event.data;
    if (!data || data.type !== 'BIJAK_LOCATION_SELECTED' || data.version !== 1 || data.state !== activeState) return;
    if (data.lat === undefined || data.lat === null || data.lng === undefined || data.lng === null) return;
    const lat = Number(data.lat), lng = Number(data.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
    const $modal = ensureModal();
    $modal.find('.bijak-location-modal__loading').show().text(__('Saving selected location...', 'bijak'));
    $.post(config.ajax_url, { action: 'bijak_save_destination_location', nonce: config.nonce, state: activeState, lat: lat, lng: lng })
      .done(function (res) {
        if (!res || !res.success) { showModalError((res && res.data && res.data.message) || __('Unable to save location.', 'bijak')); return; }
        setLocationStatus(true, '');
        closeModal();
        if (isBijakChosen() && boxValues().cityId) bijakPriceEstimate(true);
      })
      .fail(function (xhr) { showModalError((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || __('Unable to save location.', 'bijak')); });
  });

  ensureCities(true);
  showBox();
});
