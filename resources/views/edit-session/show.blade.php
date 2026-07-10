@extends('layouts.app')

@section('title', __('edit_session.title') . ($editSession->game_name ? ' - ' . $editSession->game_name : ''))

@section('content')
<div class="container mx-auto px-4 py-8" x-data="editSession">
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">
            <i class="fas fa-pen-to-square text-purple-400 mr-2"></i>{{ __('edit_session.title') }}
        </h1>
        <p class="text-gray-400">
            @if($editSession->game_name)
                {{ $editSession->game_name }}
                @if($editSession->source_language && $editSession->target_language)
                    &bull; {{ $editSession->source_language }} <i class="fas fa-arrow-right text-xs"></i> {{ $editSession->target_language }}
                @endif
            @endif
        </p>
        <p class="text-sm text-purple-300 mt-1">
            <i class="fas fa-gamepad mr-1"></i> {{ __('edit_session.subtitle') }}
        </p>
    </div>

    {{-- Live update toast (mod pushed changes from the game) --}}
    <div x-show="refreshNotice" x-cloak
        class="fixed top-4 right-4 z-50 bg-purple-900/90 border border-purple-600 rounded-lg px-4 py-3 text-purple-200 shadow-xl">
        <i class="fas fa-gamepad mr-2"></i><span x-text="refreshNotice"></span>
    </div>

    {{-- Loading state --}}
    <div x-show="!loaded" class="text-center py-12">
        <i class="fas fa-spinner fa-spin text-4xl text-purple-400 mb-4"></i>
        <p class="text-gray-400">{{ __('merge_preview.loading') }}</p>
    </div>

    {{-- Error state --}}
    <div x-show="error" x-cloak class="bg-red-900/50 border border-red-600 rounded-lg p-6 text-center">
        <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
        <p class="text-red-300" x-text="error"></p>
    </div>

    {{-- Main content --}}
    <div x-show="loaded && !error" x-cloak>
        {{-- Stats --}}
        <div class="mb-6 grid grid-cols-3 gap-4">
            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 text-center">
                <p class="text-2xl font-bold text-white" x-text="allKeys.length"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.total_keys') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-purple-700 text-center">
                <p class="text-2xl font-bold text-purple-400" x-text="totalChanges"></p>
                <p class="text-sm text-gray-400">{{ __('edit_session.pending_changes') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-green-700 text-center">
                <p class="text-2xl font-bold text-green-400" x-text="savedCount"></p>
                <p class="text-sm text-gray-400">{{ __('edit_session.saved_count') }}</p>
            </div>
        </div>

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-4 items-center text-sm bg-gray-800 p-4 rounded-lg border border-gray-700">
            <span class="text-gray-500">{{ __('merge_preview.show') }}:</span>

            {{-- Tag filters in HVASM order --}}
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagH" @change="toggleFilter('tagH')"
                    class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="tag-H">H</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagV" @change="toggleFilter('tagV')"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="tag-V">V</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagA" @change="toggleFilter('tagA')"
                    class="rounded bg-gray-700 border-gray-600 text-orange-600">
                <span class="tag-A">A</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagS" @change="toggleFilter('tagS')"
                    class="rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="tag-S">S</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagM" @change="toggleFilter('tagM')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="tag-M">M</span>
            </label>

            <span class="text-gray-600">|</span>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.pendingOnly" @change="toggleFilter('pendingOnly')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="text-purple-400">{{ __('edit_session.pending_changes') }}</span>
            </label>
        </div>

        @include('partials.editor-floating-search')

        {{-- Search (Enter/Shift+Enter navigate matches) + replace --}}
        <div class="mb-4 space-y-2" x-ref="searchBar">
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <input type="text" x-model="searchQuery" @keydown.enter.prevent="onSearchEnter($event)"
                        placeholder="{{ __('merge_preview.search_placeholder') }}"
                        class="w-full px-4 py-2 pl-10 pr-32 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                        <span x-show="hasQuery" x-cloak class="text-xs text-gray-500 tabular-nums" x-text="matchCounterText"></span>
                        <button x-show="hasQuery" x-cloak @click="prevMatch()" type="button"
                            class="text-gray-500 hover:text-white transition" title="{{ __('merge.search_prev') }}">
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button x-show="hasQuery" x-cloak @click="nextMatch()" type="button"
                            class="text-gray-500 hover:text-white transition" title="{{ __('merge.search_next') }}">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <button x-show="searchQuery" x-cloak @click="searchQuery = ''" type="button"
                            class="text-gray-500 hover:text-white transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <select x-model="searchScope"
                    class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                    title="{{ __('merge_preview.search_scope_title') }}">
                    <option value="both">{{ __('merge_preview.search_scope_both') }}</option>
                    <option value="keys">{{ __('merge_preview.search_scope_keys') }}</option>
                    <option value="values">{{ __('merge_preview.search_scope_values') }}</option>
                </select>
                <button type="button" @click="toggleReplace()"
                    :class="replaceOpen ? 'bg-purple-700 text-white border-purple-500' : 'bg-gray-800 text-gray-300 border-gray-700 hover:text-white'"
                    class="border rounded-lg px-3 py-2 text-sm transition" title="{{ __('merge.replace') }}">
                    <i class="fas fa-right-left"></i>
                </button>
            </div>
            {{-- Replace: single-row only, staged as a human edit (→ H), no replace-all --}}
            <div x-show="replaceOpen" x-cloak class="flex gap-2">
                <div class="relative flex-1">
                    <input type="text" x-model="replaceValue" @keydown.enter.prevent="replaceCurrent()"
                        placeholder="{{ __('merge.replace_with') }}"
                        class="w-full px-4 py-2 pl-10 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                    <i class="fas fa-right-left absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                </div>
                <button type="button" @click="replaceCurrent()" :disabled="replaceDisabled"
                    class="bg-purple-600 hover:bg-purple-700 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed px-4 py-2 rounded-lg text-white text-sm transition">
                    {{ __('merge.replace') }}
                </button>
            </div>
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700 mb-6">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 sticky top-0 z-10">
                    <tr>
                        <th class="px-4 py-3 text-left text-gray-400 font-medium cursor-pointer hover:text-white transition"
                            @click="toggleSort('key')">
                            <div class="flex items-center gap-2">
                                {{ __('merge_preview.key') }}
                                <i class="fas" :class="getSortIcon('key')"></i>
                            </div>
                        </th>
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                            @click="toggleSort('tag')">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-gray-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs" :class="getSortIcon('tag')"></i>
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left border-l border-gray-700 cursor-pointer hover:text-white transition"
                            @click="toggleSort('value')">
                            <div class="flex items-center gap-2">
                                <span class="text-purple-400 font-medium">{{ __('edit_session.translation_column') }}</span>
                                <i class="fas" :class="getSortIcon('value')"></i>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Windowed rendering: huge files stay snappy --}}
                    {{-- Keyed by translation key, NOT by position: index-keyed row
                         recycling was measured barely faster and desynced recycled
                         scopes (wrong values shown on wrong keys) — unacceptable in
                         an editor. The window size is the safe lever instead. --}}
                    <template x-for="(key, idx) in visibleKeys" :key="key">
                        <tr class="border-t border-gray-700 hover:bg-gray-750 transition-colors"
                            :class="isCurrentMatchRow(idx) ? 'current-match-row' : ''"
                            :data-row-index="idx">
                            {{-- Key --}}
                            <td class="px-4 py-2 font-mono text-xs text-gray-500 break-words" x-safe-html="highlightKey(key)"></td>

                            {{-- Tag (clickable for tag change) --}}
                            <td class="px-2 py-2 text-center border-l border-gray-700"
                                :class="hasTagChange(key) ? 'tag-changed-cell' : ''">
                                {{-- Shows the tag the save will PRODUCE (edit → H,
                                     M/S preserved), not just the stored one --}}
                                <button type="button"
                                    @click.stop="openTagDropdown($event, key, displayTag(key, getTag(data[key])), getValue(data[key]))"
                                    class="transition rounded cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800"
                                    title="{{ __('merge.click_to_change_tag') }}">
                                    <span :class="'tag-' + displayTag(key, getTag(data[key]))" x-text="displayTag(key, getTag(data[key]))"></span>
                                </button>
                            </td>

                            {{-- Value: single click validates an AI line (A → V, same
                                 gesture as clicking Main in the merge view — a double
                                 click toggles twice, so editing never alters the tag),
                                 double-click or pencil to edit --}}
                            <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="[isValidatedPending(key) ? 'selected-main' : '', isEdited(key) ? 'selected-manual' : '', isDeleted(key) ? 'deleted-cell' : '']"
                                @click="toggleValidate(key)"
                                @dblclick="editCell(key, getValue(data[key]))">
                                <span class="edit-affordance">
                                    {{-- Re-translate with the PLAYER's AI backend (request travels
                                         to the mod over SSE — the site holds no AI credential) --}}
                                    <button type="button" x-show="canRetranslate(key)" @click.stop="requestRetranslate(key)"
                                        title="{{ __('edit_session.retranslate') }}{{ $editSession->ai_model ? ' — ' . $editSession->ai_model : '' }}"><i class="fas fa-wand-magic-sparkles"></i></button>
                                    <button type="button" x-show="rowHasPending(key)" @click.stop="revertRow(key)"
                                        title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
                                    <button type="button" @click.stop="editCell(key, getValue(data[key]))"
                                        title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
                                    <button type="button" class="delete-btn" @click.stop="toggleDelete(key)"
                                        title="{{ __('translation.delete') }}"><i class="fas fa-trash"></i></button>
                                </span>
                                <span x-show="underlyingChanged[key]"
                                    class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                                    title="{{ __('edit_session.changed_in_game') }}">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('edit_session.changed_in_game') }}
                                </span>
                                {{-- Non-blocking guard: the pending edit altered [!v*N] placeholders --}}
                                <span x-show="hasPlaceholderWarning(key)" x-cloak
                                    class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                                    title="{{ __('merge.placeholder_warning') }}">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>Placeholders
                                </span>
                                {{-- The player's AI is working on this line --}}
                                <span x-show="retranslating[key]" x-cloak
                                    class="inline-block mb-1 px-1.5 py-0.5 rounded bg-purple-900/60 text-purple-300 text-xs">
                                    <i class="fas fa-spinner fa-spin mr-1"></i>{{ __('edit_session.retranslating') }}
                                </span>
                                <span class="break-words"
                                    :class="[isEdited(key) ? 'text-purple-300' : '', isDeleted(key) ? 'line-through opacity-40' : '']">
                                    <span x-show="isEdited(key)" x-safe-html="highlightValue(editedValues[key])"></span>
                                    <span x-show="!isEdited(key)" x-safe-html="highlightValue(getValue(data[key]))"></span>
                                </span>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="filteredKeys.length === 0">
                        <td colspan="3" class="px-4 py-12 text-center text-gray-500">
                            <i class="fas fa-filter text-4xl mb-3 text-gray-600"></i>
                            <p>{{ __('edit_session.no_entries') }}</p>
                        </td>
                    </tr>

                    <tr x-show="hiddenCount > 0">
                        <td colspan="3" class="px-4 py-3 text-center">
                            <button type="button" @click="showMore()"
                                class="text-purple-400 hover:text-purple-300 text-sm transition">
                                <i class="fas fa-chevron-down mr-1"></i>
                                {{ __('merge_preview.show_more') }} (<span x-text="hiddenCount"></span>)
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Footer with Save button. min-w-0 on the text + shrink-0 on the
             buttons: the instructions wrap instead of squeezing the save button.
             ↑↓ shortcuts float at both ends of the bar --}}
        <div class="flex flex-wrap gap-4 justify-between items-center bg-gray-800 p-4 rounded-lg border border-gray-700 sticky bottom-4">
            <div class="flex flex-col gap-1 shrink-0">
                <button type="button" @click="scrollToTop()"
                    class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_top') }}">
                    <i class="fas fa-angles-up"></i>
                </button>
                <button type="button" @click="scrollToBottom()"
                    class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_bottom') }}">
                    <i class="fas fa-angles-down"></i>
                </button>
            </div>
            <div class="text-sm text-gray-400 min-w-0 grow">
                <span x-show="totalChanges > 0">
                    <span class="text-white font-bold" x-text="totalChanges"></span> {{ __('merge_preview.modifications') }}
                </span>
                {{-- One line per gesture, with the same icons as the table --}}
                <div x-show="totalChanges === 0 && !saveMessage" class="text-gray-500 space-y-1">
                    <p>
                        <i class="fas fa-arrow-pointer w-4 text-center mr-1"></i>{{ __('edit_session.instructions_validate') }}
                        <span class="tag-A">A</span> <i class="fas fa-arrow-right text-xs"></i> <span class="tag-V">V</span>
                    </p>
                    <p><i class="fas fa-pen w-4 text-center mr-1"></i>{{ __('edit_session.instructions') }}</p>
                    <p><i class="fas fa-trash w-4 text-center mr-1"></i>{{ __('merge.instructions_delete') }}</p>
                </div>
                <span x-show="saveMessage" class="text-green-400">
                    <i class="fas fa-check-circle mr-1"></i><span x-text="saveMessage"></span>
                </span>
            </div>

            <div class="flex gap-4 items-center shrink-0">
                <button type="button" @click="clearAll()" x-show="totalChanges > 0"
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-times mr-1"></i> {{ __('merge_preview.cancel_changes') }}
                </button>

                <form method="POST" action="{{ route('edit-session.end') }}"
                    onsubmit="return confirm(@js(__('edit_session.end_confirm')))">
                    @csrf
                    <button type="submit"
                        class="text-red-400 hover:text-red-300 text-sm transition">
                        <i class="fas fa-power-off mr-1"></i> {{ __('edit_session.end_session') }}
                    </button>
                </form>

                <button type="button" @click="save()" :disabled="saving || totalChanges === 0"
                    class="bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-6 py-3 rounded-lg text-white font-bold transition">
                    <i class="fas fa-save mr-2" x-show="!saving"></i>
                    <i class="fas fa-spinner fa-spin mr-2" x-show="saving"></i>
                    {{ __('edit_session.save') }} (<span x-text="totalChanges">0</span>)
                </button>
                <div class="flex flex-col gap-1 shrink-0">
                    <button type="button" @click="scrollToTop()"
                        class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_top') }}">
                        <i class="fas fa-angles-up"></i>
                    </button>
                    <button type="button" @click="scrollToBottom()"
                        class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_bottom') }}">
                        <i class="fas fa-angles-down"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Edit Modal --}}
    <div x-show="editModal.open" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
        @click.self="closeEditModal()">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-2xl mx-4"
            @keydown.ctrl.enter="saveEditModal()">
            <div class="px-6 py-4 border-b border-gray-700">
                <h3 class="text-lg font-semibold text-white">{{ __('merge_preview.edit_translation') }}</h3>
                <p class="text-sm text-gray-400 font-mono mt-1 break-words" x-text="editModal.key"></p>
            </div>
            <div class="px-6 py-4">
                {{-- x-model must target a TOP-LEVEL property: the Alpine CSP
                     build prohibits property assignments (editModal.value = x) --}}
                <textarea
                    id="editModalTextarea"
                    x-model="editModalValue"
                    class="w-full h-48 px-4 py-3 bg-gray-900 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 resize-y"
                    placeholder="{{ __('merge_preview.enter_translation') }}"
                ></textarea>
                <p x-show="editModalPlaceholderMismatch" x-cloak class="mt-2 text-xs text-orange-400">
                    <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('merge.placeholder_warning') }}
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Ctrl+Enter</kbd> {{ __('merge_preview.to_save') }} &bull;
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Esc</kbd> {{ __('merge_preview.to_cancel') }}
                </p>
            </div>
            <div class="px-6 py-4 border-t border-gray-700 flex justify-end gap-3">
                <button type="button" @click="closeEditModal()"
                    class="px-4 py-2 text-gray-400 hover:text-white transition">
                    {{ __('merge_preview.cancel') }}
                </button>
                <button type="button" @click="saveEditModal()"
                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                    <i class="fas fa-check mr-1"></i> {{ __('merge_preview.save') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Tag Dropdown Menu (V = validate, A = invalidate, S = skip — same in every editor) --}}
    <div x-show="tagDropdown.open" x-cloak
        class="fixed z-50 bg-gray-800 rounded-lg shadow-xl border border-gray-600 py-1 min-w-[160px]"
        :style="'left: ' + tagDropdown.x + 'px; top: ' + tagDropdown.y + 'px;'"
        @click.outside="closeTagDropdown()"
        @keydown.escape="closeTagDropdown()">

        <div class="px-3 py-2 border-b border-gray-700">
            <p class="text-xs text-gray-400">{{ __('merge.change_tag_to') }}</p>
        </div>

        <button type="button"
            @click="setTag('V')"
            :class="tagDropdown.currentTag === 'V' ? 'bg-gray-700' : 'hover:bg-gray-700'"
            class="w-full px-3 py-2 text-left flex items-center gap-3 transition">
            <span class="tag-V">V</span>
            <span class="text-sm text-gray-300">{{ __('merge.tag_validate') }}</span>
            <span x-show="tagDropdown.currentTag === 'V'" class="ml-auto text-green-400">
                <i class="fas fa-check"></i>
            </span>
        </button>

        <button type="button"
            @click="setTag('S')"
            :class="tagDropdown.currentTag === 'S' ? 'bg-gray-700' : 'hover:bg-gray-700'"
            class="w-full px-3 py-2 text-left flex items-center gap-3 transition">
            <span class="tag-S">S</span>
            <span class="text-sm text-gray-300">{{ __('merge.tag_skip') }}</span>
            <span x-show="tagDropdown.currentTag === 'S'" class="ml-auto text-green-400">
                <i class="fas fa-check"></i>
            </span>
        </button>

        <button type="button"
            @click="setTag('A')"
            :class="tagDropdown.currentTag === 'A' ? 'bg-gray-700' : 'hover:bg-gray-700'"
            class="w-full px-3 py-2 text-left flex items-center gap-3 transition">
            <span class="tag-A">A</span>
            <span class="text-sm text-gray-300">{{ __('merge.tag_invalidate') }}</span>
            <span x-show="tagDropdown.currentTag === 'A'" class="ml-auto text-green-400">
                <i class="fas fa-check"></i>
            </span>
        </button>

        <template x-if="hasTagChange(tagDropdown.key)">
            <div class="border-t border-gray-700 mt-1 pt-1">
                <button type="button"
                    @click="cancelAndCloseTagDropdown(tagDropdown.key)"
                    class="w-full px-3 py-2 text-left flex items-center gap-3 text-gray-400 hover:bg-gray-700 hover:text-white transition">
                    <i class="fas fa-undo text-xs"></i>
                    <span class="text-sm">{{ __('merge.cancel_tag_change') }}</span>
                </button>
            </div>
        </template>
    </div>

    {{-- Legend (HVASM order) --}}
    <div class="mt-6 text-xs text-gray-500 flex flex-wrap gap-4">
        <span><span class="tag-H">H</span> Human</span>
        <span><span class="tag-V">V</span> Validated</span>
        <span><span class="tag-A">A</span> AI</span>
        <span><span class="tag-S">S</span> Skipped</span>
        <span><span class="tag-M">M</span> Mod UI</span>
        <span class="text-gray-600">|</span>
        <span><span class="inline-block w-3 h-3 bg-purple-900/50 rounded mr-1"></span> {{ __('merge_preview.manual_edit') }}</span>
    </div>
