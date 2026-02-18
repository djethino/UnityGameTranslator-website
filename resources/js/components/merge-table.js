/**
 * Alpine.js component for the merge table view.
 * Handles selection, editing, deletion, tag changes, and tracking of translation modifications.
 * Persists state to sessionStorage to survive page navigation (pagination, search, sort).
 */
export default function mergeTable() {
    return {
        uuid: '',
        isMain: true, // true if user is Main owner, false for branches
        selections: {},
        deletions: {},
        tagChanges: {}, // { key: { newTag: 'S', originalTag: 'A', value: '...' } }

        // Dropdown state for tag editing
        tagDropdown: {
            open: false,
            key: '',
            currentTag: '',
            value: '',
            x: 0,
            y: 0
        },

        // Modal state for multiline editing
        editModal: {
            open: false,
            key: '',
            value: '',
            originalValue: ''
        },

        // Storage key for this merge session
        get storageKey() {
            return `merge_state_${this.uuid}`;
        },

        init() {
            // Read parameters from data attributes (CSP-safe, no eval)
            this.uuid = this.$el.dataset.uuid || '';
            this.isMain = this.$el.dataset.isMain !== 'false';

            // Restore state from sessionStorage
            this.restoreState();

            // Auto-submit filter checkboxes (save state before submit)
            document.querySelectorAll('.filter-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    this.saveState();
                    checkbox.form.submit();
                });
            });

            // Save state before any link navigation (pagination, sort)
            document.querySelectorAll('a[href*="page="], a[href*="sort="]').forEach((link) => {
                link.addEventListener('click', () => {
                    this.saveState();
                });
            });

            // Save state before search form submit
            const searchForm = document.querySelector('form[method="GET"]:has(input[name="search"])');
            if (searchForm) {
                searchForm.addEventListener('submit', () => {
                    this.saveState();
                });
            }

            // Clear state on successful form submit (merge applied)
            const mergeForm = document.getElementById('mergeForm');
            if (mergeForm) {
                mergeForm.addEventListener('submit', () => {
                    this.clearState();
                });
            }

            // Close modal on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.editModal.open) {
                    this.closeEditModal();
                }
            });

            // Branch rating stars
            this.initBranchRating();
        },

        /**
         * Save current state to sessionStorage.
         */
        saveState() {
            const state = {
                selections: this.selections,
                deletions: this.deletions,
                tagChanges: this.tagChanges
            };
            sessionStorage.setItem(this.storageKey, JSON.stringify(state));
        },

        /**
         * Restore state from sessionStorage.
         */
        restoreState() {
            const stored = sessionStorage.getItem(this.storageKey);
            if (stored) {
                try {
                    const state = JSON.parse(stored);
                    this.selections = state.selections || {};
                    this.deletions = state.deletions || {};
                    this.tagChanges = state.tagChanges || {};
                    // Update hidden inputs to reflect restored state
                    this.updateHiddenInputs();
                } catch (e) {
                    console.error('Failed to restore merge state:', e);
                }
            }
        },

        /**
         * Clear state from sessionStorage (after successful submit).
         */
        clearState() {
            sessionStorage.removeItem(this.storageKey);
        },

        /**
         * Initialize branch rating functionality.
         * Allows Main owner to rate branches with 1-5 stars.
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
        },

        get selectionCount() {
            return Object.keys(this.selections).length;
        },

        get deleteCount() {
            return Object.keys(this.deletions).length;
        },

        get tagChangeCount() {
            return Object.keys(this.tagChanges).length;
        },

        get totalChanges() {
            return this.selectionCount + this.deleteCount + this.tagChangeCount;
        },

        isSelected(key, source) {
            return this.selections[key]?.source === source;
        },

        isEdited(key) {
            return this.selections[key]?.source === 'manual';
        },

        isDeleted(key) {
            return this.deletions[key] === true;
        },

        toggleDelete(key) {
            if (this.deletions[key]) {
                // Unmark deletion
                delete this.deletions[key];
            } else {
                // Mark for deletion and remove any selection for this key
                this.deletions[key] = true;
                delete this.selections[key];
            }
            this.updateHiddenInputs();
            this.saveState();
        },

        getEditedValue(key) {
            return this.selections[key]?.value || '';
        },

        getCellClass(key, source) {
            const sel = this.selections[key];
            if (!sel) return '';
            if (sel.source === source) {
                if (source === 'main') return 'selected-main';
                if (sel.source === 'manual') return 'selected-manual';
                return 'selected-branch';
            }
            // If this key is selected but from a different source, show the edited value in main
            if (source === 'main' && sel.source === 'manual') {
                return 'selected-manual';
            }
            return '';
        },

        select(key, source, value, tag) {
            // Toggle if same selection
            if (this.selections[key]?.source === source && this.selections[key]?.source !== 'manual') {
                delete this.selections[key];
            } else {
                this.selections[key] = { source, value, tag };
            }
            this.updateHiddenInputs();
            this.saveState();
        },

        editCell(key, currentValue) {
            const existingValue = this.selections[key]?.value ?? currentValue;
            this.editModal = {
                open: true,
                key: key,
                value: existingValue,
                originalValue: currentValue
            };

            // Focus textarea after modal opens
            this.$nextTick(() => {
                const textarea = document.getElementById('editModalTextarea');
                if (textarea) {
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }
            });
        },

        saveEditModal() {
            const { key, value, originalValue } = this.editModal;

            if (value === '') {
                // Empty value = clear selection for this key
                delete this.selections[key];
            } else if (value !== originalValue) {
                // Only save if changed
                this.selections[key] = {
                    source: 'manual',
                    value: value,
                    tag: 'H'
                };
            }

            this.updateHiddenInputs();
            this.saveState();
            this.closeEditModal();
        },

        closeEditModal() {
            this.editModal = {
                open: false,
                key: '',
                value: '',
                originalValue: ''
            };
        },

        clearAll() {
            if (confirm('Annuler toutes les modifications ?')) {
                this.selections = {};
                this.deletions = {};
                this.tagChanges = {};
                this.updateHiddenInputs();
                this.clearState();
            }
        },

        // ========================================
        // Tag Change Methods
        // ========================================

        /**
         * Check if a key has a pending tag change.
         */
        hasTagChange(key) {
            return key in this.tagChanges;
        },

        /**
         * Get the new tag for a key (if changed), or the original tag.
         */
        getDisplayTag(key, originalTag) {
            if (this.tagChanges[key]) {
                return this.tagChanges[key].newTag;
            }
            return originalTag;
        },

        /**
         * Open the tag dropdown menu for a key.
         */
        openTagDropdown(event, key, currentTag, value) {
            event.stopPropagation();

            // Position dropdown near the click
            const rect = event.target.getBoundingClientRect();
            this.tagDropdown = {
                open: true,
                key: key,
                currentTag: this.getDisplayTag(key, currentTag),
                originalTag: currentTag,
                value: value,
                x: rect.left,
                y: rect.bottom + window.scrollY
            };
        },

        /**
         * Close the tag dropdown.
         */
        closeTagDropdown() {
            this.tagDropdown = {
                open: false,
                key: '',
                currentTag: '',
                originalTag: '',
                value: '',
                x: 0,
                y: 0
            };
        },

        /**
         * Change the tag for a key.
         * - Both Main and Branch can set tag to 'S' (Skip)
         * - Only Main can set tag to 'A' (Invalidate)
         */
        setTag(newTag) {
            const { key, originalTag, value } = this.tagDropdown;

            // Permission check: only Main can set 'A'
            if (newTag === 'A' && !this.isMain) {
                console.warn('Only Main owner can invalidate translations');
                this.closeTagDropdown();
                return;
            }

            // If setting back to original tag, remove the change
            if (newTag === originalTag) {
                delete this.tagChanges[key];
            } else {
                // Store the tag change
                this.tagChanges[key] = {
                    newTag: newTag,
                    originalTag: originalTag,
                    value: value
                };

                // Remove any selection for this key (tag change takes precedence)
                delete this.selections[key];
            }

            this.updateHiddenInputs();
            this.saveState();
            this.closeTagDropdown();
        },

        /**
         * Cancel a pending tag change for a key.
         */
        cancelTagChange(key) {
            delete this.tagChanges[key];
            this.updateHiddenInputs();
            this.saveState();
        },

        /**
         * Cancel a tag change and close the dropdown (CSP-safe single method call).
         */
        cancelAndCloseTagDropdown(key) {
            this.cancelTagChange(key);
            this.closeTagDropdown();
        },

        /**
         * Check if the tag can be changed to the specified value.
         */
        canSetTag(newTag) {
            // Main can set any tag
            if (this.isMain) {
                return true;
            }
            // Branch can only set 'S' (skip)
            return newTag === 'S';
        },

        updateHiddenInputs() {
            // Update selections container
            const selectionsContainer = document.getElementById('selectionsContainer');
            if (selectionsContainer) {
                selectionsContainer.replaceChildren();

                let i = 0;
                for (const [key, data] of Object.entries(this.selections)) {
                    const keyInput = document.createElement('input');
                    keyInput.type = 'hidden';
                    keyInput.name = `selections[${i}][key]`;
                    keyInput.value = key;

                    const valueInput = document.createElement('input');
                    valueInput.type = 'hidden';
                    valueInput.name = `selections[${i}][value]`;
                    valueInput.value = data.value;

                    const tagInput = document.createElement('input');
                    tagInput.type = 'hidden';
                    tagInput.name = `selections[${i}][tag]`;
                    tagInput.value = data.tag;

                    const sourceInput = document.createElement('input');
                    sourceInput.type = 'hidden';
                    sourceInput.name = `selections[${i}][source]`;
                    sourceInput.value = data.source;

                    selectionsContainer.appendChild(keyInput);
                    selectionsContainer.appendChild(valueInput);
                    selectionsContainer.appendChild(tagInput);
                    selectionsContainer.appendChild(sourceInput);

                    i++;
                }
            }

            // Update deletions container
            const deletionsContainer = document.getElementById('deletionsContainer');
            if (deletionsContainer) {
                deletionsContainer.replaceChildren();

                let i = 0;
                for (const key of Object.keys(this.deletions)) {
                    const keyInput = document.createElement('input');
                    keyInput.type = 'hidden';
                    keyInput.name = `deletions[${i}]`;
                    keyInput.value = key;
                    deletionsContainer.appendChild(keyInput);
                    i++;
                }
            }

            // Update tag changes container
            const tagChangesContainer = document.getElementById('tagChangesContainer');
            if (tagChangesContainer) {
                tagChangesContainer.replaceChildren();

                let i = 0;
                for (const [key, data] of Object.entries(this.tagChanges)) {
                    const keyInput = document.createElement('input');
                    keyInput.type = 'hidden';
                    keyInput.name = `tagChanges[${i}][key]`;
                    keyInput.value = key;

                    const tagInput = document.createElement('input');
                    tagInput.type = 'hidden';
                    tagInput.name = `tagChanges[${i}][tag]`;
                    tagInput.value = data.newTag;

                    const valueInput = document.createElement('input');
                    valueInput.type = 'hidden';
                    valueInput.name = `tagChanges[${i}][value]`;
                    valueInput.value = data.value;

                    tagChangesContainer.appendChild(keyInput);
                    tagChangesContainer.appendChild(tagInput);
                    tagChangesContainer.appendChild(valueInput);

                    i++;
                }
            }
        }
    }
}
