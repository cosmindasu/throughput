<?php

/**
 * PUBLICAT din framework, VERBATIM — vezi docblock-ul din `lang/en/auth.php` pentru motiv
 * și pentru procedura de upgrade. Array-ul e copie exactă, verificată cu `===`.
 *
 * NU edita valorile engleze de mai jos. Fișierul nu există ca să schimbe engleza, ci ca să
 * AIBĂ o pereche franceză: fără el, `App::setLocale('fr')` lăsa ORICE eroare de formular în
 * engleză — câmp obligatoriu, e-mail invalid, lungime maximă — pe o interfață altfel complet
 * franceză, exact tiparul pe care FR-I18N-02 îl numește „găuri invizibile pe ecranele mai
 * puțin vizitate".
 *
 * Aplicația folosește azi 28 de reguli distincte, deci majoritatea cheilor de aici nu se
 * randează niciodată. Publicate totuși INTEGRAL, nu pe subsetul folosit — și ăsta nu e zel,
 * ci consecința modului în care `FileLoader` îmbină cele două căi de limbă (explicat în
 * `lang/en/auth.php`): o cheie absentă din fișierele NOASTRE se rezolvă în continuare din
 * `vendor/`, în engleză, iar `i18n:coverage` n-o vede, fiindcă lipsește simetric. Un subset
 * ar fi însemnat că prima folosire a regulii `uuid` sau `mimes` trece tăcut pe engleză, fără
 * ca nimic să pice. Doar cheile scrise aici sunt păzite.
 *
 * `custom` și `attributes` — vezi notele de la ele în `lang/fr/validation.php`.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'array_keys' => 'The :attribute field must only contain the following keys: :values.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'base64' => 'The :attribute field must be a valid Base64 string.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'account_id' => 'account',
        'acknowledge_backorder' => 'backorder acknowledgement',
        'active' => 'active',
        'amount' => 'amount',
        'attributes' => 'attributes',
        'billing_address' => 'billing address',
        'billing_address.city' => 'billing address city',
        'billing_address.country' => 'billing address country',
        'billing_address.line1' => 'billing address line 1',
        'billing_address.postal_code' => 'billing address postal code',
        'billing_address.state' => 'billing address state',
        'category' => 'category',
        'columns' => 'columns',
        'columns.*' => 'column',
        'confirm_duplicate_email' => 'duplicate email confirmation',
        'confirmed' => 'confirmed',
        'contact' => 'contact',
        'contact.email' => 'contact email',
        'contact.first_name' => 'contact first name',
        'contact.last_name' => 'contact last name',
        'contact.phone' => 'contact phone',
        'contact.title' => 'contact title',
        'contact_id' => 'contact',
        'cost' => 'cost',
        'credentials' => 'credentials',
        'credentials.api_key' => 'API key',
        'credit_terms' => 'credit terms',
        'currency' => 'currency',
        'deal_id' => 'deal',
        'delta' => 'quantity change',
        'direction' => 'direction',
        'domain' => 'domain',
        'email' => 'email',
        'expected_close_date' => 'expected close date',
        'file' => 'file',
        'filter' => 'filter',
        'filter.*' => 'filter value',
        'first_name' => 'first name',
        'format' => 'format',
        'from_location_id' => 'source location',
        'ids' => 'selected records',
        'ids.*' => 'selected record',
        'industry' => 'industry',
        'is_active' => 'active',
        'is_lost' => 'lost',
        'is_primary' => 'primary',
        'is_won' => 'won',
        'key' => 'key',
        'last_name' => 'last name',
        'lines' => 'lines',
        'lines.*' => 'quantity',
        'lines.*.discount' => 'line discount',
        'lines.*.quantity' => 'line quantity',
        'lines.*.unit_price' => 'line unit price',
        'lines.*.variant_id' => 'line variant',
        'locale' => 'language',
        'location_id' => 'location',
        'lost_reason' => 'lost reason',
        'low_stock_threshold' => 'low stock threshold',
        'mapping' => 'column mapping',
        'mapping.*' => 'mapped column',
        'method' => 'payment method',
        'mode' => 'mode',
        'name' => 'name',
        'new_owner_user_id' => 'new owner',
        'note' => 'note',
        'notes' => 'notes',
        'opt_out' => 'marketing opt-out',
        'owner_user_id' => 'owner',
        'paid_at' => 'payment date',
        'password' => 'password',
        'phone' => 'phone',
        'price' => 'price',
        'primary_contact_id' => 'primary contact',
        'probability' => 'probability',
        'provider' => 'carrier',
        'quantity' => 'quantity',
        'reason' => 'reason',
        'reassign' => 'reassignment',
        'recipients' => 'recipients',
        'recipients.*' => 'recipient',
        'report_type' => 'source',
        'resolvedTheme' => 'resolved theme',
        'resource_type' => 'resource type',
        'role' => 'role',
        'saved_view_id' => 'saved view',
        'schedule_day' => 'schedule day',
        'schedule_frequency' => 'schedule frequency',
        'schedule_time' => 'schedule time',
        'selectAllMatching' => 'select all matching',
        'shipping_address' => 'shipping address',
        'shipping_address.city' => 'shipping address city',
        'shipping_address.country' => 'shipping address country',
        'shipping_address.line1' => 'shipping address line 1',
        'shipping_address.postal_code' => 'shipping address postal code',
        'shipping_address.state' => 'shipping address state',
        'sku' => 'SKU',
        'sort' => 'sort',
        'source' => 'source',
        'stage_ids' => 'stages',
        'stage_ids.*' => 'stage',
        'status' => 'status',
        'tags' => 'tags',
        'tags.*' => 'tag',
        'theme' => 'theme',
        'title' => 'title',
        'to_location_id' => 'destination location',
        'to_stage_id' => 'target stage',
        'token' => 'token',
        'unit_of_measure' => 'unit of measure',
        'value' => 'value',
        'visibility' => 'visibility',
        'weight' => 'weight',
    ],

];
