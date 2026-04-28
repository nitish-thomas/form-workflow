<?php
/**
 * recipients.php — Manage stage recipients
 * Assign individual users or recipient groups to an approval stage.
 * Requires ?stage_id= and ?form_id= query parameters.
 */

require_once __DIR__ . '/includes/auth-check.php';

$stageId = $_GET['stage_id'] ?? '';
$formId  = $_GET['form_id']  ?? '';
if (!$stageId || !$formId) { header('Location: /forms.php'); exit; }

// ── Handle AJAX ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        switch ($action) {
            case 'add_user':
                $result = $sb->from('stage_recipients')->insert([
                    'stage_id' => $stageId,
                    'user_id'  => $input['user_id'],
                ]);
                echo json_encode(['ok' => true, 'recipient' => $result[0] ?? null]);
                break;

            case 'add_group':
                $result = $sb->from('stage_recipients')->insert([
                    'stage_id' => $stageId,
                    'group_id' => $input['group_id'],
                ]);
                echo json_encode(['ok' => true, 'recipient' => $result[0] ?? null]);
                break;

            case 'add_field_key':
                $fieldKey = trim($input['field_key'] ?? '');
                if (!$fieldKey) throw new Exception('Field key is required');
                $result = $sb->from('stage_recipients')->insert([
                    'stage_id'  => $stageId,
                    'field_key' => $fieldKey,
                ]);
                echo json_encode(['ok' => true, 'recipient' => $result[0] ?? null]);
                break;

            case 'remove':
                $sb->from('stage_recipients')->eq('id', $input['id'])->delete();
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

// ── Fetch context ───────────────────────────────────────
$formResult = $sb->from('forms')->select('*')->eq('id', $formId)->execute();
if (empty($formResult)) { header('Location: /forms.php'); exit; }
$form = $formResult[0];

$stageResult = $sb->from('form_stages')->select('*')->eq('id', $stageId)->execute();
if (empty($stageResult)) { header('Location: /form-stages.php?form_id=' . urlencode($formId)); exit; }
$stage = $stageResult[0];

// Current recipients
$recipients = $sb->from('stage_recipients')->select('*')->eq('stage_id', $stageId)->execute() ?? [];

// All users (for the add-user dropdown)
$allUsers = $sb->from('users')->select('id,email,display_name')->order('display_name')->execute() ?? [];

// All groups (for the add-group dropdown)
$allGroups = $sb->from('recipient_groups')->select('id,name')->order('name')->execute() ?? [];

// Build lookup maps
$userMap  = [];
foreach ($allUsers as $u) { $userMap[$u['id']] = $u; }
$groupMap = [];
foreach ($allGroups as $g) { $groupMap[$g['id']] = $g; }

// Separate recipients by type
$userRecipients      = [];
$groupRecipients     = [];
$fieldKeyRecipients  = [];
$assignedUserIds     = [];
$assignedGroupIds    = [];

foreach ($recipients as $r) {
    if (!empty($r['user_id'])) {
        $userRecipients[] = $r;
        $assignedUserIds[] = $r['user_id'];
    } elseif (!empty($r['group_id'])) {
        $groupRecipients[] = $r;
        $assignedGroupIds[] = $r['group_id'];
    } elseif (!empty($r['field_key'])) {
        $fieldKeyRecipients[] = $r;
    }
}

// Fetch field names from the most recent submission for this form (for the field_key dropdown)
$formFieldNames = [];
$latestSub = $sb->from('submissions')->select('form_data')
    ->eq('form_id', $formId)->order('created_at', false)->limit(1)->execute();
if (!empty($latestSub) && !empty($latestSub[0]['form_data'])) {
    $rawData = $latestSub[0]['form_data'];
    $parsed  = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (is_array($parsed)) {
        $formFieldNames = array_keys($parsed);
    }
}

