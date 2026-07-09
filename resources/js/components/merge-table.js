/**
 * Alpine.js shell for the merge view page chrome: branch selection
 * checkboxes, quick filters and branch rating stars.
 *
 * The translation table itself (selection, editing, deletion, tag changes,
 * filters, search, sort, windowing) is the shared translation-editor core —
 * see the inline mergeView component in resources/views/merge/show.blade.php.
 */
export default function mergeTable() {
    return {
        init() {
            // Branch checkboxes: auto-submit on change (server reloads the
            // page with the new branch set; pending table state survives via
            // the shared core's sessionStorage persistence)
            document.querySelectorAll('.branch-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    document.getElementById('branchForm')?.submit();
                });
            });

            // Quick filter buttons for branches
            document.querySelectorAll('.branch-quick-filter').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const ids = btn.dataset.ids ? btn.dataset.ids.split(',') : [];
                    document.querySelectorAll('.branch-checkbox').forEach((cb) => {
                        cb.checked = ids.includes(cb.value);
                    });
                    document.getElementById('branchForm')?.submit();
                });
            });

            this.initBranchRating();
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
