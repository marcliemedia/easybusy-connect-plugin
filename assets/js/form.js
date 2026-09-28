/**
 * EasyBusy Connect — multi-step booking form.
 *
 * No framework, no build step. All state that matters lives server-side behind
 * an opaque draft token; this file only renders what the REST proxy returns and
 * posts the user's choices back one step at a time.
 */
(function () {
	'use strict';

	var runtime = window.ebcRuntime || { rest: '', i18n: {} };
	var t = runtime.i18n || {};

	function text(key, fallback) {
		return typeof t[key] === 'string' && t[key] !== '' ? t[key] : fallback;
	}

	function sprintf(template, values) {
		var i = 0;
		return String(template)
			.replace(/%(\d)\$d/g, function (_, index) {
				return values[Number(index) - 1];
			})
			.replace(/%d|%s/g, function () {
				return values[i++];
			});
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'class') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else if (key.indexOf('on') === 0 && typeof attrs[key] === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
			} else if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) {
				node.setAttribute(key, String(attrs[key]));
			}
		});
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(child);
			}
		});
		return node;
	}

	function money(price, currency) {
		if (price === null || price === undefined) {
			return '';
		}
		var value = Number(price);
		var formatted = value % 1 === 0 ? String(value) : value.toFixed(2);
		return currency ? formatted + ' ' + currency : formatted;
	}

	/** Inline SVG chevron (Lucide geometry) — a text glyph depends on the theme font. */
	function chevron(direction) {
		var path = direction === 'left' ? 'M15 18l-6-6 6-6' : 'M9 18l6-6-6-6';
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" ' +
			'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			'<path d="' + path + '"/></svg>';
	}

	/** @return {Array} distinct values of a key, order preserved. */
	function uniqueValues(items, key) {
		var seen = [];
		items.forEach(function (item) {
			if (seen.indexOf(item[key]) === -1) {
				seen.push(item[key]);
			}
		});
		return seen;
	}

	/**
	 * Inline SVG icon (Lucide geometry) wrapped in a span, so it can sit next to
	 * a text node inside a button.
	 */
	function icon(name) {
		var paths = {
			'arrow-right': 'M5 12h14M13 6l6 6-6 6',
			'arrow-left': 'M19 12H5M11 18l-6-6 6-6',
			'send': 'M22 2 11 13M22 2l-7 20-4-9-9-4 20-7'
		};
		var node = el('span', { class: 'ebc-button__icon', 'aria-hidden': 'true' });
		node.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' +
			'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="' +
			(paths[name] || paths['arrow-right']) + '"/></svg>';

		return node;
	}

	function Api(root) {
		this.root = String(root).replace(/\/$/, '');
	}

	Api.prototype.call = function (path, options) {
		var opts = options || {};
		var init = {
			method: opts.method || 'GET',
			headers: { Accept: 'application/json' },
			credentials: 'same-origin',
			cache: 'no-store'
		};
		if (opts.body) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		var url = this.root + path;
		if (opts.query) {
			var parts = Object.keys(opts.query)
				.filter(function (key) {
					return opts.query[key] !== null && opts.query[key] !== undefined && opts.query[key] !== '';
				})
				.map(function (key) {
					return encodeURIComponent(key) + '=' + encodeURIComponent(opts.query[key]);
				});
			if (parts.length) {
				url += (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
			}
		}
		return fetch(url, init).then(function (response) {
			return response
				.json()
				.catch(function () {
					return {};
				})
				.then(function (payload) {
					if (!response.ok) {
						var error = new Error(payload.message || text('genericError', 'Something went wrong.'));
						error.code = payload.code || 'http_' + response.status;
						error.fields = (payload.data && payload.data.fields) || {};
						error.status = response.status;
						throw error;
					}
					return payload;
				});
		});
	};

	/** First-touch attribution; EasyBusy parses utm_* out of the URL strings. */
	function attribution() {
		var stored = '';
		try {
			stored = window.localStorage.getItem('ebc_landing') || '';
			if (!stored && /utm_/.test(window.location.search)) {
				window.localStorage.setItem('ebc_landing', window.location.href);
				stored = window.location.href;
			}
		} catch (e) {
			stored = '';
		}
		return {
			contact_page: window.location.href,
			referer: document.referrer || '',
			landing: stored
		};
	}

	function Form(container) {
		this.container = container;
		this.mount = container.querySelector('.ebc-form__mount');
		this.api = new Api(runtime.rest);
		this.state = {
			token: '',
			steps: [],
			intro: null,
			config: {},
			index: 0,
			summary: {},
			services: [],
			groups: [],
			doctors: [],
			slotDays: [],
			selectedDay: null,
			contact: {},
			message: '',
			files: [],
			errors: {},
			result: null,
			busy: false
		};
	}

	Form.prototype.start = function () {
		var self = this;
		var preselect = Number(this.container.getAttribute('data-ebc-service') || 0);

		this.api
			.call('/session', {
				method: 'POST',
				body: {
					lang: this.container.getAttribute('data-ebc-lang') || '',
					attribution: attribution()
				}
			})
			.then(function (payload) {
				self.state.token = payload.token;
				self.state.steps = payload.steps || [];
				// The intro normally lives in the page (a Bricks section), not in
				// the form; the shortcode can opt back in with intro="yes".
				self.state.intro = self.container.getAttribute('data-ebc-intro') === '1'
					? payload.intro || null
					: null;
				self.state.config = payload.config || {};
				return self.loadServices();
			})
			.then(function () {
				var wanted = preselect;
				if (!wanted) {
					// Every step starts on a real choice so the visitor sees a
					// concrete result rather than an empty form.
					wanted = self.state.services.length ? self.state.services[0].serviceId : 0;
				}

				var known = self.state.services.some(function (service) {
					return service.serviceId === wanted;
				});
				if (wanted && known) {
					return self.chooseService(wanted);
				}
				self.render();
			})
			.catch(function (error) {
				self.fail(error);
			});
	};

	Form.prototype.loadServices = function () {
		var self = this;
		return this.api.call('/services', { query: { token: this.state.token } }).then(function (payload) {
			self.state.groups = payload.groups || [];
			self.state.services = [];
			self.state.groups.forEach(function (group) {
				group.services.forEach(function (service) {
					self.state.services.push(service);
				});
			});
			return payload;
		});
	};

	Form.prototype.stepId = function () {
		var step = this.state.steps[this.state.index];
		return step ? step.id : 'service';
	};

	/** Titles come from the server-built step graph so wording stays in one place. */
	Form.prototype.stepTitle = function (stepId, fallback) {
		var match = (this.state.steps || []).filter(function (step) {
			return step.id === stepId;
		})[0];
		return match && match.title ? match.title : fallback;
	};

	/**
	 * PHP's date('D') is never localised, so weekday names are formatted here in
	 * the clinic's language rather than the server's.
	 */
	Form.prototype.dayLabel = function (day) {
		var lang = (this.state.config && this.state.config.language) || 'hr';
		var parts = String(day.date).split('-');
		var when = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
		try {
			return new Intl.DateTimeFormat(lang, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' }).format(when);
		} catch (e) {
			return day.label;
		}
	};

	Form.prototype.goTo = function (stepId) {
		for (var i = 0; i < this.state.steps.length; i++) {
			if (this.state.steps[i].id === stepId) {
				this.state.index = i;
				this.render();
				return;
			}
		}
	};

	Form.prototype.next = function () {
		if (this.state.index < this.state.steps.length - 1) {
			this.state.index++;
			this.render();
		}
	};

	Form.prototype.back = function () {
		if (this.state.index > 0) {
			this.state.index--;
			this.render();
		}
	};

	Form.prototype.patch = function (step, data) {
		var self = this;
		var body = Object.assign({ token: this.state.token, step: step }, data || {});
		this.state.busy = true;
		this.render();
		return this.api
			.call('/draft', { method: 'POST', body: body })
			.then(function (payload) {
				self.state.busy = false;
				self.state.summary = payload.summary || {};
				return payload;
			})
			.catch(function (error) {
				self.state.busy = false;
				throw error;
			});
	};

	/**
	 * Choosing a service stays on step 1 and reveals the specialist row.
	 *
	 * The specialist list belongs to the *previous* service until the new one
	 * loads, so it is cleared and re-rendered first: clicking a stale specialist
	 * used to reach the server after the service had already changed and came
	 * back as "Taj specijalist ne izvodi odabranu uslugu".
	 */
	Form.prototype.chooseService = function (serviceId) {
		var self = this;

		this.state.doctors = [];
		this.state.slotDays = [];
		this.state.selectedDay = null;
		this.state.selectedSlotId = null;
		this.state.selectedStart = '';
		this.state.errors = {};
		this.state.busy = true;
		this.render();

		return this.patch('service', { serviceId: serviceId })
			.then(function () {
				return self.state.config.doctorStep ? self.loadDoctors(serviceId) : null;
			})
			.then(function () {
				self.state.busy = false;
				self.render();
			})
			.catch(function (error) {
				self.state.busy = false;
				self.fail(error);
			});
	};

	Form.prototype.loadDoctors = function (serviceId) {
		var self = this;
		return this.api
			.call('/doctors', { query: { token: this.state.token, service: serviceId } })
			.then(function (payload) {
				self.state.doctors = payload.doctors || [];
			});
	};

	Form.prototype.chooseDoctor = function (doctorId) {
		var self = this;
		this.patch('doctor', { doctorId: doctorId })
			.then(function () {
				self.state.slotDays = [];
				self.state.selectedDay = null;
				self.state.selectedSlotId = null;
				self.state.selectedStart = '';
				self.render();
			})
			.catch(function (error) {
				// A mismatch can only mean the list on screen is out of date;
				// refresh it silently and fall back to "no preference".
				if (error.code === 'ebc_doctor_mismatch') {
					var serviceId = self.state.summary.service ? self.state.summary.service.serviceId : 0;
					self.state.errors = {};

					return self.loadDoctors(serviceId).then(function () {
						return self.patch('doctor', { doctorId: 0 });
					}).then(function () {
						self.render();
					});
				}
				self.fail(error);
			});
	};

	/** Leaving step 1: load the slots the next step needs, then advance. */
	Form.prototype.afterService = function () {
		var self = this;
		var work = this.state.config.bookingEnabled ? this.loadSlots() : Promise.resolve();
		this.state.busy = true;
		this.render();

		work
			.then(function () {
				self.state.busy = false;
				self.next();
			})
			.catch(function (error) {
				self.state.busy = false;
				self.fail(error);
			});
	};

	Form.prototype.loadSlots = function () {
		var self = this;
		return this.api
			.call('/slots', { query: { token: this.state.token } })
			.then(function (payload) {
				self.state.slotDays = payload.days || [];
				var first = self.state.slotDays[0] || null;
				self.state.selectedDay = first ? first.date : null;
				self.state.month = first ? first.date.slice(0, 7) : null;

				// Preselect the earliest free time so the step always shows a
				// concrete result instead of an empty choice.
				return first ? self.selectFirstOf(first) : null;
			});
	};

	/** @param {{times: Array}} day */
	Form.prototype.selectFirstOf = function (day) {
		var time = day.times[0];
		if (!time) {
			return null;
		}

		return this.chooseSlot(time.slotId, time.value);
	};

	/** Picking a day immediately preselects its first free time. */
	Form.prototype.chooseDay = function (date) {
		var self = this;
		this.state.selectedDay = date;
		var day = this.state.slotDays.filter(function (entry) {
			return entry.date === date;
		})[0];

		var work = day ? this.selectFirstOf(day) : null;
		if (!work) {
			this.render();

			return;
		}
		work.then(function () {
			self.render();
		});
	};

	/**
	 * Selecting a time stores it on the draft but stays on the step — the
	 * visitor confirms with "Continue", so a mis-tap is not a lost step.
	 */
	Form.prototype.chooseSlot = function (slotId, start) {
		var self = this;

		return this.patch('schedule', { slotId: slotId, start: start || '' })
			.then(function () {
				self.state.selectedSlotId = slotId;
				self.state.selectedStart = start || '';
				self.state.errors.slot = '';
				self.render();
			})
			.catch(function (error) {
				// A taken slot is expected traffic, not a failure: refresh and re-pick.
				if (error.code === 'ebc_slot_gone') {
					self.state.errors = { slot: error.message };
					self.state.selectedSlotId = null;
					self.state.selectedStart = '';

					return self.loadSlots().then(function () {
						self.render();
					});
				}
				self.fail(error);
			});
	};

	Form.prototype.submit = function () {
		var self = this;
		this.state.errors = {};
		this.state.busy = true;
		this.render();

		this.api
			.call('/submit', {
				method: 'POST',
				body: {
					token: this.state.token,
					website: '',
					contact: this.state.contact
				}
			})
			.then(function (payload) {
				self.state.busy = false;
				self.state.result = payload.result;
				self.state.summary = payload.summary || self.state.summary;
				self.state.index = self.state.steps.length - 1;

				// A dedicated thank-you URL exists so Ads/GA4 conversions can be
				// measured on a page view. The token lets that page read the
				// confirmation details from the draft; no data travels in the URL.
				var thanks = self.state.config.thankYouUrl;
				if (thanks) {
					window.location.assign(thanks + (thanks.indexOf('?') === -1 ? '?' : '&') + 'ebc=' + encodeURIComponent(self.state.token));

					return;
				}

				self.render();
			})
			.catch(function (error) {
				self.state.busy = false;
				self.state.errors = error.fields || {};
				// Field errors belong on the fields; anything else (API down,
				// slot taken, rate limit) must be stated plainly at the top of
				// the step, never swallowed.
				self.state.errors._form = Object.keys(self.state.errors).length
					? text('fixFields', 'Please correct the highlighted fields.')
					: error.message || text('genericError', 'Something went wrong. Please try again.');
				self.state.errors._code = error.code || '';
				self.render();
			});
	};

	Form.prototype.fail = function (error) {
		this.state.errors = { _form: error && error.message ? error.message : text('genericError', 'Error') };
		this.render();
	};

	/* ---------- rendering ---------- */

	Form.prototype.render = function () {
		var body = document.createDocumentFragment();
		var done = !!this.state.result;
		var stepId = done ? 'done' : this.stepId();

		// The intro explains the page on the first step only; afterwards the
		// progress bar carries the context and the intro is just noise.
		if (!done && this.state.index === 0) {
			var intro = this.renderIntro();
			if (intro) {
				body.appendChild(intro);
			}
		}

		if (!done) {
			body.appendChild(this.renderProgress());
		}

		// A failed submission has to be impossible to miss: the alert sits above
		// the step, announces itself and keeps the visitor's data intact.
		if (this.state.errors._form) {
			body.appendChild(this.renderAlert());
		}

		var panel;
		if (done) {
			panel = this.renderDone();
		} else if (stepId === 'service') {
			panel = this.renderServices();
		} else if (stepId === 'schedule') {
			panel = this.renderSchedule();
		} else {
			panel = this.renderContact();
		}

		body.appendChild(panel);

		if (!done && this.state.index > 0) {
			body.appendChild(this.renderSummary());
		}

		this.mount.innerHTML = '';
		this.mount.appendChild(body);

		var heading = this.mount.querySelector('.ebc-step__title');
		if (heading) {
			heading.setAttribute('tabindex', '-1');
			heading.focus({ preventScroll: true });
		}
	};

	Form.prototype.renderAlert = function () {
		var code = this.state.errors._code || '';
		var children = [
			el('span', { class: 'ebc-alert__icon', 'aria-hidden': 'true' }),
			el('div', { class: 'ebc-alert__body' }, [
				el('strong', { text: text('errorTitle', 'The request was not sent') }),
				el('span', { text: this.state.errors._form }),
				// A dead end is worse than a retry hint: always offer the phone.
				el('span', { class: 'ebc-alert__hint', text: text('errorHint', 'Please try again, or call the clinic if the problem persists.') }),
				code ? el('code', { class: 'ebc-alert__code', text: code }) : null
			])
		];

		var node = el('div', { class: 'ebc-alert', role: 'alert', tabindex: '-1' }, children);
		node.querySelector('.ebc-alert__icon').innerHTML =
			'<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" ' +
			'stroke-linecap="round" stroke-linejoin="round" focusable="false">' +
			'<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5h.01"/></svg>';

		return node;
	};

	Form.prototype.renderIntro = function () {
		var intro = this.state.intro;
		if (!intro || !intro.heading) {
			return null;
		}

		var steps = (intro.steps || []).map(function (step, index) {
			return el('li', { class: 'ebc-intro__step' }, [
				el('span', { class: 'ebc-intro__num', 'aria-hidden': 'true', text: String(index + 1) }),
				el('span', { class: 'ebc-intro__title', text: step.title }),
				step.hint ? el('span', { class: 'ebc-intro__hint', text: step.hint }) : null
			]);
		});

		return el('section', { class: 'ebc-intro' }, [
			el('h2', { class: 'ebc-intro__heading', text: intro.heading }),
			intro.lead ? el('p', { class: 'ebc-intro__lead', text: intro.lead }) : null,
			steps.length ? el('ol', { class: 'ebc-intro__steps' }, steps) : null
		]);
	};

	Form.prototype.renderProgress = function () {
		var self = this;
		var total = this.state.steps.length;
		var items = this.state.steps.map(function (step, index) {
			var classes = ['ebc-progress__item'];
			if (index === self.state.index) {
				classes.push('is-current');
			}
			if (index < self.state.index) {
				classes.push('is-done');
			}
			return el('li', {
				class: classes.join(' '),
				'aria-current': index === self.state.index ? 'step' : null
			}, [
				el('span', { class: 'ebc-progress__num', 'aria-hidden': 'true', text: String(index + 1) }),
				el('span', { class: 'ebc-progress__label', text: step.title })
			]);
		});

		return el('nav', { class: 'ebc-progress', 'aria-label': sprintf(text('step', 'Step %1$d of %2$d'), [this.state.index + 1, total]) }, [
			el('ol', { class: 'ebc-progress__list' }, items)
		]);
	};

	/**
	 * Step 1 — service, and the optional specialist right below it. Merging the
	 * two keeps the flow at three steps; picking a service only reveals the
	 * specialist row, it does not jump the visitor forward.
	 */
	Form.prototype.renderServices = function () {
		var self = this;
		var step = this.state.steps[0] || {};
		var chosen = this.state.summary.service ? this.state.summary.service.serviceId : null;

		var groups = (this.state.groups || []).map(function (group) {
			var cards = group.services.map(function (service) {
				var price = money(service.price, service.currency);
				return el('button', {
					type: 'button',
					class: 'ebc-card' + (service.serviceId === chosen ? ' is-selected' : ''),
					'aria-pressed': service.serviceId === chosen ? 'true' : 'false',
					onclick: function () {
						self.chooseService(service.serviceId);
					}
				}, [
					el('span', { class: 'ebc-card__name', text: service.name }),
					price ? el('span', { class: 'ebc-card__price', text: price }) : null
				]);
			});

			return el('div', { class: 'ebc-group' }, [
				group.category ? el('h4', { class: 'ebc-group__title', text: group.category }) : null,
				el('div', { class: 'ebc-group__cards' }, cards)
			]);
		});

		var children = [
			el('h3', { class: 'ebc-step__title', text: step.title || text('service', 'Service') }),
			step.hint ? el('p', { class: 'ebc-step__hint', text: step.hint }) : null
		].concat(groups);

		if (chosen && this.state.config.doctorStep) {
			children.push(this.renderDoctorPicker());
		}

		if (chosen) {
			children.push(this.renderNav({
				back: false,
				onNext: function () {
					self.afterService();
				}
			}));
		}

		return el('section', { class: 'ebc-step ebc-step--service' }, children);
	};

	Form.prototype.renderDoctorPicker = function () {
		var self = this;
		var current = this.state.summary.doctorId || 0;

		var options = [
			el('button', {
				type: 'button',
				class: 'ebc-card' + (current === 0 ? ' is-selected' : ''),
				'aria-pressed': current === 0 ? 'true' : 'false',
				onclick: function () {
					self.chooseDoctor(0);
				}
			}, [el('span', { class: 'ebc-card__name', text: text('anyDoctor', 'No preference') })])
		].concat(
			this.state.doctors.map(function (doctor) {
				return el('button', {
					type: 'button',
					class: 'ebc-card' + (doctor.doctorId === current ? ' is-selected' : ''),
					'aria-pressed': doctor.doctorId === current ? 'true' : 'false',
					onclick: function () {
						self.chooseDoctor(doctor.doctorId);
					}
				}, [
					el('span', { class: 'ebc-card__name', text: doctor.name }),
					doctor.titles ? el('span', { class: 'ebc-card__meta', text: doctor.titles }) : null
				]);
			})
		);

		// A clinic can list many specialists, so phones get the native picker and
		// wider screens keep the cards (same both-rendered, CSS-swapped pattern).
		var selectOptions = [{
			value: '0',
			label: text('anyDoctor', 'No preference'),
			selected: current === 0
		}].concat(
			this.state.doctors.map(function (doctor) {
				return {
					value: String(doctor.doctorId),
					label: doctor.titles ? doctor.name + ' · ' + doctor.titles : doctor.name,
					selected: doctor.doctorId === current
				};
			})
		);

		return el('div', { class: 'ebc-group' }, [
			el('h4', { class: 'ebc-group__title', text: text('doctor', 'Specialist') }),
			this.renderChoiceSelect({
				id: 'ebc-doctor-select',
				label: text('doctor', 'Specialist'),
				options: selectOptions,
				onChange: function (value) {
					self.chooseDoctor(Number(value));
				}
			}),
			el('div', { class: 'ebc-group__cards ebc-group__cards--doctors' }, options)
		]);
	};

	/**
	 * X-rays and similar. Files are staged server-side against the draft and
	 * only travel to EasyBusy at submit time, through a lead — the booking API
	 * itself has no upload endpoint.
	 */
	Form.prototype.renderAttachments = function () {
		var self = this;
		var config = this.state.config;
		if (!config.attachments) {
			return null;
		}

		var staged = this.state.files || [];
		var list = staged.map(function (file) {
			return el('li', { class: 'ebc-file', text: file.name + ' (' + Math.round(file.size / 1024) + ' KB)' });
		});

		var input = el('input', {
			type: 'file',
			class: 'ebc-input ebc-file-input',
			id: 'ebc-files',
			accept: '.jpg,.jpeg,.png,.pdf',
			disabled: staged.length >= config.maxFiles ? 'disabled' : null,
			onchange: function (event) {
				var file = event.target.files && event.target.files[0];
				if (file) {
					self.uploadFile(file);
				}
				event.target.value = '';
			}
		});

		return el('div', { class: 'ebc-attachments' }, [
			el('label', { class: 'ebc-field' }, [
				el('span', {
					class: 'ebc-field__label',
					text: sprintf(text('attachmentsLabel', 'Attach an X-ray or document (max %1$d files, %2$d MB each)'), [config.maxFiles, config.maxFileMb])
				}),
				input
			]),
			staged.length ? el('ul', { class: 'ebc-files' }, list) : null,
			this.state.errors.file ? el('p', { class: 'ebc-error', role: 'alert', text: this.state.errors.file }) : null
		]);
	};

	Form.prototype.uploadFile = function (file) {
		var self = this;
		var body = new FormData();
		body.append('file', file);
		body.append('token', this.state.token);

		this.state.errors.file = '';
		this.state.busy = true;
		this.render();

		fetch(this.api.root + '/upload', { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
			.then(function (response) {
				return response.json().then(function (payload) {
					if (!response.ok) {
						throw new Error(payload.message || text('genericError', 'Upload failed.'));
					}
					return payload;
				});
			})
			.then(function (payload) {
				self.state.files = payload.files || [];
				self.state.busy = false;
				self.render();
			})
			.catch(function (error) {
				self.state.busy = false;
				self.state.errors.file = error.message;
				self.render();
			});
	};

	/**
	 * Step 2 — a month calendar plus the times of the selected day.
	 *
	 * EasyBusy exposes availability only as a flat slot list
	 * (GET /simple-booking/available-slots?from&to&serviceId[&doctorId]); there is
	 * no month/calendar endpoint. The whole horizon is fetched once and the
	 * calendar is drawn from it, so navigating months costs no extra requests.
	 */
	Form.prototype.renderSchedule = function () {
		var self = this;
		var days = this.state.slotDays;
		var step = this.state.steps[this.state.index] || {};

		if (!days.length) {
			return el('section', { class: 'ebc-step ebc-step--schedule' }, [
				el('h3', { class: 'ebc-step__title', text: step.title || text('chooseDay', 'Choose a day') }),
				el('p', { class: 'ebc-empty', text: text('noSlots', 'No free appointments.') }),
				this.renderNav({
					onNext: function () {
						self.next();
					}
				})
			]);
		}

		var selected = days.filter(function (day) {
			return day.date === self.state.selectedDay;
		})[0] || days[0];

		// One flat list of pickable start times for the selected day; the server
		// already collapsed the overlapping blocks EasyBusy returns.
		var options = selected.times.map(function (time) {
			return {
				slotId: time.slotId,
				value: time.value,
				label: time.label,
				durationMin: time.durationMin,
				doctorName: time.doctorName || ''
			};
		});

		// Duration and doctor are usually identical for a whole day; printing
		// them on every chip is what made the grid sprawl. Show them once as a
		// caption and keep them on the chip only when they actually differ.
		var durations = uniqueValues(options, 'durationMin');
		var doctors = uniqueValues(options, 'doctorName');
		var captionParts = [sprintf(text('slotsAvailable', '%d free times'), [options.length])];
		if (durations.length === 1) {
			captionParts.push(sprintf(text('duration', '%d min'), [durations[0]]));
		}
		if (doctors.length === 1 && doctors[0] !== '') {
			captionParts.push(doctors[0]);
		}

		// A busy day can hold well over a hundred start times. Grouping them by
		// specialist (and duration) moves the meta out of the chips, so a chip is
		// just a time and the grid stays dense and scannable.
		var groups = [];
		options.forEach(function (option) {
			var key = option.doctorName + '|' + option.durationMin;
			var group = groups.filter(function (candidate) {
				return candidate.key === key;
			})[0];
			if (!group) {
				group = { key: key, doctorName: option.doctorName, durationMin: option.durationMin, items: [] };
				groups.push(group);
			}
			group.items.push(option);
		});

		var chipGroups = groups.map(function (group) {
			var chips = group.items.map(function (option) {
				var isSelected = self.state.selectedStart === option.value && self.state.selectedSlotId === option.slotId;
				return el('button', {
					type: 'button',
					class: 'ebc-time' + (isSelected ? ' is-selected' : ''),
					'aria-pressed': isSelected ? 'true' : 'false',
					onclick: function () {
						self.chooseSlot(option.slotId, option.value);
					}
				}, [el('span', { class: 'ebc-time__value', text: option.label })]);
			});

			var label = [];
			if (group.doctorName && doctors.length > 1) {
				label.push(group.doctorName);
			}
			if (durations.length > 1) {
				label.push(sprintf(text('duration', '%d min'), [group.durationMin]));
			}

			return el('div', { class: 'ebc-times-group' }, [
				label.length ? el('h5', { class: 'ebc-times-group__title', text: label.join(' · ') }) : null,
				el('div', { class: 'ebc-times' }, chips)
			]);
		});

		return el('section', { class: 'ebc-step ebc-step--schedule' }, [
			el('h3', { class: 'ebc-step__title', text: step.title || text('chooseDay', 'Choose a day') }),
			step.hint ? el('p', { class: 'ebc-step__hint', text: step.hint }) : null,
			this.state.errors.slot ? el('p', { class: 'ebc-error', role: 'alert', text: this.state.errors.slot }) : null,
			el('div', { class: 'ebc-schedule' }, [
				this.renderCalendar(),
				el('div', { class: 'ebc-times-panel' }, [
					el('h4', { class: 'ebc-subtitle', text: text('chooseTime', 'Choose a time') + ' — ' + this.dayLabel(selected) }),
					el('p', { class: 'ebc-times-caption', text: captionParts.join(' · ') }),
					// Native picker on phones, chip grid on wider screens.
					this.renderChoiceSelect({
						id: 'ebc-time-select',
						label: text('chooseTime', 'Choose a time'),
						options: options.map(function (option) {
							var label = option.label + ' · ' + sprintf(text('duration', '%d min'), [option.durationMin]);
							if (option.doctorName) {
								label += ' · ' + option.doctorName;
							}
							return {
								value: option.slotId + '|' + option.value,
								label: label,
								selected: self.state.selectedStart === option.value && self.state.selectedSlotId === option.slotId
							};
						}),
						onChange: function (value) {
							var parts = String(value).split('|');
							if (parts.length === 2) {
								self.chooseSlot(Number(parts[0]), parts[1]);
							}
						}
					}),
					el('div', { class: 'ebc-times-scroll' }, chipGroups)
				])
			]),
			this.renderNav({
				onNext: function () {
					self.next();
				}
			})
		]);
	};

	/**
	 * Phone control for a list choice: a native <select>. The OS picker scrolls a
	 * long list far better than a grid of cards or chips, and it costs one tap.
	 * Both controls are always rendered; CSS decides which one is visible, so
	 * there is no viewport JavaScript and no resize handling.
	 *
	 * @param {{id: string, label: string, options: Array<{value: string, label: string, selected: boolean}>, onChange: function(string): void}} spec
	 */
	Form.prototype.renderChoiceSelect = function (spec) {
		var select = el('select', {
			class: 'ebc-input ebc-select',
			id: spec.id,
			onchange: function (event) {
				spec.onChange(event.target.value);
			}
		});

		spec.options.forEach(function (option) {
			var node = el('option', { value: option.value, text: option.label });
			if (option.selected) {
				node.selected = true;
			}
			select.appendChild(node);
		});

		return el('label', { class: 'ebc-select-field' }, [
			el('span', { class: 'ebc-field__label ebc-visually-hidden', text: spec.label }),
			select
		]);
	};

	/** Month grid built from the fetched slot list; days without slots are disabled. */
	Form.prototype.renderCalendar = function () {
		var self = this;
		var lang = (this.state.config && this.state.config.language) || 'hr';
		var byDate = {};
		this.state.slotDays.forEach(function (day) {
			byDate[day.date] = day;
		});

		var months = Object.keys(byDate).map(function (date) {
			return date.slice(0, 7);
		});
		months = months.filter(function (month, index) {
			return months.indexOf(month) === index;
		}).sort();

		if (!this.state.month || months.indexOf(this.state.month) === -1) {
			this.state.month = (this.state.selectedDay || months[0] || '').slice(0, 7) || months[0];
		}

		var monthIndex = months.indexOf(this.state.month);
		var parts = this.state.month.split('-');
		var year = Number(parts[0]);
		var month = Number(parts[1]);
		var first = new Date(year, month - 1, 1);
		var daysInMonth = new Date(year, month, 0).getDate();
		// Monday-first week, which is the Croatian convention.
		var offset = (first.getDay() + 6) % 7;

		var monthLabel;
		try {
			monthLabel = new Intl.DateTimeFormat(lang, { month: 'long', year: 'numeric' }).format(first);
		} catch (e) {
			monthLabel = this.state.month;
		}

		var dowNames = [];
		for (var d = 0; d < 7; d++) {
			var sample = new Date(2024, 0, 1 + d); // 2024-01-01 is a Monday
			try {
				dowNames.push(new Intl.DateTimeFormat(lang, { weekday: 'short' }).format(sample).replace('.', ''));
			} catch (e2) {
				dowNames.push(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'][d]);
			}
		}

		var cells = dowNames.map(function (name) {
			return el('div', { class: 'ebc-cal__dow', text: name });
		});

		for (var pad = 0; pad < offset; pad++) {
			cells.push(el('div', { class: 'ebc-cal__day ebc-cal__day--empty', 'aria-hidden': 'true' }));
		}

		for (var dayNum = 1; dayNum <= daysInMonth; dayNum++) {
			var iso = year + '-' + String(month).padStart(2, '0') + '-' + String(dayNum).padStart(2, '0');
			var entry = byDate[iso];
			var isSelected = iso === this.state.selectedDay;
			var classes = ['ebc-cal__day'];
			if (entry) {
				classes.push('is-free');
			}
			if (isSelected) {
				classes.push('is-selected');
			}

			cells.push(
				el('button', {
					type: 'button',
					class: classes.join(' '),
					disabled: entry ? null : 'disabled',
					'aria-pressed': isSelected ? 'true' : 'false',
					'aria-label': entry
						? iso + ' — ' + sprintf(text('slotsAvailable', '%d free times'), [entry.times.length])
						: iso,
					onclick: entry
						? (function (date) {
							return function () {
								self.chooseDay(date);
							};
						})(iso)
						: null
				}, [
					el('span', { text: String(dayNum) }),
					entry ? el('span', { class: 'ebc-cal__dot', 'aria-hidden': 'true' }) : null
				])
			);
		}

		return el('div', { class: 'ebc-cal' }, [
			el('div', { class: 'ebc-cal__head' }, [
				el('button', {
					type: 'button',
					class: 'ebc-cal__nav',
					'aria-label': text('prevMonth', 'Previous month'),
					disabled: monthIndex <= 0 ? 'disabled' : null,
					html: chevron('left'),
					onclick: function () {
						self.state.month = months[monthIndex - 1];
						self.render();
					}
				}),
				el('span', { class: 'ebc-cal__month', text: monthLabel }),
				el('button', {
					type: 'button',
					class: 'ebc-cal__nav',
					'aria-label': text('nextMonth', 'Next month'),
					disabled: monthIndex >= months.length - 1 ? 'disabled' : null,
					html: chevron('right'),
					onclick: function () {
						self.state.month = months[monthIndex + 1];
						self.render();
					}
				})
			]),
			el('div', { class: 'ebc-cal__grid', role: 'grid' }, cells),
			el('div', { class: 'ebc-cal__legend' }, [
				el('span', {}, [
					el('span', { class: 'ebc-cal__swatch ebc-cal__swatch--free', 'aria-hidden': 'true' }),
					el('span', { text: text('legendFree', 'Free appointments') })
				]),
				el('span', {}, [
					el('span', { class: 'ebc-cal__swatch ebc-cal__swatch--selected', 'aria-hidden': 'true' }),
					el('span', { text: text('legendSelected', 'Selected day') })
				])
			])
		]);
	};

	Form.prototype.renderContact = function () {
		var self = this;
		var config = this.state.config;

		function field(name, label, type, required) {
			var input = el('input', {
				type: type || 'text',
				class: 'ebc-input' + (self.state.errors[name] ? ' has-error' : ''),
				id: 'ebc-' + name,
				name: name,
				required: required ? 'required' : null,
				'aria-invalid': self.state.errors[name] ? 'true' : null,
				oninput: function (event) {
					self.state.contact[name] = event.target.value;
				}
			});
			input.value = self.state.contact[name] || '';

			return el('label', { class: 'ebc-field' }, [
				el('span', { class: 'ebc-field__label', text: label + (required ? ' *' : ' (' + text('optional', 'optional') + ')') }),
				input,
				self.state.errors[name] ? el('span', { class: 'ebc-error', text: self.state.errors[name] }) : null
			]);
		}

		/** Closed list: EasyBusy stores whatever string it is sent, typos included. */
		function countryField() {
			var name = 'countryCode';
			var current = self.state.contact[name] || config.defaultCountry || '';
			var select = el('select', {
				class: 'ebc-input ebc-select' + (self.state.errors[name] ? ' has-error' : ''),
				id: 'ebc-' + name,
				name: name,
				required: 'required',
				'aria-invalid': self.state.errors[name] ? 'true' : null,
				onchange: function (event) {
					self.state.contact[name] = event.target.value;
				}
			});

			Object.keys(config.countries || {}).forEach(function (code) {
				var option = el('option', { value: code, text: config.countries[code] });
				if (code === current) {
					option.selected = true;
				}
				select.appendChild(option);
			});
			self.state.contact[name] = current;

			return el('label', { class: 'ebc-field' }, [
				el('span', { class: 'ebc-field__label', text: text('country', 'Country') + ' *' }),
				select,
				self.state.errors[name] ? el('span', { class: 'ebc-error', text: self.state.errors[name] }) : null
			]);
		}

		// The country travels with every request: EasyBusy rejects an appointment
		// whose patientInfo.address.countryCode is null.
		var fields = [
			field('firstName', text('firstName', 'First name'), 'text', true),
			field('lastName', text('lastName', 'Last name'), 'text', true),
			field('email', text('email', 'E-mail'), 'email', true),
			field('phone', text('phone', 'Phone'), 'tel', true),
			countryField()
		];

		if (config.requireOib) {
			fields.push(field('personalId', text('personalId', 'OIB'), 'text', true));
		}
		if (config.collectAddress) {
			fields.push(field('streetName', text('streetName', 'Street'), 'text', false));
			fields.push(field('streetNumber', text('streetNumber', 'Number'), 'text', false));
			fields.push(field('postalCode', text('postalCode', 'Postal code'), 'text', false));
			fields.push(field('city', text('city', 'City'), 'text', false));
		}

		var consentText = (config.consent && config.consent.text) || text('consentDefault', '');
		var consentBox = el('input', {
			type: 'checkbox',
			class: 'ebc-checkbox',
			id: 'ebc-consent',
			onchange: function (event) {
				self.state.contact.consent = event.target.checked ? '1' : '';
			}
		});
		consentBox.checked = !!this.state.contact.consent;

		var consentLabel = el('label', { class: 'ebc-consent' }, [
			consentBox,
			el('span', { text: consentText }),
			config.consent && config.consent.url
				? el('a', { href: config.consent.url, target: '_blank', rel: 'noopener', text: ' ' + text('consentLink', 'Privacy policy') })
				: null
		]);

		var honeypot = el('input', { type: 'text', name: 'website', class: 'ebc-honeypot', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });

		// Description and attachments live here so the flow stays at three steps.
		var area = el('textarea', {
			class: 'ebc-textarea',
			id: 'ebc-message',
			rows: 4,
			maxlength: 2000,
			placeholder: text('messagePlaceholder', ''),
			oninput: function (event) {
				self.state.message = event.target.value;
			}
		});
		area.value = this.state.message || this.state.summary.message || '';

		var messageField = el('label', { class: 'ebc-field ebc-field--wide' }, [
			el('span', { class: 'ebc-field__label', text: text('messageLabel', 'Describe your issue') }),
			area
		]);

		var preferred = null;
		if (!config.bookingEnabled) {
			preferred = el('label', { class: 'ebc-field ebc-field--wide' }, [
				el('span', { class: 'ebc-field__label', text: text('preferredTime', 'Preferred time') }),
				el('input', { type: 'text', class: 'ebc-input', id: 'ebc-preferred' })
			]);
		}

		var step = this.state.steps[this.state.steps.length - 1] || {};

		return el('section', { class: 'ebc-step ebc-step--contact' }, [
			el('h3', { class: 'ebc-step__title', text: step.title || text('yourDetails', 'Your details') }),
			step.hint ? el('p', { class: 'ebc-step__hint', text: step.hint }) : null,
			el('div', { class: 'ebc-fields' }, fields.concat([messageField, preferred])),
			this.renderAttachments(),
			consentLabel,
			this.state.errors.consent ? el('p', { class: 'ebc-error', role: 'alert', text: this.state.errors.consent }) : null,
			honeypot,
			this.renderNav({
				nextLabel: this.state.busy ? text('sending', 'Sending…') : text('submit', 'Send request'),
				nextIcon: 'send',
				onNext: function () {
					self.submitAll(area.value);
				}
			})
		]);
	};

	/**
	 * Persist the free-text fields, then submit. They are patched separately
	 * because the draft is the only trusted source of the message.
	 */
	Form.prototype.submitAll = function (fallbackMessage) {
		var self = this;
		var message = this.state.message || fallbackMessage || '';
		var preferred = document.getElementById('ebc-preferred');

		this.state.busy = true;
		this.render();

		var chain = this.patch('message', { message: message });
		if (preferred && preferred.value) {
			chain = chain.then(function () {
				return self.patch('preferred_time', { preferred_time: preferred.value });
			});
		}

		chain
			.then(function () {
				self.state.busy = false;
				self.submit();
			})
			.catch(function (error) {
				self.state.busy = false;
				self.fail(error);
			});
	};

	Form.prototype.renderDone = function () {
		var result = this.state.result || {};
		var children = [el('h3', { class: 'ebc-step__title', text: text('requestReceived', 'Request received') })];

		if (result.dry_run) {
			children.push(el('p', { class: 'ebc-notice ebc-notice--dry', text: text('dryRunNotice', 'Test mode.') }));
			children.push(el('pre', { class: 'ebc-payload', text: JSON.stringify(result.payload || {}, null, 2) }));
			children.push(el('p', { class: 'ebc-meta', text: result.endpoint || '' }));
		} else if (result.appointment) {
			children.push(el('p', { class: 'ebc-notice', text: text('notConfirmed', '') }));
			children.push(
				el('dl', { class: 'ebc-result' }, [
					el('dt', { text: text('appointmentId', 'Reference') }),
					el('dd', { text: String(result.appointment.appointmentId || '') }),
					el('dt', { text: text('service', 'Service') }),
					el('dd', { text: result.appointment.serviceName || '' }),
					el('dt', { text: text('appointment', 'Appointment') }),
					el('dd', { text: result.appointment.startLabel || '' })
				])
			);
		} else if (result.lead) {
			children.push(el('p', { class: 'ebc-notice', text: text('notConfirmed', '') }));
			children.push(el('p', { class: 'ebc-meta', text: text('appointmentId', 'Reference') + ': ' + String(result.lead.leadId || '') }));
		}

		var self = this;
		children.push(
			el('div', { class: 'ebc-nav' }, [
				el('button', {
					type: 'button',
					class: 'ebc-button ebc-button--ghost',
					text: text('restart', 'Start over'),
					onclick: function () {
						self.state = Object.assign(self.state, {
							index: 0,
							result: null,
							contact: {},
							message: '',
							files: [],
							errors: {},
							slotDays: [],
							selectedDay: null
						});
						self.start();
					}
				})
			])
		);

		return el('section', { class: 'ebc-step ebc-step--done' }, children);
	};

	Form.prototype.renderSummary = function () {
		var summary = this.state.summary || {};
		var rows = [];

		if (summary.service) {
			rows.push(el('dt', { text: text('service', 'Service') }));
			rows.push(el('dd', { text: summary.service.name + (money(summary.service.price, summary.service.currency) ? ' · ' + money(summary.service.price, summary.service.currency) : '') }));
		}
		if (summary.slot) {
			rows.push(el('dt', { text: text('appointment', 'Appointment') }));
			rows.push(el('dd', { text: summary.slot.date + ' ' + summary.slot.time + ' · ' + sprintf(text('duration', '%d min'), [summary.slot.durationMin]) + (summary.slot.doctorName ? ' · ' + summary.slot.doctorName : '') }));
		}

		if (!rows.length) {
			return el('div', { class: 'ebc-summary is-empty' });
		}

		return el('aside', { class: 'ebc-summary' }, [
			el('h4', { class: 'ebc-summary__title', text: text('summaryTitle', 'Your selection') }),
			el('dl', { class: 'ebc-summary__list' }, rows)
		]);
	};

	Form.prototype.renderNav = function (options) {
		var self = this;
		var opts = options || {};
		var buttons = [];

		if (this.state.index > 0 && opts.back !== false) {
			buttons.push(
				el('button', {
					type: 'button',
					class: 'ebc-button ebc-button--ghost',
					onclick: function () {
						self.back();
					}
				}, [
					icon('arrow-left'),
					el('span', { text: text('back', 'Back') })
				])
			);
		}

		if (opts.next !== false) {
			// The arrow makes the primary action recognisable before the label is
			// read, which matters on a step form.
			buttons.push(
				el('button', {
					type: 'button',
					class: 'ebc-button',
					disabled: this.state.busy ? 'disabled' : null,
					onclick: opts.onNext ||
						function () {
							self.next();
						}
				}, [
					el('span', { text: opts.nextLabel || (this.state.busy ? text('loading', '…') : text('next', 'Continue')) }),
					icon(opts.nextIcon || 'arrow-right')
				])
			);
		}

		return el('div', { class: 'ebc-nav' }, buttons);
	};

	function init() {
		var forms = document.querySelectorAll('.ebc-form');
		for (var i = 0; i < forms.length; i++) {
			new Form(forms[i]).start();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
