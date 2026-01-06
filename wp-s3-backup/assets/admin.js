(function ($) {
  function applyPreset(presetKey) {
    if (!window.WPS3B_PRESETS || !window.WPS3B_PRESETS[presetKey]) return;
    const p = window.WPS3B_PRESETS[presetKey];

    const $endpoint = $('#wps3b_endpoint');
    const $region   = $('#wps3b_region');
    const $prefix   = $('#wps3b_prefix');
    const $path     = $('#wps3b_path_style');

    if ($endpoint.length) $endpoint.val(p.endpoint);
    if ($region.length)   $region.val(p.region);
    if ($prefix.length)   $prefix.val(p.prefix);
    if ($path.length)     $path.prop('checked', !!p.path_style);
  }

  $(function () {
    $('#wps3b_provider').on('change', function () {
      const v = $(this).val();
      if (v === 'custom') return;
      applyPreset(v);
    });
  });
})(jQuery);
