(function ($) {
  'use strict';

  var config = window.BIJAK_ADMIN || {};
  var __ = window.wp && wp.i18n ? wp.i18n.__ : function (text) { return text; };
  var pickerState = '';
  var pickerIframe = null;

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var field = document.createElement('textarea');
      field.value = text;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      var copied = false;
      try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
      document.body.removeChild(field);
      if (copied) resolve(); else reject(new Error('copy_failed'));
    });
  }

  $(document).on('click', '.bijak-tracking-copy', function () {
    var button = this;
    var text = String(button.getAttribute('data-tracking') || '');
    if (!text) return;
    copyText(text).then(function () {
      var originalTitle = button.getAttribute('title') || '';
      button.classList.add('is-copied');
      button.setAttribute('title', __('Tracking number copied', 'bijak'));
      window.setTimeout(function () {
        button.classList.remove('is-copied');
        button.setAttribute('title', originalTitle);
      }, 1400);
    });
  });

  if (window.jalaliDatepicker) {
    window.jalaliDatepicker.startWatch({
      time: false,
      persianDigits: false,
      separatorChars: { date: '-', between: ' ', time: ':' }
    });
  }

  function setCoords(lat, lng) {
    var $field = $('#bijak-origin-coords');
    var $status = $('#bijak-origin-coords-status');
    if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
      $field.val('');
      $status.removeClass('is-set').addClass('is-missing').text(__('Origin location is not set.', 'bijak'));
      return;
    }

    var value = Number(lat).toFixed(7).replace(/0+$/, '').replace(/\.$/, '') + ',' + Number(lng).toFixed(7).replace(/0+$/, '').replace(/\.$/, '');
    var url = 'https://nshn.ir/?lat=' + encodeURIComponent(Number(lat).toFixed(7)) + '&lng=' + encodeURIComponent(Number(lng).toFixed(7));
    $field.val(value);
    $status.removeClass('is-missing').addClass('is-set').html('<a id="bijak-origin-map-link" target="_blank" rel="noopener"></a>');
    $('#bijak-origin-map-link').attr('href', url).text(__('View origin on Neshan map', 'bijak'));
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
    var $modal = $('<div id="bijak-origin-picker-modal" class="bijak-origin-picker-modal" role="dialog" aria-modal="true" aria-label="' + __('Choose origin location', 'bijak') + '">' +
      '<div class="bijak-origin-picker-modal__panel"><div class="bijak-origin-picker-modal__header"><strong>' + __('Choose origin location', 'bijak') + '</strong><button type="button" class="bijak-origin-picker-modal__close" aria-label="' + __('Close', 'bijak') + '">&times;</button></div>' +
      '<div class="bijak-origin-picker-modal__body"><p class="bijak-origin-picker-modal__loading">' + __('Loading map...', 'bijak') + '</p><iframe title="' + __('Choose origin location', 'bijak') + '" allow="geolocation" referrerpolicy="strict-origin-when-cross-origin"></iframe></div></div></div>');
    $('body').append($modal);
    pickerIframe = $modal.find('iframe')[0];
    $(pickerIframe).on('load', function () { $modal.find('.bijak-origin-picker-modal__loading').hide(); });
    $modal.on('click', '.bijak-origin-picker-modal__close', closeModal);
    $modal.on('click', function (event) { if (event.target === $modal[0]) closeModal(); });
    pickerIframe.src = url;
  }

  $('#bijak-origin-profile').on('click', function () {
    var $button = $(this);
    $button.prop('disabled', true).text(__('Loading profile...', 'bijak'));
    $.post(config.ajax_url, { action: 'bijak_admin_get_profile', nonce: config.nonce })
      .done(function (response) {
        if (!response || !response.success || !response.data) {
          showError((response && response.data && response.data.message) || __('Unable to load the Bijak profile.', 'bijak'));
          return;
        }
        $('#bijak-origin-address').val(response.data.address || '');
        if (response.data.city_id) $('#bijak_woo_options-origin_city_id, select[name="bijak_woo_options[origin_city_id]"]').val(String(response.data.city_id)).trigger('change');
        $('#bijak-origin-location-source').val('profile');
        setCoords(response.data.lat, response.data.lng);
        $('#bijak-admin-origin-notice').hide();
      })
      .fail(function () { showError(__('Unable to load the Bijak profile.', 'bijak')); })
      .always(function () { $button.prop('disabled', false).text(__('Fill address from Bijak profile', 'bijak')); });
  });

  $('#bijak-origin-address').on('input', function () {
    $('#bijak-origin-location-source').val('manual');
  });

  $('#bijak-origin-map').on('click', function () {
    var $button = $(this);
    $button.prop('disabled', true).text(__('Creating secure session...', 'bijak'));
    $.post(config.ajax_url, { action: 'bijak_admin_create_origin_picker_session', nonce: config.nonce })
      .done(function (response) {
        if (!response || !response.success || !response.data || !response.data.url) {
          showError((response && response.data && response.data.message) || __('Unable to open the origin map.', 'bijak'));
          return;
        }
        pickerState = String(response.data.state || '');
        openModal(String(response.data.url));
        $('#bijak-origin-picker-modal').attr('data-picker-state', pickerState);
      })
      .fail(function () { showError(__('Unable to open the origin map.', 'bijak')); })
      .always(function () { $button.prop('disabled', false).text(__('Choose origin on map', 'bijak')); });
  });

  window.addEventListener('message', function (event) {
    var activeIframe = $('#bijak-origin-picker-modal iframe')[0] || pickerIframe;
    var activeState = String($('#bijak-origin-picker-modal').attr('data-picker-state') || pickerState || '');
    if (activeIframe && activeIframe.src) {
      try {
        activeState = new URL(activeIframe.src, window.location.href).searchParams.get('state') || activeState;
      } catch (error) {
        // Keep the state captured when the modal was opened.
      }
    }
    if (!activeIframe || event.source !== activeIframe.contentWindow || event.origin !== config.picker_origin) return;
    var data = event.data || {};
    if (data.type !== 'BIJAK_LOCATION_SELECTED' || data.version !== 1 || String(data.state) !== activeState) return;
    $.post(config.ajax_url, { action: 'bijak_admin_save_origin_location', nonce: config.nonce, state: activeState, lat: data.lat, lng: data.lng })
      .done(function (response) {
        if (!response || !response.success || !response.data) { showError((response && response.data && response.data.message) || __('Unable to save the origin location.', 'bijak')); return; }
        $('#bijak-origin-location-source').val('map');
        setCoords(response.data.lat, response.data.lng);
        $('#bijak-admin-origin-notice').hide();
        closeModal();
      })
      .fail(function () { showError(__('Unable to save the origin location.', 'bijak')); });
  });
})(jQuery);
