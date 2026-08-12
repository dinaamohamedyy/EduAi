/**
 * EduAI Assistant — front-end controller.
 *
 * Every element carrying [data-eduai-app] becomes an independent instance, so
 * the floating widget and an inline panel can coexist on the same page.
 */
(function () {
	'use strict';

	var CFG = window.EduAIConfig || {};
	var T = CFG.i18n || {};

	/* ------------------------------------------------------------- helpers */

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

	function threadKey() {
		try {
			var k = sessionStorage.getItem('eduai-thread');
			if (!k) {
				k = Math.random().toString(36).slice(2, 12) + Date.now().toString(36).slice(-4);
				sessionStorage.setItem('eduai-thread', k);
			}
			return k;
		} catch (e) {
			return 'tmp' + Date.now().toString(36);
		}
	}

	// The agent outlives the thread — a student who picks "Critical thinking"
	// means it for the session after this one too, so it lives in localStorage
	// rather than sessionStorage.
	function agentKey() {
		try {
			return localStorage.getItem('eduai-agent') || CFG.defaultAgent || '';
		} catch (e) {
			return CFG.defaultAgent || '';
		}
	}

	function agentDescription(id) {
		var found = (CFG.agents || []).filter(function (a) { return a.id === id; })[0];
		return found ? (found.description || '') : '';
	}

	/* ------------------------------------------------------- speech output */

	var Speech = {
		supported: 'speechSynthesis' in window,
		current: null,

		speak: function (text, onEnd) {
			if (!this.supported) { return false; }
			this.stop();

			var u = new SpeechSynthesisUtterance(text);
			u.lang = CFG.ttsLang || 'en-US';
			u.rate = 1;
			u.pitch = 1;
			u.onend = onEnd;
			u.onerror = onEnd;

			this.current = u;
			window.speechSynthesis.speak(u);
			return true;
		},

		stop: function () {
			if (this.supported) { window.speechSynthesis.cancel(); }
			this.current = null;
		}
	};

	/* -------------------------------------------------------------- widget */

	function Assistant(root) {
		this.root = root;
		this.log = root.querySelector('[data-eduai-log]');
		this.form = root.querySelector('[data-eduai-form]');
		this.input = root.querySelector('[data-eduai-input]');
		this.send = root.querySelector('[data-eduai-send]');
		this.mic = root.querySelector('[data-eduai-mic]');
		this.status = root.querySelector('[data-eduai-status]');
		this.suggestions = root.querySelector('[data-eduai-suggestions]');
		this.agentSelect = root.querySelector('[data-eduai-agent]');
		this.agentHint = root.querySelector('[data-eduai-agenthint]');
		this.postId = parseInt(root.getAttribute('data-post-id'), 10) || 0;
		this.thread = threadKey();
		this.agent = agentKey();
		this.busy = false;
		this.recognition = null;
		this.idleStatus = this.status ? this.status.textContent : '';

		this.bindTabs();
		this.bindAgent();
		this.bindChat();
		this.bindSummary();
		this.renderGreeting();
		this.renderSuggestions();
	}

	/* -------------------------------------------------------------- agents */

	Assistant.prototype.bindAgent = function () {
		var self = this;
		if (!this.agentSelect) { return; }

		// The stored choice may name an agent whose file has since been removed.
		if (this.agentSelect.querySelector('option[value="' + this.agent + '"]')) {
			this.agentSelect.value = this.agent;
		} else {
			this.agent = this.agentSelect.value;
		}

		this.showAgentHint();

		this.agentSelect.addEventListener('change', function () {
			self.agent = self.agentSelect.value;

			try { localStorage.setItem('eduai-agent', self.agent); } catch (e) { /* private mode */ }

			self.showAgentHint();
			if (self.input) { self.input.focus(); }
		});
	};

	Assistant.prototype.showAgentHint = function () {
		if (!this.agentHint) { return; }
		this.agentHint.textContent = agentDescription(this.agent);
	};

	/* ---------------------------------------------------------------- tabs */

	Assistant.prototype.bindTabs = function () {
		var self = this;
		var tabs = this.root.querySelectorAll('[data-eduai-tab]');

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var name = tab.getAttribute('data-eduai-tab');

				tabs.forEach(function (t) {
					var on = t === tab;
					t.classList.toggle('is-active', on);
					t.setAttribute('aria-selected', String(on));
				});

				self.root.querySelectorAll('[data-eduai-pane]').forEach(function (pane) {
					pane.classList.toggle('is-active', pane.getAttribute('data-eduai-pane') === name);
				});
			});
		});

		// Honour a container asking to open on a specific tab.
		var wrapper = this.root.closest('[data-eduai-default-tab]');
		if (wrapper) {
			var want = wrapper.getAttribute('data-eduai-default-tab');
			var target = this.root.querySelector('[data-eduai-tab="' + want + '"]');
			if (target) { target.click(); }
		}
	};

	/* --------------------------------------------------------------- chat  */

	Assistant.prototype.renderGreeting = function () {
		if (!this.log || this.log.children.length) { return; }

		var wrap = el('div', 'eduai-empty');
		wrap.innerHTML =
			'<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" ' +
			'stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6' +
			'L5.5 9.5l4.6-1.9L12 3z"/></svg>' +
			'<div>' + escapeHtml(CFG.greeting || '') + '</div>';

		this.log.appendChild(wrap);
	};

	Assistant.prototype.renderSuggestions = function () {
		var self = this;
		if (!this.suggestions) { return; }

		this.suggestions.innerHTML = '';
		(CFG.suggestions || []).forEach(function (text) {
			var b = el('button', null, escapeHtml(text));
			b.type = 'button';
			b.addEventListener('click', function () {
				self.input.value = text;
				self.submit();
			});
			self.suggestions.appendChild(b);
		});
	};

	Assistant.prototype.bindChat = function () {
		var self = this;
		if (!this.form) { return; }

		this.form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.submit();
		});

		// Enter sends, Shift+Enter inserts a newline.
		this.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				self.submit();
			}
		});

		// Auto-grow the textarea.
		this.input.addEventListener('input', function () {
			self.input.style.height = 'auto';
			self.input.style.height = Math.min(self.input.scrollHeight, 130) + 'px';
		});

		var newBtn = this.root.querySelector('[data-eduai-new]');
		if (newBtn) {
			newBtn.addEventListener('click', function () {
				Speech.stop();
				try { sessionStorage.removeItem('eduai-thread'); } catch (err) { /* noop */ }
				self.thread = threadKey();
				self.log.innerHTML = '';
				self.renderGreeting();
				self.renderSuggestions();
				self.input.focus();
			});
		}

		if (this.mic) { this.bindMic(); }
	};

	Assistant.prototype.submit = function () {
		var text = (this.input.value || '').trim();
		if (!text || this.busy) { return; }

		if (!CFG.loggedIn) {
			this.pushError(T.loginPrompt || 'Please sign in.');
			return;
		}

		this.input.value = '';
		this.input.style.height = 'auto';
		if (this.suggestions) { this.suggestions.innerHTML = ''; }

		var empty = this.log.querySelector('.eduai-empty');
		if (empty) { empty.remove(); }

		this.pushMessage('user', escapeHtml(text).replace(/\n/g, '<br>'));
		this.ask(text);
	};

	Assistant.prototype.ask = function (text) {
		var self = this;
		var typing = this.pushTyping();

		this.setBusy(true);

		fetch(CFG.root + '/chat', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce
			},
			body: JSON.stringify({
				message: text,
				thread_id: self.thread,
				post_id: self.postId,
				agent: self.agent || '',
				/* The scope the server resolved and gated, echoed back. Read
				   from CFG and never from location.search: ?source= IS the
				   authorisation decision, so re-deriving it here would be a
				   second source of truth and would let anyone send an id the
				   server refused. 0 when unscoped, which is the whole story —
				   "no parameter" and "refused" arrive identically on purpose,
				   because telling them apart would reveal whether a given post
				   exists and is closed.

				   This is the line the owner was missing: the link carried
				   ?source= and chat.js never looked, so Ask rendered the
				   ordinary assistant on a page that had already resolved a
				   lesson. Summarise read it; this did not. */
				source: (CFG.scope && CFG.scope.id) ? parseInt(CFG.scope.id, 10) || 0 : 0
			})
		})
			.then(function (res) {
				return res.json().then(function (data) {
					return { ok: res.ok, status: res.status, data: data };
				});
			})
			.then(function (r) {
				typing.remove();

				if (!r.ok) {
					self.pushError(r.data && r.data.message ? r.data.message : (T.error || 'Error'));
					return;
				}

				self.pushMessage('bot', r.data.html || escapeHtml(r.data.reply || ''), {
					sources: r.data.sources || [],
					speakText: r.data.reply || ''
				});
			})
			.catch(function () {
				typing.remove();
				self.pushError(T.error || 'Network error');
			})
			.finally(function () {
				self.setBusy(false);
			});
	};

	Assistant.prototype.setBusy = function (state) {
		this.busy = state;
		this.root.classList.toggle('is-busy', state);
		if (this.send) { this.send.disabled = state; }
		if (this.status) {
			this.status.textContent = state ? (T.thinking || 'Thinking…') : this.idleStatus;
		}
	};

	Assistant.prototype.pushMessage = function (who, html, opts) {
		opts = opts || {};

		var msg = el('div', 'eduai-msg eduai-msg--' + (who === 'user' ? 'user' : 'bot'));
		var bubble = el('div', 'eduai-bubble', html);

		if (opts.sources && opts.sources.length) {
			var box = el('div', 'eduai-sources');
			box.appendChild(el('strong', null, escapeHtml(T.sources || 'Sources')));

			opts.sources.forEach(function (s) {
				if (!s.url) { return; }
				var a = el('a', null, escapeHtml(s.title || s.url));
				a.href = s.url;
				a.target = '_blank';
				a.rel = 'noopener';
				box.appendChild(a);
			});

			bubble.appendChild(box);
		}

		msg.appendChild(bubble);

		if (who === 'bot' && opts.speakText) {
			bubble.appendChild(this.buildTools(opts.speakText));
		}

		this.log.appendChild(msg);
		this.scroll();

		return msg;
	};

	Assistant.prototype.buildTools = function (plainText) {
		var tools = el('div', 'eduai-msg__tools');

		var copy = el('button', null, escapeHtml(T.copy || 'Copy'));
		copy.type = 'button';
		copy.addEventListener('click', function () {
			if (navigator.clipboard) {
				navigator.clipboard.writeText(plainText).then(function () {
					copy.textContent = T.copied || 'Copied';
					setTimeout(function () { copy.textContent = T.copy || 'Copy'; }, 1600);
				});
			}
		});
		tools.appendChild(copy);

		if (CFG.ttsEnabled && Speech.supported) {
			var speak = el('button', null, '🔊 ' + escapeHtml(T.speak || 'Read aloud'));
			speak.type = 'button';
			speak.addEventListener('click', function () {
				if (speak.classList.contains('is-active')) {
					Speech.stop();
					speak.classList.remove('is-active');
					speak.innerHTML = '🔊 ' + escapeHtml(T.speak || 'Read aloud');
					return;
				}

				speak.classList.add('is-active');
				speak.innerHTML = '⏹ ' + escapeHtml(T.stopSpeaking || 'Stop');

				Speech.speak(plainText, function () {
					speak.classList.remove('is-active');
					speak.innerHTML = '🔊 ' + escapeHtml(T.speak || 'Read aloud');
				});
			});
			tools.appendChild(speak);
		}

		return tools;
	};

	Assistant.prototype.pushTyping = function () {
		var msg = el('div', 'eduai-msg eduai-msg--bot');
		var bubble = el('div', 'eduai-bubble');
		bubble.appendChild(el('div', 'eduai-typing', '<span></span><span></span><span></span>'));
		msg.appendChild(bubble);
		this.log.appendChild(msg);
		this.scroll();
		return msg;
	};

	Assistant.prototype.pushError = function (text) {
		var msg = el('div', 'eduai-msg eduai-msg--bot');
		msg.appendChild(el('div', 'eduai-error', escapeHtml(text)));
		this.log.appendChild(msg);
		this.scroll();
	};

	Assistant.prototype.scroll = function () {
		var log = this.log;
		requestAnimationFrame(function () { log.scrollTop = log.scrollHeight; });
	};

	/* ------------------------------------------------------- speech input  */

	Assistant.prototype.bindMic = function () {
		var self = this;
		var SR = window.SpeechRecognition || window.webkitSpeechRecognition;

		if (!SR) {
			this.mic.addEventListener('click', function () {
				self.pushError(T.noVoice || 'Speech recognition is not supported in this browser.');
			});
			return;
		}

		var rec = new SR();
		rec.lang = CFG.ttsLang || 'en-US';
		rec.interimResults = true;
		rec.continuous = false;
		rec.maxAlternatives = 1;

		var finalText = '';

		rec.onstart = function () {
			finalText = '';
			self.mic.classList.add('is-listening');
			self.mic.setAttribute('aria-label', T.stopListening || 'Stop listening');
			if (self.status) { self.status.textContent = T.listening || 'Listening…'; }
		};

		rec.onresult = function (e) {
			var interim = '';
			for (var i = e.resultIndex; i < e.results.length; i++) {
				var chunk = e.results[i][0].transcript;
				if (e.results[i].isFinal) { finalText += chunk; } else { interim += chunk; }
			}
			self.input.value = (finalText + interim).trim();
			self.input.dispatchEvent(new Event('input'));
		};

		rec.onerror = function (e) {
			self.mic.classList.remove('is-listening');
			if (e.error !== 'aborted' && e.error !== 'no-speech') {
				self.pushError(T.noVoice || 'Microphone error.');
			}
		};

		rec.onend = function () {
			self.mic.classList.remove('is-listening');
			self.mic.setAttribute('aria-label', T.speak || 'Ask by voice');
			self.setBusy(self.busy);

			// Auto-send a completed dictation.
			if (finalText.trim().length > 2) { self.submit(); }
		};

		this.recognition = rec;

		this.mic.addEventListener('click', function () {
			if (self.mic.classList.contains('is-listening')) {
				rec.stop();
				return;
			}
			Speech.stop();
			try { rec.start(); } catch (err) { /* already started */ }
		});
	};

	/* ------------------------------------------------------------ summary  */

	Assistant.prototype.bindSummary = function () {
		var self = this;
		var form = this.root.querySelector('[data-eduai-summary-form]');
		if (!form) { return; }

		var drop = form.querySelector('[data-eduai-drop]');
		var fileInput = form.querySelector('[data-eduai-file]');
		var dropMsg = form.querySelector('[data-eduai-dropmsg]');
		var textArea = form.querySelector('[data-eduai-summary-text]');
		var styleSel = form.querySelector('[data-eduai-style]');
		var button = form.querySelector('[data-eduai-summarize]');
		var result = this.root.querySelector('[data-eduai-summary-result]');

		function setFileLabel(name) {
			drop.classList.toggle('has-file', Boolean(name));
			dropMsg.textContent = name || (T.dropFile || 'Drop a file here, or click to choose');
		}

		drop.addEventListener('click', function () { fileInput.click(); });
		drop.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
		});

		['dragenter', 'dragover'].forEach(function (evt) {
			drop.addEventListener(evt, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
		});
		['dragleave', 'drop'].forEach(function (evt) {
			drop.addEventListener(evt, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
		});

		drop.addEventListener('drop', function (e) {
			if (e.dataTransfer.files && e.dataTransfer.files[0]) {
				fileInput.files = e.dataTransfer.files;
				setFileLabel(e.dataTransfer.files[0].name);
			}
		});

		fileInput.addEventListener('change', function () {
			setFileLabel(fileInput.files[0] ? fileInput.files[0].name : '');
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			var file = fileInput.files[0];
			var pasted = (textArea.value || '').trim();

			if (!file && pasted.length < 80) {
				result.hidden = false;
				result.innerHTML = '<div class="eduai-error">' +
					escapeHtml('Attach a file, or paste at least a paragraph of the lecture.') + '</div>';
				return;
			}

			if (file && file.size > (CFG.maxUploadMb || 20) * 1024 * 1024) {
				result.hidden = false;
				result.innerHTML = '<div class="eduai-error">' +
					escapeHtml('That file is larger than ' + (CFG.maxUploadMb || 20) + ' MB.') + '</div>';
				return;
			}

			var body = new FormData();
			if (file) { body.append('file', file); }
			if (pasted) { body.append('text', pasted); }
			body.append('style', styleSel.value);

			button.disabled = true;
			button.textContent = T.thinking || 'Working…';
			result.hidden = false;
			result.innerHTML = '<div class="eduai-typing"><span></span><span></span><span></span></div>';

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
						result.innerHTML = '<div class="eduai-error">' +
							escapeHtml(r.data && r.data.message ? r.data.message : (T.error || 'Error')) + '</div>';
						return;
					}

					result.innerHTML = '';
					result.appendChild(self.buildTools(r.data.summary || ''));
					result.appendChild(el('div', null, r.data.html || ''));
				})
				.catch(function () {
					result.innerHTML = '<div class="eduai-error">' + escapeHtml(T.error || 'Network error') + '</div>';
				})
				.finally(function () {
					button.disabled = false;
					button.textContent = T.summariseBtn || 'Summarise';
				});
		});
	};

	/* ------------------------------------------------------------ launcher */

	function bindLauncher() {
		var launcher = document.querySelector('[data-eduai-launcher]');
		var dock = document.querySelector('[data-eduai-dock]');
		if (!launcher || !dock) { return; }

		function open() {
			dock.hidden = false;
			launcher.setAttribute('aria-expanded', 'true');
			var input = dock.querySelector('[data-eduai-input]');
			if (input) { setTimeout(function () { input.focus(); }, 120); }
		}

		function close() {
			dock.hidden = true;
			launcher.setAttribute('aria-expanded', 'false');
			Speech.stop();
			launcher.focus();
		}

		launcher.addEventListener('click', open);

		dock.querySelectorAll('[data-eduai-close]').forEach(function (btn) {
			btn.addEventListener('click', close);
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !dock.hidden) { close(); }
		});

		// Any [data-eduai-open] element on the page opens the assistant.
		document.querySelectorAll('[data-eduai-open]').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				open();

				var tab = btn.getAttribute('data-eduai-open');
				if (tab === 'summary') {
					var target = dock.querySelector('[data-eduai-tab="summary"]');
					if (target) { target.click(); }
				}
			});
		});
	}

	/* ----------------------------------------------------------------- go  */

	function init() {
		document.querySelectorAll('[data-eduai-app]').forEach(function (node) {
			if (node.__eduai) { return; }
			node.__eduai = new Assistant(node);
		});
		bindLauncher();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
