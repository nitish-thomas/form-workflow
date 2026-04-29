<?php
/**
 * view-helpers.php — Small display-layer helpers used across pages.
 *
 * Keep this include dependency-free — it should not require Supabase,
 * PHPMailer, or config. Pure PHP + HTML output only.
 */

if (!function_exists('vh_requestRef')) {
    /**
     * Format a human-readable request reference from a submission + form row.
     *
     * Examples:
     *   vh_requestRef($submission, $form)  →  "SFA-0001"
     *   vh_requestRef($submission, $form)  →  "REQ-0042"
     *
     * Falls back gracefully if the request_number column hasn't been populated yet
     * (e.g. old submissions before the migration ran).
     *
     * @param array $submission  Row from the submissions table
     * @param array $form        Row from the forms table (needs request_prefix)
     * @return string            Formatted reference, e.g. "SFA-0001", or empty string
     */
    function vh_requestRef(array $submission, array $form): string
    {
        $num = $submission['request_number'] ?? null;
        if ($num === null) return '';
        $prefix = strtoupper(trim($form['request_prefix'] ?? ''));
        if ($prefix === '') {
            // Derive a 3-char prefix from the form title as a last resort
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $form['title'] ?? $form['name'] ?? 'REQ'), 0, 3));
        }
        if ($prefix === '') $prefix = 'REQ';
        return $prefix . '-' . str_pad((string)(int)$num, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('vh_normaliseFormData')) {
    /**
     * Normalise a submission's form_data column into an associative array.
     * The column is stored as JSON TEXT in Supabase, so callers may receive
     * either a string or an already-decoded array.
     */
    function vh_normaliseFormData($raw): array
    {
        if (is_array($raw)) return $raw;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }
}

if (!function_exists('vh_renderFormDataList')) {
    /**
     * Render submitted form_data as a clean definition-style HTML list.
     * Designed to sit inside a <details> block on dashboard.php and
     * submissions.php.
     *
     * Uses Tailwind utility classes — assumes the surrounding page loads
     * Tailwind via the shared header include.
     *
     * @param mixed  $formData  array, JSON string, or null
     * @param string $emptyText fallback text when no data is present
     */
    function vh_renderFormDataList($formData, string $emptyText = 'No form data captured.'): string
    {
        $data = vh_normaliseFormData($formData);
        if (empty($data)) {
            return '<p class="text-xs text-gray-400 italic">' . htmlspecialchars($emptyText) . '</p>';
        }

        $html = '<dl class="grid grid-cols-1 sm:grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">';
        foreach ($data as $key => $val) {
            // ── File upload fields ────────────────────────────────
            if (is_array($val) && isset($val['type']) && $val['type'] === 'files') {
                $files = $val['files'] ?? [];
                if (empty($files)) {
                    $valSafe = '<span class="text-gray-400 italic">(no files)</span>';
                } else {
                    $links = '';
                    foreach ($files as $f) {
                        $links .= '<li><a href="' . htmlspecialchars($f['url'] ?? '#')
                               . '" target="_blank" rel="noopener noreferrer"'
                               . ' class="text-blue-600 hover:underline">'
                               . htmlspecialchars($f['name'] ?? 'File') . '</a></li>';
                    }
                    $valSafe = '<ul class="list-none space-y-0.5">' . $links . '</ul>';
                }
                $html .= '<dt class="text-xs font-semibold text-gray-500 uppercase tracking-wide pt-0.5">'
                       . htmlspecialchars((string)$key) .
                       '</dt>'
                       . '<dd class="text-gray-800 break-words">' . $valSafe . '</dd>';
                continue;
            }

            // Flatten array answers defensively
            if (is_array($val)) {
                $val = implode(', ', array_map(
                    fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
                    $val
                ));
            }
            $valStr = (string)$val;
            if ($valStr === '') {
                $valSafe = '<span class="text-gray-400 italic">(blank)</span>';
            } else {
                // Auto-linkify: split on whitespace/commas to find URL tokens.
                // Google Forms file-upload answers arrive as plain Drive URLs.
                $parts = preg_split('/[\s,]+/', $valStr, -1, PREG_SPLIT_NO_EMPTY);
                $allUrls = !empty($parts) && count($parts) <= 10 && array_reduce($parts, fn($c, $p) => $c && (bool)filter_var($p, FILTER_VALIDATE_URL), true);
                if ($allUrls) {
                    $links = '';
                    foreach ($parts as $url) {
                        $urlSafe  = htmlspecialchars($url, ENT_QUOTES);
                        // Use filename from URL or a generic label
                        $label = basename(parse_url($url, PHP_URL_PATH) ?: $url);
                        if (!$label || strlen($label) > 80) $label = 'Open file';
                        $links .= '<li><a href="' . $urlSafe . '" target="_blank" rel="noopener noreferrer"'
                               . ' class="text-blue-600 hover:underline break-all">'
                               . htmlspecialchars($label) . '</a></li>';
                    }
                    $valSafe = '<ul class="list-none space-y-0.5">' . $links . '</ul>';
                } else {
                    $valSafe = nl2br(htmlspecialchars($valStr));
                }
            }
            $html .= '<dt class="text-xs font-semibold text-gray-500 uppercase tracking-wide pt-0.5">'
                   . htmlspecialchars((string)$key) .
                   '</dt>'
                   . '<dd class="text-gray-800 break-words">' . $valSafe . '</dd>';
        }
        $html .= '</dl>';
        return $html;
    }
}
