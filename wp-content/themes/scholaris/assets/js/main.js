/* Scholaris theme behaviour: colour scheme, mobile nav, scroll reveal. */
(function () {
	'use strict';

	/* ---------------------------------------------------------------- theme */
	var root = document.documentElement;
	var toggle = document.querySelector('[data-theme-toggle]');

	function paintToggle() {
		if (!toggle) { return; }
		var dark = root.getAttribute('data-theme') === 'dark';
		var sun = toggle.querySelector('[data-icon="sun"]');
		var moon = toggle.querySelector('[data-icon="moon"]');
		if (sun) { sun.hidden = dark; }
		if (moon) { moon.hidden = !dark; }
		toggle.setAttribute('aria-pressed', String(dark));
	}

	if (toggle) {
		paintToggle();
		toggle.addEventListener('click', function () {
			var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
			root.setAttribute('data-theme', next);
			try { localStorage.setItem('scholaris-theme', next); } catch (e) { /* private mode */ }
			paintToggle();
			document.dispatchEvent(new CustomEvent('scholaris:themechange', { detail: { theme: next } }));
		});
	}

	/* ------------------------------------------------------------ mobile nav */
	var navToggle = document.querySelector('.nav-toggle');
	var nav = document.getElementById('primary-nav');

	if (navToggle && nav) {
		navToggle.addEventListener('click', function () {
			var open = nav.classList.toggle('is-open');
			navToggle.setAttribute('aria-expanded', String(open));
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && nav.classList.contains('is-open')) {
				nav.classList.remove('is-open');
				navToggle.setAttribute('aria-expanded', 'false');
				navToggle.focus();
			}
		});
	}

	/* --------------------------------------------------------- scroll reveal */
	var revealables = document.querySelectorAll('.reveal');

	if (revealables.length && 'IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('is-visible');
					io.unobserve(entry.target);
				}
			});
		}, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

		revealables.forEach(function (el) { io.observe(el); });
	} else {
		revealables.forEach(function (el) { el.classList.add('is-visible'); });
	}

	/* ------------------------------------------------- count-up on stat tiles */
	document.querySelectorAll('[data-countup]').forEach(function (el) {
		var target = parseFloat(el.getAttribute('data-countup'));
		if (isNaN(target)) { return; }
		if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

		var suffix = el.getAttribute('data-suffix') || '';
		var start = null;
		var duration = 900;

		function step(ts) {
			if (start === null) { start = ts; }
			var p = Math.min((ts - start) / duration, 1);
			var eased = 1 - Math.pow(1 - p, 3);
			el.textContent = (Math.round(target * eased * 10) / 10).toString().replace(/\.0$/, '') + suffix;
			if (p < 1) { requestAnimationFrame(step); }
		}

		requestAnimationFrame(step);
	});
}());
