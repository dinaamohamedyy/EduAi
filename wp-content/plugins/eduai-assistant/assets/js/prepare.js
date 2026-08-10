/**
 * PrepareME — front end for the three exam routes (docs/07 §2–§4).
 *
 * Two rules in here are ship gates rather than preferences, and both are about
 * what a student sees rather than what the server computes.
 *
 * 1. THE PAPER NEVER HOLDS THE ANSWER KEY. It is rendered from the withheld
 *    projection: answer_index, expected and explanation are absent from the
 *    response and must stay absent from this DOM until marking returns them.
 *
 * 2. `correct` IS TRI-STATE. true and false on MCQ, and **null on every short
 *    answer**, because a short answer is not right or wrong, it is worth some
 *    number of marks. `if (r.correct)` reads null as false and stamps a ✗ on an
 *    answer that scored full marks — telling a student they got something wrong
 *    when they got it right, which is worse than failing outright, because they
 *    will re-learn a correct answer as incorrect. Shorts are therefore rendered
 *    from `awarded` against `of`, and `correct` is only ever read on MCQ.
 */
(function () {
	'use strict';

	var CFG = window.EduAIPrepConfig || {};
	var T = CFG.i18n || {};
	var LETTERS = ['A', 'B', 'C', 'D'];

	function el(tag, cls, html) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (html !== undefined) { node.innerHTML = html; }
		return node;
	}

	function esc(str) {
		return String(str === null || str === undefined ? '' : str)
			.replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
	}

	/* A mark as a student would write it: 2 not 2.0, 2.5 kept. */
	function num(n) {
		return Math.round(Number(n) * 100) / 100;
	}

	function Prepare(root) {
		this.root = root;
		this.stages = {};
		root.querySelectorAll('[data-eduai-prep-stage]').forEach(function (s) {
			this.stages[s.getAttribute('data-eduai-prep-stage')] = s;
		}, this);

		this.file = root.querySelector('[data-eduai-prep-file]');
		this.drop = root.querySelector('[data-eduai-prep-drop]');
		this.dropMsg = root.querySelector('[data-eduai-prep-dropmsg]');
		this.text = root.querySelector('[data-eduai-prep-text]');
		this.lenPick = root.querySelector('[data-eduai-prep-len]');
		this.generate = root.querySelector('[data-eduai-prep-generate]');
		this.setupOut = root.querySelector('[data-eduai-prep-setup-out]');

		this.title = root.querySelector('[data-eduai-prep-title]');
		this.meta = root.querySelector('[data-eduai-prep-meta]');
		this.questions = root.querySelector('[data-eduai-prep-questions]');
		this.count = root.querySelector('[data-eduai-prep-count]');
		this.warn = root.querySelector('[data-eduai-prep-warn]');
		this.submit = root.querySelector('[data-eduai-prep-submit]');
		this.paperOut = root.querySelector('[data-eduai-prep-paper-out]');

		this.report = root.querySelector('[data-eduai-prep-report]');

		this.exam = null;
		this.length = 10;
		this.busy = false;

		this.bind();
	}

	Prepare.prototype.show = function (name) {
		Object.keys(this.stages).forEach(function (key) {
			this.stages[key].hidden = key !== name;
		}, this);
	};

	Prepare.prototype.bind = function () {
		var self = this;

		this.drop.addEventListener('click', function () { self.file.click(); });
		this.drop.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); self.file.click(); }
		});
		['dragenter', 'dragover'].forEach(function (ev) {
			self.drop.addEventListener(ev, function (e) { e.preventDefault(); self.drop.classList.add('is-over'); });
		});
		['dragleave', 'drop'].forEach(function (ev) {
			self.drop.addEventListener(ev, function (e) { e.preventDefault(); self.drop.classList.remove('is-over'); });
		});
		this.drop.addEventListener('drop', function (e) {
			if (e.dataTransfer.files && e.dataTransfer.files[0]) {
				self.file.files = e.dataTransfer.files;
				self.setFile(e.dataTransfer.files[0]);
			}
		});
		this.file.addEventListener('change', function () { self.setFile(self.file.files[0]); });

		this.lenPick.querySelectorAll('[data-len]').forEach(function (b) {
			b.addEventListener('click', function () {
				self.lenPick.querySelectorAll('[data-len]').forEach(function (x) { x.classList.remove('is-on'); });
				b.classList.add('is-on');
				self.length = parseInt(b.getAttribute('data-len'), 10) || 10;
			});
		});

		this.generate.addEventListener('click', function () { self.doGenerate(); });
		this.submit.addEventListener('click', function () { self.doSubmit(); });

		this.root.querySelector('[data-eduai-prep-retake]').addEventListener('click', function () {
			self.renderPaper(self.exam);
			self.show('paper');
		});
		this.root.querySelector('[data-eduai-prep-new]').addEventListener('click', function () {
			self.exam = null;
			self.setupOut.innerHTML = '';
			self.show('setup');
		});
	};

	Prepare.prototype.setFile = function (file) {
		this.drop.classList.toggle('has-file', Boolean(file));
		this.dropMsg.textContent = file
			? file.name + '  ·  ' + Math.max(1, Math.round(file.size / 1024)) + ' KB'
			: T.dropFile;
	};

	Prepare.prototype.note = function (where, cls, html) {
		where.innerHTML = '';
		where.appendChild(el('div', cls, html));
	};

	/* ------------------------------------------------------------ generate */

	Prepare.prototype.doGenerate = function () {
		var self = this;
		var file = this.file.files[0];
		var pasted = (this.text.value || '').trim();

		if (this.busy) { return; }
		if (!CFG.loggedIn) { this.note(this.setupOut, 'eduai-error', esc(T.loginPrompt)); return; }
		if (!file && pasted.length < 80) { this.note(this.setupOut, 'eduai-error', esc(T.needSource)); return; }

		var body = new FormData();
		if (file) { body.append('file', file); }
		if (pasted) { body.append('text', pasted); }
		body.append('count', String(this.length));

		this.busy = true;
		this.generate.disabled = true;
		this.note(this.setupOut, 'eduai-prep__working',
			'<div class="eduai-typing"><span></span><span></span><span></span></div><p>' +
			esc(T.generating.replace('%d', this.length)) + '</p>');

		fetch(CFG.root + '/exam', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CFG.nonce },
			body: body
		})
			.then(function (res) { return res.json().then(function (d) { return { ok: res.ok, data: d }; }); })
			.then(function (r) {
				if (!r.ok) {
					self.note(self.setupOut, 'eduai-error', esc((r.data && r.data.message) || T.error));
					return;
				}
				self.setupOut.innerHTML = '';
				self.exam = r.data;
				self.renderPaper(r.data);
				self.show('paper');
			})
			.catch(function () { self.note(self.setupOut, 'eduai-error', esc(T.error)); })
			.finally(function () { self.busy = false; self.generate.disabled = false; });
	};

	/* --------------------------------------------------------------- paper */

	Prepare.prototype.renderPaper = function (exam) {
		var self = this;
		var qs = exam.questions || [];

		this.title.textContent = exam.title || '';
		this.meta.textContent = T.paperMeta
			.replace('%1$d', qs.length)
			.replace('%2$s', exam.source_label || T.pastedText);

		this.questions.innerHTML = '';
		this.paperOut.innerHTML = '';

		var band = null;

		qs.forEach(function (q, i) {
			if (q.band !== band) {
				band = q.band;
				self.questions.appendChild(el('h3', 'eduai-prep__band', esc(T.bands[band] || band)));
			}

			var card = el('div', 'eduai-prep__q');
			card.appendChild(el('p', 'eduai-prep__qhead',
				'<span class="eduai-prep__marks">' + esc(T.marks.replace('%d', num(q.marks))) + '</span> ' +
				'Q' + esc(q.id) + '. ' + esc(q.question)));

			if (q.type === 'mcq') {
				var list = el('div', 'eduai-prep__options');
				(q.options || []).forEach(function (opt, idx) {
					var id = 'q' + q.id + '-' + idx;
					var row = el('label', 'eduai-prep__option');
					row.setAttribute('for', id);
					row.innerHTML =
						'<input type="radio" id="' + id + '" name="q' + q.id + '" value="' + idx + '">' +
						'<span class="eduai-prep__letter">' + LETTERS[idx] + '</span>' +
						'<span>' + esc(opt) + '</span>';
					list.appendChild(row);
				});
				card.appendChild(list);
			} else {
				var ta = el('textarea', 'eduai-prep__answer');
				ta.rows = 3;
				ta.setAttribute('data-short', String(q.id));
				ta.setAttribute('aria-label', 'Q' + q.id);
				card.appendChild(ta);
			}

			self.questions.appendChild(card);
		});

		this.questions.addEventListener('input', function () { self.updateCount(); });
		this.questions.addEventListener('change', function () { self.updateCount(); });
		this.updateCount();
	};

	Prepare.prototype.collect = function () {
		var answers = [];

		(this.exam.questions || []).forEach(function (q) {
			if (q.type === 'mcq') {
				var picked = this.questions.querySelector('input[name="q' + q.id + '"]:checked');
				// Omitted rather than sent as null when unanswered — the server
				// scores it zero either way and still reports a row.
				if (picked) { answers.push({ id: q.id, choice: parseInt(picked.value, 10) }); }
			} else {
				var ta = this.questions.querySelector('[data-short="' + q.id + '"]');
				var text = ta ? (ta.value || '').trim() : '';
				if (text) { answers.push({ id: q.id, text: text }); }
			}
		}, this);

		return answers;
	};

	Prepare.prototype.updateCount = function () {
		var total = (this.exam.questions || []).length;
		var done = this.collect().length;

		this.count.textContent = T.answered.replace('%1$d', done).replace('%2$d', total);
		this.warn.textContent = done < total ? T.someBlank.replace('%d', total - done) : '';
	};

	/* -------------------------------------------------------------- submit */

	Prepare.prototype.doSubmit = function () {
		var self = this;
		if (this.busy || !this.exam) { return; }

		this.busy = true;
		this.submit.disabled = true;
		this.note(this.paperOut, 'eduai-prep__working',
			'<div class="eduai-typing"><span></span><span></span><span></span></div><p>' +
			esc(T.marking) + '</p>');

		fetch(CFG.root + '/exam/' + encodeURIComponent(this.exam.exam_id) + '/submit', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify({ answers: this.collect() })
		})
			.then(function (res) { return res.json().then(function (d) { return { ok: res.ok, data: d }; }); })
			.then(function (r) {
				if (!r.ok) {
					self.note(self.paperOut, 'eduai-error', esc((r.data && r.data.message) || T.error));
					return;
				}
				self.paperOut.innerHTML = '';
				self.renderReport(r.data);
				self.show('report');
				self.root.scrollIntoView({ block: 'start', behavior: 'smooth' });
			})
			.catch(function () { self.note(self.paperOut, 'eduai-error', esc(T.error)); })
			.finally(function () { self.busy = false; self.submit.disabled = false; });
	};

	/* -------------------------------------------------------------- report */

	Prepare.prototype.renderReport = function (resp) {
		var self = this;
		var pass = resp.percent >= 50;

		var html =
			'<div class="eduai-prep__score">' +
				'<div><b>' + num(resp.score) + ' / ' + num(resp.total) + '</b>' +
				'<span>' + esc(self.exam.title || '') + '</span></div>' +
				'<span class="eduai-prep__pct ' + (pass ? 'is-pass' : 'is-fail') + '">' +
				esc(resp.percent) + '%</span>' +
			'</div>';

		html += '<div class="eduai-prep__bands">';
		['easy', 'medium', 'hard'].forEach(function (b) {
			var d = (resp.bands || {})[b];
			if (!d || !d.of) { return; }
			html += '<div class="eduai-prep__bandrow">' +
				'<b>' + esc(T.bands[b] || b) + '</b>' +
				'<span class="eduai-prep__meter"><span style="width:' +
					Math.round((d.awarded / d.of) * 100) + '%"></span></span>' +
				'<span class="eduai-prep__bandnum">' + num(d.awarded) + ' / ' + num(d.of) + '</span>' +
			'</div>';
		});
		html += '</div>';

		(resp.results || []).forEach(function (r) {
			var q = (self.exam.questions || []).filter(function (x) { return x.id === r.id; })[0] || {};

			html += '<div class="eduai-prep__q">' +
				'<p class="eduai-prep__qhead"><span class="eduai-prep__marks">' +
				esc((T.bands[r.band] || r.band) + ' · ' + (r.type === 'mcq' ? T.mcq : T.short)) +
				'</span> Q' + esc(r.id) + '. ' + esc(q.question || '') + '</p>';

			if (r.type === 'mcq') {
				// correct is a real boolean here, and only here.
				if (r.your_choice === null || r.your_choice === undefined) {
					html += mark('no', 0, r.of, T.notAnswered + ' ' +
						T.correctWas.replace('%1$s', LETTERS[r.answer_index])
							.replace('%2$s', esc((q.options || [])[r.answer_index] || '')));
				} else if (r.correct) {
					html += mark('ok', r.awarded, r.of,
						T.yourAnswerRight.replace('%s', LETTERS[r.your_choice]));
				} else {
					html += mark('no', r.awarded, r.of,
						T.youChose.replace('%s', LETTERS[r.your_choice]) + ' ' +
						T.correctWas.replace('%1$s', LETTERS[r.answer_index])
							.replace('%2$s', esc((q.options || [])[r.answer_index] || '')));
				}

				if (r.explanation) {
					html += '<p class="eduai-prep__why">' + esc(r.explanation) + '</p>';
				}
			} else {
				/*
				 * SHORT ANSWERS: `correct` is null by contract, so it is never
				 * read here. The tone comes from the marks awarded — full,
				 * partial or none — which is why a full-marks short answer
				 * carries a ✓ and never a ✗.
				 */
				var tone = r.awarded >= r.of ? 'ok' : (r.awarded > 0 ? 'part' : 'no');

				html += mark(tone, r.awarded, r.of, r.your_text ? '' : T.notAnswered);

				if (r.your_text) {
					html += '<p class="eduai-prep__yours">' + esc(r.your_text) + '</p>';
				}
				if (r.comment) {
					html += '<p class="eduai-prep__why">' + esc(r.comment) + '</p>';
				}
				if (r.expected) {
					html += '<p class="eduai-prep__scheme"><b>' + esc(T.markScheme) + '</b> ' +
						esc(r.expected) + '</p>';
				}
			}

			html += '</div>';
		});

		this.report.innerHTML = html;
	};

	/* One mark line. tone is ok | part | no — never derived from `correct`
	   for short answers. */
	function mark(tone, awarded, of, tail) {
		var glyph = tone === 'ok' ? '✓' : (tone === 'part' ? '◐' : '✗');
		return '<p class="eduai-prep__mark is-' + tone + '">' + glyph + ' ' +
			num(awarded) + ' / ' + num(of) +
			(tail ? ' <span>— ' + tail + '</span>' : '') + '</p>';
	}

	function init() {
		document.querySelectorAll('[data-eduai-prep]').forEach(function (node) {
			if (node.__eduaiPrep) { return; }
			node.__eduaiPrep = new Prepare(node);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
