/* Study-material meta boxes: document picker, video source panes, video picker.
 *
 * PROGRESSIVE ENHANCEMENT. Nothing here is required to SAVE. Every input the
 * form submits is rendered by PHP and posts on its own; this file only reveals
 * the pane that matches the chosen source and fills two hidden fields from the
 * media library. With JS off, whatever the material already has still edits and
 * still saves — see the note at syncPanes() for the one case that does not.
 */
(function ($) {
	'use strict';

	/* Localised strings arrive via wp_localize_script as SLAdmin. The video
	   keys are not in that array yet (it carries the document picker's two), so
	   every read falls back the way the original picker already did — this file
	   works today and picks the translations up the moment they are added. */
	function str(key, fallback) {
		return (window.SLAdmin && window.SLAdmin[key]) || fallback;
	}

	/* Built as nodes, never as an HTML string. A filename is user-supplied: it
	   survives sanitize_file_name() with & and quotes intact, and string
	   concatenation into .html() would let those become markup. .text() cannot. */
	function fileLink(url, filename) {
		return $('<a>').attr({ href: url, target: '_blank', rel: 'noopener' }).text(filename);
	}

	/* One frame per picker, created lazily and reused — reopening a built frame
	   keeps the user's place, and rebuilding it each click leaks listeners. */
	function picker(opts) {
		var frame;
		return function (e) {
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
				title: opts.title,
				button: { text: opts.button },
				library: { type: opts.type },
				multiple: false
			});
			frame.on('select', function () {
				opts.onSelect(frame.state().get('selection').first().toJSON());
			});
			frame.open();
		};
	}

	$(function () {
		/* ---------------------------------------------------------- document -- */
		var $fileId = $('#sl_file_id');
		var $fileLabel = $('#sl_file_label');

		$('#sl_pick_file').on('click', picker({
			title: str('title', 'Choose the document'),
			button: str('button', 'Use this file'),
			type: ['application/pdf', 'application/msword', 'text/plain',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
			onSelect: function (file) {
				$fileId.val(file.id);
				$fileLabel.empty().append(fileLink(file.url, file.filename));
			}
		}));

		$('#sl_clear_file').on('click', function (e) {
			e.preventDefault();
			$fileId.val('');
			$fileLabel.empty().append($('<em>').text(str('noFile', 'No file attached yet.')));
		});

		/* ------------------------------------------------------- video source -- */
		var $sources = $('input[name="sl_video_source"]');
		var $panes = $('[data-sl-video-pane]');

		/* Show the pane matching the chosen source, hide the rest. The radio
		   values are '', 'link' and 'file' (class-sl-meta.php render_video), and
		   '' deliberately matches no pane.

		   WITHOUT THIS, THE FEATURE IS UNREACHABLE ON A NEW MATERIAL: PHP prints
		   `hidden` on both panes unless the STORED source already names one, so
		   a material that has never been saved offers three radios and no fields
		   whichever you pick.

		   That is also the one thing JS-off users cannot do here, and this file
		   cannot fix it — the attribute is already in the markup by the time we
		   run. The server-side fix is to render the panes visible and let this
		   function do the first hide; then no-JS shows every field, which is
		   cluttered but complete. Raised with back-end rather than patched here,
		   because it is their markup. */
		function syncPanes() {
			var value = $sources.filter(':checked').val() || '';
			$panes.each(function () {
				var pane = $(this);
				pane.prop('hidden', pane.attr('data-sl-video-pane') !== value);
			});
		}

		/* prop(), not attr(): .attr('hidden', false) leaves the attribute in
		   place in some jQuery versions, and the pane stays invisible while the
		   DOM claims otherwise — the attribute-versus-rendered trap again. */
		$sources.on('change', syncPanes);
		syncPanes();

		/* -------------------------------------------------------- video file -- */
		var $videoId = $('#sl_video_id');
		var $videoLabel = $('#sl_video_label');

		$('#sl_pick_video').on('click', picker({
			title: str('videoTitle', 'Choose the video'),
			button: str('videoButton', 'Use this video'),
			type: 'video',
			onSelect: function (file) {
				$videoId.val(file.id);
				$videoLabel.empty().append(fileLink(file.url, file.filename));
			}
		}));

		$('#sl_clear_video').on('click', function (e) {
			e.preventDefault();
			$videoId.val('');
			$videoLabel.empty().append($('<em>').text(str('noVideo', 'No video attached yet.')));
		});
	});
}(jQuery));
