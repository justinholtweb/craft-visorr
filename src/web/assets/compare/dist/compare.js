/* Visorr — comparison screen behaviour: the revision pickers and the selective restore. */
(function () {
    'use strict';

    function reload(form) {
        var params = new URLSearchParams(window.location.search);
        params.set('left', form.querySelector('[name="left"]').value);
        params.set('right', form.querySelector('[name="right"]').value);
        window.location.search = params.toString();
    }

    function initPickers() {
        var form = document.querySelector('[data-visorr-compare-form]');

        if (!form) {
            return;
        }

        form.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () {
                reload(form);
            });
        });

        var swap = form.querySelector('[data-visorr-swap]');

        if (swap) {
            swap.addEventListener('click', function (event) {
                event.preventDefault();
                var left = form.querySelector('[name="left"]');
                var right = form.querySelector('[name="right"]');
                var held = left.value;
                left.value = right.value;
                right.value = held;
                reload(form);
            });
        }
    }

    /**
     * The restore bar counts the ticked fields and stays out of the way until there is at least
     * one. Restoring nothing is not an operation, so the button is never offered for it.
     */
    function initRestore() {
        var bar = document.querySelector('[data-visorr-restore-bar]');

        if (!bar) {
            return;
        }

        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-visorr-restore-field]'));
        var count = bar.querySelector('[data-visorr-restore-count]');
        var button = bar.querySelector('[data-visorr-restore-button]');
        var selectAll = document.querySelector('[data-visorr-restore-all]');

        var selected = function () {
            return boxes.filter(function (box) {
                return box.checked;
            });
        };

        var sync = function () {
            var n = selected().length;
            count.textContent = n === 1
                ? Craft.t('visorr', '1 field selected')
                : Craft.t('visorr', '{n} fields selected', { n: n });
            button.disabled = n === 0;
            button.classList.toggle('disabled', n === 0);
        };

        boxes.forEach(function (box) {
            box.addEventListener('change', sync);
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                boxes.forEach(function (box) {
                    box.checked = selectAll.checked;
                });
                sync();
            });
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();

            var handles = selected().map(function (box) {
                return box.value;
            });

            if (!handles.length) {
                return;
            }

            button.disabled = true;

            // Preview first, always. A restore writes over live content, and the confirmation
            // has to describe what is about to be overwritten rather than what is being put
            // back — those are different lists and only one of them is the risk.
            Craft.sendActionRequest('POST', 'visorr/compare/preview-restore', {
                data: {
                    elementId: bar.dataset.visorrElementId,
                    revisionId: bar.dataset.visorrRevisionId,
                    siteId: bar.dataset.visorrSiteId || null,
                    handles: handles,
                },
            })
                .then(function (response) {
                    showConfirmation(bar, handles, response.data.html);
                })
                .catch(function (error) {
                    Craft.cp.displayError(
                        (error && error.response && error.response.data && error.response.data.message) ||
                            Craft.t('visorr', 'Could not build the restore preview.')
                    );
                })
                .finally(function () {
                    button.disabled = false;
                });
        });

        sync();
    }

    function showConfirmation(bar, handles, html) {
        var modal = document.createElement('div');
        modal.innerHTML = html;

        var hud = new Garnish.HUD(bar.querySelector('[data-visorr-restore-button]'), modal, {
            hudClass: 'hud visorr-restore-hud',
        });

        var confirm = modal.querySelector('[data-visorr-restore-confirm]');

        if (!confirm) {
            return;
        }

        confirm.addEventListener('click', function (event) {
            event.preventDefault();
            confirm.disabled = true;

            Craft.sendActionRequest('POST', 'visorr/compare/restore', {
                data: {
                    elementId: bar.dataset.visorrElementId,
                    revisionId: bar.dataset.visorrRevisionId,
                    siteId: bar.dataset.visorrSiteId || null,
                    handles: handles,
                    note: (modal.querySelector('[data-visorr-restore-note]') || {}).value || null,
                },
            })
                .then(function (response) {
                    Craft.cp.displayNotice(response.data.message);
                    hud.hide();
                    if (response.data.redirect) {
                        window.location.href = response.data.redirect;
                    } else {
                        window.location.reload();
                    }
                })
                .catch(function (error) {
                    confirm.disabled = false;
                    Craft.cp.displayError(
                        (error && error.response && error.response.data && error.response.data.message) ||
                            Craft.t('visorr', 'The restore failed.')
                    );
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPickers();
        initRestore();
    });
})();
