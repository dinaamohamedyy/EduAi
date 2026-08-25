/**
 * EduAI Enquiry — the sales/enquiry widget.
 *
 * Renders one documented message envelope. Everything visible here is built
 * from a field the engine sent; nothing is inferred, defaulted to a plausible
 * value, or filled in when absent. A course fee and a start date are promises,
 * and this widget would rather print "Not listed" four times than imply one.
 *
 * STUB MODE: set EQConfig.stub = true and it answers from the fixtures at the
 * bottom of this file with a realistic delay. That is how the UI was built and
 * measured without spending model tokens.
 */
(function () {
  'use strict';

  var CFG = window.EQConfig || {};
  var STUB = !!CFG.stub;

  /* ------------------------------------------------------------- strings -- */
  /*
   * Both languages live here rather than being fetched, so the widget can open
   * and switch language before any request completes. Arabic is the product's
   * second language, not a machine translation of the first — these are the
   * strings a visitor would expect to read, not a gloss of the English.
   */
  var STR = {
    en: {
      dir: 'ltr', label: 'English',
      launch: 'Ask about courses',
      title: 'Course enquiries',
      sub: 'Ask about courses, fees and how to join',
      close: 'Close',
      send: 'Send',
      placeholder: 'Type your question…',
      typing: 'Assistant is typing',
      human: 'Talk to a person',
      humanTitle: 'A colleague is joining',
      humanNote: 'Anything you type now goes to them, not to the assistant.',
      notListed: 'Not listed',
      fDuration: 'Duration', fFormat: 'Format', fPrice: 'Price', fSchedule: 'Starts',
      view: 'View course',
      required: 'This field is required',
      email: 'Enter an email address so we can reply',
      submit: 'Send my details',
      sent: 'Thank you — someone will be in touch.',
      failed: 'That did not send. Try again in a moment.',
      switched: 'Language switched to English.',
    },
    ar: {
      dir: 'rtl', label: 'العربية',
      launch: 'اسأل عن الدورات',
      title: 'استفسارات الدورات',
      sub: 'اسأل عن الدورات والرسوم وطريقة الانضمام',
      close: 'إغلاق',
      send: 'إرسال',
      placeholder: 'اكتب سؤالك…',
      typing: 'جارٍ الكتابة',
      human: 'التحدث إلى موظف',
      humanTitle: 'سينضم أحد الزملاء',
      humanNote: 'كل ما تكتبه الآن يصل إليه وليس إلى المساعد.',
      notListed: 'غير محدد',
      fDuration: 'المدة', fFormat: 'النظام', fPrice: 'الرسوم', fSchedule: 'البداية',
      view: 'عرض الدورة',
      required: 'هذا الحقل مطلوب',
      email: 'أدخل بريدًا إلكترونيًا حتى نتمكن من الرد',
      submit: 'أرسل بياناتي',
      sent: 'شكرًا لك — سيتواصل معك أحد الزملاء.',
      failed: 'لم يتم الإرسال. حاول مرة أخرى بعد قليل.',
      switched: 'تم تغيير اللغة إلى العربية.',
    },
  };

  var lang = (CFG.lang === 'ar') ? 'ar' : 'en';
  var t = function (k) { return STR[lang][k]; };

  /* --------------------------------------------------------------- utils -- */
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    // textContent, never innerHTML: every string below can originate from the
    // model or from a course title, and one unescaped field is the whole hole.
    if (text != null) { n.textContent = text; }
    return n;
  }

  function svg(paths, size) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('width', size || 18);
    s.setAttribute('height', size || 18);
    s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '2');
    s.setAttribute('stroke-linecap', 'round');
    s.setAttribute('stroke-linejoin', 'round');
    s.setAttribute('aria-hidden', 'true');
    paths.forEach(function (d) {
      var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      p.setAttribute('d', d);
      s.appendChild(p);
    });
    return s;
  }

  /* ---------------------------------------------------------------- root -- */
  var root = el('div', 'eq-root');
  root.setAttribute('data-eq-root', '');

  var launcher = el('button', 'eq-launcher');
  launcher.type = 'button';
  launcher.setAttribute('aria-expanded', 'false');
  var launcherLabel = el('span', null, t('launch'));
  launcher.appendChild(svg(['M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'], 18));
  launcher.appendChild(launcherLabel);

  var panel = el('div', 'eq-panel');
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'false');

  /* ---------------------------------------------------------------- head -- */
  var head = el('div', 'eq-head');
  var headText = el('div');
  var headTitle = el('p', 'eq-head__title', t('title'));
  var headSub = el('p', 'eq-head__sub', t('sub'));
  headText.appendChild(headTitle);
  headText.appendChild(headSub);

  var headActions = el('div', 'eq-head__actions');
  var langBtn = el('button', 'eq-lang');
  langBtn.type = 'button';
  var closeBtn = el('button', 'eq-iconbtn');
  closeBtn.type = 'button';
  closeBtn.appendChild(svg(['M18 6 6 18', 'M6 6l12 12'], 16));
  headActions.appendChild(langBtn);
  headActions.appendChild(closeBtn);

  head.appendChild(headText);
  head.appendChild(headActions);

  /* ----------------------------------------------------------------- log -- */
  var log = el('div', 'eq-log');
  log.setAttribute('role', 'log');
  // polite, not assertive: a reply arriving must not cut across whatever the
  // visitor is reading or typing.
  log.setAttribute('aria-live', 'polite');
  log.setAttribute('aria-relevant', 'additions');
  log.setAttribute('tabindex', '0');

  var status = el('p', 'eq-sr');
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');

  /* ------------------------------------------------------------ composer -- */
  var composer = el('form', 'eq-composer');
  var input = el('textarea');
  input.rows = 1;
  var sendBtn = el('button', 'eq-send');
  sendBtn.type = 'submit';
  sendBtn.appendChild(svg(['m22 2-7 20-4-9-9-4Z', 'M22 2 11 13'], 17));
  composer.appendChild(input);
  composer.appendChild(sendBtn);

  panel.appendChild(head);
  panel.appendChild(log);
  panel.appendChild(status);
  panel.appendChild(composer);
  root.appendChild(launcher);
  root.appendChild(panel);

  /* ------------------------------------------------------------ language -- */
  /*
   * Direction is applied to the LOG and the COMPOSER, not only to the root.
   * Existing messages keep the direction they were written in, so an Arabic
   * reply above an English one still reads correctly after a switch — the
   * thread is a record of what was said, and retro-flipping it would be a lie
   * about which language each turn happened in.
   */
  function applyLang() {
    var d = STR[lang].dir;
    var other = lang === 'en' ? 'ar' : 'en';
    launcherLabel.textContent = t('launch');
    launcher.setAttribute('aria-label', t('launch'));
    headTitle.textContent = t('title');
    headSub.textContent = t('sub');
    closeBtn.setAttribute('aria-label', t('close'));
    sendBtn.setAttribute('aria-label', t('send'));
    input.setAttribute('placeholder', t('placeholder'));
    input.setAttribute('aria-label', t('placeholder'));
    // The toggle shows the language you would GET, in its own script.
    langBtn.textContent = STR[other].label;
    langBtn.setAttribute('aria-label', STR[other].label);
    langBtn.setAttribute('lang', other);
    panel.setAttribute('lang', lang);
    panel.setAttribute('dir', d);
    head.setAttribute('dir', d);
    composer.setAttribute('dir', d);
    log.setAttribute('dir', d);
    root.style.setProperty('--eq-dir', d);
  }

  langBtn.addEventListener('click', function () {
    lang = (lang === 'en') ? 'ar' : 'en';
    applyLang();
    status.textContent = t('switched');
    if (CFG.onLang) { CFG.onLang(lang); }
  });

  /* -------------------------------------------------------------- render -- */
  function bubble(who, text, msgLang) {
    var wrap = el('div', 'eq-msg eq-msg--' + who);
    var b = el('div', 'eq-bubble', text);
    // Per message, so a mixed thread stays readable both ways.
    var l = msgLang || lang;
    b.setAttribute('lang', l);
    b.setAttribute('dir', STR[l] ? STR[l].dir : 'auto');
    wrap.appendChild(b);
    return wrap;
  }

  /*
   * A card row. `value` of null means the engine explicitly does not know —
   * which is different from a key being absent, and the envelope guarantees
   * every field is present so those two can never be confused here.
   */
  function fact(key, value) {
    var row = el('div', 'eq-fact');
    row.appendChild(el('span', 'eq-fact__key', key));
    if (value == null || value === '') {
      var u = el('span', 'eq-fact__val eq-fact__val--unknown', t('notListed'));
      u.setAttribute('dir', 'auto');
      row.appendChild(u);
    } else {
      var val = el('span', 'eq-fact__val', String(value));
      val.setAttribute('dir', 'auto');
      row.appendChild(val);
    }
    return row;
  }

  function courseCard(card) {
    var c = el('article', 'eq-card');
    /*
     * EVERY CARD FIELD CARRIES ITS OWN DIRECTION.
     *
     * The card block takes its dir from the message language, and the fields
     * used to inherit it. That is wrong whenever a field is written in the
     * other language — which here is the normal case, not the exception: all
     * four course titles on this site are English, so an Arabic conversation
     * renders English titles and descriptions inside an RTL box.
     *
     * The symptom is bidi reordering of the neutral characters at the edges.
     * "Covers Linear Regression and LR." rendered as ".Covers Linear
     * Regression and LR" — the full stop jumped to the visual start, because a
     * trailing neutral takes the direction of the PARAGRAPH rather than of the
     * text it follows. Invisible in the string; only visible on screen.
     *
     * dir="auto" asks the browser to take each field's direction from its own
     * first strong character, so an English title is laid out LTR inside an
     * otherwise RTL card and an Arabic one is not disturbed.
     *
     * Deliberately NOT applied to the message bubble: a recommendation reads
     * "Machine Learning: يُغطي…", whose first strong character is Latin, so
     * dir="auto" would lay out a whole Arabic sentence left-to-right. The
     * bubble knows its language from the envelope and should keep using it.
     */
    var h = el('p', 'eq-card__title');
    h.setAttribute('dir', 'auto');
    if (card.url) {
      var a = el('a', null, card.title || '');
      a.href = card.url;
      h.appendChild(a);
    } else {
      h.textContent = card.title || '';
    }
    c.appendChild(h);

    // Description is the one field allowed to be absent rather than "Not
    // listed": a missing sentence is not a fact anyone was promised, and a row
    // reading "Description: not listed" is noise rather than honesty.
    if (card.description) {
      var d = el('p', 'eq-card__desc', card.description);
      d.setAttribute('dir', 'auto');
      c.appendChild(d);
    }

    var facts = el('div', 'eq-card__facts');
    facts.appendChild(fact(t('fDuration'), card.duration));
    facts.appendChild(fact(t('fFormat'), card.format));
    facts.appendChild(fact(t('fPrice'), card.price));
    facts.appendChild(fact(t('fSchedule'), card.schedule));
    c.appendChild(facts);

    if (card.url) {
      var cta = el('div', 'eq-card__cta');
      var link = el('a', 'eq-btn', card.cta || t('view'));
      link.href = card.url;
      cta.appendChild(link);
      c.appendChild(cta);
    }
    return c;
  }

  function chipRow(chips, onPick) {
    var row = el('div', 'eq-chips');
    chips.forEach(function (chip) {
      var b = el('button', 'eq-chip', chip.label);
      b.type = 'button';
      b.addEventListener('click', function () {
        // Once a choice is taken the row is spent — leaving them live invites
        // a second answer to a question already answered.
        Array.prototype.forEach.call(row.querySelectorAll('.eq-chip'), function (x) { x.disabled = true; });
        onPick(chip);
      });
      row.appendChild(b);
    });
    return row;
  }

  function leadForm(form, onSubmit) {
    var f = el('form', 'eq-form');
    f.setAttribute('novalidate', '');
    if (form.title) { f.appendChild(el('p', 'eq-form__title', form.title)); }

    var fields = [];
    (form.fields || []).forEach(function (spec) {
      var wrap = el('div', 'eq-field');
      var id = 'eq-f-' + spec.name;
      var lab = el('label', null, spec.label || spec.name);
      lab.setAttribute('for', id);
      var inp = el(spec.type === 'textarea' ? 'textarea' : 'input');
      if (spec.type !== 'textarea') { inp.type = spec.type || 'text'; }
      inp.id = id;
      inp.name = spec.name;
      if (spec.autocomplete) { inp.setAttribute('autocomplete', spec.autocomplete); }
      if (spec.required) { inp.setAttribute('aria-required', 'true'); }
      var err = el('p', 'eq-field__err');
      err.id = id + '-err';
      err.hidden = true;
      inp.setAttribute('aria-describedby', err.id);
      wrap.appendChild(lab); wrap.appendChild(inp); wrap.appendChild(err);
      f.appendChild(wrap);
      fields.push({ spec: spec, input: inp, err: err, wrap: wrap });
    });

    if (form.consent) {
      var cw = el('label', 'eq-consent');
      var cb = el('input');
      cb.type = 'checkbox';
      cb.name = 'consent';
      cw.appendChild(cb);
      cw.appendChild(el('span', null, form.consent));
      f.appendChild(cw);
      fields.push({ spec: { name: 'consent', required: true, label: form.consent }, input: cb, err: null, wrap: cw });
    }

    var submit = el('button', 'eq-btn', form.submit || t('submit'));
    submit.type = 'submit';
    f.appendChild(submit);

    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = {};
      var firstBad = null;
      fields.forEach(function (fld) {
        var val = fld.input.type === 'checkbox' ? fld.input.checked : fld.input.value.trim();
        var bad = '';
        if (fld.spec.required && (val === '' || val === false)) { bad = t('required'); }
        else if (fld.spec.type === 'email' && val && val.indexOf('@') < 1) { bad = t('email'); }
        if (fld.err) {
          fld.err.textContent = bad;
          fld.err.hidden = !bad;
        }
        fld.wrap.classList.toggle('eq-field--invalid', !!bad);
        fld.input.setAttribute('aria-invalid', bad ? 'true' : 'false');
        if (bad && !firstBad) { firstBad = fld.input; }
        payload[fld.spec.name] = val;
      });
      if (firstBad) { firstBad.focus(); return; }
      submit.disabled = true;
      onSubmit(payload, f);
    });
    return f;
  }

  function handoffBlock(meta) {
    var h = el('div', 'eq-handoff');
    h.appendChild(el('span', 'eq-handoff__title', (meta && meta.title) || t('humanTitle')));
    h.appendChild(el('span', 'eq-handoff__note', (meta && meta.note) || t('humanNote')));
    return h;
  }

  /* ------------------------------------------------------------ envelope -- */
  /*
   * One envelope, rendered in order: text, then cards, then chips, then form,
   * then handoff. Anything the engine omits simply does not appear — but a
   * card FIELD it omits would be a bug, which is why the contract says every
   * card field is always present and unknown values are explicitly null.
   */
  function renderEnvelope(env) {
    var frag = document.createDocumentFragment();
    var msgLang = env.lang || lang;

    if (env.text) { frag.appendChild(bubble('bot', env.text, msgLang)); }

    /*
     * TWO-PHASE: the cards are known without asking a model, the sentence
     * about them is not. Every other path answers in 130-288ms; only the
     * written recommendation waits on someone else's server, at 1.4-2.1s.
     *
     * So phase one paints the cards now and reserves the slot the sentence
     * will occupy — reserved BEFORE the cards are appended, so when the text
     * arrives it fills a space that already existed and nothing below it
     * moves. Filling a gap is invisible; inserting one shifts the cards a
     * visitor may already be reading.
     */
    var slot = null;

    if (env.meta && env.meta.follow_up && !env.text) {
      slot = el('div', 'eq-msg eq-msg--bot');
      var pend = el('div', 'eq-bubble eq-typing');
      pend.setAttribute('aria-label', t('typing'));
      pend.appendChild(el('span')); pend.appendChild(el('span')); pend.appendChild(el('span'));
      slot.appendChild(pend);
      frag.appendChild(slot);
    }

    if (env.cards && env.cards.length) {
      var wrap = el('div', 'eq-msg eq-msg--bot');
      var cards = el('div', 'eq-cards');
      cards.setAttribute('dir', STR[msgLang] ? STR[msgLang].dir : 'auto');
      cards.setAttribute('lang', msgLang);
      env.cards.forEach(function (c) { cards.appendChild(courseCard(c)); });
      wrap.appendChild(cards);
      frag.appendChild(wrap);
    }

    if (env.chips && env.chips.length) {
      frag.appendChild(chipRow(env.chips, function (chip) {
        say(chip.label);
        ask(chip.value != null ? chip.value : chip.label);
      }));
    }

    if (env.form) {
      frag.appendChild(leadForm(env.form, function (payload, formEl) {
        submitLead(env.form.id, payload, formEl);
      }));
    }

    if (env.type === 'handoff' || (env.meta && env.meta.handoff)) {
      handedOff = true;
      frag.appendChild(handoffBlock(env.meta && env.meta.handoff));
    }

    log.appendChild(frag);
    log.scrollTop = log.scrollHeight;
    // The live region carries the words, not the markup.
    if (env.text) { status.textContent = env.text; }

    if (slot) { fillFollowUp(slot, env.meta.follow_up, msgLang); }
  }

  /*
   * Phase two. Fills the reserved slot, or removes it.
   *
   * REMOVING IT IS THE IMPORTANT HALF. The cards are already on screen and
   * already useful — they came from the catalogue, not from a model. If the
   * sentence never arrives, the honest result is the cards without it, not a
   * placeholder animating forever. A spinner that never resolves is a claim
   * that something is still coming, and after the deadline that claim is
   * false.
   *
   * The client keeps its own deadline as well as the engine's. Their timeout
   * protects the server from a slow model; this one protects the visitor from
   * a request that never returns at all, which their timeout cannot see.
   */
  function fillFollowUp(slot, follow, msgLang) {
    var settled = false;

    var give_up = function () {
      if (settled) { return; }
      settled = true;
      if (slot.parentNode) { slot.parentNode.removeChild(slot); }
    };

    var timer = setTimeout(give_up, (follow && follow.timeout_ms) || 6000);

    var url = (follow && follow.url) || CFG.recommendUrl || (CFG.root + 'recommend');

    var req = STUB ? stubFollowUp() : post(url, { token: follow && follow.token, lang: lang, session: CFG.session || null });

    req
      .then(function (env2) {
        if (settled) { return; }
        settled = true;
        clearTimeout(timer);
        var text = env2 && env2.text;
        if (!text) { give_up(); return; }
        var l2 = (env2 && env2.lang) || msgLang;
        var b = el('div', 'eq-bubble', text);
        b.setAttribute('lang', l2);
        b.setAttribute('dir', STR[l2] ? STR[l2].dir : 'auto');

        /*
         * Reserving the slot is not the same as reserving its HEIGHT, and I
         * had claimed otherwise until it was measured: three dots are shorter
         * than two lines of prose, so filling the slot grew it and pushed the
         * cards 60px down the screen — under the eyes of a visitor already
         * reading them.
         *
         * So take the height the slot gains and give it back to the scroll
         * position. Everything below the slot then stays exactly where it was
         * while the sentence appears in the space above it.
         *
         * Only the fill needs this. Removal shrinks the slot and the browser
         * already compensates — measured at 367px before and after.
         */
        var before = slot.getBoundingClientRect().height;
        slot.replaceChild(b, slot.firstChild);
        var grew = slot.getBoundingClientRect().height - before;
        if (grew > 0) { log.scrollTop += grew; }
        // Announced only now: this is the first moment the sentence exists,
        // and announcing the placeholder would have said nothing.
        status.textContent = text;
      })
      .catch(function () { clearTimeout(timer); give_up(); });
  }

  /* ---------------------------------------------------------------- flow -- */
  var handedOff = false;
  var busy = false;
  var typingNode = null;

  function say(text) {
    log.appendChild(bubble('user', text, lang));
    log.scrollTop = log.scrollHeight;
  }

  function showTyping() {
    if (typingNode) { return; }
    typingNode = el('div', 'eq-msg eq-msg--bot');
    var t3 = el('div', 'eq-bubble eq-typing');
    t3.setAttribute('aria-label', t('typing'));
    t3.appendChild(el('span')); t3.appendChild(el('span')); t3.appendChild(el('span'));
    typingNode.appendChild(t3);
    log.appendChild(typingNode);
    log.scrollTop = log.scrollHeight;
  }

  function hideTyping() {
    if (typingNode && typingNode.parentNode) { typingNode.parentNode.removeChild(typingNode); }
    typingNode = null;
  }

  function ask(text) {
    if (busy) { return; }
    busy = true;
    sendBtn.disabled = true;
    // Optimistic, and immediate: the budget is under two seconds end to end
    // and most of it is the model. Waiting for the reply to appear before
    // acknowledging the send is how a visitor sends it twice.
    showTyping();

    transport(text).then(function (env) {
      hideTyping();
      renderEnvelope(env || { text: '' });
    }).catch(function () {
      hideTyping();
      var e = el('div', 'eq-error', t('failed'));
      log.appendChild(e);
      status.textContent = t('failed');
    }).then(function () {
      busy = false;
      sendBtn.disabled = false;
    });
  }

  function submitLead(formId, payload, formEl) {
    var body = { form: formId, fields: payload, lang: lang };
    var done = function (ok) {
      var note = el('div', ok ? 'eq-handoff' : 'eq-error', ok ? t('sent') : t('failed'));
      formEl.parentNode.replaceChild(note, formEl);
      status.textContent = ok ? t('sent') : t('failed');
    };
    if (STUB) { setTimeout(function () { done(true); }, 420); return; }
    post(CFG.leadUrl || (CFG.root + 'lead'), body).then(function () { done(true); }, function () { done(false); });
  }

  composer.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = input.value.trim();
    if (!v) { return; }
    input.value = '';
    input.style.height = '';
    say(v);
    ask(v);
  });

  // Enter sends, Shift+Enter breaks the line — the convention every chat uses,
  // and getting it wrong makes a multi-line enquiry impossible to write.
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      composer.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });
  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 112) + 'px';
  });

  /* ------------------------------------------------------------ open/close - */
  function openPanel() {
    panel.hidden = false;
    launcher.hidden = true;
    launcher.setAttribute('aria-expanded', 'true');
    input.focus();
    if (!log.childNodes.length) { greet(); }
  }

  function closePanel() {
    panel.hidden = true;
    launcher.hidden = false;
    launcher.setAttribute('aria-expanded', 'false');
    // Focus must land somewhere deliberate, or it falls to the body and a
    // keyboard user starts again from the top of the document.
    launcher.focus();
  }

  launcher.addEventListener('click', openPanel);
  closeBtn.addEventListener('click', closePanel);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) { closePanel(); }
  });

  /*
   * Keep focus inside the panel while it is open. Not aria-modal — the page
   * behind stays usable on desktop by design — but tabbing out of a floating
   * dialog into a page you cannot see is worse than a loop.
   */
  panel.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab') { return; }
    var f = panel.querySelectorAll('a[href], button:not([disabled]), textarea, input, select');
    if (!f.length) { return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  /* -------------------------------------------------- launcher collision -- */
  /*
   * The study assistant has its own fixed launcher in the same corner at
   * z-index 9998. It is retired today, so the corner is free — but "retired
   * today" is a setting, not a guarantee, and the two would sit exactly on top
   * of each other the moment it is switched back on.
   *
   * Measured rather than assumed: if another fixed launcher is really on the
   * page and really visible, take its height and stack above it. A hardcoded
   * offset would be wrong the first time either button changed size.
   */
  function avoidCollision() {
    var other = document.querySelector('.eduai-launcher');
    if (!other || other.hidden) { root.style.setProperty('--eq-stack', '0px'); return; }
    var r = other.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) { root.style.setProperty('--eq-stack', '0px'); return; }
    root.style.setProperty('--eq-stack', Math.round(r.height + 12) + 'px');
  }

  /* ----------------------------------------------------------- transport -- */
  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
      body: JSON.stringify(body),
    }).then(function (res) {
      if (!res.ok) { throw new Error('http ' + res.status); }
      return res.json();
    });
  }

  function transport(text) {
    if (STUB) { return stubReply(text); }
    return post(CFG.chatUrl || (CFG.root + 'chat'), {
      text: text, lang: lang, session: CFG.session || null,
    });
  }

  function greet() {
    if (STUB) { stubReply(null).then(renderEnvelope); return; }
    transport('').then(renderEnvelope).catch(function () {});
  }

  /* --------------------------------------------------------------- stub --- */
  /*
   * Fixtures shaped like the real data rather than like a demo: four courses
   * with almost every field genuinely unknown, because that is what the
   * database actually holds and a card that only looks right on invented data
   * is a card that has never been tested.
   */
  /*
   * The second phase, stubbed. CFG.stubFollowUp: 'ok' fills the slot,
   * 'fail' rejects and 'hang' never settles — the last one is the only way to
   * exercise the client deadline, and the removal path is the half worth
   * testing because it is what a visitor sees when the model is slow.
   */
  function stubFollowUp() {
    var mode = CFG.stubFollowUp || 'ok';
    if (mode === 'hang') { return new Promise(function () {}); }
    if (mode === 'fail') { return new Promise(function (_, rej) { setTimeout(function () { rej(new Error('stub')); }, 500); }); }
    return new Promise(function (r) {
      setTimeout(function () {
        r({
          type: 'message', lang: lang,
          text: lang === 'ar'
            ? 'بناءً على ما ذكرته، أقترح البدء بدورة تعلم الآلة — فهي مجانية ومفتوحة للتسجيل الآن.'
            : 'Based on what you have told me, I would start with Machine Learning — it is free and open for enrolment now.',
        });
      }, 1500);
    });
  }

  function stubReply(text) {
    var delay = 700;
    var ar = lang === 'ar';
    var env;
    if (text === null) {
      env = {
        type: 'message', lang: lang,
        text: ar ? 'أهلًا بك. يمكنني مساعدتك في اختيار دورة ومعرفة طريقة الالتحاق. بماذا تهتم؟'
                 : 'Hello. I can help you find a course and explain how to join. What are you interested in?',
        chips: ar
          ? [{ label: 'اعرض كل الدورات', value: 'show all courses' }, { label: 'الرسوم', value: 'fees' }, { label: 'التحدث إلى موظف', value: 'human' }]
          : [{ label: 'Show all courses', value: 'show all courses' }, { label: 'Fees', value: 'fees' }, { label: 'Talk to a person', value: 'human' }],
      };
    } else if (/human|موظف/i.test(text)) {
      env = { type: 'handoff', lang: lang, text: ar ? 'بالتأكيد، سأحوّلك الآن.' : 'Of course — putting you through now.', meta: { handoff: {} } };
    } else if (/fee|price|رسوم/i.test(text)) {
      env = {
        type: 'message', lang: lang,
        text: ar ? 'هذه هي الرسوم المسجلة لدينا. الحقول غير المحددة لم تُدخل بعد.'
                 : 'Here is what we have on file. Fields marked not listed have not been entered yet.',
        cards: [{ id: 228, title: 'Machine Learning', url: '#', description: null, duration: null, format: null, price: 'Free', schedule: null, cta: null }],
      };
    } else if (/recommend|اقترح|أقترح/i.test(text)) {
      // Phase one: cards now, no text, and a slot reserved for the sentence.
      env = {
        type: 'message', lang: lang,
        cards: [
          { id: 228, title: 'Machine Learning', url: '#', description: null, duration: null, format: null, price: 'Free', schedule: null, cta: null },
          { id: 438, title: 'New', url: '#', description: null, duration: null, format: null, price: 'Open', schedule: null, cta: null },
        ],
        meta: { follow_up: { url: null, token: 'stub', timeout_ms: 6000 } },
      };
      delay = 200;
    } else if (/course|دورات|show all/i.test(text)) {
      env = {
        type: 'message', lang: lang,
        text: ar ? 'لدينا أربع دورات متاحة حاليًا.' : 'We have four courses open at the moment.',
        cards: [
          { id: 228, title: 'Machine Learning', url: '#', description: null, duration: null, format: null, price: 'Free', schedule: null, cta: null },
          { id: 438, title: 'New', url: '#', description: null, duration: null, format: null, price: 'Open', schedule: null, cta: null },
        ],
        chips: ar ? [{ label: 'اترك بياناتك', value: 'lead' }] : [{ label: 'Leave my details', value: 'lead' }],
      };
    } else if (/lead|بيانات/i.test(text)) {
      env = {
        type: 'message', lang: lang,
        text: ar ? 'اترك بياناتك وسنتواصل معك.' : 'Leave your details and we will get back to you.',
        form: {
          id: 'enquiry',
          title: ar ? 'بياناتك' : 'Your details',
          fields: [
            { name: 'name', label: ar ? 'الاسم' : 'Name', type: 'text', required: true, autocomplete: 'name' },
            { name: 'email', label: ar ? 'البريد الإلكتروني' : 'Email', type: 'email', required: true, autocomplete: 'email' },
          ],
          consent: ar ? 'أوافق على أن يتم التواصل معي بشأن هذه الدورات.' : 'I agree to be contacted about these courses.',
          submit: ar ? 'أرسل بياناتي' : 'Send my details',
        },
      };
    } else {
      env = {
        type: 'message', lang: lang,
        text: ar ? 'لست متأكدًا من ذلك. هل تريد أن أعرض الدورات المتاحة؟'
                 : 'I am not sure about that. Would you like me to show the available courses?',
        chips: ar ? [{ label: 'اعرض الدورات', value: 'show all courses' }] : [{ label: 'Show courses', value: 'show all courses' }],
      };
    }
    return new Promise(function (r) { setTimeout(function () { r(env); }, delay); });
  }

  /* ---------------------------------------------------------------- boot -- */
  applyLang();
  document.body.appendChild(root);
  avoidCollision();
  window.addEventListener('resize', avoidCollision);

  window.EQWidget = { open: openPanel, close: closePanel, render: renderEnvelope, setLang: function (l) { lang = l; applyLang(); } };
}());
