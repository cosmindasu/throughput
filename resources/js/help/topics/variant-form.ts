import type { HelpTopic } from '@/help/types';

/**
 * `Variants/Create`, `Variants/Edit` — specs.md §10.2.
 */
const variantForm: HelpTopic = {
    id: 'variant-form',
    title: 'Variant',
    whatIsThis:
        'A single SKU: the exact thing a customer orders, with its own SKU code, price, cost and (optionally) weight and free-form attributes like size or pack.',
    whatCanYouDo: [
        'Set the "SKU" — it must be unique across your whole workspace, not just this product.',
        'Set the list "Price" customers pay and the "Cost" you pay — the difference is the margin.',
        'Set a "Weight", used later for shipping calculations.',
        'Mark the variant inactive instead of deleting it, if you stop selling it but keep its history.',
    ],
    rules: [
        'Only Owner and Manager reach this form at all — Agent and Viewer never see "Add variant" or "Edit" on a variant.',
        '"Cost" is saved like any other field here, but it never appears on this variant anywhere Agent or Viewer can look — margin is an Owner/Manager concern only (specs.md §7.4).',
        "A duplicate SKU is rejected before saving, with the error next to the field — not a generic failure.",
    ],
    howItsBuilt: {
        summary:
            'The SKU uniqueness check runs against the workspace\'s own variants only: the underlying database rule is bypassed by validation shortcuts unless the tenant is filtered in explicitly, so a SKU already used by a different workspace never blocks yours.',
    },
};

export default variantForm;
