<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$isAuthPage = in_array($currentPage, ['login.php', 'register.php', 'forgot_password.php', 'reset_password.php']);
?>
<?php if ($isAuthPage): ?>
    </div>
    <footer class="auth-footer">
        <p>&copy; <?= date('Y') ?> Municipality of Carranglan, Nueva Ecija. All rights reserved.</p>
    </footer>
<?php else: ?>
        </main>
        <footer>
            <p>&copy; <?= date('Y') ?> Municipality of Carranglan, Nueva Ecija. All rights reserved.</p>
        </footer>
    </div>
</div>
<?php endif; ?>
<!-- Sidebar script moved to includes/sidebar.php -->
<div class="global-confirm-backdrop" id="global-action-confirm" hidden>
    <div class="global-confirm-card" role="dialog" aria-modal="true" aria-labelledby="global-action-confirm-title">
        <div class="global-confirm-icon" id="global-action-confirm-icon"><i class="bi bi-trash3" aria-hidden="true"></i></div>
        <h3 id="global-action-confirm-title">Delete?</h3>
        <p id="global-action-confirm-message">Are you sure you want to delete this item?</p>
        <div class="global-confirm-actions">
            <button type="button" class="admin-secondary-button" title="Cancel" aria-label="Cancel" data-global-confirm-cancel><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <button type="button" class="admin-primary-button global-confirm-delete" title="Delete" aria-label="Delete" data-global-confirm-submit><i class="bi bi-trash3" aria-hidden="true"></i></button>
        </div>
    </div>
