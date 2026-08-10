(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var target = event.target;
        var link = target instanceof Element ? target.closest('a[data-seo-post-action]') : null;

        if (!link) {
            return;
        }

        event.preventDefault();

        var confirmation = link.getAttribute('data-confirm');
        if (confirmation && !window.confirm(confirmation)) {
            return;
        }

        var form = document.createElement('form');
        form.method = 'post';
        form.action = link.href;
        form.style.display = 'none';

        if (link.target) {
            form.target = link.target;
        }

        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = link.getAttribute('data-csrf-token') || '';
        form.appendChild(token);

        document.body.appendChild(form);
        form.submit();
        form.remove();
    });
})();
