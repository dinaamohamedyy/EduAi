/* Media picker for the study-material Document meta box. */
(function ($) {
	'use strict';

	$(function () {
		var frame;
		var $id = $('#sl_file_id');
		var $label = $('#sl_file_label');

		$('#sl_pick_file').on('click', function (e) {
			e.preventDefault();

			if (frame) { frame.open(); return; }

			frame = wp.media({
				title: (window.SLAdmin && window.SLAdmin.title) || 'Choose the document',
				button: { text: (window.SLAdmin && window.SLAdmin.button) || 'Use this file' },
				library: { type: ['application/pdf', 'application/msword', 'text/plain',
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'application/vnd.openxmlformats-officedocument.presentationml.presentation'] },
				multiple: false
			});

			frame.on('select', function () {
				var file = frame.state().get('selection').first().toJSON();
				$id.val(file.id);
				$label.html('<a href="' + file.url + '" target="_blank" rel="noopener">' + file.filename + '</a>');
			});

			frame.open();
		});

		$('#sl_clear_file').on('click', function (e) {
			e.preventDefault();
			$id.val('');
			$label.html('<em>No file attached yet.</em>');
		});
	});
}(jQuery));
