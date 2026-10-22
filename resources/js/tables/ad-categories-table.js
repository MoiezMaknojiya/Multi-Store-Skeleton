/**
 * Ad Builder → Categories: the platform's one list of categories ("Menu", "Beer", "Education"). A name each, made, renamed and
 * deleted above the organizations alone; a delete takes the category off its ads and is one confirmation away.
 */
import { createCrudTable } from '../core/crud-table-base.js';
import { validate, required, maxLen } from '../core/validate.js';

export function registerAdCategoriesTable(Alpine) {
    Alpine.data('adCategoriesTable', createCrudTable({
        fetchUrl: '/builder/categories/data',
        dataKey: 'categories',
        entityLabel: 'category',
        formModalName: 'category-form-modal',
        deleteModalName: 'confirm-category-deletion',
        defaultForm: { name: '' },
        mapItemToForm: (category) => ({ name: category.name }),

        /* Mirrors AdCategoryController's rules (a name new to the list stays the server's to say). */
        validateForm: (form) => validate(form, {
            name: [required('Name'), maxLen('Name', 40)],
        }),
    }));
}
