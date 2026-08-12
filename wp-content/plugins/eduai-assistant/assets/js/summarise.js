/**
 * Summarise — front end for POST /eduai/v1/summarize.
 *
 * The endpoint is unchanged; this replaces the widget shell the summariser used
 * to live inside (docs/06 §2.1). It sends multipart when a file is attached and
 * JSON-ish form data when text is pasted, because extraction happens server-side
 * in EduAI_PDF — the browser never parses a PDF or a slide deck here.
 *
 * A summary is the slowest call in the product: a whole lecture in, structured
 * notes out. So the wait is narrated rather than left as a spinner, and the
 * button says what stage it is at.
 */
(function () {
	'use strict';

	var CFG = window.EduAISumConfig || {};
	var T = CFG.i18n || {};

	function el(tag, cls, html) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (html !== undefined) { node.innerHTML = html; }
		return node;
	}

	function escapeHtml(str) {
		return String(str).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function Summariser(root) {
		this.root = root;
		this.form = root.querySelector('[data-eduai-sum-form]');
		this.drop = root.querySelector('[data-eduai-sum-drop]');
		this.file = root.querySelector('[data-eduai-sum-file]');
		this.dropMsg = root.querySelector('[data-eduai-sum-dropmsg]');
		this.text = root.querySelector('[data-eduai-sum-text]');
		this.style = root.querySelector('[data-eduai-sum-style]');
		this.button = root.querySelector('[data-eduai-sum-go]');
		this.out = root.querySelector('[data-eduai-sum-out]');
		this.idleLabel = this.button.textContent;
		this.busy = false;

		/* The scope id is read off the banner the server rendered, NOT off
		   location.search. The server resolved ?source= and applied that post
		   type's gate before printing it, so the banner existing IS the
		   authorisation. Re-deriving the id from the URL here would make a
		   second source of truth for one question, and would let anyone type
		   ?source=<id> and have us send an id the server refused. No banner,
		   no id — see the contract note in templates/summarizer.php. */
		var scopeEl = root.querySelector('[data-eduai-scope]');
		this.scopeId = scopeEl ? parseInt(scopeEl.getAttribute('data-eduai-scope'), 10) || 0 : 0;

		this.bind();
	}

	Summariser.prototype.bind = function () {
		var self = this;

		this.drop.addEventListener('click', function () { self.file.click(); });
		this.drop.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); self.file.click(); }
		});

		['dragenter', 'dragover'].forEach(function (ev) {
			self.drop.addEventListener(ev, function (e) {
				e.preventDefault();
				self.drop.classList.add('is-over');
			});
		});
		['dragleave', 'drop'].forEach(function (ev) {
			self.drop.addEventListener(ev, function (e) {
				e.preventDefault();
				self.drop.classList.remove('is-over');
			});
		});

		this.drop.addEventListener('drop', function (e) {
			if (e.dataTransfer.files && e.dataTransfer.files[0]) {
				self.file.files = e.dataTransfer.files;
				self.setFile(e.dataTransfer.files[0]);
			}
		});

		this.file.addEventListener('change', function () {
			self.setFile(self.file.files[0]);
		});

		this.form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.submit();
		});
	};

	Summariser.prototype.setFile = function (file) {
		this.drop.classList.toggle('has-file', Boolean(file));
		this.dropMsg.textContent = file
			? file.name + '  ·  ' + Math.max(1, Math.round(file.size / 1024)) + ' KB'
			: T.dropFile;
	};

	Summariser.prototype.note = function (cls, html) {
		this.out.hidden = false;
		this.out.innerHTML = '';
		this.out.appendChild(el('div', cls, html));
	};

	Summariser.prototype.submit = function () {
		var self = this;
		var file = this.file.files[0];
		var pasted = (this.text.value || '').trim();

		if (this.busy) { return; }

		if (!CFG.loggedIn) {
			this.note('eduai-error', escapeHtml(T.loginPrompt));
			return;
		}
		/* A scoped page already HAS its source — that is the whole feature, so
		   demanding a file or pasted text there would refuse the one request
		   the student came to make. Unscoped, the guard is unchanged. */
		if (!this.scopeId && !file && pasted.length < 80) {
			this.note('eduai-error', escapeHtml(T.needSource));
			return;
		}
		if (file && file.size > CFG.maxUploadMb * 1024 * 1024) {
			this.note('eduai-error', escapeHtml(
				T.tooBig.replace('%d', CFG.maxUploadMb)
			));
			return;
		}

		var body = new FormData();
		if (file) { body.append('file', file); }
		if (pasted) { body.append('text', pasted); }
		body.append('style', this.style.value);
		/* Sent as `source`, the same word wp_eduai_chunks already uses for the
		   thing an answer is grounded in. The endpoint re-gates it: this id
		   came from the server, but arriving back over the wire it is input
		   again, and the second call site must ask the same one predicate the
		   first did rather than a second one that agrees today. */
		if (this.scopeId) { body.append('source', String(this.scopeId)); }

		this.busy = true;
		this.button.disabled = true;
		// Reading happens server-side, so the two stages are worth naming: a
		// large deck spends real time in extraction before the model sees it.
		this.button.textContent = file ? T.reading : T.summarising;
		this.note('eduai-sum__working',
			'<div class="eduai-typing"><span></span><span></span><span></span></div>' +
			'<p>' + escapeHtml(file ? T.readingNote : T.summarisingNote) + '</p>');

		var stage = setTimeout(function () {
			self.button.textContent = T.summarising;
		}, 2500);

		fetch(CFG.root + '/summarize', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CFG.nonce },
			body: body
		})
			.then(function (res) {
				return res.json().then(function (data) { return { ok: res.ok, data: data }; });
			})
			.then(function (r) {
				if (!r.ok) {
					// The endpoint's errors are already written for a student —
					// "that deck has no text on its slides", not a status code.
					self.note('eduai-error', escapeHtml(
						(r.data && r.data.message) || T.error
					));
					return;
				}
				self.render(r.data);
			})
			.catch(function () {
				self.note('eduai-error', escapeHtml(T.error));
			})
			.finally(function () {
				clearTimeout(stage);
				self.busy = false;
				self.button.disabled = false;
				self.button.textContent = self.idleLabel;
			});
	};

	Summariser.prototype.render = function (data) {
		this.out.hidden = false;
		this.out.innerHTML = '';

		var card = el('div', 'eduai-sum__card');
		var head = el('div', 'eduai-sum__head');

		head.appendChild(el('strong', null, escapeHtml(
			data.label || T.pastedText
		)));
		head.appendChild(el('span', 'eduai-sum__style', escapeHtml(
			(T.styles && T.styles[data.style]) || data.style || ''
		)));
		card.appendChild(head);

		// Server-rendered and already sanitised by EduAI_REST::to_html().
		card.appendChild(el('div', 'eduai-sum__body', data.html || ''));

		var tools = el('div', 'eduai-sum__tools');
		var copy = el('button', null, escapeHtml(T.copy));
		copy.type = 'button';
		copy.addEventListener('click', function () {
			if (!navigator.clipboard) { return; }
			navigator.clipboard.writeText(data.summary || '').then(function () {
				copy.textContent = T.copied;
				setTimeout(function () { copy.textContent = T.copy; }, 1600);
			});
		});
		tools.appendChild(copy);
		card.appendChild(tools);

		this.out.appendChild(card);
	};

	function init() {
		document.querySelectorAll('[data-eduai-sum]').forEach(function (node) {
			if (node.__eduaiSum) { return; }
			node.__eduaiSum = new Summariser(node);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