$pageTitle  = 'Recipients — ' . $stage['stage_name'];
$activePage = 'forms';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Breadcrumb -->
<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6 flex-wrap">
    <a href="/forms.php" class="hover:text-brand-600 transition-colors">Forms</a>
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    <a href="/form-stages.php?form_id=<?= urlencode($formId) ?>" class="hover:text-brand-600 transition-colors"><?= htmlspecialchars($form['title']) ?></a>
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    <span class="text-gray-900 font-medium"><?= htmlspecialchars($stage['stage_name']) ?> — Recipients</span>
</nav>

<!-- Page heading -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Stage Recipients</h1>
    <p class="mt-1 text-sm text-gray-500">
        Stage: <strong><?= htmlspecialchars($stage['stage_name']) ?></strong>
        · Type: <?= ucfirst($stage['stage_type']) ?>
        <?php if ($stage['stage_type'] === 'approval'): ?>
            · Mode: <?= $stage['approval_mode'] === 'all' ? 'All must approve' : 'Any one approves' ?>
        <?php endif; ?>
    </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    <!-- ── Individual Users ─────────────────────────────── -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900">Individual Users</h2>
            <span class="text-xs text-gray-400"><?= count($userRecipients) ?> assigned</span>
        </div>

        <!-- Add user form -->
        <div class="flex gap-2 mb-4">
            <select id="add-user-select"
                    class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                <option value="">Select a user…</option>
                <?php foreach ($allUsers as $u): ?>
                    <?php if (!in_array($u['id'], $assignedUserIds)): ?>
                        <option value="<?= htmlspecialchars($u['id']) ?>">
                            <?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button onclick="addUser()"
                    class="px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shrink-0">
                Add
            </button>
        </div>

        <!-- User list -->
        <div id="user-recipients" class="space-y-2">
            <?php if (empty($userRecipients)): ?>
                <p class="text-sm text-gray-400 py-4 text-center" id="no-users-msg">No individual users assigned</p>
            <?php endif; ?>
            <?php foreach ($userRecipients as $r): ?>
                <?php $u = $userMap[$r['user_id']] ?? null; ?>
                <?php if ($u): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg" id="recip-<?= $r['id'] ?>">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 font-semibold text-xs">
                                <?= strtoupper(substr($u['display_name'] ?? 'U', 0, 1)) ?>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($u['display_name']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($u['email']) ?></p>
                            </div>
                        </div>
                        <button onclick="removeRecipient('<?= $r['id'] ?>')"
                                class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Remove">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Recipient Groups ─────────────────────────────── -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900">Recipient Groups</h2>
            <span class="text-xs text-gray-400"><?= count($groupRecipients) ?> assigned</span>
        </div>

        <!-- Add group form -->
        <div class="flex gap-2 mb-4">
            <select id="add-group-select"
                    class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                <option value="">Select a group…</option>
                <?php foreach ($allGroups as $g): ?>
                    <?php if (!in_array($g['id'], $assignedGroupIds)): ?>
                        <option value="<?= htmlspecialchars($g['id']) ?>">
                            <?= htmlspecialchars($g['name']) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button onclick="addGroup()"
                    class="px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shrink-0">
                Add
            </button>
        </div>

        <!-- Group list -->
        <div id="group-recipients" class="space-y-2">
            <?php if (empty($groupRecipients)): ?>
                <p class="text-sm text-gray-400 py-4 text-center" id="no-groups-msg">No groups assigned</p>
            <?php endif; ?>
            <?php foreach ($groupRecipients as $r): ?>
                <?php $g = $groupMap[$r['group_id']] ?? null; ?>
                <?php if ($g): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg" id="recip-<?= $r['id'] ?>">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($g['name']) ?></p>
                                <a href="/groups.php?highlight=<?= urlencode($g['id']) ?>" class="text-xs text-brand-600 hover:underline">View members →</a>
                            </div>
                        </div>
                        <button onclick="removeRecipient('<?= $r['id'] ?>')"
                                class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Remove">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if (empty($allGroups)): ?>
            <div class="mt-3 p-3 bg-amber-50 rounded-lg">
                <p class="text-xs text-amber-700">
                    No recipient groups exist yet.
                    <a href="/groups.php?action=new" class="font-medium underline">Create one</a> first.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Dynamic Field Recipients ────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-start justify-between mb-1">
        <h2 class="text-base font-semibold text-gray-900">Dynamic Recipients</h2>
        <span class="text-xs text-gray-400 mt-0.5"><?= count($fieldKeyRecipients) ?> assigned</span>
    </div>
    <p class="text-xs text-gray-500 mb-4">
        Select the form field that contains the recipient's email address. At submission time, the system reads that field's value and sends the notification directly to that address —
        even if they have never logged into the portal.
    </p>

    <!-- Add field key form -->
    <div class="flex gap-2 mb-4">
        <?php if (!empty($formFieldNames)): ?>
            <select id="add-field-key-input"
                    class="flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none bg-white">
                <option value="">— Select a form field —</option>
                <?php foreach ($formFieldNames as $fn): ?>
                    <option value="<?= htmlspecialchars($fn) ?>"><?= htmlspecialchars($fn) ?></option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input id="add-field-key-input" type="text"
                   placeholder="e.g. Manager Email (no submissions yet)"
                   class="flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
        <?php endif; ?>
        <button onclick="addFieldKey()"
                class="px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shrink-0">
            Add
        </button>
    </div>
    <?php if (!empty($formFieldNames)): ?>
        <p class="text-xs text-emerald-600 flex items-center gap-1 mb-3">
            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <?= count($formFieldNames) ?> fields loaded from your most recent submission.
        </p>
    <?php else: ?>
        <p class="text-xs text-amber-600 flex items-center gap-1 mb-3">
            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            No submissions yet for this form. Enter the exact field name once a submission arrives.
        </p>
    <?php endif; ?>

    <!-- Field key list -->
    <div id="field-key-recipients" class="space-y-2">
        <?php if (empty($fieldKeyRecipients)): ?>
            <p class="text-sm text-gray-400 py-4 text-center" id="no-field-keys-msg">No dynamic recipients assigned</p>
        <?php endif; ?>
        <?php foreach ($fieldKeyRecipients as $r): ?>
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg" id="recip-<?= $r['id'] ?>">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-violet-100 flex items-center justify-center text-violet-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($r['field_key']) ?></p>
                        <p class="text-xs text-gray-500">Resolved from form field at submission time</p>
                    </div>
                </div>
                <button onclick="removeRecipient('<?= $r['id'] ?>')"
                        class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Remove">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
const stageId = '<?= htmlspecialchars($stageId) ?>';
const formId  = '<?= htmlspecialchars($formId) ?>';
const endpoint = `/recipients.php?stage_id=${stageId}&form_id=${formId}`;

async function addUser() {
    const sel = document.getElementById('add-user-select');
    const userId = sel.value;
    if (!userId) { showToast('Select a user first', 'error'); return; }

    try {
        await api(endpoint, { action: 'add_user', user_id: userId });
        showToast('User added');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function addGroup() {
    const sel = document.getElementById('add-group-select');
    const groupId = sel.value;
    if (!groupId) { showToast('Select a group first', 'error'); return; }

    try {
        await api(endpoint, { action: 'add_group', group_id: groupId });
        showToast('Group added');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function addFieldKey() {
    const el = document.getElementById('add-field-key-input');
    const fieldKey = el.value.trim();
    if (!fieldKey) { showToast('Please select a form field first', 'error'); return; }

    try {
        await api(endpoint, { action: 'add_field_key', field_key: fieldKey });
        showToast('Dynamic recipient added');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function removeRecipient(id) {
    if (!confirmAction('Remove this recipient from the stage?')) return;
    try {
        await api(endpoint, { action: 'remove', id });
        showToast('Recipient removed');
        document.getElementById('recip-' + id)?.remove();
    } catch (e) {
        showToast(e.message, 'error');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
