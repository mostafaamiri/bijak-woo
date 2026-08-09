(function ($) {
  'use strict';

  var config = window.BIJAK_ADMIN || {};
  var pickerState = '';
  var pickerIframe = null;

  function setCoords(lat, lng) {
    var $field = $('#bijak-origin-coords');
    var $status = $('#bijak-origin-coords-status');
    if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
      $field.val('');
      $status.removeClass('is-set').addClass('is-missing').text('موقعیت مبدا ثبت نشده است.');
      return;
    }

    var value = Number(lat).toFixed(7).replace(/0+$/, '').replace(/\.$/, '') + ',' + Number(lng).toFixed(7).replace(/0+$/, '').replace(/\.$/, '');
    var url = 'https://nshn.ir/?lat=' + encodeURIComponent(Number(lat).toFixed(7)) + '&lng=' + encodeURIComponent(Number(lng).toFixed(7));
    $field.val(value);
    $status.removeClass('is-missing').addClass('is-set').html('<a id="bijak-origin-map-link" target="_blank" rel="noopener"></a>');
    $('#bijak-origin-map-link').attr('href', url).text('مشاهده مبدا در نقشه نشان');
  }

  function showError(message) {
    var $notice = $('#bijak-admin-origin-notice');
    if (!$notice.length) {
      $notice = $('<div id="bijak-admin-origin-notice" class="notice notice-error inline"><p></p></div>');
      $('.bijak-origin-location').first().prepend($notice);
    }
    $notice.find('p').text(message).end().show();
  }

  function closeModal() {
    $('#bijak-origin-picker-modal').remove();
    pickerIframe = null;
    pickerState = '';
  }

  function openModal(url) {
    closeModal();
    var $modal = $('<div id="bijak-origin-picker-modal" class="bijak-origin-picker-modal" role="dialog" aria-modal="true" aria-label="انتخاب موقعیت مبدا">' +
      '<div class="bijak-origin-picker-modal__panel"><div class="bijak-origin-picker-modal__header"><strong>انتخاب موقعیت مبدا</strong><button type="button" class="bijak-origin-picker-modal__close" aria-label="بستن">&times;</button></div>' +
      '<div class="bijak-origin-picker-modal__body"><p class="bijak-origin-picker-modal__loading">در حال بارگذاری نقشه...</p><iframe title="انتخاب موقعیت مبدا" allow="geolocation" referrerpolicy="strict-origin-when-cross-origin"></iframe></div></div></div>');
    $('body').append($modal);
    pickerIframe = $modal.find('iframe')[0];
    $(pickerIframe).on('load', function () { $modal.find('.bijak-origin-picker-modal__loading').hide(); });
    $modal.on('click', '.bijak-origin-picker-modal__close', closeModal);
    $modal.on('click', function (event) { if (event.target === $modal[0]) closeModal(); });
    pickerIframe.src = url;
  }

  $('#bijak-origin-profile').on('click', function () {
    var $button = $(this);
    $button.prop('disabled', true).text('در حال دریافت اطلاعات پروفایل...');
    $.post(config.ajax_url, { action: 'bijak_admin_get_profile', nonce: config.nonce })
      .done(function (response) {
        if (!response || !response.success || !response.data) {
          showError((response && response.data && response.data.message) || 'دریافت پروفایل بیجک ناموفق بود.');
          return;
        }
        $('#bijak-origin-address').val(response.data.address || '');
        if (response.data.city_id) $('#bijak_woo_options-origin_city_id, select[name="bijak_woo_options[origin_city_id]"]').val(String(response.data.city_id)).trigger('change');
        $('#bijak-origin-location-source').val('profile');
        setCoords(response.data.lat, response.data.lng);
        $('#bijak-admin-origin-notice').hide();
      })
      .fail(function () { showError('دریافت پروفایل بیجک ناموفق بود.'); })
      .always(function () { $button.prop('disabled', false).text('پر کردن آدرس از پروفایل بیجک'); });
  });

  $('#bijak-origin-address').on('input', function () {
    $('#bijak-origin-location-source').val('manual');
  });

  $('#bijak-origin-map').on('click', function () {
    var $button = $(this);
    $button.prop('disabled', true).text('در حال ایجاد نشست امن...');
    $.post(config.ajax_url, { action: 'bijak_admin_create_origin_picker_session', nonce: config.nonce })
      .done(function (response) {
        if (!response || !response.success || !response.data || !response.data.url) {
          showError((response && response.data && response.data.message) || 'باز کردن نقشه مبدا ناموفق بود.');
          return;
        }
        pickerState = String(response.data.state || '');
        openModal(String(response.data.url));
      })
      .fail(function () { showError('باز کردن نقشه مبدا ناموفق بود.'); })
      .always(function () { $button.prop('disabled', false).text('انتخاب مبدا روی نقشه'); });
  });

  window.addEventListener('message', function (event) {
    if (!pickerIframe || event.source !== pickerIframe.contentWindow || event.origin !== config.picker_origin) return;
    var data = event.data || {};
    if (data.type !== 'BIJAK_LOCATION_SELECTED' || data.version !== 1 || String(data.state) !== pickerState) return;
    $.post(config.ajax_url, { action: 'bijak_admin_save_origin_location', nonce: config.nonce, state: pickerState, lat: data.lat, lng: data.lng })
      .done(function (response) {
        if (!response || !response.success || !response.data) { showError((response && response.data && response.data.message) || 'ذخیره موقعیت مبدا ناموفق بود.'); return; }
        $('#bijak-origin-location-source').val('map');
        setCoords(response.data.lat, response.data.lng);
        $('#bijak-admin-origin-notice').hide();
        closeModal();
      })
      .fail(function () { showError('ذخیره موقعیت مبدا ناموفق بود.'); });
  });
})(jQuery);
