/*
 * The searchable selector of «Активный стиль текста».
 *
 * The theme ships a select2 initializer of its own, `ui-kit/assets/vendor/select2/select2-custom.js`,
 * which rewrites every `.select2` on a template page. This page has one such field
 * and no business restyling anything else on it, so the widget is mounted by its id.
 */
$(document).ready(function () {
  $('#setting-activeTextStyle').select2({ width: '100%' });
});
