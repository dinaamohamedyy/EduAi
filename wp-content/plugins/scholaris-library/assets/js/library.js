/* Library filters: submit on change, and keep the URL tidy. */
(function () {
	'use strict';

	function init() {
		document.querySelectorAll('[data-sl-autosubmit]').forEach(function (control) {
			control.addEventListener('change', function () {
				var form = control.closest('form');
				if (form) { form.submit(); }
			});
		});

		// Drop empty parameters before submitting so URLs stay readable.
		document.querySelectorAll('.sl-filters').forEach(function (form) {
			form.addEventListener('submit', function () {
				form.querySelectorAll('input, select').forEach(function (field) {
					if (!field.value) { field.disabled = true; }
				});
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
