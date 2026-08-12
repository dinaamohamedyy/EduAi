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

		/* ------------------------------------------------------ question bank -- */

		var bank = document.querySelector('[data-sl-bank]');

		if (bank) {
			/* Searched from the bank's PARENT, not from the bank. The container
			   div closes before the hidden count field and the <template> — all
			   three are siblings inside the meta box, not nested. Scoping these
			   to [data-sl-bank] silently found nothing, and the failure was not
			   an error: rows still renumbered and legends still corrected, so
			   the editor looked like it worked while the count field it exists
			   to maintain was never written. */
			var scope = bank.parentNode || document;
			var tpl = scope.querySelector('template[data-sl-bank-template]');
			var countField = scope.querySelector('[data-sl-bank-count]');
			var max = parseInt(bank.getAttribute('data-sl-bank-max'), 10) || 0;

			/* THE ONE FUNCTION THAT WRITES sl_bank_count, and it COUNTS rather
			   than increments.

			   That field is half of the truncation guard: PHP's max_input_vars
			   silently discards the tail of a long POST, so the box declares how
			   many rows it sent and the server refuses a mismatch. A script that
			   incremented on add and decremented on remove would be two
			   operations that must agree forever — and the failure is not a
			   broken editor, it is a REFUSED SAVE on a perfectly good paper,
			   blamed on a limit that was never hit.

			   Counting the rows that actually exist cannot drift. It is also why
			   add and remove both end here rather than each maintaining state.

			   Renumbering to 0..n-1 on every change is the same argument applied
			   to the field names: the server compares count($_POST['sl_bank'])
			   against the declared number, so sparse keys after a removal would
			   still pass — but a duplicate key would silently merge two questions
			   into one, and contiguous indices make that unrepresentable. */
			var renumber = function () {
				var rows = bank.querySelectorAll('[data-sl-bank-row]');

				Array.prototype.forEach.call(rows, function (row, i) {
					Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (field) {
						field.name = field.name.replace(/^sl_bank\[[^\]]*\]/, 'sl_bank[' + i + ']');
					});
					var legend = row.querySelector('.sl-bank__legend');
					// Only the number is replaced, so the translated string stands.
					if (legend) { legend.textContent = legend.textContent.replace(/\d+/, String(i + 1)); }
				});

				if (countField) { countField.value = rows.length; }
				if (addBtn) { addBtn.disabled = max > 0 && rows.length >= max; }
			};

			/* Built here rather than server-side on purpose: without JS these
			   controls would be visible and dead. The rows PHP already rendered
			   still save with the script disabled — that is the progressive
			   enhancement requirement, and it is why the count starts correct. */
			var addBtn = null;

			var addRemoveTo = function (row) {
				if (row.querySelector('[data-sl-bank-remove]')) { return; }
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'button-link sl-bank__remove';
				btn.setAttribute('data-sl-bank-remove', '');
				btn.textContent = str('bankRemove', 'Remove this question');
				btn.addEventListener('click', function () {
					row.parentNode.removeChild(row);
					renumber();
				});
				row.appendChild(btn);
			};

			Array.prototype.forEach.call(bank.querySelectorAll('[data-sl-bank-row]'), addRemoveTo);

			if (tpl) {
				addBtn = document.createElement('button');
				addBtn.type = 'button';
				addBtn.className = 'button sl-bank__add';
				addBtn.textContent = str('bankAdd', 'Add a question');

				addBtn.addEventListener('click', function () {
					var rows = bank.querySelectorAll('[data-sl-bank-row]');
					if (max > 0 && rows.length >= max) { return; }

					var clone = tpl.content.cloneNode(true);
					var row = clone.querySelector('[data-sl-bank-row]');
					if (!row) { return; }

					// __i__ is the template's placeholder index; renumber()
					// immediately overwrites it, but leaving it would produce
					// one malformed name if renumber ever failed to run.
					Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (field) {
						field.name = field.name.replace('__i__', String(rows.length));
					});

					bank.insertBefore(row, addBtn);
					addRemoveTo(row);
					renumber();

					var first = row.querySelector('textarea');
					if (first) { first.focus(); }
				});

				bank.appendChild(addBtn);
			}

			renumber();
		}
	});
}(jQuery));
