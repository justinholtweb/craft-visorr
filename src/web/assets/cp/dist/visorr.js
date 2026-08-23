/* Visorr — shared control-panel behaviour.
 *
 * Written as a classic script rather than a module: the control panel loads it through Craft's
 * asset bundle pipeline, there is no build step anywhere in this plugin, and nothing here needs
 * more than the DOM and Craft's own helpers.
 */
(function () {
    'use strict';

    /**
     * Pin toggles. The button carries its own state, so several can sit on one page — the
     * sidebar panel, the history screen and the comparison screen all render the same control.
     */
    function initPinToggles(root) {
        (root || document).querySelectorAll('[data-visorr-pin]').forEach(function (button) {
            if (button.dataset.visorrBound === '1') {
                return;
            }
            button.dataset.visorrBound = '1';

            button.addEventListener('click', function (event) {
                event.preventDefault();

                if (button.disabled) {
                    return;
                }

                button.disabled = true;

                Craft.sendActionRequest('POST', 'visorr/pins/toggle', {
                    data: {
                        revisionId: button.dataset.visorrPin,
                        label: button.dataset.visorrPinLabel || null,
                    },
                })
                    .then(function (response) {
                        var pinned = !!response.data.pinned;
                        button.setAttribute('aria-pressed', pinned ? 'true' : 'false');
                        button.title = pinned
                            ? Craft.t('visorr', 'Pinned — pruning will leave this revision alone')
                            : Craft.t('visorr', 'Pin this revision');
                        var row = button.closest('[data-visorr-revision]');
                        if (row) {
                            row.classList.toggle('visorr-is-pinned', pinned);
                        }
                        Craft.cp.displayNotice(response.data.message);
                    })
                    .catch(function (error) {
                        Craft.cp.displayError(
                            (error && error.response && error.response.data && error.response.data.message) ||
                                Craft.t('visorr', 'Could not change the pin.')
                        );
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
            });
        });
    }

    /**
     * A destructive action that only arms once its confirmation phrase has been typed exactly.
     *
     * Deliberately not a `confirm()` dialog: a dialog is dismissed by muscle memory, and the
     * whole point is to interrupt it. Browser dialogs also block the extension-driven testing
     * this plugin is verified with.
     */
    function initTypedConfirmations(root) {
        (root || document).querySelectorAll('[data-visorr-confirm]').forEach(function (form) {
            if (form.dataset.visorrBound === '1') {
                return;
            }
            form.dataset.visorrBound = '1';

            var input = form.querySelector('[data-visorr-confirm-input]');
            var button = form.querySelector('[data-visorr-confirm-button]');
            var phrase = form.dataset.visorrConfirm;

            if (!input || !button) {
                return;
            }

            var sync = function () {
                var matches = input.value.trim() === phrase;
                button.disabled = !matches;
                button.classList.toggle('disabled', !matches);
            };

            input.addEventListener('input', sync);
            sync();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPinToggles(document);
        initTypedConfirmations(document);
    });

    window.Visorr = window.Visorr || {};
    window.Visorr.initPinToggles = initPinToggles;
    window.Visorr.initTypedConfirmations = initTypedConfirmations;
})();