</div>

{{-- Editor styles (tags, cells, affordances) are shared in resources/css/app.css --}}
@push('head')
<style>
    [x-cloak] { display: none !important; }
</style>
@endpush

<script nonce="{{ $cspNonce }}">
// Shared editor core (modal, filters, search, sort, tag rules, windowing):
// resources/js/components/translation-editor.js, exposed by app.js.
// Only the edit-session specifics live here.
document.addEventListener('alpine:init', () => {
    // window.UGT is set by app.js (deferred module): it exists by the time
    // Alpine fires alpine:init, but NOT during the initial HTML parse
    Alpine.data('editSession', () => window.UGT.composeEditor({
        // UI state (filters/search) is shared across sessions of the same
        // browser tab; PENDING work is scoped to THIS session — restored
        // edits from a previous session would be ghost modifications on
        // keys this file may not even contain
        persistKey: 'edit_session_ui',
        pendingKey: 'edit_session_{{ $editSession->id }}_pending',
        filters: {
            tagH: true,
            tagV: true,
            tagA: true,
            tagS: true,
            tagM: true,
            pendingOnly: false
        }
    }, {
        loaded: false,
        error: null,
        saving: false,
        saveMessage: '',
        savedCount: 0,
        data: {},          // key -> {v, t} or string (session file, minus _metadata)
        allKeys: [],
        // Live sync with the game (mod pushes new AI translations / in-game edits)
        currentHash: null,
        pollTimer: null,
        refreshNotice: '',
        underlyingChanged: {},  // pending keys whose in-game value changed under the edit
        // Per-line AI retranslation, executed by the PLAYER's own backend:
        // the request travels to the mod over SSE, the result comes back
        // through the normal mod push. No AI credential ever touches the site.
        aiAvailable: @js((bool) $editSession->ai_available),
        retranslating: {},      // key -> request timestamp (visual state)

        _sync: null,

        init() {
            // Search/filters/sort survive page refreshes (F5 keeps the
            // session alive by design — the UI state survives with it)
            this.initEditorCore();

            // Fetch + parse + normalize + diff all run in a Web Worker:
            // doing them here froze the main thread ~200ms on every mod
            // push (translation files can be tens of MB), stalling cursor
            // and clicks while the game translates
            this._sync = window.UGT.createLiveSync('{{ route("edit-session.data") }}');
            this._sync.fetch()
                .then(result => {
                    // First fetch: the worker sends the full content,
                    // already normalized and metadata-stripped
                    this.data = result.full;
                    this.allKeys = Object.keys(result.full).sort();
                    this.loaded = true;
                    this.startLiveSync();
                })
                .catch(e => {
                    this.error = e.message === 'expired'
                        ? @js(__('edit_session.error_expired'))
                        : @js(__('merge_preview.error_load_failed'));
                    this.loaded = true;
                });
        },

        // ── Shared-core callbacks ────────────────────────────────────────

        rowPassesFilters(key) {
            if (this.filters.pendingOnly && !this.isEdited(key) && !this.hasTagChange(key) && !this.isDeleted(key)) {
                return false;
            }

            // Tag filter: the row passes on its STORED or its PREVIEWED tag —
            // a pending change must not make its row vanish mid-work
            return this.tagVisible(this.getTag(this.data[key]))
                || this.tagVisible(this.displayTag(key, this.getTag(this.data[key])));
        },

        rowMatchesSearch(key, query) {
            if (this.searchScope !== 'values' && key.toLowerCase().includes(query)) {
                return true;
            }
            if (this.searchScope !== 'keys') {
                // A pending edit matches on its OLD value too: correcting the
                // very text you searched for must not make the row vanish
                // before "Save & apply in game"
                if (this.getValue(this.data[key]).toLowerCase().includes(query)) return true;
                if (this.editedValues[key] !== undefined
                    && this.editedValues[key].toLowerCase().includes(query)) return true;
            }
            return false;
        },

        rowSortValue(key, column) {
            if (column === 'tag') {
                return this.getTag(this.data[key]);
            }
            // 'value' — sort on the stored value: a pending edit must not
            // make the row jump around while the user is still working
            return this.getValue(this.data[key]).toLowerCase();
        },

        /** Core hook: the stored editable value (replace, placeholder guard). */
        storedValue(key) {
            return this.getValue(this.data[key]);
        },

        // ── Per-line AI retranslation (player's own backend, via the mod) ──

        canRetranslate(key) {
            return this.aiAvailable && !this.isEdited(key) && !this.isDeleted(key)
                && !this.retranslating[key];
        },

        /**
         * Fire-and-forget: the site relays the key to the mod over SSE, the
         * mod re-translates with the player's configured backend and pushes
         * its file back — the result lands through the normal applyDiff.
         * The visual pending state frees itself after 3 minutes if the mod
         * never answers (AI error, session gap): the button simply returns.
         */
        requestRetranslate(key) {
            if (!this.canRetranslate(key)) return;
            this.setMatchCursor(key);
            this.retranslating[key] = Date.now();
            this._scheduleNextPoll(); // switch to the fast poll right away

            fetch('{{ route("edit-session.retranslate") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ key: key })
            })
                .then(response => {
                    if (!response.ok) throw new Error('request_failed');
                })
                .catch(() => {
                    delete this.retranslating[key];
                    this._scheduleNextPoll();
                });

            setTimeout(() => {
                if (this.retranslating[key]) {
                    delete this.retranslating[key];
                    this._scheduleNextPoll();
                }
            }, 180000);
        },

        // ── Click-to-validate (parity with the merge view's Main click) ──

        /** The row carries a pending validation (previewed V, green cell). */
        isValidatedPending(key) {
            return this.hasTagChange(key) && this.tagChanges[key].newTag === 'V';
        },

        /**
         * Single click on an AI-tagged line stages its validation (A → V)
         * as a regular tag change; clicking again cancels it.
         * A click only ever produces a REAL change (see
         * analyse/editors-gestures-parity.md): already-V lines, H/M/S
         * tags, pending edits and deleted rows are left alone —
         * devalidating is an explicit tag-dropdown gesture, never a click.
         */
        toggleValidate(key) {
            // Even on inert rows the click moves the search cursor (IDE caret)
            this.setMatchCursor(key);
            if (this.isDeleted(key) || this.isEdited(key)) return;
            if (this.isValidatedPending(key)) {
                this.cancelTagChange(key);
                return;
            }
            const tag = this.getTag(this.data[key]);
            if (tag !== 'A') return;
            this.tagChanges[key] = { newTag: 'V', originalTag: tag, value: this.getValue(this.data[key]) };
            this.persistPendingState();
        },

        get totalChanges() {
            const keys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges),
                ...Object.keys(this.deletions)
            ]);
            return keys.size;
        },

        // ── Live sync with the game ─────────────────────────────────────
        // The mod pushes its local file to the session when it changes
        // (new AI translations while playing, in-game edits). The state
        // endpoint doubles as the browser presence heartbeat.

        startLiveSync() {
            this._scheduleNextPoll();
            this.checkState(); // seed currentHash immediately

            // Tell the mod when the page goes away (close, navigation —
            // also fires on refresh, which the mod absorbs with its grace
            // period; the next state poll signals the rejoin)
            window.addEventListener('pagehide', () => {
                navigator.sendBeacon('{{ route("edit-session.leave") }}');
            });
        },

        /**
         * Self-rescheduling poll: 2s while a retranslation is pending (the
         * user is actively waiting on the mod's push), 10s otherwise.
         */
        _scheduleNextPoll() {
            clearTimeout(this.pollTimer);
            const delay = Object.keys(this.retranslating).length > 0 ? 2000 : 10000;
            this.pollTimer = setTimeout(() => {
                this.checkState();
                this._scheduleNextPoll();
            }, delay);
        },

        stopLiveSync() {
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
        },

        checkState() {
            fetch('{{ route("edit-session.state") }}', { headers: { 'Accept': 'application/json' } })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.status === 410 ? 'expired' : 'state_failed');
                    }
                    return response.json();
                })
                .then(state => {
                    // The player can toggle the mod's AI backend mid-session
                    if (typeof state.ai_available === 'boolean') {
                        this.aiAvailable = state.ai_available;
                    }
                    if (this.currentHash === null) {
                        this.currentHash = state.content_hash;
                        return;
                    }
                    if (state.content_hash !== this.currentHash) {
                        this.currentHash = state.content_hash;
                        this.refreshData();
                    }
                })
                .catch(e => {
                    if (e.message === 'expired') {
                        this.stopLiveSync();
                        this.error = @js(__('edit_session.error_expired'));
                    }
                    // transient network errors: next poll retries
                });
        },

        refreshData() {
            this._sync.fetch()
                .then(result => this.applyDiff(result.changed || {}, result.removed || []))
                .catch(() => { /* next poll retries */ });
        },

        /**
         * Apply the worker's diff. Entries identical to what we already
         * display are skipped: after OUR OWN save the worker's cache lags
         * behind and re-reports the saved entries — they must not count
         * as "updated from game".
         */
        applyDiff(changed, removed) {
            const pendingKeys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges)
            ]);

            let changedCount = 0;
            let keysChanged = false;
            for (const [key, value] of Object.entries(changed)) {
                if (key in this.data
                    && this.getValue(value) === this.getValue(this.data[key])
                    && this.getTag(value) === this.getTag(this.data[key])) {
                    continue;
                }
                // Flag pending keys whose in-game value changed under the
                // edit — the pending edit stays displayed and wins at save
                // (human > AI), the badge lets the user double-check
                if (pendingKeys.has(key) && key in this.data
                    && this.getValue(value) !== this.getValue(this.data[key])) {
                    this.underlyingChanged[key] = true;
                }
                // A requested retranslation came back through the mod's push
                if (this.retranslating[key]) {
                    delete this.retranslating[key];
                    this._scheduleNextPoll();
                }
                if (!(key in this.data)) keysChanged = true;
                this.data[key] = value;
                changedCount++;
            }
            for (const key of removed) {
                if (key in this.data) {
                    delete this.data[key];
                    changedCount++;
                    keysChanged = true;
                }
            }
            if (keysChanged) {
                this.allKeys = Object.keys(this.data).sort();
            }

            if (changedCount > 0) {
                this.refreshNotice = @js(__('edit_session.updated_from_game')) + ' (' + changedCount + ')';
                setTimeout(() => { this.refreshNotice = ''; }, 5000);
            }
        },

        // ── Actions ──────────────────────────────────────────────────────

        clearAll() {
            if (confirm(@js(__('merge_preview.confirm_cancel')))) {
                this.clearPendingState();
            }
        },

        save() {
            if (this.saving || this.totalChanges === 0) return;
            this.saving = true;
            this.saveMessage = '';

            // One selection per pending key; a value edit combined with a tag
            // change sends the new value AND the new tag. An explicit tag
            // change goes as 'local' even when combined with an edit: the
            // server writes 'local' tags as-is, while 'manual' would force H
            // and override the user's chosen tag
            const selections = [];
            const pendingKeys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges)
            ]);
            for (const key of pendingKeys) {
                const isEdited = this.editedValues[key] !== undefined;
                const value = isEdited ? this.editedValues[key] : this.getValue(this.data[key]);
                const tag = this.tagChanges[key]
                    ? this.tagChanges[key].newTag
                    : this.getTag(this.data[key]);
                selections.push({
                    key: key,
                    value: value,
                    tag: tag,
                    source: isEdited && !this.tagChanges[key] ? 'manual' : 'local'
                });
            }
            const deletions = Object.keys(this.deletions);

            fetch('{{ route("edit-session.save") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ selections, deletions })
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.status === 410 ? 'expired' : 'save_failed');
                    }
                    return response.json();
                })
                .then(result => {
                    // Saved entries become the new baseline (same tag rules as the server)
                    for (const sel of selections) {
                        let tag = sel.tag;
                        if (tag !== 'M' && tag !== 'S' && sel.source === 'manual') {
                            tag = 'H';
                        }
                        this.data[sel.key] = { v: sel.value, t: tag };
                        // Conflict resolved by this save: the user's version won
                        delete this.underlyingChanged[sel.key];
                    }
                    for (const key of deletions) {
                        delete this.data[key];
                        delete this.underlyingChanged[key];
                    }
                    if (deletions.length > 0) {
                        this.allKeys = Object.keys(this.data).sort();
                    }
                    this.clearPendingState();
                    this.savedCount += (result.saved || 0) + (result.deleted || 0);
                    // Our own save changed the session hash — don't refetch on next poll
                    this.currentHash = result.content_hash;
                    this.saveMessage = @js(__('edit_session.saved_ok'));
                    setTimeout(() => { this.saveMessage = ''; }, 5000);
                })
                .catch(e => {
                    if (e.message === 'expired') {
                        this.error = @js(__('edit_session.error_expired'));
                    } else {
                        this.saveMessage = '';
                        alert(@js(__('edit_session.save_failed')));
                    }
                })
                .finally(() => {
                    this.saving = false;
                });
        }
    }));
});
</script>
@endsection
