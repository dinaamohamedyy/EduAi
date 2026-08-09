/**
 * AiCalc — front end for POST /eduai/v1/calc.
 *
 * The one thing this file must get right is the distinction the endpoint
 * reports: `method: "exact"` means EduAI_Calc evaluated the arithmetic in PHP
 * and the answer is exactly right; `method: "model"` means it was symbolic or
 * worded and a language model answered it. Those two deserve different framing,
 * and "computed exactly" must never appear over a model's answer (docs/07 §7).
 */
(function () {
	'use strict';

	var CFG = window.EduAICalcConfig || {};

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

	function Calc(root) {
		this.root = root;
		this.form = root.querySelector('[data-eduai-calc-form]');
		this.input = root.querySelector('[data-eduai-calc-input]');
		this.button = root.querySelector('[data-eduai-calc-go]');
		this.out = root.querySelector('[data-eduai-calc-out]');
		this.busy = false;

		this.bind();
	}

	Calc.prototype.bind = function () {
		var self = this;

		this.form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.submit();
		});

		this.root.querySelectorAll('[data-eduai-calc-example]').forEach(function (b) {
			b.addEventListener('click', function () {
				self.input.value = b.getAttribute('data-eduai-calc-example');
				self.submit();
			});
		});
	};

	Calc.prototype.message = function (cls, html) {
		this.out.hidden = false;
		this.out.innerHTML = '';
		this.out.appendChild(el('div', cls, html));
	};

	Calc.prototype.submit = function () {
		var self = this;
		var text = (this.input.value || '').trim();

		if (!text || this.busy) { return; }

		if (!CFG.loggedIn) {
			this.message('eduai-error', escapeHtml(CFG.i18n.loginPrompt));
			return;
		}

		this.busy = true;
		this.button.disabled = true;
		this.message('eduai-calc__working', '<div class="eduai-typing"><span></span><span></span><span></span></div>');

		fetch(CFG.root + '/calc', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify({ input: text })
		})
			.then(function (res) {
				return res.json().then(function (data) { return { ok: res.ok, data: data }; });
			})
			.then(function (r) {
				if (!r.ok) {
					self.message('eduai-error', escapeHtml(
						(r.data && r.data.message) || CFG.i18n.error
					));
					return;
				}
				self.render(r.data);
			})
			.catch(function () {
				self.message('eduai-error', escapeHtml(CFG.i18n.error));
			})
			.finally(function () {
				self.busy = false;
				self.button.disabled = false;
			});
	};

	Calc.prototype.render = function (data) {
		var exact = 'exact' === data.method;

		this.out.hidden = false;
		this.out.innerHTML = '';

		var card = el('div', 'eduai-calc__card' + (exact ? ' is-exact' : ' is-model'));

		// The badge is the whole point of the screen: it says which kind of
		// answer this is before the student reads the answer itself.
		card.appendChild(el(
			'span',
			'eduai-calc__badge',
			escapeHtml(exact ? CFG.i18n.exact : CFG.i18n.viaModel)
		));

		if (exact) {
			card.appendChild(el('div', 'eduai-calc__answer', escapeHtml(data.answer)));

			if (data.steps && data.steps.length) {
				var list = el('ol', 'eduai-calc__steps');
				data.steps.forEach(function (line, i) {
					// The first line is the sum as entered, not a step.
					list.appendChild(el('li', i === 0 ? 'is-input' : null, escapeHtml(line)));
				});
				card.appendChild(list);
			}

			card.appendChild(el('p', 'eduai-calc__note', escapeHtml(CFG.i18n.exactNote)));
		} else {
			// The model path returns rendered HTML, already sanitised server-side
			// by EduAI_REST::to_html().
			card.appendChild(el('div', 'eduai-calc__prose', data.html || ''));
			card.appendChild(el('p', 'eduai-calc__note', escapeHtml(CFG.i18n.modelNote)));
		}

		this.out.appendChild(card);
	};

	function init() {
		document.querySelectorAll('[data-eduai-calc]').forEach(function (node) {
			if (node.__eduaiCalc) { return; }
			node.__eduaiCalc = new Calc(node);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
