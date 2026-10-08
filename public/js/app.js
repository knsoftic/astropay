(function () {
    'use strict';

    // Mobile sidebar
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-nav-toggle]')) {
            document.body.classList.toggle('nav-open');
        } else if (event.target.closest('[data-nav-close]')) {
            document.body.classList.remove('nav-open');
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.body.classList.remove('nav-open');
        }
    });

    // Dismissible alerts
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-dismiss]');
        if (button) {
            var alert = button.closest('.alert');
            if (alert) {
                alert.remove();
            }
        }
    });

    // Show / hide a password field
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-reveal]');
        if (!button) {
            return;
        }
        var input = document.querySelector(button.getAttribute('data-reveal'));
        if (input) {
            input.type = input.type === 'password' ? 'text' : 'password';
            button.setAttribute('aria-pressed', input.type === 'text' ? 'true' : 'false');
        }
    });

    // Copy to clipboard
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button || !navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function () {
            var label = button.querySelector('[data-copy-label]');
            if (label) {
                var original = label.textContent;
                label.textContent = 'Copied';
                setTimeout(function () { label.textContent = original; }, 1500);
            }
        });
    });

    // Confirm before submitting, then lock the submit button against double clicks
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }
        if (form.hasAttribute('data-lock')) {
            var buttons = Array.prototype.slice.call(form.querySelectorAll('button[type=submit]'));
            if (form.id) {
                buttons = buttons.concat(Array.prototype.slice.call(document.querySelectorAll('button[type=submit][form="' + form.id + '"]')));
            }
            // Disable after the browser has collected the form data.
            setTimeout(function () {
                buttons.forEach(function (button) {
                    button.disabled = true;
                    button.classList.add('is-loading');
                });
            }, 0);
        }
    });
})();