</div>
<script>
    (() => {
        const controls = document.querySelectorAll(
            '.admin-primary-button, .admin-secondary-button, .admin-link-button, .admin-icon-button, .btn-action, .icon-circle-button'
        );

        controls.forEach((control) => {
            if (control.closest('.sidebar, .header-right, .announcement-confirm-card, .global-confirm-card, .processing-actions-panel, .workflow-receive-form, .workflow-text-button, .workflow-icon-button, .documents-header-row, .document-versions-modal')) {
                return;
            }

            const actionLabel = [
                control.getAttribute('aria-label'),
                control.getAttribute('title'),
                control.textContent
            ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
            if (/\b(add|create|new)\b/i.test(actionLabel)) {
                control.classList.add('add-create-control');
                return;
            }

            const originalLabel = (control.getAttribute('aria-label') || control.getAttribute('title') || control.textContent || '').trim();
            if (!originalLabel) {
                return;
            }

            const normalizedLabel = originalLabel.replace(/\s+/g, ' ');
            const text = normalizedLabel.toLowerCase();
            let label = normalizedLabel;
            if (text.includes('delete') || text.includes('trash')) {
                label = 'Delete';
            } else if (text.includes('suspend') || text.includes('pause')) {
                label = 'Suspend';
            } else if (text.includes('edit') || text.includes('update') || text.includes('configure')) {
                label = 'Edit';
            } else if (text.includes('view') || text.includes('open') || text.includes('details') || text.includes('review') || text.includes('track')) {
                label = 'View';
            } else if (text.includes('approve') || text.includes('accept') || text.includes('save') || text.includes('restore') || text.includes('confirm') || text.includes('complete') || text.includes('read')) {
                label = text.includes('restore') ? 'Restore' : 'Save';
            } else if (text.includes('return')) {
                label = 'Return';
            } else if (text.includes('cancel') || text.includes('close') || text.includes('reject')) {
                label = 'Cancel';
            } else if (text.includes('search') || text.includes('filter')) {
                label = 'Search';
            } else if (text.includes('add') || text.includes('create') || text.includes('new') || text.includes('post') || text.includes('submit')) {
                label = 'Add';
            } else if (text.includes('print')) {
                label = 'Print';
            } else if (text.includes('download')) {
                label = 'Download';
            } else if (text.includes('forward') || text.includes('route')) {
                label = 'Forward';
            }
            control.setAttribute('data-tooltip', label);
            control.setAttribute('aria-label', label);
            control.removeAttribute('title');

            let iconClass = 'bi-three-dots';
            if (text.includes('return')) {
                iconClass = 'bi-arrow-left';
            } else if (text.includes('delete') || text.includes('reject') || text.includes('cancel')) {
                iconClass = text.includes('delete') ? 'bi-trash3' : 'bi-x-lg';
            } else if (text.includes('approve') || text.includes('accept') || text.includes('save') || text.includes('read') || text.includes('restore') || text.includes('confirm') || text.includes('complete')) {
                iconClass = 'bi-check-lg';
            } else if (text.includes('search') || text.includes('filter')) {
                iconClass = 'bi-search';
            } else if (text.includes('view') || text.includes('open') || text.includes('details') || text.includes('review') || text.includes('track')) {
                iconClass = 'bi-eye-fill';
            } else if (text.includes('edit') || text.includes('update')) {
                iconClass = 'bi-pencil-fill';
            } else if (text.includes('suspend') || text.includes('pause')) {
                iconClass = 'bi-pause-fill';
            } else if (text.includes('add') || text.includes('create') || text.includes('new') || text.includes('post') || text.includes('submit')) {
                iconClass = 'bi-plus-lg';
            } else if (text.includes('print') || text.includes('download')) {
                iconClass = 'bi-download';
            } else if (text.includes('forward') || text.includes('route')) {
                iconClass = 'bi-arrow-right';
            }

            control.innerHTML = `<i class="bi ${iconClass}" aria-hidden="true"></i>`;
            control.classList.add('system-icon-control');
        });
    })();

    (() => {
        const modal = document.getElementById('global-action-confirm');
        if (!modal) return;

        let pendingForm = null;
        const title = modal.querySelector('#global-action-confirm-title');
        const message = modal.querySelector('#global-action-confirm-message');
        const icon = modal.querySelector('#global-action-confirm-icon i');
        const submitButton = modal.querySelector('[data-global-confirm-submit]');
        const closeModal = () => {
            modal.hidden = true;
            pendingForm = null;
        };

        document.addEventListener('click', (event) => {
            const button = event.target.closest('button, input[type="submit"]');
            if (!button || button.closest('.announcement-confirm-card, .announcement-confirm-backdrop')) return;

            const workflowAction = button.getAttribute('data-confirm-action');
            const workflowCopy = {
                approve: ['Approve document?', 'Are you sure you want to approve and forward this document?', 'bi-check-lg'],
                receive: ['Confirm receipt?', 'Are you sure you want to confirm receipt of this document?', 'bi-inbox-fill'],
                return: ['Return document?', 'Are you sure you want to return this document to the previous sender?', 'bi-arrow-left'],
                forward: ['Forward document?', 'Are you sure you want to forward this document to the selected department?', 'bi-arrow-right']
            };
            const isWorkflowAction = workflowAction && workflowCopy[workflowAction];
            if (!isWorkflowAction) return;

            const form = button.closest('form');
            if (!form || form.classList.contains('delete-announcement-form')) return;

            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                form.reportValidity();
                return;
            }

            event.preventDefault();
            pendingForm = form;
            const action = workflowCopy[workflowAction][0].replace('?', '');
            title.textContent = workflowCopy[workflowAction][0];
            message.textContent = workflowCopy[workflowAction][1];
            const confirmIcon = workflowCopy[workflowAction][2];
            submitButton.innerHTML = `<i class="bi ${confirmIcon}" aria-hidden="true"></i>`;
            submitButton.setAttribute('aria-label', action);
            submitButton.classList.remove('global-confirm-suspend', 'global-confirm-delete');
            icon.className = `bi ${confirmIcon}`;
            modal.hidden = false;
        });

        modal.querySelector('[data-global-confirm-cancel]').addEventListener('click', closeModal);
        modal.querySelector('[data-global-confirm-submit]').addEventListener('click', () => {
            if (!pendingForm) return;
            const form = pendingForm;
            closeModal();
            form.submit();
        });
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });
    })();
</script>

</body>
</html>
