<?php
/**
 * forms.php — Approval Forms Registry
 * CRUD interface for creating/editing/deleting approval forms.
 * Each form links to its stages, recipients, and routing rules.
 */

require_once __DIR__ . '/includes/auth-check.php';

// ── Handle AJAX POST requests ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        switch ($action) {
            case 'create':
                $result = $sb->from('forms')->insert([
                    'title'          => trim($input['title'] ?? ''),
                    'description'    => trim($input['description'] ?? ''),
                    'created_by'     => $currentUser['id'],
                    'allow_resubmit' => !empty($input['allow_resubmit']),
                    'status'         => $input['status'] ?? 'draft',
                ]);
                echo json_encode(['ok' => true, 'form' => $result[0] ?? null]);
                break;

            case 'update':
                $result = $sb->from('forms')
                    ->eq('id', $input['id'])
                    ->update([
                        'title'          => trim($input['title'] ?? ''),
                        'description'    => trim($input['description'] ?? ''),
                        'allow_resubmit' => !empty($input['allow_resubmit']),
                        'status'         => $input['status'] ?? 'draft',
                    ]);
                echo json_encode(['ok' => true, 'form' => $result[0] ?? null]);
                break;

            case 'delete':
                $sb->from('forms')->eq('id', $input['id'])->delete();
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

// ── Fetch all forms ─────────────────────────────────────
$forms = $sb->from('forms')->select('*')->order('created_at', false)->execute() ?? [];

$pageTitle  = 'Forms';
$activePage = 'forms';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Page heading -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Approval Forms</h1>
        <p class="mt-1 text-sm text-gray-500">Create and configure approval workflows</p>
    </div>
    <button onclick="openFormModal()"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        New Form
    </button>
</div>

<!-- Forms list -->
<div id="forms-list" class="space-y-4">
    <?php if (empty($forms)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-gray-500 font-medium">No forms yet</p>
            <p class="text-gray-400 text-sm mt-1">Create your first approval form to get started.</p>
        </div>
    <?php else: ?>
        <?php foreach ($forms as $form): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-sm transition-shadow"
                 id="form-<?= htmlspecialchars($form['id']) ?>">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3">
                            <h3 class="text-base font-semibold text-gray-900 truncate">
                                <?= htmlspecialchars($form['title']) ?>
                            </h3>
                            <?php
                                $statusColors = [
                                    'draft'  => 'bg-gray-100 text-gray-600',
                                    'active' => 'bg-emerald-100 text-emerald-700',
                                    'paused' => 'bg-amber-100 text-amber-700',
                                ];
                                $sc = $statusColors[$form['status']] ?? $statusColors['draft'];
                            ?>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium <?= $sc ?>">
                                <?= ucfirst(htmlspecialchars($form['status'])) ?>
                            </span>
                            <?php if (!empty($form['allow_resubmit'])): ?>
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-600">
                                    Resubmit OK
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($form['description'])): ?>
                            <p class="mt-1 text-sm text-gray-500 line-clamp-2"><?= htmlspecialchars($form['description']) ?></p>
                        <?php endif; ?>
                        <p class="mt-2 text-xs text-gray-400">
                            Created <?= date('j M Y', strtotime($form['created_at'])) ?>
                        </p>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex items-center gap-1 ml-4 shrink-0">
                        <a href="/form-stages.php?form_id=<?= urlencode($form['id']) ?>"
                           class="p-2 text-gray-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"
                           title="Manage Stages">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                            </svg>
                        </a>
                        <a href="/routing-rules.php?form_id=<?= urlencode($form['id']) ?>"
                           class="p-2 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                           title="Routing Rules">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </a>
                        <button onclick='openFormModal(<?= json_encode($form) ?>)'
                                class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                                title="Edit Form">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button onclick="deleteForm('<?= htmlspecialchars($form['id']) ?>', '<?= htmlspecialchars(addslashes($form['title'])) ?>')"
                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                title="Delete Form">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

<!-- ── Create / Edit Modal ──────────────────────────────── -->
<div id="form-modal" class="fixed inset-0 z-40 hidden">
    <div class="fixed inset-0 bg-black/40" onclick="closeFormModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg relative">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 id="modal-title" class="text-lg font-semibold text-gray-900">New Form</h2>
            </div>
            <div class="px-6 py-5 space-y-4">
                <input type="hidden" id="form-id">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                    <input id="form-title" type="text" placeholder="e.g. Purchase Order Approval"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="form-desc" rows="3" placeholder="Optional description of this workflow..."
                              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none resize-none"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select id="form-status"
                                class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input id="form-resubmit" type="checkbox"
                                   class="w-4 h-4 text-brand-600 border-gray-300 rounded focus:ring-brand-500">
                            <span class="text-sm text-gray-700">Allow resubmission</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeFormModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                    Cancel
                </button>
                <button onclick="saveForm()" id="save-btn"
                        class="px-5 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700">
                    Save Form
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openFormModal(form = null) {
    document.getElementById('form-modal').classList.remove('hidden');
    if (form) {
        document.getElementById('modal-title').textContent = 'Edit Form';
        document.getElementById('form-id').value = form.id;
        document.getElementById('form-title').value = form.title || '';
        document.getElementById('form-desc').value = form.description || '';
        document.getElementById('form-status').value = form.status || 'draft';
        document.getElementById('form-resubmit').checked = !!form.allow_resubmit;
    } else {
        document.getElementById('modal-title').textContent = 'New Form';
        document.getElementById('form-id').value = '';
        document.getElementById('form-title').value = '';
        document.getElementById('form-desc').value = '';
        document.getElementById('form-status').value = 'draft';
        document.getElementById('form-resubmit').checked = false;
    }
    setTimeout(() => document.getElementById('form-title').focus(), 100);
}

function closeFormModal() {
    document.getElementById('form-modal').classList.add('hidden');
}

async function saveForm() {
    const id    = document.getElementById('form-id').value;
    const title = document.getElementById('form-title').value.trim();
    if (!title) { showToast('Title is required', 'error'); return; }

    const payload = {
        action:         id ? 'update' : 'create',
        id:             id || undefined,
        title:          title,
        description:    document.getElementById('form-desc').value.trim(),
        status:         document.getElementById('form-status').value,
        allow_resubmit: document.getElementById('form-resubmit').checked,
    };

    try {
        await api('/forms.php', payload);
        showToast(id ? 'Form updated' : 'Form created');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function deleteForm(id, title) {
    if (!confirmAction(`Delete "${title}"? This will also remove all its stages, recipients, and routing rules.`)) return;
    try {
        await api('/forms.php', { action: 'delete', id });
        showToast('Form deleted');
        document.getElementById('form-' + id)?.remove();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

// Auto-open modal if ?action=new
if (new URLSearchParams(location.search).get('action') === 'new') {
    openFormModal();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
