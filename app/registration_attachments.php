<?php
declare(strict_types=1);

/**
 * Supported government ID types. Keys are stored in registration_documents.id_type.
 * Keep the keys stable when changing the display labels.
 */
function registration_id_type_choices(): array
{
    return [
        'national_id' => 'Philippine National ID (PhilID)',
        'passport' => 'Philippine Passport',
        'drivers_license' => "Driver's License",
        'umid' => 'UMID',
        'prc_id' => 'PRC ID',
        'postal_id' => 'Postal ID',
        'voters_id' => "Voter's ID",
        'other_government_id' => 'Other Government-issued ID',
    ];
}

function registration_id_type_label(?string $idType): string
{
    if ($idType === null || $idType === '') return 'Valid ID (type not recorded)';
    return registration_id_type_choices()[$idType] ?? 'Valid ID (other type)';
}

/**
 * Normalize PHP's $_FILES['field'][attribute][rowIndex] layout into rows.
 * Errors are returned to the caller; malformed uploads never bypass validation.
 */
function registration_unpack_uploads(mixed $group, string $label, array &$errors): array
{
    if ($group === null) return [];
    if (!is_array($group)) {
        $errors[] = $label . ' upload data is invalid.';
        return [];
    }
    foreach (['name', 'tmp_name', 'error', 'size'] as $attribute) {
        if (!isset($group[$attribute]) || !is_array($group[$attribute])) {
            $errors[] = $label . ' upload data is invalid.';
            return [];
        }
    }

    $rows = [];
    $keys = array_unique(array_merge(
        array_keys($group['name']),
        array_keys($group['tmp_name']),
        array_keys($group['error']),
        array_keys($group['size'])
    ));
    foreach ($keys as $key) {
        if (!(is_int($key) || (is_string($key) && ctype_digit($key))) || (int)$key < 0 || (int)$key > 999) {
            $errors[] = $label . ' contains an invalid upload index.';
            continue;
        }
        $name = $group['name'][$key] ?? '';
        $tmp = $group['tmp_name'][$key] ?? '';
        $error = $group['error'][$key] ?? UPLOAD_ERR_NO_FILE;
        $size = $group['size'][$key] ?? 0;
        if (!is_string($name) || !is_string($tmp) || !is_int($error) || !is_int($size)) {
            $errors[] = $label . ' contains malformed file information.';
            continue;
        }
        $rows[(int)$key] = [
            'name' => $name,
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => $size,
            'type' => is_string($group['type'][$key] ?? '') ? $group['type'][$key] : '',
        ];
    }
    return $rows;
}

/**
 * Validates selected ID types and every file (server-side).
 * Supports the prior single-upload fields during rolling deployment.
 *
 * @return array{items: array<int,array{document_type:string,id_type:?string,info:array}>,errors:array<int,string>}
 */
