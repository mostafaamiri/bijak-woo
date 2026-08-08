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
      return data.map(function (city) { return { id: String(city.city_id), text: city.city_name }; });
    });
  }

  function populateCities($select, options) {
    if (!$select.length) return;
    const saved = window.__bijak_saved_city || config.saved_city_id || '';
    $select.empty().append('<option value=""></option>');
    options.forEach(function (option) {
      $('<option>', { value: option.id, text: option.text }).appendTo($select);
    });
    if (saved) $select.val(saved);
    reinit($select);
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
    $modal.html('<div class="bijak-location-modal__panel"><div class="bijak-location-modal__header"><h2 id="bijak-location-modal-title"></h2><button type="button" class="bijak-location-modal__close" aria-label=""></button></div><div class="bijak-location-modal__body"><p class="bijak-location-modal__loading"></p><p class="bijak-location-modal__error" role="alert"></p><iframe title="" sandbox="allow-scripts allow-forms allow-same-origin" referrerpolicy="strict-origin-when-cross-origin"></iframe></div></div>');
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
    $modal.addClass('is-open is-loading').attr('aria-hidden', 'false');
    $modal.find('.bijak-location-modal__loading').show().text(__('Creating a secure picker session...', 'bijak'));
    $modal.find('.bijak-location-modal__error').hide().empty();
    $.post(config.ajax_url, { action: 'bijak_create_location_picker_session', nonce: config.nonce, destination_city_id: cityId })
      .done(function (res) {
        if (!res || !res.success || !res.data || !res.data.url || !res.data.state) { showModalError((res && res.data && res.data.message) || __('Unable to open location picker.', 'bijak')); return; }
        pickerState = String(res.data.state);
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

  function clearLocation() {
    return $.post(config.ajax_url, { action: 'bijak_clear_destination_location', nonce: config.nonce })
      .always(function () { setLocationStatus(false, ''); });
  }

  function bijakPriceEstimate(triggerUpdate, preferredBox) {
    const values = boxValues(preferredBox);
    const $out = values.$box.find('#bijak_estimate_result').text(__('Calculating...', 'bijak'));
    return $.post(config.ajax_url, {
      action: 'bijak_price_estimate', nonce: config.nonce,
      dest_city_id: values.cityId,
      is_door_delivery: values.isDoor ? 1 : 0
    }).done(function (res) {
      if (!res || !res.success) { $out.text((res && res.data && res.data.message) || __('Failed to estimate price', 'bijak')); return; }
      const data = res.data.data || {};
      let html = '<ul>';
      (data.items || []).forEach(function (item) {
        html += '<li>' + $('<div>').text(item.text || '').html() + ' : ' + Number(item.value || 0).toLocaleString('fa-IR') + ' ' + __('Toman', 'bijak') + '</li>';
      });
      html += '</ul><strong>' + __('Total', 'bijak') + ': ' + Number(data.sum || 0).toLocaleString('fa-IR') + ' ' + __('Toman', 'bijak') + '</strong>';
      $out.html(html);
      if (triggerUpdate) {
        updatingCheckout = true;
        $(document.body).trigger('update_checkout');
        setTimeout(function () { updatingCheckout = false; }, 800);
      }
    }).fail(function (xhr) { $out.text((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || __('Failed to estimate price', 'bijak')); });
  }

  function showBox() {
    const chosen = isBijakChosen();
    $('.bijak-box').first().toggle(chosen);
    $('.bijak-box').slice(1).remove();
    if (chosen) {
      ensureCities(false);
      const values = boxValues();
      values.$box.find('.bijak-location-picker').toggle(values.isDoor);
    } else {
      $('#bijak_estimate_result').empty();
    }
  }

  // Delegated handlers survive WooCommerce checkout fragment replacement.
  $(document).on('change', 'input[name^="shipping_method"], select[name^="shipping_method"]', function () { setTimeout(showBox, 50); });
  $(document).on('click', '.bijak-location-picker__open', openPicker);
  $(document.body).on('updated_checkout updated_wc_div updated_shipping_method wc_fragments_loaded wc_fragments_refreshed', function () { if (!updatingCheckout) setTimeout(showBox, 100); });
  $(document).on('change', '#bijak_dest_city', function () {
    const $box = $(this).closest('.bijak-box');
    const city = $(this).val() || '';
    const oldCity = window.__bijak_saved_city || '';
    window.__bijak_saved_city = city;
    if (city !== oldCity) clearLocation();
    if (isBijakChosen() && city) bijakPriceEstimate(true, $box);
    showBox();
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
    if (!pickerIframe || !pickerState || event.origin !== config.map_picker_origin || event.source !== pickerIframe.contentWindow) return;
    const data = event.data;
    if (!data || data.type !== 'BIJAK_LOCATION_SELECTED' || data.version !== 1 || data.state !== pickerState) return;
    if (data.lat === undefined || data.lat === null || data.lng === undefined || data.lng === null) return;
    const lat = Number(data.lat), lng = Number(data.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
    if (data.address !== undefined && (typeof data.address !== 'string' || data.address.length > 1000)) return;
    const $modal = ensureModal();
    $modal.find('.bijak-location-modal__loading').show().text(__('Saving selected location...', 'bijak'));
    $.post(config.ajax_url, { action: 'bijak_save_destination_location', nonce: config.nonce, state: pickerState, lat: lat, lng: lng, address: typeof data.address === 'string' ? data.address : '' })
      .done(function (res) {
        if (!res || !res.success) { showModalError((res && res.data && res.data.message) || __('Unable to save location.', 'bijak')); return; }
        setLocationStatus(true, res.data.address || '');
        closeModal();
        if (isBijakChosen() && boxValues().cityId) bijakPriceEstimate(true);
      })
      .fail(function (xhr) { showModalError((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || __('Unable to save location.', 'bijak')); });
  });

  ensureCities(true);
  showBox();
});
