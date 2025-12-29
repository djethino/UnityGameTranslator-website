/**
 * Alpine.js component for the merge table view.
 * Handles selection, editing, and tracking of translation modifications.
 */
export default function mergeTable() {
    return {
        selections: {},

        // Modal state for multiline editing
        editModal: {
            open: false,
            key: '',
            value: '',
            originalValue: ''
        },

        init() {
            // Auto-submit filter checkboxes
            document.querySelectorAll('.filter-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', function() {
                    this.form.submit();
                });
            });

            // Close modal on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.editModal.open) {
                    this.closeEditModal();
                }
            });
        },

        get selectionCount() {
            return Object.keys(this.selections).length;
        },

        isSelected(key, source) {
            return this.selections[key]?.source === source;
        },

        isEdited(key) {
            return this.selections[key]?.source === 'manual';
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

        clearSelections() {
            if (confirm('Annuler toutes les selections ?')) {
                this.selections = {};
                this.updateHiddenInputs();
            }
        },

        updateHiddenInputs() {
            const container = document.getElementById('selectionsContainer');
            if (!container) return;

            container.innerHTML = '';

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

                container.appendChild(keyInput);
                container.appendChild(valueInput);
                container.appendChild(tagInput);
                container.appendChild(sourceInput);

                i++;
            }
        }
    }
}
