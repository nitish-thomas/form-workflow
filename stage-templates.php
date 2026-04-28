<?php
/**
 * stage-templates.php — Reusable Stage Templates
 *
 * Admin-only. CRUD for stage templates + inline recipient management.
 * Templates are snapshots — editing a template does NOT retroactively
 * change any form stage that was created from it.
 *
 * Archive instead of delete keeps history clean.
 */

require_once __DIR__ . '/includes/auth-check.php';

if ($currentUser['role'] !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

// ── Handle AJAX ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        switch ($action) {

            case 'create_template': {
                $name = trim($input['name'] ?? '');
                if (!$name) throw new Exception('Template name is required');

                $reminderDays   = isset($input['reminder_days'])   && $input['reminder_days']   !== '' ? (int)$input['reminder_days']   : null;
                $escalationDays = isset($input['escalation_days']) && $input['escalation_days'] !== '' ? (int)$input['escalation_days'] : null;

                $result = $sb->from('stage_templates')->insert([
                    'name'            => $name,
                    'stage_type'      => $input['stage_type']    ?? 'approval',
                    'approval_mode'   => $input['approval_mode'] ?? 'any',
                    'description'     => trim($input['description'] ?? '') ?: null,
                    'reminder_days'   => $reminderDays,
                    'escalation_days' => $escalationDays,
                    'created_by'      => $currentUser['id'],
                ]);
                if (!$result || empty($result[0])) throw new Exception('Database insert failed');
                echo json_encode(['ok' => true, 'template' => $result[0]]);
                break;
            }

            case 'update_template': {
                $name = trim($input['name'] ?? '');
                if (!$name) throw new Exception('Template name is required');

                $reminderDays   = isset($input['reminder_days'])   && $input['reminder_days']   !== '' ? (int)$input['reminder_days']   : null;
                $escalationDays = isset($input['escalation_days']) && $input['escalation_days'] !== '' ? (int)$input['escalation_days'] : null;

                $result = $sb->from('stage_templates')
                    ->eq('id', $input['id'])
                    ->update([
                        'name'            => $name,
                        'stage_type'      => $input['stage_type']    ?? 'approval',
                        'approval_mode'   => $input['approval_mode'] ?? 'any',
                        'description'     => trim($input['description'] ?? '') ?: null,
                        'reminder_days'   => $reminderDays,
                        'escalation_days' => $escalationDays,
                    ]);
                echo json_encode(['ok' => true]);
                break;
            }

            case 'archive_template': {
                $archivedAt = $input['archive'] ? date('c') : null;
                $sb->from('stage_templates')
                    ->eq('id', $input['id'])
                    ->update(['archived_at' => $archivedAt]);
                echo json_encode(['ok' => true]);
                break;
            }

            case 'add_recipient': {
                $templateId = $input['template_id'] ?? '';
                if (!$templateId) throw new Exception('Missing template_id');

                $row = ['stage_template_id' => $templateId];

                if (!empty($input['user_id'])) {
                    $row['user_id'] = $input['user_id'];
                } elseif (!empty($input['group_id'])) {
                    $row['group_id'] = $input['group_id'];
                } else {
                    throw new Exception('Select a user or group');
                }

                $result = $sb->from('stage_template_recipients')->insert($row);
                if (!$result || empty($result[0])) throw new Exception('Database insert failed');
                echo json_encode(['ok' => true, 'recipient' => $result[0]]);
                break;
            }

            case 'remove_recipient': {
                $sb->from('stage_template_recipients')->eq('id', $input['id'])->delete();
                echo json_encode(['ok' => true]);
                break;
            }

            default:
                throw new Exception('Unknown action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$templates  = $sb->from('stage_templates')->select('*')->order('name')->execute() ?? [];
$allUsers   = $sb->from('users')->select('id,email,display_name')->order('display_name')->execute() ?? [];
$allGroups  = $sb->from('recipient_groups')->select('id,name')->order('name')->execute() ?? [];
$allRecips  = $sb->from('stage_template_recipients')->select('*')->execute() ?? [];

$userMap  = [];  foreach ($allUsers  as $u) { $userMap[$u['id']]   = $u; }
$groupMap = [];  foreach ($allGroups as $g) { $groupMap[$g['id']]  = $g; }

$recipsByTemplate = [];
foreach ($allRecips as $r) {
    $recipsByTemplate[$r['stage_template_id']][] = $r;
}

// Split active vs archived for display
$activeTemplates   = array_values(array_filter($templates, fn($t) => empty($t['archived_at'])));
$archivedTemplates = array_values(array_filter($templates, fn($t) => !empty($t['archived_at'])));

$pageTitle  = 'Stage Templates';
$activePage = 'stage-templates';
require_once __DIR__ . '/includes/header.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function typeLabel(string $type): string {
    return match($type) {
        'approval'     => 'Approval',
        'notification' => 'Notification',
        'signature'    => 'Signature',
        'action'       => 'Action',
        default        => ucfirst($type),
    };
}
function typeColor(string $type): string {
    return match($type) {
        'approval'     => 'text-emerald-600 bg-emerald-50',
        'notification' => 'text-blue-600 bg-blue-50',
        'signature'    => 'text-purple-600 bg-purple-50',
        'action'       => 'text-orange-600 bg-orange-50',
        default        => 'text-gray-600 bg-gray-50',
    };
}
?>

<!-- Page heading -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Stage Templates</h1>
        <p class="mt-1 text-sm text-gray-500">Reusable stage blueprints. Applying a template copies its settings — changes here do not affect existing stages.</p>
    </div>
    <button onclick="openTemplateModal()"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        New Template
    </button>
</div>

<!-- ── Active templates ───────────────────────────────────────────────────── -->
<div class="space-y-6">
    <?php if (empty($activeTemplates)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-gray-500 font-medium">No templates yet</p>
            <p class="text-gray-400 text-sm mt-1">Create a template to speed up stage configuration across forms.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($activeTemplates as $tmpl):
        $recips  = $recipsByTemplate[$tmpl['id']] ?? [];
        $recipUserIds  = array_filter(array_column($recips, 'user_id'));
        $recipGroupIds = array_filter(array_column($recips, 'group_id'));
        // Users not already in this template's recipients
        $availableUsers  = array_filter($allUsers,  fn($u) => !in_array($u['id'], $recipUserIds));
        $availableGroups = array_filter($allGroups, fn($g) => !in_array($g['id'], $recipGroupIds));
    ?>
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden"
         id="template-<?= htmlspecialchars($tmpl['id']) ?>">

        <!-- Template header -->
        <div class="px-5 py-4 flex items-start justify-between gap-4">
            <div class="flex items-start gap-3 min-w-0">
                <div class="w-10 h-10 rounded-lg <?= typeColor($tmpl['stage_type']) ?> flex items-center justify-center shrink-0 mt-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($tmpl['name']) ?></h3>
                    <p class="text-sm text-gray-500 mt-0.5">
                        <?= typeLabel($tmpl['stage_type']) ?>
                        <?php if ($tmpl['stage_type'] === 'approval'): ?>
                            · <?= $tmpl['approval_mode'] === 'all' ? 'All must approve' : 'Any one approves' ?>
                        <?php endif; ?>
                        <?php if (!empty($tmpl['reminder_days'])): ?>
                            · <span class="text-amber-600">Reminder: <?= (int)$tmpl['reminder_days'] ?>d</span>
                        <?php endif; ?>
                        <?php if (!empty($tmpl['escalation_days'])): ?>
                            · <span class="text-red-500">Escalate: <?= (int)$tmpl['escalation_days'] ?>d</span>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($tmpl['description'])): ?>
                        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($tmpl['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <span class="px-2.5 py-1 bg-gray-100 text-gray-600 text-xs font-medium rounded-full mr-1">
                    <?= count($recips) ?> recipient<?= count($recips) !== 1 ? 's' : '' ?>
                </span>
                <button onclick='openTemplateModal(<?= json_encode($tmpl) ?>)'
                        class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" title="Edit">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                </button>
                <button onclick="archiveTemplate('<?= htmlspecialchars($tmpl['id']) ?>', '<?= htmlspecialchars(addslashes($tmpl['name'])) ?>', true)"
                        class="p-2 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" title="Archive template">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Recipients section -->
        <div class="border-t border-gray-100 px-5 py-4 bg-gray-50/50">
            <!-- Add recipient row -->
            <div class="flex gap-2 mb-3">
                <select id="add-user-<?= $tmpl['id'] ?>"
                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none bg-white">
                    <option value="">Add a user…</option>
                    <?php foreach ($availableUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['id']) ?>">
                            <?= htmlspecialchars($u['display_name'] ?? $u['email']) ?> (<?= htmlspecialchars($u['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="addRecipient('<?= htmlspecialchars($tmpl['id']) ?>', 'user')"
                        class="px-3 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shrink-0">Add</button>
            </div>
            <div class="flex gap-2 mb-3">
                <select id="add-group-<?= $tmpl['id'] ?>"
                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none bg-white">
                    <option value="">Add a group…</option>
                    <?php foreach ($availableGroups as $g): ?>
                        <option value="<?= htmlspecialchars($g['id']) ?>"><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button onclick="addRecipient('<?= htmlspecialchars($tmpl['id']) ?>', 'group')"
                        class="px-3 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shrink-0">Add</button>
            </div>

            <!-- Recipient list -->
            <div class="space-y-1.5" id="recips-<?= htmlspecialchars($tmpl['id']) ?>">
                <?php if (empty($recips)): ?>
                    <p class="text-sm text-gray-400 py-1 text-center">No recipients — add users or groups above</p>
                <?php endif; ?>
                <?php foreach ($recips as $r): ?>
                    <?php
                        if (!empty($r['user_id'])) {
                            $u = $userMap[$r['user_id']] ?? null;
                            $label  = $u ? htmlspecialchars($u['display_name'] ?? $u['email']) : 'Unknown user';
                            $sublabel = $u ? htmlspecialchars($u['email']) : '';
                            $icon   = 'text-brand-700 bg-brand-100';
                            $initial = strtoupper(substr($u['display_name'] ?? 'U', 0, 1));
                            $badge  = '';
                        } elseif (!empty($r['group_id'])) {
                            $g = $groupMap[$r['group_id']] ?? null;
                            $label  = $g ? htmlspecialchars($g['name']) : 'Unknown group';
                            $sublabel = 'Group';
                            $icon   = 'text-emerald-700 bg-emerald-100';
                            $initial = 'G';
                            $badge  = '<span class="ml-1.5 px-1.5 py-0.5 text-xs bg-emerald-100 text-emerald-700 rounded font-medium">Group</span>';
                        } else {
                            continue;
                        }
                    ?>
                    <div class="flex items-center justify-between py-2 px-3 bg-white rounded-lg" id="recip-<?= $r['id'] ?>">
                        <div class="flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-full <?= $icon ?> flex items-center justify-center font-semibold text-xs">
                                <?= $initial ?>
                            </div>
                            <div>
                                <span class="text-sm font-medium text-gray-900"><?= $label ?><?= $badge ?></span>
                                <?php if ($sublabel && $sublabel !== 'Group'): ?>
                                    <span class="text-xs text-gray-400 ml-1.5"><?= $sublabel ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button onclick="removeRecipient('<?= htmlspecialchars($r['id']) ?>')"
                                class="p-1 text-gray-400 hover:text-red-500 rounded transition-colors" title="Remove">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Archived templates (collapsible) ──────────────────────────────────── -->
<?php if (!empty($archivedTemplates)): ?>
<details class="mt-8 group">
    <summary class="cursor-pointer flex items-center gap-2 text-sm font-medium text-gray-400 hover:text-gray-600 transition-colors list-none select-none">
        <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <?= count($archivedTemplates) ?> archived template<?= count($archivedTemplates) !== 1 ? 's' : '' ?>
    </summary>
    <div class="mt-4 space-y-3">
        <?php foreach ($archivedTemplates as $tmpl): ?>
        <div class="bg-white rounded-xl border border-gray-200 opacity-60 px-5 py-4 flex items-center justify-between gap-4"
             id="template-<?= htmlspecialchars($tmpl['id']) ?>">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-8 h-8 rounded-lg bg-gray-100 text-gray-400 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-600 truncate"><?= htmlspecialchars($tmpl['name']) ?></p>
                    <p class="text-xs text-gray-400">
                        <?= typeLabel($tmpl['stage_type']) ?>
                        · Archived <?= date('j M Y', strtotime($tmpl['archived_at'])) ?>
                    </p>
                </div>
            </div>
            <button onclick="archiveTemplate('<?= htmlspecialchars($tmpl['id']) ?>', '<?= htmlspecialchars(addslashes($tmpl['name'])) ?>', false)"
                    class="px-3 py-1.5 text-xs font-medium text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors shrink-0">
                Unarchive
            </button>
        </div>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<!-- ── Template Modal ─────────────────────────────────────────────────────── -->
<div id="template-modal" class="fixed inset-0 z-40 hidden">
    <div class="fixed inset-0 bg-black/40" onclick="closeTemplateModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md relative my-4">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 id="template-modal-title" class="text-lg font-semibold text-gray-900">New Template</h2>
            </div>
            <div class="px-6 py-5 space-y-4">
                <input type="hidden" id="tmpl-id">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Template Name <span class="text-red-500">*</span></label>
                    <input id="tmpl-name" type="text" placeholder="e.g. Manager Approval"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                    <p class="mt-1 text-xs text-gray-400">This becomes the default stage name when applied to a form.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="tmpl-description" rows="2" placeholder="Optional — describe when to use this template…"
                              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Stage Type</label>
                    <select id="tmpl-type" onchange="tmplToggleMode()"
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        <option value="approval">Approval — requires sign-off</option>
                        <option value="notification">Notification — inform only</option>
                        <option value="signature">Signature — requires signature</option>
                        <option value="action">Action — checklist item to mark done</option>
                    </select>
                </div>

                <div id="tmpl-mode-group">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Approval Mode</label>
                    <select id="tmpl-mode"
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        <option value="any">Any one approver</option>
                        <option value="all">All must approve</option>
                    </select>
                </div>

                <div id="tmpl-reminder-group" class="border-t border-gray-100 pt-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Reminders &amp; Escalation <span class="font-normal normal-case text-gray-400">(optional)</span></p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Reminder every (days)</label>
                            <input id="tmpl-reminder-days" type="number" min="1" max="365" placeholder="e.g. 3"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Escalate after (days)</label>
                            <input id="tmpl-escalation-days" type="number" min="1" max="365" placeholder="e.g. 7"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeTemplateModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                <button onclick="saveTemplate()"
                        class="px-5 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700">Save Template</button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Template CRUD ─────────────────────────────────────────────────────────────
function openTemplateModal(tmpl = null) {
    document.getElementById('template-modal').classList.remove('hidden');
    if (tmpl) {
        document.getElementById('template-modal-title').textContent = 'Edit Template';
        document.getElementById('tmpl-id').value              = tmpl.id;
        document.getElementById('tmpl-name').value            = tmpl.name || '';
        document.getElementById('tmpl-description').value     = tmpl.description || '';
        document.getElementById('tmpl-type').value            = tmpl.stage_type || 'approval';
        document.getElementById('tmpl-mode').value            = tmpl.approval_mode || 'any';
        document.getElementById('tmpl-reminder-days').value   = tmpl.reminder_days   ?? '';
        document.getElementById('tmpl-escalation-days').value = tmpl.escalation_days ?? '';
    } else {
        document.getElementById('template-modal-title').textContent = 'New Template';
        document.getElementById('tmpl-id').value              = '';
        document.getElementById('tmpl-name').value            = '';
        document.getElementById('tmpl-description').value     = '';
        document.getElementById('tmpl-type').value            = 'approval';
        document.getElementById('tmpl-mode').value            = 'any';
        document.getElementById('tmpl-reminder-days').value   = '';
        document.getElementById('tmpl-escalation-days').value = '';
    }
    tmplToggleMode();
    setTimeout(() => document.getElementById('tmpl-name').focus(), 100);
}

function closeTemplateModal() {
    document.getElementById('template-modal').classList.add('hidden');
}

function tmplToggleMode() {
    const type = document.getElementById('tmpl-type').value;
    document.getElementById('tmpl-mode-group').style.display    = type === 'approval' ? 'block' : 'none';
    document.getElementById('tmpl-reminder-group').style.display = type === 'notification' ? 'none' : 'block';
}

async function saveTemplate() {
    const id   = document.getElementById('tmpl-id').value;
    const name = document.getElementById('tmpl-name').value.trim();
    if (!name) { showToast('Template name is required', 'error'); return; }

    const reminderVal   = document.getElementById('tmpl-reminder-days').value.trim();
    const escalationVal = document.getElementById('tmpl-escalation-days').value.trim();

    const payload = {
        action:          id ? 'update_template' : 'create_template',
        id:              id || undefined,
        name,
        description:     document.getElementById('tmpl-description').value.trim(),
        stage_type:      document.getElementById('tmpl-type').value,
        approval_mode:   document.getElementById('tmpl-mode').value,
        reminder_days:   reminderVal   !== '' ? parseInt(reminderVal,   10) : '',
        escalation_days: escalationVal !== '' ? parseInt(escalationVal, 10) : '',
    };

    try {
        await api('/stage-templates.php', payload);
        showToast(id ? 'Template updated' : 'Template created');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function archiveTemplate(id, name, archive) {
    const msg = archive
        ? `Archive "${name}"? It will no longer appear in the form stage picker.`
        : `Unarchive "${name}"? It will become available in the form stage picker again.`;
    if (!confirmAction(msg)) return;
    try {
        await api('/stage-templates.php', { action: 'archive_template', id, archive });
        showToast(archive ? 'Template archived' : 'Template unarchived');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

// ── Recipients ────────────────────────────────────────────────────────────────
async function addRecipient(templateId, type) {
    const selId = type === 'user' ? 'add-user-' + templateId : 'add-group-' + templateId;
    const sel   = document.getElementById(selId);
    const value = sel?.value;
    if (!value) { showToast('Select a ' + type + ' first', 'error'); return; }

    const payload = { action: 'add_recipient', template_id: templateId };
    if (type === 'user')  payload.user_id  = value;
    if (type === 'group') payload.group_id = value;

    try {
        await api('/stage-templates.php', payload);
        showToast('Recipient added');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function removeRecipient(id) {
    if (!confirmAction('Remove this recipient from the template?')) return;
    try {
        await api('/stage-templates.php', { action: 'remove_recipient', id });
        showToast('Recipient removed');
        document.getElementById('recip-' + id)?.remove();
    } catch (e) {
        showToast(e.message, 'error');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
