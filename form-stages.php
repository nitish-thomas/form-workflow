<?php
/**
 * form-stages.php — Manage approval stages for a form
 * Drag-to-reorder, add/edit/delete stages, set approval_mode and stage_type.
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
                // Find max stage_order
                $existing = $sb->from('form_stages')->select('stage_order')
                    ->eq('form_id', $formId)->order('stage_order', false)->limit(1)->execute();
                $nextOrder = empty($existing) ? 1 : ($existing[0]['stage_order'] + 1);

                $stageName = trim($input['stage_name'] ?? '');
                if (!$stageName) throw new Exception('Stage name is required');

                $reminderDays    = isset($input['reminder_days'])   && $input['reminder_days']   !== '' ? (int)$input['reminder_days']   : null;
                $escalationDays  = isset($input['escalation_days']) && $input['escalation_days'] !== '' ? (int)$input['escalation_days'] : null;
                $reminderMessage = trim($input['reminder_message'] ?? '') ?: null;

                $result = $sb->from('form_stages')->insert([
                    'form_id'          => $formId,
                    'stage_order'      => $nextOrder,
                    'stage_name'       => $stageName,
                    'name'             => $stageName, // keep original column populated
                    'stage_type'       => $input['stage_type'] ?? 'approval',
                    'approval_mode'    => $input['approval_mode'] ?? 'any',
                    'reminder_days'    => $reminderDays,
                    'escalation_days'  => $escalationDays,
                    'reminder_message' => $reminderMessage,
                ]);
                if (!$result || empty($result[0])) throw new Exception('Database insert failed — run migration 2026-04-16_phase3c_stages_schema_align.sql in Supabase.');

                // If a template was selected, copy its recipients into this new stage
                $newStageId = $result[0]['id'];
                if (!empty($input['template_id'])) {
                    $templateRecips = $sb->from('stage_template_recipients')
                        ->select('*')
                        ->eq('stage_template_id', $input['template_id'])
                        ->execute() ?? [];
                    foreach ($templateRecips as $tr) {
                        $row = ['stage_id' => $newStageId];
                        if (!empty($tr['user_id']))   $row['user_id']   = $tr['user_id'];
                        if (!empty($tr['group_id']))  $row['group_id']  = $tr['group_id'];
                        if (!empty($tr['field_key'])) $row['field_key'] = $tr['field_key'];
                        if (!empty($row)) $sb->from('stage_recipients')->insert($row);
                    }
                }

                echo json_encode(['ok' => true, 'stage' => $result[0]]);
                break;

            case 'update':
                $stageName = trim($input['stage_name'] ?? '');
                if (!$stageName) throw new Exception('Stage name is required');

                $reminderDays    = isset($input['reminder_days'])   && $input['reminder_days']   !== '' ? (int)$input['reminder_days']   : null;
                $escalationDays  = isset($input['escalation_days']) && $input['escalation_days'] !== '' ? (int)$input['escalation_days'] : null;
                $reminderMessage = trim($input['reminder_message'] ?? '') ?: null;

                $result = $sb->from('form_stages')
                    ->eq('id', $input['id'])
                    ->update([
                        'stage_name'       => $stageName,
                        'name'             => $stageName,
                        'stage_type'       => $input['stage_type'] ?? 'approval',
                        'approval_mode'    => $input['approval_mode'] ?? 'any',
                        'reminder_days'    => $reminderDays,
                        'escalation_days'  => $escalationDays,
                        'reminder_message' => $reminderMessage,
                    ]);
                if (!$result || empty($result[0])) throw new Exception('Database update failed.');
                echo json_encode(['ok' => true, 'stage' => $result[0]]);
                break;

            case 'toggle':
                $newState = (bool)($input['is_active'] ?? true);
                $result = $sb->from('form_stages')
                    ->eq('id', $input['id'])
                    ->update(['is_active' => $newState]);
                if ($result === null) throw new Exception('Database update failed');
                echo json_encode(['ok' => true]);
                break;

            case 'delete':
                $stageToDelete = $input['id'];

                // Check for submission history (cannot cascade-delete — historical data)
                $submissionHistory = $sb->from('submission_stages')
                    ->select('id')->eq('stage_id', $stageToDelete)->limit(1)->execute();
                if (!empty($submissionHistory)) {
                    throw new Exception('This stage has submission history and cannot be deleted. Use the pause button to deactivate it instead.');
                }

                // Cascade: remove stage recipients first
                $sb->from('stage_recipients')->eq('stage_id', $stageToDelete)->delete();

                // Cascade: remove routing rules that point TO this stage as a target
                $sb->from('routing_rules')->eq('target_stage_id', $stageToDelete)->delete();

                // Cascade: remove routing rules that belong to this stage
                $sb->from('routing_rules')->eq('stage_id', $stageToDelete)->delete();

                // Now delete the stage itself
                $deleteResult = $sb->from('form_stages')->eq('id', $stageToDelete)->delete();
                if ($deleteResult === null) {
                    throw new Exception('Failed to delete stage. It may still be referenced by other records.');
                }
                echo json_encode(['ok' => true]);
                break;

            case 'reorder':
                // Uses an RPC function to update all orders atomically,
                // avoiding unique-constraint violations from sequential updates.
                $ids = $input['order'] ?? [];
                if (empty($ids)) { echo json_encode(['ok' => true]); break; }
                $result = $sb->rpc('reorder_form_stages', [
                    'p_form_id'   => $formId,
                    'p_stage_ids' => $ids,
                ]);
                if ($result === null) throw new Exception('Reorder failed');
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

// ── Fetch form + stages ─────────────────────────────────
$formResult = $sb->from('forms')->select('*')->eq('id', $formId)->execute();
if (empty($formResult)) { header('Location: /forms.php'); exit; }
$form = $formResult[0];

$stages = $sb->from('form_stages')->select('*')
    ->eq('form_id', $formId)->order('stage_order')->execute() ?? [];

// Active templates for the "Start from template" picker
$allTemplates    = $sb->from('stage_templates')->select('*')->order('name')->execute() ?? [];
$activeTemplates = array_values(array_filter($allTemplates, fn($t) => empty($t['archived_at'])));

$pageTitle  = 'Stages — ' . $form['title'];
$activePage = 'forms';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Breadcrumb -->
<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6">
    <a href="/forms.php" class="hover:text-brand-600 transition-colors">Forms</a>
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    <span class="text-gray-900 font-medium"><?= htmlspecialchars($form['title']) ?></span>
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    <span class="text-gray-900 font-medium">Stages</span>
</nav>

<!-- Page heading -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Approval Stages</h1>
        <p id="heading-sub" class="mt-1 text-sm text-gray-500">Each stage is evaluated in sequence.</p>
    </div>
    <div class="flex items-center gap-2">
        <!-- Normal mode buttons -->
        <div id="normal-actions" class="flex items-center gap-2">
            <button onclick="enterReorderMode()"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                Reorder
            </button>
            <button onclick="openStageModal()"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add Stage
            </button>
        </div>
        <!-- Reorder mode buttons (hidden initially) -->
        <div id="reorder-actions" class="hidden flex items-center gap-2">
            <button onclick="cancelReorderMode()"
                    class="px-4 py-2.5 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </button>
            <button onclick="saveReorder()"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Save Order
            </button>
        </div>
    </div>
</div>

<!-- Stages list (sortable) -->
<div id="stages-list" class="space-y-3">
    <?php if (empty($stages)): ?>
        <div id="empty-state" class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
            <p class="text-gray-500 font-medium">No stages defined</p>
            <p class="text-gray-400 text-sm mt-1">Add your first approval stage to begin configuring this workflow.</p>
        </div>
    <?php else: ?>
        <?php foreach ($stages as $stage): ?>
            <?php
                $typeIcons = [
                    'approval'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
                    'notification' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
                    'signature'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>',
                    'action'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
                ];
                $typeColors = [
                    'approval'     => 'text-emerald-500 bg-emerald-50',
                    'notification' => 'text-blue-500 bg-blue-50',
                    'signature'    => 'text-purple-500 bg-purple-50',
                    'action'       => 'text-orange-500 bg-orange-50',
                ];
                $icon  = $typeIcons[$stage['stage_type']]  ?? $typeIcons['approval'];
                $color = $typeColors[$stage['stage_type']] ?? $typeColors['approval'];
            ?>
            <?php $isActive = $stage['is_active'] ?? true; ?>
            <div class="stage-item rounded-xl border p-4 flex items-center gap-4 transition-shadow
                        <?= $isActive
                            ? 'bg-white border-gray-200 hover:shadow-sm'
                            : 'bg-gray-50 border-gray-200 border-dashed opacity-70' ?>"
                 data-id="<?= htmlspecialchars($stage['id']) ?>">
                <!-- Drag handle (hidden outside reorder mode) -->
                <div class="drag-handle hidden cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-600 shrink-0">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M7 2a2 2 0 10.001 4.001A2 2 0 007 2zm0 6a2 2 0 10.001 4.001A2 2 0 007 8zm0 6a2 2 0 10.001 4.001A2 2 0 007 14zm6-8a2 2 0 10-.001-4.001A2 2 0 0013 6zm0 2a2 2 0 10.001 4.001A2 2 0 0013 8zm0 6a2 2 0 10.001 4.001A2 2 0 0013 14z"/>
                    </svg>
                </div>

                <!-- Order badge -->
                <div class="w-8 h-8 rounded-full <?= $isActive ? 'bg-gray-100 text-gray-600' : 'bg-gray-200 text-gray-400' ?> flex items-center justify-center text-sm font-bold shrink-0 stage-order">
                    <?= $stage['stage_order'] ?>
                </div>

                <!-- Type icon -->
                <div class="w-9 h-9 rounded-lg <?= $isActive ? $color : 'text-gray-400 bg-gray-100' ?> flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $icon ?></svg>
                </div>

                <!-- Stage info -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-sm font-semibold <?= $isActive ? 'text-gray-900' : 'text-gray-400' ?>"><?= htmlspecialchars($stage['stage_name']) ?></h3>
                        <?php if (!$isActive): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Paused
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs <?= $isActive ? 'text-gray-500' : 'text-gray-400' ?> mt-0.5">
                        <?= ucfirst($stage['stage_type']) ?>
                        <?php if ($stage['stage_type'] === 'approval'): ?>
                            · <?= $stage['approval_mode'] === 'all' ? 'All must approve' : 'Any one approves' ?>
                        <?php elseif ($stage['stage_type'] === 'action'): ?>
                            · Blocks until marked done
                        <?php endif; ?>
                        <?php if (!empty($stage['reminder_days'])): ?>
                            · <span class="text-amber-600">Reminder: <?= (int)$stage['reminder_days'] ?>d</span>
                        <?php endif; ?>
                        <?php if (!empty($stage['escalation_days'])): ?>
                            · <span class="text-red-500">Escalate: <?= (int)$stage['escalation_days'] ?>d</span>
                        <?php endif; ?>
                        <?php if (!empty($stage['reminder_message'])): ?>
                            · <span class="text-indigo-500">Custom reminder</span>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Actions (hidden during reorder mode) -->
                <div class="stage-actions flex items-center gap-1 shrink-0">
                    <a href="/recipients.php?stage_id=<?= urlencode($stage['id']) ?>&form_id=<?= urlencode($formId) ?>"
                       class="p-2 text-gray-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"
                       title="Manage Recipients">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </a>
                    <!-- Pause / Activate toggle -->
                    <button onclick="toggleStage('<?= htmlspecialchars($stage['id']) ?>', <?= $isActive ? 'false' : 'true' ?>)"
                            class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                            title="<?= $isActive ? 'Deactivate stage' : 'Activate stage' ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?php if ($isActive): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            <?php endif; ?>
                        </svg>
                    </button>
                    <button onclick='openStageModal(<?= json_encode($stage) ?>)'
                            class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" title="Edit">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                    <button onclick="deleteStage('<?= htmlspecialchars($stage['id']) ?>', '<?= htmlspecialchars(addslashes($stage['stage_name'])) ?>')"
                            class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── Stage Modal ──────────────────────────────────────── -->
<div id="stage-modal" class="fixed inset-0 z-40 hidden">
    <div class="fixed inset-0 bg-black/40" onclick="closeStageModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md relative">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 id="stage-modal-title" class="text-lg font-semibold text-gray-900">Add Stage</h2>
            </div>
            <div class="px-6 py-5 space-y-4">
                <input type="hidden" id="stage-id">

                <!-- Template picker — only shown when adding a new stage -->
                <div id="template-picker-group" class="bg-brand-50 border border-brand-100 rounded-lg px-4 py-3">
                    <label class="block text-sm font-medium text-brand-800 mb-1">Start from a template <span class="font-normal text-brand-600">(optional)</span></label>
                    <select id="template-select" onchange="applyTemplate()"
                            class="w-full px-3 py-2 border border-brand-200 rounded-lg text-sm bg-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        <option value="">— Define from scratch —</option>
                        <?php foreach ($activeTemplates as $t): ?>
                        <option value="<?= htmlspecialchars($t['id']) ?>"
                                data-name="<?= htmlspecialchars($t['name']) ?>"
                                data-type="<?= htmlspecialchars($t['stage_type']) ?>"
                                data-mode="<?= htmlspecialchars($t['approval_mode']) ?>"
                                data-reminder="<?= htmlspecialchars((string)($t['reminder_days'] ?? '')) ?>"
                                data-escalation="<?= htmlspecialchars((string)($t['escalation_days'] ?? '')) ?>">
                            <?= htmlspecialchars($t['name']) ?>
                            (<?= $t['stage_type'] === 'approval' ? ($t['approval_mode'] === 'all' ? 'All approve' : 'Any one') : ucfirst($t['stage_type']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-brand-600">Selecting a template pre-fills the fields below. You can still edit them before saving.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Stage Name <span class="text-red-500">*</span></label>
                    <input id="stage-name" type="text" placeholder="e.g. Manager Approval"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Stage Type</label>
                    <select id="stage-type"
                            onchange="toggleApprovalMode()"
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        <option value="approval">Approval — requires sign-off</option>
                        <option value="notification">Notification — inform only</option>
                        <option value="signature">Signature — requires signature</option>
                        <option value="action">Action — checklist item to mark done</option>
                    </select>
                </div>

                <div id="approval-mode-group">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Approval Mode</label>
                    <select id="stage-approval-mode"
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        <option value="any">Any one approver</option>
                        <option value="all">All must approve</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-400" id="mode-hint">Stage advances when any single recipient approves.</p>
                </div>

                <div id="reminder-escalation-group" class="border-t border-gray-100 pt-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Reminders &amp; Escalation <span class="font-normal normal-case text-gray-400">(optional)</span></p>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Reminder every (days)</label>
                            <input id="stage-reminder-days" type="number" min="1" max="365" placeholder="e.g. 3"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                            <p class="mt-1 text-xs text-gray-400">Re-notify pending approvers</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Escalate after (days)</label>
                            <input id="stage-escalation-days" type="number" min="1" max="365" placeholder="e.g. 7"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                            <p class="mt-1 text-xs text-gray-400">Alert escalation contact if overdue</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Custom Reminder Message <span class="font-normal text-gray-400">(optional)</span></label>
                        <textarea id="stage-reminder-message" rows="3"
                                  placeholder="e.g. Please review the attached request and provide your decision at your earliest convenience."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none resize-none"></textarea>
                        <p class="mt-1 text-xs text-gray-400">Shown in reminder emails to pending approvers. Leave blank to use the default message.</p>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeStageModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                <button onclick="saveStage()"
                        class="px-5 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700">Save Stage</button>
            </div>
        </div>
    </div>
</div>

<!-- SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<script>
const formId = '<?= htmlspecialchars($formId) ?>';

// ── Drag-to-reorder (manual Edit/Save mode) ─────────────
const list = document.getElementById('stages-list');
let sortable = null;
let originalOrder = null; // snapshot of IDs before editing

function enterReorderMode() {
    // Snapshot current order for cancel
    originalOrder = [...list.querySelectorAll('.stage-item')].map(el => el.dataset.id);

    // Show drag handles, hide action buttons
    list.querySelectorAll('.drag-handle').forEach(el => el.classList.remove('hidden'));
    list.querySelectorAll('.stage-actions').forEach(el => el.classList.add('hidden'));

    // Switch header buttons
    document.getElementById('normal-actions').classList.add('hidden');
    document.getElementById('reorder-actions').classList.remove('hidden');

    // Update subtitle
    document.getElementById('heading-sub').textContent = 'Drag stages to set the new order, then click Save Order.';

    // Add a highlight ring to show cards are draggable
    list.querySelectorAll('.stage-item').forEach(el => el.classList.add('ring-2', 'ring-brand-200'));

    // Initialise SortableJS
    sortable = Sortable.create(list, {
        handle: '.drag-handle',
        animation: 200,
        ghostClass: 'opacity-40',
        onEnd: function() {
            // Update order badges live as user drags
            list.querySelectorAll('.stage-item').forEach((el, i) => {
                el.querySelector('.stage-order').textContent = i + 1;
            });
        }
    });
}

function cancelReorderMode() {
    // Restore original DOM order
    if (originalOrder) {
        originalOrder.forEach(id => {
            const el = list.querySelector(`[data-id="${id}"]`);
            if (el) list.appendChild(el);
        });
        list.querySelectorAll('.stage-item').forEach((el, i) => {
            el.querySelector('.stage-order').textContent = i + 1;
        });
    }
    exitReorderMode();
}

function exitReorderMode() {
    if (sortable) { sortable.destroy(); sortable = null; }
    originalOrder = null;

    list.querySelectorAll('.drag-handle').forEach(el => el.classList.add('hidden'));
    list.querySelectorAll('.stage-actions').forEach(el => el.classList.remove('hidden'));
    list.querySelectorAll('.stage-item').forEach(el => el.classList.remove('ring-2', 'ring-brand-200'));

    document.getElementById('normal-actions').classList.remove('hidden');
    document.getElementById('reorder-actions').classList.add('hidden');
    document.getElementById('heading-sub').textContent = 'Each stage is evaluated in sequence.';
}

async function saveReorder() {
    const order = [...list.querySelectorAll('.stage-item')].map(el => el.dataset.id);
    try {
        await api('/form-stages.php?form_id=' + formId, { action: 'reorder', order });
        showToast('Order saved');
        exitReorderMode();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

// ── Modal ────────────────────────────────────────────────
function openStageModal(stage = null) {
    document.getElementById('stage-modal').classList.remove('hidden');
    const pickerGroup = document.getElementById('template-picker-group');
    if (stage) {
        document.getElementById('stage-modal-title').textContent = 'Edit Stage';
        document.getElementById('stage-id').value = stage.id;
        document.getElementById('stage-name').value = stage.stage_name || '';
        document.getElementById('stage-type').value = stage.stage_type || 'approval';
        document.getElementById('stage-approval-mode').value = stage.approval_mode || 'any';
        document.getElementById('stage-reminder-days').value    = stage.reminder_days    ?? '';
        document.getElementById('stage-escalation-days').value  = stage.escalation_days  ?? '';
        document.getElementById('stage-reminder-message').value = stage.reminder_message ?? '';
        // Hide template picker when editing — template applies only on create
        pickerGroup.style.display = 'none';
    } else {
        document.getElementById('stage-modal-title').textContent = 'Add Stage';
        document.getElementById('stage-id').value = '';
        document.getElementById('stage-name').value = '';
        document.getElementById('stage-type').value = 'approval';
        document.getElementById('stage-approval-mode').value = 'any';
        document.getElementById('stage-reminder-days').value    = '';
        document.getElementById('stage-escalation-days').value  = '';
        document.getElementById('stage-reminder-message').value = '';
        document.getElementById('template-select').value = '';
        pickerGroup.style.display = 'block';
    }
    toggleApprovalMode();
    setTimeout(() => document.getElementById('stage-name').focus(), 100);
}

// Pre-fill modal fields from the selected template
function applyTemplate() {
    const sel = document.getElementById('template-select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    document.getElementById('stage-name').value            = opt.dataset.name       || '';
    document.getElementById('stage-type').value            = opt.dataset.type       || 'approval';
    document.getElementById('stage-approval-mode').value   = opt.dataset.mode       || 'any';
    document.getElementById('stage-reminder-days').value   = opt.dataset.reminder   || '';
    document.getElementById('stage-escalation-days').value = opt.dataset.escalation || '';
    toggleApprovalMode();
}

function closeStageModal() {
    document.getElementById('stage-modal').classList.add('hidden');
}

function toggleApprovalMode() {
    const type = document.getElementById('stage-type').value;

    // Show approval-mode only for 'approval' type
    document.getElementById('approval-mode-group').style.display = type === 'approval' ? 'block' : 'none';

    // Show reminder/escalation for all blocking types (not notification, which auto-advances)
    document.getElementById('reminder-escalation-group').style.display = type === 'notification' ? 'none' : 'block';

    const mode = document.getElementById('stage-approval-mode').value;
    document.getElementById('mode-hint').textContent =
        mode === 'all' ? 'Stage advances only when every recipient approves.' :
                         'Stage advances when any single recipient approves.';
}

document.getElementById('stage-approval-mode').addEventListener('change', toggleApprovalMode);

async function saveStage() {
    const id   = document.getElementById('stage-id').value;
    const name = document.getElementById('stage-name').value.trim();
    if (!name) { showToast('Stage name is required', 'error'); return; }

    const reminderVal   = document.getElementById('stage-reminder-days').value.trim();
    const escalationVal = document.getElementById('stage-escalation-days').value.trim();

    const templateId = document.getElementById('template-select')?.value || null;

    const payload = {
        action:           id ? 'update' : 'create',
        id:               id || undefined,
        stage_name:       name,
        stage_type:       document.getElementById('stage-type').value,
        approval_mode:    document.getElementById('stage-approval-mode').value,
        reminder_days:    reminderVal   !== '' ? parseInt(reminderVal,   10) : '',
        escalation_days:  escalationVal !== '' ? parseInt(escalationVal, 10) : '',
        reminder_message: document.getElementById('stage-reminder-message').value.trim(),
        template_id:      (!id && templateId) ? templateId : undefined,
    };

    try {
        await api('/form-stages.php?form_id=' + formId, payload);
        showToast(id ? 'Stage updated' : 'Stage added');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function deleteStage(id, name) {
    if (!confirmAction(`Delete stage "${name}"? Its recipients will also be removed.`)) return;
    try {
        await api('/form-stages.php?form_id=' + formId, { action: 'delete', id });
        showToast('Stage deleted');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function toggleStage(id, active) {
    try {
        await api('/form-stages.php?form_id=' + formId, { action: 'toggle', id, is_active: active });
        showToast(active ? 'Stage activated' : 'Stage deactivated');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
