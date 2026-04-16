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

                $result = $sb->from('form_stages')->insert([
                    'form_id'       => $formId,
                    'stage_order'   => $nextOrder,
                    'stage_name'    => trim($input['stage_name'] ?? 'New Stage'),
                    'stage_type'    => $input['stage_type'] ?? 'approval',
                    'approval_mode' => $input['approval_mode'] ?? 'any',
                ]);
                echo json_encode(['ok' => true, 'stage' => $result[0] ?? null]);
                break;

            case 'update':
                $result = $sb->from('form_stages')
                    ->eq('id', $input['id'])
                    ->update([
                        'stage_name'    => trim($input['stage_name'] ?? ''),
                        'stage_type'    => $input['stage_type'] ?? 'approval',
                        'approval_mode' => $input['approval_mode'] ?? 'any',
                    ]);
                echo json_encode(['ok' => true, 'stage' => $result[0] ?? null]);
                break;

            case 'delete':
                $sb->from('form_stages')->eq('id', $input['id'])->delete();
                echo json_encode(['ok' => true]);
                break;

            case 'reorder':
                // $input['order'] = ['uuid1', 'uuid2', ...]
                $ids = $input['order'] ?? [];
                foreach ($ids as $i => $stageId) {
                    $sb->from('form_stages')
                        ->eq('id', $stageId)
                        ->update(['stage_order' => $i + 1]);
                }
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
        <p class="mt-1 text-sm text-gray-500">Drag to reorder. Each stage is evaluated in sequence.</p>
    </div>
    <button onclick="openStageModal()"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Add Stage
    </button>
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
                ];
                $typeColors = [
                    'approval'     => 'text-emerald-500 bg-emerald-50',
                    'notification' => 'text-blue-500 bg-blue-50',
                    'signature'    => 'text-purple-500 bg-purple-50',
                ];
                $icon  = $typeIcons[$stage['stage_type']]  ?? $typeIcons['approval'];
                $color = $typeColors[$stage['stage_type']] ?? $typeColors['approval'];
            ?>
            <div class="stage-item bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-4 hover:shadow-sm transition-shadow"
                 data-id="<?= htmlspecialchars($stage['id']) ?>">
                <!-- Drag handle -->
                <div class="drag-handle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M7 2a2 2 0 10.001 4.001A2 2 0 007 2zm0 6a2 2 0 10.001 4.001A2 2 0 007 8zm0 6a2 2 0 10.001 4.001A2 2 0 007 14zm6-8a2 2 0 10-.001-4.001A2 2 0 0013 6zm0 2a2 2 0 10.001 4.001A2 2 0 0013 8zm0 6a2 2 0 10.001 4.001A2 2 0 0013 14z"/>
                    </svg>
                </div>

                <!-- Order badge -->
                <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-sm font-bold text-gray-600 shrink-0 stage-order">
                    <?= $stage['stage_order'] ?>
                </div>

                <!-- Type icon -->
                <div class="w-9 h-9 rounded-lg <?= $color ?> flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $icon ?></svg>
                </div>

                <!-- Stage info -->
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($stage['stage_name']) ?></h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        <?= ucfirst($stage['stage_type']) ?>
                        <?php if ($stage['stage_type'] === 'approval'): ?>
                            · <?= $stage['approval_mode'] === 'all' ? 'All must approve' : 'Any one approves' ?>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-1 shrink-0">
                    <a href="/recipients.php?stage_id=<?= urlencode($stage['id']) ?>&form_id=<?= urlencode($formId) ?>"
                       class="p-2 text-gray-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"
                       title="Manage Recipients">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </a>
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

// ── Drag-to-reorder ─────────────────────────────────────
const list = document.getElementById('stages-list');
if (list.querySelector('.stage-item')) {
    Sortable.create(list, {
        handle: '.drag-handle',
        animation: 200,
        ghostClass: 'opacity-40',
        onEnd: async function() {
            const items = list.querySelectorAll('.stage-item');
            const order = [];
            items.forEach((el, i) => {
                order.push(el.dataset.id);
                el.querySelector('.stage-order').textContent = i + 1;
            });
            try {
                await api('/form-stages.php?form_id=' + formId, { action: 'reorder', order });
                showToast('Order saved');
            } catch (e) {
                showToast(e.message, 'error');
            }
        }
    });
}

// ── Modal ────────────────────────────────────────────────
function openStageModal(stage = null) {
    document.getElementById('stage-modal').classList.remove('hidden');
    if (stage) {
        document.getElementById('stage-modal-title').textContent = 'Edit Stage';
        document.getElementById('stage-id').value = stage.id;
        document.getElementById('stage-name').value = stage.stage_name || '';
        document.getElementById('stage-type').value = stage.stage_type || 'approval';
        document.getElementById('stage-approval-mode').value = stage.approval_mode || 'any';
    } else {
        document.getElementById('stage-modal-title').textContent = 'Add Stage';
        document.getElementById('stage-id').value = '';
        document.getElementById('stage-name').value = '';
        document.getElementById('stage-type').value = 'approval';
        document.getElementById('stage-approval-mode').value = 'any';
    }
    toggleApprovalMode();
    setTimeout(() => document.getElementById('stage-name').focus(), 100);
}

function closeStageModal() {
    document.getElementById('stage-modal').classList.add('hidden');
}

function toggleApprovalMode() {
    const type = document.getElementById('stage-type').value;
    const group = document.getElementById('approval-mode-group');
    group.style.display = type === 'approval' ? 'block' : 'none';

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

    const payload = {
        action:        id ? 'update' : 'create',
        id:            id || undefined,
        stage_name:    name,
        stage_type:    document.getElementById('stage-type').value,
        approval_mode: document.getElementById('stage-approval-mode').value,
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
