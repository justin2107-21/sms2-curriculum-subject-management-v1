(function () {
    'use strict';
    const ui = window.CurriculumUI;
    const form = document.getElementById('adminAccountForm');
    const modal = ui.createModal(document.getElementById('adminAccountModal'));
    const credential = ui.createModal(document.getElementById('adminCredentialModal'));
    const credentialShell = document.getElementById('adminCredentialModal');
    if (credentialShell) new MutationObserver(() => {
        const output = document.getElementById('adminTemporaryCredential');
        if (credentialShell.hidden && output.textContent) {
            output.textContent = ''; window.location.reload();
        }
    }).observe(credentialShell, {attributes:true, attributeFilter:['hidden']});
    document.getElementById('adminNewUser')?.addEventListener('click', () => {
        form.reset(); form.elements.id.value = ''; document.getElementById('adminAccountTitle').textContent = 'Create User';
        document.getElementById('adminAccountMessage').hidden = true; modal.open();
    });
    document.querySelectorAll('[data-admin-edit]').forEach(button => button.addEventListener('click', async () => {
        try {
            const {user} = await ui.fetchJson('account_actions.php', {action:'get', id:button.dataset.adminEdit});
            form.reset(); ['id','username','email','first_name','last_name','is_active'].forEach(key => form.elements[key].value = user[key]);
            form.elements.role_id.value = user.role_ids[0] || ''; document.getElementById('adminAccountTitle').textContent = 'Edit User';
            document.getElementById('adminAccountMessage').hidden = true; modal.open();
        } catch (error) { ui.showToast(error.message, 'error'); }
    }));
    form?.addEventListener('submit', async event => {
        event.preventDefault(); const button = form.querySelector('[type="submit"]'); button.disabled = true;
        try {
            const data = await ui.postForm('account_actions.php', new FormData(form)); modal.close();
            if (data.temporary_password) {
                document.getElementById('adminTemporaryCredential').textContent = data.user.username + ': ' + data.temporary_password;
                credential.open();
            } else window.location.reload();
        } catch (error) { const msg=document.getElementById('adminAccountMessage');msg.textContent=error.message;msg.hidden=false; }
        finally { button.disabled=false; }
    });
    document.querySelectorAll('[data-role-permissions]').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault(); const button=form.querySelector('[type="submit"]');button.disabled=true;
        try { await ui.postForm('account_actions.php', new FormData(form)); ui.showToast('Role permissions saved.', 'success'); }
        catch(error){ui.showToast(error.message,'error');} finally{button.disabled=false;}
    }));
})();
