/**
 * Alpine.js shell for the merge view page chrome: branch selection
 * checkboxes, quick filters and branch rating stars.
 *
 * The translation table itself (selection, editing, deletion, tag changes,
 * filters, search, sort, windowing) is the shared translation-editor core —
 * see the inline mergeView component in resources/views/merge/show.blade.php.
 */
import { workSessionId } from './translation-editor.js';

export default function mergeTable() {
    return {
        /**
         * The id of the sitting, carried through the branch form's GET.
         *
         * 🔴 **This form navigates, and a navigation normally starts a fresh sitting** — the rule
         * everywhere else, and exactly wrong here: hiding one contribution would throw away
         * everything already decided about the others. Read (and created, once) from the shared
         * core, so the table below reads the same one.
         */
        workSession: workSessionId(),

        hideOneText: '',
        hideManyText: '',

        init() {
            this.hideOneText = this.$el.dataset.hideOne || '';
            this.hideManyText = this.$el.dataset.hideMany || '';

            // Branch checkboxes: auto-submit on change (server reloads the page with the new
            // branch set; the sitting's pending work survives — see the shared core)
            document.querySelectorAll('.branch-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    if (!this.confirmHiding(this.hiddenBy(checkbox))) {
                        checkbox.checked = !checkbox.checked;
                        return;
                    }
                    document.getElementById('branchForm')?.submit();
                });
            });

            // Quick filter buttons for branches
            document.querySelectorAll('.branch-quick-filter').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const ids = btn.dataset.ids ? btn.dataset.ids.split(',') : [];
                    const boxes = [...document.querySelectorAll('.branch-checkbox')];
                    const dropped = boxes.filter((cb) => cb.checked && !ids.includes(cb.value));

                    if (!this.confirmHiding(dropped)) return;

                    boxes.forEach((cb) => { cb.checked = ids.includes(cb.value); });
                    document.getElementById('branchForm')?.submit();
                });
            });

            this.initBranchRating();
        },

        /** The boxes this change is turning OFF — nothing to warn about when it turns one on. */
        hiddenBy(checkbox) {
            return checkbox.checked ? [] : [checkbox];
        },

        /**
         * Ask before hiding a contribution somebody has taken lines from.
         *
         * 🔴 **Hiding a contribution is closing it, and closing is a cancel** — the same rule as
         * closing the page: what was decided about it goes, and showing it again starts afresh.
         * Kept-but-inert answers coming back later is exactly the "you think one thing, it does
         * another" this screen must not do.
         *
         * ⚠ **Only picks are at stake, never rewordings.** Rewriting a line makes its answer
         * `manual` (see onEditStaged): it stops naming any contribution, so it survives whatever is
         * shown. The message says so, because "you will lose your work" would be false and is
         * exactly the kind of warning that teaches people to click through warnings.
         *
         * ⚠ Silent when nothing was taken from it. A confirmation for a decision that costs
         * nothing is a confirmation nobody reads the next time.
         */
        confirmHiding(boxes) {
            if (!boxes.length) return true;

            const asked = boxes
                .map((cb) => ({ name: cb.dataset.branchName || '', count: this.picksFrom(cb.value) }))
                .filter((b) => b.count > 0);

            if (!asked.length) return true;

            const lines = asked.map((b) => {
                const template = b.count === 1 ? this.hideOneText : this.hideManyText;
                return template.replace(':name', b.name).replace(':count', b.count);
            });

            return window.confirm(lines.join('\n\n'));
        },

        /**
         * How many answers somebody CHOSE by hand from that contribution.
         *
         * 🔴 **Claimed picks only, never what the screen answered on its own.** A pick the defaults
         * made is not work: hiding the contribution recomputes an equivalent one from those still
         * shown, so warning about it would be a warning about nothing — and warnings about nothing
         * are how people learn to click through the ones that matter.
         *
         * ⚠ The table is a separate Alpine component (the page chrome cannot reach into it), so it
         * is asked rather than read: the event carries a box the answering side fills in. One
         * question, one answer, no shared mutable state between the two.
         */
        picksFrom(branchId) {
            const ask = { branchId: String(branchId), count: 0 };
            window.dispatchEvent(new CustomEvent('ugt-count-picks-from', { detail: ask }));
            return ask.count;
        },

        /**
         * Branch rating stars (Main owner rates branches 1-5).
         */
        initBranchRating() {
            document.querySelectorAll('.branch-rating').forEach((container) => {
                const branchId = container.dataset.branchId;
                const stars = container.querySelectorAll('.rating-star');

                stars.forEach((star) => {
                    star.addEventListener('click', async (e) => {
                        e.preventDefault();
                        e.stopPropagation();

                        const rating = parseInt(star.dataset.rating);
                        const currentRating = this.getCurrentRating(container);

                        // Toggle off if clicking same rating
                        const newRating = (currentRating === rating) ? null : rating;

                        try {
                            const response = await fetch(`/translations/${branchId}/rate-branch`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify({ rating: newRating }),
                            });

                            const data = await response.json();

                            if (data.success) {
                                this.updateStarsDisplay(container, data.rating);
                            } else {
                                console.error('Rating failed:', data.error);
                            }
                        } catch (error) {
                            console.error('Rating error:', error);
                        }
                    });
                });
            });
        },

        getCurrentRating(container) {
            const stars = container.querySelectorAll('.rating-star');
            let rating = 0;
            stars.forEach((star, index) => {
                if (star.classList.contains('text-yellow-400')) {
                    rating = index + 1;
                }
            });
            return rating;
        },

        updateStarsDisplay(container, rating) {
            const stars = container.querySelectorAll('.rating-star');
            stars.forEach((star, index) => {
                if (rating && index < rating) {
                    star.classList.remove('text-gray-600');
                    star.classList.add('text-yellow-400');
                } else {
                    star.classList.remove('text-yellow-400');
                    star.classList.add('text-gray-600');
                }
            });

            // Remove modified indicator if rating was just set
            if (rating) {
                const modifiedIndicator = container.querySelector('.text-orange-400');
                if (modifiedIndicator) {
                    modifiedIndicator.remove();
                }
            }
        }
    };
}
