<?php
/**
 * routing-rules.php — Conditional Routing Rules Builder
 * Build rules that route submissions to specific stages based on conditions.
 * Requires ?form_id= query parameter.
 */

require_once __DIR__ . '/includes/auth-check.php';

$formId = $_GET['form_id'] ?? '';
if (!$formId) { header('Location: /forms.php'); exit; }

// ── Handle AJAX ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        switch ($action) {
            case 'create':
                $result = $sb->from('routing_rules')->insert([
                    'form_id'         => $formId,
                    'name'            => trim($input['name'] ?? ''),
                    'description'     => trim($input['description'] ?? ''),
                    'condition_json'  => json_encode($input['conditions'] ?? []),
                    'target_stage_id' => $input['target_stage_id'] ?: null,
                    'priority'        => intval($input['priority'] ?? 0),
                    'is_active'       => $input['is_active'] ?? true,
                ]);
                echo json_encode(['ok' => true, 'rule' => $result[0] ?? null]);
                break;

            case 'update':
                $result = $sb->from('routing_rules')
                    ->eq('id', $input['id'])
                    ->update([
                        'name'            => trim($input['name'] ?? ''),
                        'description'     => trim($input['description'] ?? ''),
                        'condition_json'  => json_encode($input['conditions'] ?? []),
                        'target_stage_id' => $input['target_stage_id'] ?: null,
                        'priority'        => intval($input['priority'] ?? 0),
                        'is_active'       => $input['is_active'] ?? true,
                    ]);
                echo json_encode(['ok' => true, 'rule' => $result[0] ?? null]);
                break;

            case 'delete':
                $sb->from('routing_rules')->eq('id', $input['id'])->delete();
                echo json_encode(['ok' => true]);
                break;

            case 'toggle':
                $result = $sb->from('routing_rules')
                    ->eq('id', $input['id'])
                    ->update(['is_active' => $input['is_active']]);
                echo json_encode(['ok' => true]);
                break;

            default:
                throw new Exception('Unknown action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch data ──────────────────────────────────────────
$formResult = $sb->from('forms')->select('*')->eq('id', $formId)->execute();
if (empty($formResult)) { header('Location: /forms.php'); exit; }
$form = $formResult[0];

$rules = $sb->from('routing_rules')->select('*')
    ->eq('form_id', $formId)->order('priority', false)->execute() ?? [];

$stages = $sb->from('form_stages')->select('id,stage_name,stage_order')
    ->eq('form_id', $formId)->order('stage_order')->execute() ?? [];

// Fetch field names from the most recent submission for this form
$formFieldNames = [];
$latestSubmission = $sb->from('submissions')->select('form_data')
    ->eq('form_id', $formId)->order('created_at', false)->limit(1)->execute();
if (!empty($latestSubmission) && !empty($latestSubmission[0]['form_data'])) {
    $rawFormData = $latestSubmission[0]['form_data'];
    $parsedData  = is_string($rawFormData) ? json_decode($rawFormData, true) : $rawFormData;
    if (is_array($parsedData)) {
        $formFieldNames = array_keys($parsedData);
    }
}

$pageTitle  = 'Routing Rules — ' . $form['title'];
$activePage = 'forms';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Breadcrumb -->
<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6 flex-wrap">
    <a href="/forms.php" class="hover:text-brand-600 transition-colors">Forms</a>
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    <span class="text-gray-900 font-medium"><?= htmlspecialchars($form['title']) ?></span>
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    <span class="text-gray-900 font-medium">Routing Rules</span>
</nav>

<!-- Page heading -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Routing Rules</h1>
        <p class="mt-1 text-sm text-gray-500">
            Define conditions that route submissions to specific stages. Rules are evaluated by priority (highest first).
        </p>
    </div>
    <button onclick="openRuleModal()"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Add Rule
    </button>
</div>

<?php if (empty($stages)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
        <p class="text-sm text-amber-700">
            This form has no stages yet. <a href="/form-stages.php?form_id=<?= urlencode($formId) ?>" class="font-medium underline">Add stages first</a>
            before configuring routing rules.
        </p>
    </div>
<?php endif; ?>

<!-- Rules list -->
<div class="space-y-4">
    <?php if (empty($rules)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <p class="text-gray-500 font-medium">No routing rules</p>
            <p class="text-gray-400 text-sm mt-1">Without rules, submissions follow the default stage sequence.</p>
        </div>
    <?php else: ?>
        <?php foreach ($rules as $rule): ?>
            <?php
                $conditions = json_decode($rule['condition_json'] ?? '[]', true);
                if (!is_array($conditions)) $conditions = [];
                // Find target stage name
                $targetName = '—';
                foreach ($stages as $s) {
                    if ($s['id'] === $rule['target_stage_id']) {
                        $targetName = $s['stage_name'];
                        break;
                    }
                }
            ?>
            <div class="bg-white rounded-xl border border-gray-200 p-5 <?= $rule['is_active'] ? '' : 'opacity-60' ?>"
                 id="rule-<?= htmlspecialchars($rule['id']) ?>">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3">
                            <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($rule['name']) ?></h3>
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $rule['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' ?>">
                                <?= $rule['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-purple-100 text-purple-700">
                                Priority: <?= $rule['priority'] ?>
                            </span>
                        </div>
                        <?php if (!empty($rule['description'])): ?>
                            <p class="mt-1 text-sm text-gray-500"><?= htmlspecialchars($rule['description']) ?></p>
                        <?php endif; ?>

                        <!-- Conditions display -->
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="text-xs text-gray-500 font-medium py-1">IF</span>
                            <?php if (empty($conditions)): ?>
                                <span class="text-xs text-gray-400 italic py-1">no conditions set</span>
                            <?php else: ?>
                                <?php foreach ($conditions as $i => $cond): ?>
                                    <?php if ($i > 0): ?>
                                        <span class="text-xs text-gray-400 font-medium py-1">AND</span>
                                    <?php endif; ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-50 text-blue-700 text-xs font-medium rounded-md">
                                        <?= htmlspecialchars($cond['field'] ?? '?') ?>
                                        <span class="text-blue-400"><?= htmlspecialchars($cond['op'] ?? '=') ?></span>
                                        <?= htmlspecialchars($cond['value'] ?? '?') ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <span class="text-xs text-gray-500 font-medium py-1">→</span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-50 text-amber-700 text-xs font-medium rounded-md">
                                <?= htmlspecialchars($targetName) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-1 ml-4 shrink-0">
                        <button onclick="toggleRule('<?= $rule['id'] ?>', <?= $rule['is_active'] ? 'false' : 'true' ?>)"
                                class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                                title="<?= $rule['is_active'] ? 'Deactivate' : 'Activate' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <?php if ($rule['is_active']): ?>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                <?php else: ?>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                <?php endif; ?>
                            </svg>
                        </button>
                        <button onclick='openRuleModal(<?= json_encode(array_merge($rule, ["conditions" => $conditions])) ?>)'
                                class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" title="Edit">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button onclick="deleteRule('<?= htmlspecialchars($rule['id']) ?>', '<?= htmlspecialchars(addslashes($rule['name'])) ?>')"
                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── Rule Modal ───────────────────────────────────────── -->
<div id="rule-modal" class="fixed inset-0 z-40 hidden">
    <div class="fixed inset-0 bg-black/40" onclick="closeRuleModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-xl relative my-8">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 id="rule-modal-title" class="text-lg font-semibold text-gray-900">New Routing Rule</h2>
            </div>
            <div class="px-6 py-5 space-y-4">
                <input type="hidden" id="rule-id">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rule Name <span class="text-red-500">*</span></label>
                        <input id="rule-name" type="text" placeholder="e.g. High Value Override"
                               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                        <input id="rule-priority" type="number" value="0" min="0" max="100"
                               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        <p class="text-xs text-gray-400 mt-0.5">Higher = checked first</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input id="rule-desc" type="text" placeholder="Optional description…"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                </div>

                <!-- Conditions builder -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Conditions</label>
                    <div id="conditions-container" class="space-y-2">
                        <!-- Rows inserted by JS -->
                    </div>
                    <button onclick="addConditionRow()"
                            class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Condition
                    </button>
                    <?php if (!empty($formFieldNames)): ?>
                        <p class="mt-2 text-xs text-emerald-600 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            Fields loaded from your most recent submission (<?= count($formFieldNames) ?> fields available).
                        </p>
                    <?php else: ?>
                        <p class="mt-2 text-xs text-amber-600 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                            No submissions yet — type field names exactly as they appear in your Google Form.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Target stage -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Route to Stage</label>
                    <select id="rule-target"
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        <option value="">— Select target stage —</option>
                        <?php foreach ($stages as $s): ?>
                            <option value="<?= htmlspecialchars($s['id']) ?>">
                                Stage <?= $s['stage_order'] ?>: <?= htmlspecialchars($s['stage_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input id="rule-active" type="checkbox" checked
                           class="w-4 h-4 text-brand-600 border-gray-300 rounded focus:ring-brand-500">
                    <span class="text-sm text-gray-700">Rule is active</span>
                </label>
            </div>
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeRuleModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                <button onclick="saveRule()"
                        class="px-5 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700">Save Rule</button>
            </div>
        </div>
    </div>
</div>

<script>
const formId = '<?= htmlspecialchars($formId) ?>';
const endpoint = '/routing-rules.php?form_id=' + formId;
const formFieldNames = <?= json_encode($formFieldNames) ?>;

const operators = [
    { value: '=',  label: 'equals' },
    { value: '!=', label: 'not equals' },
    { value: '>',  label: 'greater than' },
    { value: '>=', label: 'greater or equal' },
    { value: '<',  label: 'less than' },
    { value: '<=', label: 'less or equal' },
    { value: 'contains',     label: 'contains' },
    { value: 'not_contains', label: 'does not contain' },
];

function buildFieldSelect(selectedField) {
    if (formFieldNames.length === 0) {
        return `<input type="text" placeholder="Field name (no submissions yet)" value="${escHtml(selectedField)}"
                       class="cond-field flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">`;
    }
    const opts = [`<option value="">— Select field —</option>`]
        .concat(formFieldNames.map(f =>
            `<option value="${escHtml(f)}" ${f === selectedField ? 'selected' : ''}>${escHtml(f)}</option>`
        )).join('');
    return `<select class="cond-field flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none bg-white">
                ${opts}
            </select>`;
}

function addConditionRow(field = '', op = '=', value = '') {
    const container = document.getElementById('conditions-container');
    const row = document.createElement('div');
    row.className = 'condition-row flex flex-col gap-2';

    row.innerHTML = `
        <div class="flex gap-2 items-center">
            ${buildFieldSelect(field)}
            <select class="cond-op shrink-0 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none bg-white">
                ${operators.map(o => `<option value="${o.value}" ${o.value === op ? 'selected' : ''}>${o.label}</option>`).join('')}
            </select>
        </div>
        <div class="flex gap-2 items-center">
            <input type="text" placeholder="Value" value="${escHtml(value)}"
                   class="cond-value flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
            <button onclick="this.closest('.condition-row').remove()"
                    class="shrink-0 p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg border border-gray-200 hover:border-red-100 transition-colors" title="Remove condition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="border-b border-gray-100 last:border-0"></div>
    `;
    container.appendChild(row);
}

function escHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML.replace(/"/g, '&quot;');
}

function getConditions() {
    const rows = document.querySelectorAll('.condition-row');
    const conditions = [];
    rows.forEach(row => {
        const field = row.querySelector('.cond-field').value.trim();
        const op    = row.querySelector('.cond-op').value;
        const value = row.querySelector('.cond-value').value.trim();
        if (field) conditions.push({ field, op, value });
    });
    return conditions;
}

function openRuleModal(rule = null) {
    document.getElementById('rule-modal').classList.remove('hidden');
    document.getElementById('conditions-container').innerHTML = '';

    if (rule) {
        document.getElementById('rule-modal-title').textContent = 'Edit Rule';
        document.getElementById('rule-id').value = rule.id;
        document.getElementById('rule-name').value = rule.name || '';
        document.getElementById('rule-desc').value = rule.description || '';
        document.getElementById('rule-priority').value = rule.priority ?? 0;
        document.getElementById('rule-target').value = rule.target_stage_id || '';
        document.getElementById('rule-active').checked = !!rule.is_active;

        const conditions = rule.conditions || [];
        conditions.forEach(c => addConditionRow(c.field || '', c.op || '=', c.value || ''));
    } else {
        document.getElementById('rule-modal-title').textContent = 'New Routing Rule';
        document.getElementById('rule-id').value = '';
        document.getElementById('rule-name').value = '';
        document.getElementById('rule-desc').value = '';
        document.getElementById('rule-priority').value = 0;
        document.getElementById('rule-target').value = '';
        document.getElementById('rule-active').checked = true;
        addConditionRow(); // Start with one empty row
    }
    setTimeout(() => document.getElementById('rule-name').focus(), 100);
}

function closeRuleModal() {
    document.getElementById('rule-modal').classList.add('hidden');
}

async function saveRule() {
    const id   = document.getElementById('rule-id').value;
    const name = document.getElementById('rule-name').value.trim();
    if (!name) { showToast('Rule name is required', 'error'); return; }

    const payload = {
        action:          id ? 'update' : 'create',
        id:              id || undefined,
        name:            name,
        description:     document.getElementById('rule-desc').value.trim(),
        conditions:      getConditions(),
        target_stage_id: document.getElementById('rule-target').value || null,
        priority:        parseInt(document.getElementById('rule-priority').value) || 0,
        is_active:       document.getElementById('rule-active').checked,
    };

    try {
        await api(endpoint, payload);
        showToast(id ? 'Rule updated' : 'Rule created');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function deleteRule(id, name) {
    if (!confirmAction(`Delete rule "${name}"?`)) return;
    try {
        await api(endpoint, { action: 'delete', id });
        showToast('Rule deleted');
        document.getElementById('rule-' + id)?.remove();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function toggleRule(id, active) {
    try {
        await api(endpoint, { action: 'toggle', id, is_active: active });
        showToast(active ? 'Rule activated' : 'Rule deactivated');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
