(function () {
	var currentTab = document.querySelector('.ar-rf-settings a.rf-tabs__tab.is-current');
	if (currentTab && typeof currentTab.scrollIntoView === 'function') {
		currentTab.scrollIntoView({ inline: 'nearest', block: 'nearest' });
	}

	function copyText(text, done) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done);
			return;
		}
		var area = document.createElement('textarea');
		area.value = text;
		document.body.appendChild(area);
		area.select();
		document.execCommand('copy');
		document.body.removeChild(area);
		done();
	}

	document.querySelectorAll('.ar-rf-copy__button').forEach(function (button) {
		button.addEventListener('click', function () {
			var value = button.parentElement.querySelector('.ar-rf-copy__value');
			if (!value) return;
			copyText(value.textContent.trim(), function () {
				var original = button.getAttribute('data-label') || button.textContent;
				button.setAttribute('data-label', original);
				button.textContent = (window.sscAdmin && window.sscAdmin.copied) || 'Copied';
				window.setTimeout(function () {
					button.textContent = original;
				}, 1600);
			});
		});
	});

	document.querySelectorAll('.rf-code__copy').forEach(function (button) {
		button.addEventListener('click', function () {
			var block = button.closest('.rf-code');
			var code = block ? block.querySelector('.rf-code__body code') : null;
			if (!code) return;
			copyText(code.textContent, function () {
				button.setAttribute('data-copied', '1');
				window.setTimeout(function () {
					button.removeAttribute('data-copied');
				}, 1600);
			});
		});
	});

	document.querySelectorAll('.ar-rf-toggle__input').forEach(function (input) {
		input.addEventListener('change', function () {
			var wrap = input.closest('.ar-rf-toggle');
			var state = wrap ? wrap.querySelector('.ar-rf-toggle__state') : null;
			if (!state) return;
			var enabled = state.getAttribute('data-enabled') || 'Enabled';
			var disabled = state.getAttribute('data-disabled') || 'Disabled';
			state.textContent = input.checked ? enabled : disabled;
		});
	});

	var brand = document.querySelector('.ar-rf-footer__brand');
	var about = document.getElementById('ar-rf-about');
	if (brand && about) {
		function setAbout(open) {
			about.hidden = !open;
			brand.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
		brand.addEventListener('click', function () {
			setAbout(about.hidden);
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !about.hidden) {
				setAbout(false);
				brand.focus();
			}
		});
		document.querySelectorAll('.ar-rf-footer a[href="#"], .ar-rf-about a[href="#"]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				event.preventDefault();
			});
		});
	}
}());