function registration_attachment_batch(array $post, array $files, bool $requiresValidId): array
{
    $errors = [];
    $items = [];
    $selectedTypes = $post['id_types'] ?? [];
    $newIdFields = array_key_exists('id_types', $post) || array_key_exists('id_files', $files);

    if ($newIdFields) {
        if (!is_array($selectedTypes)) {
            $errors[] = 'Valid ID type selections are invalid.';
            $selectedTypes = [];
        }
        $idUploads = registration_unpack_uploads($files['id_files'] ?? null, 'Valid ID', $errors);
        $keys = array_unique(array_merge(array_keys($selectedTypes), array_keys($idUploads)));
        if (count($keys) > 5) $errors[] = 'Upload at most 5 Valid IDs.';
        if (isset($files['valid_id'])) $errors[] = 'Use only one format for Valid ID uploads.';

        foreach (array_slice($keys, 0, 6) as $key) {
            if (!(is_int($key) || (is_string($key) && ctype_digit($key))) || (int)$key < 0 || (int)$key > 999) {
                $errors[] = 'A Valid ID row has an invalid index.';
                continue;
            }
            $rowNumber = (int)$key + 1;
            $selectedType = $selectedTypes[$key] ?? '';
            if (!is_string($selectedType)) {
                $errors[] = "Valid ID #{$rowNumber} has an invalid ID type.";
                continue;
            }
            $selectedType = trim($selectedType);
            $file = $idUploads[(int)$key] ?? null;
            $hasFile = $file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE;

            if ($selectedType === '' && !$hasFile) continue;
            if (!array_key_exists($selectedType, registration_id_type_choices())) {
                $errors[] = "Select a valid ID type for Valid ID #{$rowNumber}.";
            }
            if (!$hasFile) {
                $errors[] = "Upload a file for Valid ID #{$rowNumber}.";
                continue;
            }
            $info = upload_details($file, true);
            if (isset($info['error'])) {
                $errors[] = "Valid ID #{$rowNumber}: " . $info['error'];
            } elseif (array_key_exists($selectedType, registration_id_type_choices())) {
                $items[] = ['document_type' => 'valid_id', 'id_type' => $selectedType, 'info' => $info];
            }
        }
    } else {
        // Backward compatibility for older registration forms during deployment.
        $info = upload_details($files['valid_id'] ?? null, $requiresValidId);
        if (isset($info['error'])) $errors[] = $info['error'];
        elseif (!isset($info['none'])) {
            $items[] = ['document_type' => 'valid_id', 'id_type' => null, 'info' => $info];
        }
    }

    if (array_key_exists('supporting_files', $files)) {
        if (isset($files['supporting_document'])) $errors[] = 'Use only one format for supporting documents.';
        $extras = registration_unpack_uploads($files['supporting_files'], 'Supporting document', $errors);
        if (count($extras) > 5) $errors[] = 'Upload at most 5 supporting documents.';
        foreach (array_slice($extras, 0, 6, true) as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
            $info = upload_details($file, false);
            if (isset($info['error'])) {
                $errors[] = 'Supporting document #' . ((int)$key + 1) . ': ' . $info['error'];
            } elseif (!isset($info['none'])) {
                $items[] = ['document_type' => 'supporting_document', 'id_type' => null, 'info' => $info];
            }
        }
    } else {
        $info = upload_details($files['supporting_document'] ?? null, false);
        if (isset($info['error'])) $errors[] = $info['error'];
        elseif (!isset($info['none'])) {
            $items[] = ['document_type' => 'supporting_document', 'id_type' => null, 'info' => $info];
        }
    }

    if ($requiresValidId && !in_array('valid_id', array_column($items, 'document_type'), true) && !$errors) {
        $errors[] = 'Upload at least one Valid ID and select its ID type.';
    }

    return ['items' => $items, 'errors' => $errors];
}

/** Display all uploaded files, retaining their revision history. */
function registration_documents_html(array $documents): string
{
    if (!$documents) return '<p class="small text-secondary">No documents have been uploaded.</p>';
    $html = '<ul class="list-group mb-3">';
    foreach ($documents as $document) {
        $label = ($document['document_type'] === 'valid_id')
            ? registration_id_type_label(isset($document['id_type']) ? (string)$document['id_type'] : null)
            : 'Supporting Document';
        $html .= '<li class="list-group-item d-flex justify-content-between align-items-start gap-2 flex-wrap">'
            . '<span><strong>' . e($label) . '</strong>'
            . '<small class="text-secondary d-block">Submission revision ' . (int)$document['revision'] . '</small></span>'
            . '<a href="' . e(url('download_reg', ['id' => (int)$document['id']])) . '">'
            . e((string)$document['original_name']) . '</a></li>';
    }
    return $html . '</ul>';
}


