(() => {
  'use strict';
  const table = document.getElementById('usersTable');
  const form = document.getElementById('usersBulkForm');
  const status = document.getElementById('usersDeleteStatus');
  if (!table || !form || !status) return;

  let deleting = false;
  table.addEventListener('click', async event => {
    const button = event.target.closest('button[data-delete-account]');
    if (!button || button.disabled || deleting) return;
    const row = button.closest('.user-row');
    const name = row.querySelector('.user-id strong').textContent;
    if (!window.confirm('¿Eliminar definitivamente la cuenta «' + name + '» y sus datos personales?')) return;

    deleting = true;
    button.disabled = true;
    status.textContent = 'Eliminando la cuenta…';
    try {
      const body = new URLSearchParams({
        action: 'delete',
        id: button.dataset.deleteAccount,
        csrf_token: form.elements.csrf_token.value
      });
      const response = await fetch(location.href, {
        method: 'POST',
        credentials: 'same-origin',
        body
      });
      const result = await response.json();
      if (!response.ok || result.success !== true) throw Error(result.error || 'No se pudo eliminar la cuenta.');
      row.remove();
      table.dispatchEvent(new Event('sf:account-deleted'));
      status.textContent = 'Cuenta eliminada.';
      document.getElementById('userSearch')?.focus();
    } catch (error) {
      status.textContent = error instanceof SyntaxError ? 'No se pudo eliminar la cuenta. Recarga la página e inténtalo de nuevo.' : error.message;
      button.disabled = false;
    } finally {
      deleting = false;
    }
  });
})();
