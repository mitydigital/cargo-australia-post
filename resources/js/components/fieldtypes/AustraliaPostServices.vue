<script>
import { FieldtypeMixin } from '@statamic/cms';
import { Checkbox } from '@statamic/cms/ui';

export default {
    mixins: [FieldtypeMixin],

    components: { Checkbox },

    computed: {
        groups() {
            return this.meta.groups;
        },

        options() {
            return this.groups.flatMap(group => group.options);
        },

        selectedValues() {
            return this.value == null ? this.options.map(option => option.value) : (Array.isArray(this.value) ? this.value : []);
        },

        removedServices() {
            return this.selectedValues.filter(code => !this.options.some(option => option.value === code));
        },

        replicatorPreview() {
            if (this.value == null) return __('All configured services');
            if (!this.selectedValues.length) return __('No Australia Post services');

            return this.selectedValues.map(code => this.options.find(option => option.value === code)?.label || code).join(', ');
        },
    },

    methods: {
        groupSelected(group) {
            return group.options.length > 0 && group.options.every(option => this.selectedValues.includes(option.value));
        },

        groupPartial(group) {
            return !this.groupSelected(group) && group.options.some(option => this.selectedValues.includes(option.value));
        },

        toggleGroup(group, checked) {
            const codes = group.options.map(option => option.value);
            const selected = this.selectedValues.filter(code => !codes.includes(code));
            this.setSelection(checked ? [...selected, ...codes] : selected);
        },

        toggleService(code, checked) {
            const selected = this.selectedValues.filter(value => value !== code);
            this.setSelection(checked ? [...selected, code] : selected);
        },

        setSelection(values) {
            if (this.isReadOnly) return;

            const codes = this.options.map(option => option.value);
            const selected = [...new Set(values)];
            const ordered = [
                ...codes.filter(code => selected.includes(code)),
                ...selected.filter(code => !codes.includes(code)),
            ];
            const allSelected = ordered.length > 0 && ordered.length === codes.length && ordered.every(code => codes.includes(code));

            this.update(allSelected ? null : ordered);
        },

        focus() {
            this.$el.querySelector('[role="checkbox"], input')?.focus();
        },
    },
};
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Select the services that can deliver this product. An unchecked service will be unavailable for any order containing this product.') }}
        </p>

        <div v-for="group in groups" :key="group.handle" class="space-y-2">
            <Checkbox
                :label="group.label"
                :aria-label="__('Select all :group services', { group: group.label })"
                :model-value="groupSelected(group)"
                :indeterminate="groupPartial(group)"
                :disabled="isReadOnly || !group.options.length"
                @update:model-value="toggleGroup(group, $event)"
            />
            <div v-if="group.options.length" class="space-y-2 ps-6">
                <Checkbox
                    v-for="option in group.options"
                    :key="option.value"
                    :label="option.label"
                    :model-value="selectedValues.includes(option.value)"
                    :disabled="isReadOnly"
                    @update:model-value="toggleService(option.value, $event)"
                />
            </div>
            <p v-else class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('No services configured.') }}
            </p>
        </div>

        <div v-if="removedServices.length" class="space-y-2" role="alert">
            <p class="text-sm text-red-600 dark:text-red-400">
                {{ __('These services are no longer configured. Untick them before saving. They are not available at checkout.') }}
            </p>
            <Checkbox
                v-for="code in removedServices"
                :key="code"
                :label="code"
                :model-value="true"
                :disabled="isReadOnly"
                @update:model-value="toggleService(code, $event)"
            />
        </div>
    </div>
</template>