/** Form controls are shared by initial registration and corrected submissions. */
function registration_id_upload_row(int $index, string $selectedType, bool $required): string
{
    $suffix = (string)$index;
    $require = $required ? ' required' : '';
    $options = '<option value="">Select ID Type</option>';
    foreach (registration_id_type_choices() as $value => $label) {
        $options .= '<option value="' . e($value) . '"' . ($selectedType === $value ? ' selected' : '') . '>'
            . e($label) . '</option>';
    }

    return '<div class="border rounded-3 p-3 mb-3" data-attachment-row="id" data-row-index="' . $suffix . '">'
        . '<div class="d-flex justify-content-between align-items-center gap-2 mb-2">'
        . '<strong data-row-title>Valid ID</strong>'
        . '<button class="btn btn-sm btn-outline-danger d-none" type="button" data-remove-attachment aria-label="Remove this Valid ID">'
        . '<i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></div>'
        . '<div class="row g-3"><div class="col-md-6">'
        . '<label class="form-label" for="id_type_' . $suffix . '">ID Type ' . ($required ? '<span class="text-danger">*</span>' : '') . '</label>'
        . '<select class="form-select" id="id_type_' . $suffix . '" name="id_types[' . $suffix . ']" data-id-type' . $require . '>'
        . $options . '</select></div><div class="col-md-6">'
        . '<label class="form-label" for="id_file_' . $suffix . '">Upload ID ' . ($required ? '<span class="text-danger">*</span>' : '') . '</label>'
        . '<input class="form-control" type="file" id="id_file_' . $suffix . '" name="id_files[' . $suffix . ']" accept=".pdf,.jpg,.jpeg,.png" data-attachment-file' . $require . '>'
        . '<div class="form-text" data-file-name>No file selected</div>'
        . '</div></div></div>';
}

function registration_supporting_upload_row(int $index): string
{
    $suffix = (string)$index;
    return '<div class="border rounded-3 p-3 mb-3" data-attachment-row="supporting" data-row-index="' . $suffix . '">'
        . '<div class="d-flex justify-content-between align-items-center gap-2 mb-2">'
        . '<strong data-row-title>Supporting Document</strong>'
        . '<button class="btn btn-sm btn-outline-danger d-none" type="button" data-remove-attachment aria-label="Remove this supporting document">'
        . '<i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></div>'
        . '<label class="form-label" for="supporting_file_' . $suffix . '">Upload Document (optional)</label>'
        . '<input class="form-control" type="file" id="supporting_file_' . $suffix . '" name="supporting_files[' . $suffix . ']" accept=".pdf,.jpg,.jpeg,.png" data-attachment-file>'
        . '<div class="form-text" data-file-name>No file selected</div></div>';
}

function registration_attachment_form_fields(bool $new): string
{
    $posted = $_POST['id_types'] ?? [];
    $ids = [];
    if (is_array($posted)) {
        foreach ($posted as $index => $selected) {
            if ((is_int($index) || (is_string($index) && ctype_digit($index)))
                && (int)$index >= 0 && (int)$index <= 999 && is_string($selected)) {
                $ids[(int)$index] = $selected;
            }
            if (count($ids) >= 5) break;
        }
    }
    if (!$ids) $ids = [0 => ''];

    $maxBytes = max(1, (int)env_value('MAX_UPLOAD_BYTES', '5242880'));
    $maxMb = round($maxBytes / 1048576, 1);
    $html = '<hr><h3 class="h6">Registration Attachments</h3>'
        . '<p class="small text-secondary">Upload at least one government-issued ID for a new registration. Select the ID Type for each file. '
        . 'You may upload up to <strong>5 Valid IDs</strong> and <strong>5 supporting documents</strong>. '
        . 'PDF, JPG and PNG only; maximum ' . e((string)$maxMb) . ' MB per file. Files are stored securely outside the public web directory.</p>'
        . '<div id="registration-attachments" data-required-ids="' . ($new ? '1' : '0') . '" data-draft-mode="' . ($new ? '1' : '0') . '" data-max-ids="5" data-max-supporting="5" data-max-file-bytes="' . $maxBytes . '">'
        . '<h4 class="h6 mt-3 mb-2">Valid Identification Cards ' . ($new ? '<span class="text-danger">*</span>' : '(optional new uploads)') . '</h4>'
        . '<div id="valid-id-rows">';
    foreach ($ids as $index => $selected) {
        $html .= registration_id_upload_row($index, $selected, $new);
    }

    $html .= '</div><button class="btn btn-sm btn-outline-primary mb-4" type="button" data-add-id>'
        . '<i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add Another Valid ID</button>'
        . '<h4 class="h6 mb-2">Other Supporting Documents (Optional)</h4>'
        . '<div id="supporting-document-rows">'
        . registration_supporting_upload_row(0)
        . '</div><button class="btn btn-sm btn-outline-primary mb-2" type="button" data-add-supporting>'
        . '<i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add Supporting Document</button>'
        . '<p class="small text-secondary mb-0" id="attachment-feedback" role="status" aria-live="polite"></p>'
        . '</div>';
    return $html;
}
