jQuery(function ($) {
  /* ---------- helpers ---------- */
  function dedupeBox() {
    const $b = $(".bijak-box");
    if ($b.length > 1) $b.slice(1).remove();
  }
  function hasBijakRate() {
    let ok = false;
    $('input[name^="shipping_method"]').each(function () {
      if ($(this).val()?.startsWith("bijak_pay_at_dest")) { ok = true; return false; }
    });
    return ok;
  }
  function reinit($s) {
    if ($.fn.selectWoo) {
      if ($s.hasClass("select2-hidden-accessible")) $s.selectWoo("destroy");
      $s.selectWoo(); $(document.body).trigger("wc-enhanced-select-init", $s);
    }
  }

  /* ---------- city list ---------- */
  let CITY_CACHE = null, loadingCities = false;

  function fetchCities(oId) {
    return $.post(BIJAK.ajax_url, {
      action: "bijak_get_destinations",
      nonce: BIJAK.nonce,
      origin_city_id: oId,
      _: Date.now()
    }).then(res => {
      const arr = res?.success && Array.isArray(res.data?.data) ? res.data.data : [];
      return arr.map(c => ({ id: String(c.city_id), text: c.city_name }));
    });
  }

  function populateCities(opts) {
    const $sel = $("#bijak_dest_city");
    const saved = window.__bijak_saved_city || "";
    $sel.empty().append('<option value=""></option>');
    opts.forEach(o => $("<option>", { value: o.id, text: o.text }).appendTo($sel));
    if (saved) $sel.val(saved);
    reinit($sel);
  }

  function ensureCities(force = false) {
    const oId = parseInt(BIJAK.origin_city_id, 10) || 0;
    const $sel = $("#bijak_dest_city");
    if (!$sel.length || !oId) return;
    if (loadingCities) return;
    if (!force && $sel.children('option[value!=""]').length) return;

    loadingCities = true; $sel.prop("disabled", true);

    if (!force && CITY_CACHE && CITY_CACHE.oId === oId && CITY_CACHE.opts.length) {
      populateCities(CITY_CACHE.opts);
      $sel.prop("disabled", false); loadingCities = false; return;
    }

    fetchCities(oId).then(opts => {
      CITY_CACHE = { oId, opts };
      populateCities(opts);
    }).always(() => { loadingCities = false; $sel.prop("disabled", false); });
  }

  function bijak_price_estimate() {
    const $out = $("#bijak_estimate_result").text("در حال محاسبه...");
    $.post(BIJAK.ajax_url, {
      action: "bijak_price_estimate",
      nonce: BIJAK.nonce,
      dest_city_id: $("#bijak_dest_city").val(),
      is_door_delivery: $("#bijak_is_door_delivery").is(":checked") ? 1 : 0
    })
      .done(r => {
        if (!r || !r.success) { $out.text(r?.data?.message || "خطا در تخمین"); return; }
        const d = r.data.data || {};
        let h = "<ul>";
        (d.items || []).forEach(it => h += `<li>${it.text} : ${(it.value || 0).toLocaleString("fa-IR")} تومان</li>`);
        h += `</ul><strong>جمع: ${(d.sum || 0).toLocaleString("fa-IR")} تومان</strong>`;
        $out.html(h);
        $(document.body).trigger("update_checkout");
      })
      .fail(x => $out.text(x?.responseJSON?.data?.message || "خطا در تخمین"));
  }

  /* ---------- show/hide box ---------- */
  function showBox() {
    dedupeBox();
    const ok = hasBijakRate();
    $(".bijak-box").toggle(ok);
    if (ok) ensureCities();
  }

  $(document).on("change",
    'input[name^="shipping_method"], select[name^="shipping_method"]',
    () => setTimeout(showBox, 50)
  );
  $(document.body).on("updated_checkout updated_wc_div updated_shipping_method",
    () => setTimeout(showBox, 150)
  );

  /* ---------- auto re-estimate on change ---------- */
  let debounce;
  $(document).on("change", "#bijak_dest_city, #bijak_is_door_delivery", function () {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      bijak_price_estimate();
    }, 400);
  });

  $(document).on("change", "#bijak_dest_city", function () {
    window.__bijak_saved_city = $(this).val() || "";
  });

  /* ---------- init ---------- */
  ensureCities(true);
  showBox();
});
