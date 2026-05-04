(() => {
  const root = document.documentElement;
  const storedTheme = localStorage.getItem('lex-theme');
  if (storedTheme) root.dataset.theme = storedTheme;

  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });
  }

  const themeToggle = document.getElementById('themeToggle');
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      if (next === 'light') {
        delete root.dataset.theme;
        localStorage.removeItem('lex-theme');
      } else {
        root.dataset.theme = 'dark';
        localStorage.setItem('lex-theme', 'dark');
      }
    });
  }

  if (document.querySelector('[data-appointment-board]') || document.querySelector('[data-system-settings-page]')) {
    document.body.classList.add('toast-upper');
  }

  document.querySelectorAll('[data-password-toggle]').forEach((group) => {
    const input = group.querySelector('input[type="password"], input[type="text"]');
    const button = group.querySelector('[data-password-toggle-button]');
    if (!input || !button) return;

    const eyeSvg = `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M12 5c5.5 0 9.9 3.4 11.5 7-1.6 3.6-6 7-11.5 7S2.1 15.6.5 12C2.1 8.4 6.5 5 12 5Zm0 2.2A4.8 4.8 0 1 0 12 19a4.8 4.8 0 0 0 0-9.6Z"/>
      </svg>`;
    const eyeOffSvg = `
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M3 3 21 21"/>
        <path d="M2.5 12s3.6-7 9.5-7c2.1 0 3.9.6 5.3 1.5L18.9 8A18.7 18.7 0 0 1 22 12c-1.6 3.6-6 7-10 7-1.7 0-3.3-.4-4.8-1.1l1.9-1.9A4.8 4.8 0 0 0 12 19a4.8 4.8 0 1 0-4.8-4.8c0 .7.1 1.3.4 1.9L5.8 17C3.4 15.2 2.5 12 2.5 12Zm9.5-4.8a4.8 4.8 0 0 1 4.8 4.8c0 .4 0 .7-.1 1.1l-2-2A2.8 2.8 0 0 0 12 9.2c-.4 0-.8.1-1.2.2L9 9a4.7 4.7 0 0 1 3-1.8Z"/>
      </svg>`;

    const sync = () => {
      const isHidden = input.type === 'password';
      button.innerHTML = isHidden ? eyeSvg : eyeOffSvg;
      button.setAttribute('aria-pressed', String(!isHidden));
      button.setAttribute('aria-label', isHidden ? 'Show password' : 'Hide password');
      button.title = isHidden ? 'Show password' : 'Hide password';
    };

    button.addEventListener('click', () => {
      input.type = input.type === 'password' ? 'text' : 'password';
      sync();
      input.focus();
    });

    sync();
  });

  document.querySelectorAll('.toast').forEach((toast) => {
    const isError = toast.classList.contains('toast-error');
    const dismissAfter = isError ? 1400 : 1000;
    const removeAfter = 180;
    window.setTimeout(() => {
      toast.classList.add('is-dismissing');
      window.setTimeout(() => toast.remove(), removeAfter);
    }, dismissAfter);
  });

  document.querySelectorAll('form').forEach((form) => {
    if (form.hasAttribute('data-no-loading')) {
      return;
    }
    const submit = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', () => {
      if (submit) {
        submit.dataset.originalText = submit.textContent;
        submit.textContent = 'Working...';
        submit.disabled = true;
      }
      form.classList.add('loading');
    });
  });

  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (event) => {
      const message = el.getAttribute('data-confirm') || 'Are you sure?';
      const requiredText = (el.getAttribute('data-confirm-text') || '').trim();
      if (requiredText) {
        const entered = window.prompt(`${message}\n\nType ${requiredText} to continue:`, '');
        if ((entered || '').trim() !== requiredText) {
          event.preventDefault();
          return;
        }
        return;
      }
      if (!confirm(message)) {
        event.preventDefault();
      }
    });
  });

  const apiBase = document.body.dataset.apiBase;
  if (apiBase) {
    fetch(`${apiBase.replace(/\/$/, '')}/health`, { credentials: 'omit' })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data && data.status) {
          console.debug('LEXSHIELD API health:', data.status);
        }
      })
      .catch(() => {
        console.debug('LEXSHIELD API is not reachable from the browser right now.');
      });
  }

  const appointmentBoard = document.querySelector('[data-appointment-board]');
  if (appointmentBoard) {
    const endpoint = appointmentBoard.dataset.endpoint || window.location.href;
    const results = appointmentBoard.querySelector('[data-appointment-results]');
    const pagination = appointmentBoard.querySelector('[data-appointment-pagination]');
    const summary = appointmentBoard.querySelector('[data-appointment-summary]');
    const filters = appointmentBoard.querySelector('[data-appointment-filters]');
    const searchInput = filters ? filters.querySelector('[data-appointment-search]') : null;
    const pageInput = filters ? filters.querySelector('[data-appointment-page-input]') : null;
    let searchTimer = null;
    let activeRequest = 0;

    const readState = () => ({
      q: searchInput ? searchInput.value.trim() : '',
      page: pageInput ? pageInput.value : '1',
    });

    const readStateFromUrl = () => {
      const params = new URLSearchParams(window.location.search);
      return {
        q: params.get('q') || '',
        page: params.get('page') || '1',
      };
    };

    const syncInputs = (state) => {
      if (searchInput && searchInput.value !== (state.q || '')) searchInput.value = state.q || '';
      if (pageInput) pageInput.value = String(state.page || '1');
    };

    const syncUrl = (state, mode) => {
      const url = new URL(window.location.href);
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.page && String(state.page) !== '1') url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      url.searchParams.delete('format');
      if (mode === 'push') {
        history.pushState({}, '', url);
      } else {
        history.replaceState({}, '', url);
      }
    };

    const buildUrl = (state) => {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('format', 'json');
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.page && String(state.page) !== '1') url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      return url;
    };

    const setBusy = (isBusy) => {
      appointmentBoard.classList.toggle('is-loading', isBusy);
      if (results) {
        results.setAttribute('aria-busy', isBusy ? 'true' : 'false');
      }
    };

    const fetchAppointments = async (state, options = {}) => {
      if (!endpoint) return;
      const requestId = ++activeRequest;
      const nextState = {
        q: state.q || '',
        page: state.page || '1',
      };
      const url = buildUrl(nextState);
      setBusy(true);
      try {
        const response = await fetch(url.toString(), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (!response.ok) {
          throw new Error('Request failed');
        }
        const data = await response.json();
        if (requestId !== activeRequest) {
          return;
        }
        if (results && data.resultsHtml !== undefined) {
          results.innerHTML = data.resultsHtml;
        }
        if (pagination && data.paginationHtml !== undefined) {
          pagination.innerHTML = data.paginationHtml;
        }
        if (summary && data.summaryText !== undefined) {
          summary.textContent = data.summaryText;
        }
        const syncedState = data.state || nextState;
        syncInputs(syncedState);
        syncUrl(syncedState, options.push ? 'push' : 'replace');
      } catch (error) {
        console.debug('Unable to refresh appointments right now.');
      } finally {
        if (requestId === activeRequest) {
          setBusy(false);
        }
      }
    };

    if (filters) {
      filters.addEventListener('submit', (event) => {
        event.preventDefault();
        fetchAppointments({
          ...readState(),
          page: '1',
        }, { push: true });
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
          fetchAppointments({
            ...readState(),
            page: '1',
          }, { push: false });
        }, 220);
      });
    }

    if (pageInput) {
      pageInput.addEventListener('change', () => {
        fetchAppointments(readState(), { push: true });
      });
    }

    appointmentBoard.addEventListener('click', (event) => {
      const pageLink = event.target.closest('[data-appointment-page]');
      if (!pageLink) return;
      if (pageLink.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        return;
      }
      if (pageLink.tagName !== 'A') {
        return;
      }
      event.preventDefault();
      fetchAppointments({
        ...readState(),
        page: pageLink.dataset.page || '1',
      }, { push: true });
    });

    window.addEventListener('popstate', () => {
      const state = readStateFromUrl();
      syncInputs(state);
      fetchAppointments(state, { push: false });
    });
  }

    const caseFileClientSelect = document.querySelector('[data-casefile-client-select]');
    const caseFileFullName = document.querySelector('[data-casefile-fullname]');
    let clientDeleteModal = null;
    if (caseFileClientSelect && caseFileFullName) {
      const syncCaseFileName = () => {
        const selected = caseFileClientSelect.selectedOptions[0];
        if (selected) {
          const selectedName = selected.textContent.replace(/\s*-\s*Client\s*$/i, '').trim();
        if (caseFileFullName.value.trim() === '' || caseFileFullName.dataset.autofill === '1') {
          caseFileFullName.value = selectedName;
          caseFileFullName.dataset.autofill = '1';
        }
      }
    };
    caseFileClientSelect.addEventListener('change', syncCaseFileName);
    caseFileFullName.addEventListener('input', () => {
      caseFileFullName.dataset.autofill = caseFileFullName.value.trim() === '' ? '1' : '0';
    });
    syncCaseFileName();
  }

  const caseFilesApp = document.querySelector('[data-case-files-app]');
  if (caseFilesApp) {
    const endpoint = caseFilesApp.dataset.endpoint || '';
    const summaryContainer = document.querySelector('[data-case-summary-container]');
    const listContainer = document.querySelector('[data-case-list-container]');
    const detailContainer = document.querySelector('[data-case-detail-container]');
    const vaultContainer = document.querySelector('[data-case-vault-container]');
    const activityContainer = document.querySelector('[data-case-activity-container]');
    const filterForm = caseFilesApp.querySelector('[data-case-filter-form]');
    const searchStatus = document.querySelector('[data-case-search-status]');
    const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
    const trackedInputs = filterForm ? Array.from(filterForm.querySelectorAll('input[name="q"], select')) : [];
    const scrollVaultIntoView = () => {
      const section = document.querySelector('[data-case-vault-container]');
      if (section) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    };
    let searchTimer = null;
    let activeDetailModal = null;
    let activeCreateModal = null;
    let activeEditModal = null;

    const readFilters = () => ({
      q: searchInput ? searchInput.value.trim() : '',
      status: filterForm ? (filterForm.querySelector('select[name="status"]')?.value || 'all') : 'all',
      sort: filterForm ? (filterForm.querySelector('select[name="sort"]')?.value || 'updated_at') : 'updated_at',
      dir: filterForm ? (filterForm.querySelector('select[name="dir"]')?.value || 'desc') : 'desc',
      page: 1,
      record: 0,
    });

    const readStateFromUrl = () => {
      const params = new URLSearchParams(window.location.search);
      return {
        q: params.get('q') || '',
        status: params.get('status') || 'all',
        sort: params.get('sort') || 'updated_at',
        dir: params.get('dir') || 'desc',
        page: Math.max(1, parseInt(params.get('page') || '1', 10) || 1),
        record: Math.max(0, parseInt(params.get('record') || '0', 10) || 0),
      };
    };

    const buildUrl = (state) => {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('format', 'json');
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.sort && state.sort !== 'updated_at') url.searchParams.set('sort', state.sort); else url.searchParams.delete('sort');
      if (state.dir && state.dir !== 'desc') url.searchParams.set('dir', state.dir); else url.searchParams.delete('dir');
      if (state.page && state.page > 1) url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      if (state.record && state.record > 0) url.searchParams.set('record', String(state.record)); else url.searchParams.delete('record');
      return url;
    };

    const syncUrl = (state, mode) => {
      const url = new URL(window.location.href);
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.sort && state.sort !== 'updated_at') url.searchParams.set('sort', state.sort); else url.searchParams.delete('sort');
      if (state.dir && state.dir !== 'desc') url.searchParams.set('dir', state.dir); else url.searchParams.delete('dir');
      if (state.page && state.page > 1) url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      if (state.record && state.record > 0) url.searchParams.set('record', String(state.record)); else url.searchParams.delete('record');
      url.searchParams.delete('format');
      if (mode === 'push') {
        history.pushState({}, '', url);
      } else {
        history.replaceState({}, '', url);
      }
    };

    const updateFilterForm = (state) => {
      if (!filterForm) return;
      const q = filterForm.querySelector('input[name="q"]');
      const status = filterForm.querySelector('select[name="status"]');
      const sort = filterForm.querySelector('select[name="sort"]');
      const dir = filterForm.querySelector('select[name="dir"]');
      const page = filterForm.querySelector('input[name="page"]');
      if (q && q.value !== state.q) q.value = state.q || '';
      if (status && status.value !== state.status) status.value = state.status || 'all';
      if (sort && sort.value !== state.sort) sort.value = state.sort || 'updated_at';
      if (dir && dir.value !== state.dir) dir.value = state.dir || 'desc';
      if (page) page.value = String(state.page || 1);
    };

    const setBusy = (isBusy, message) => {
      const text = message || (isBusy ? 'Loading case files...' : 'Ready.');
      if (searchStatus) searchStatus.textContent = text;
      [summaryContainer, listContainer, detailContainer, activityContainer].forEach((node) => {
        if (node) node.setAttribute('aria-busy', isBusy ? 'true' : 'false');
      });
    };

    const openDetailModal = () => {
      const modal = document.querySelector('[data-case-detail-modal]');
      if (!modal) return;
      activeDetailModal = modal;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      const focusTarget = modal.querySelector('[data-case-detail-close]') || modal.querySelector('button, a, input, select, textarea');
      if (focusTarget) {
        window.setTimeout(() => focusTarget.focus(), 0);
      }
    };

    const closeDetailModal = () => {
      if (!activeDetailModal) {
        const modal = document.querySelector('[data-case-detail-modal]');
        if (!modal) return;
        activeDetailModal = modal;
      }
      activeDetailModal.classList.remove('is-open');
      activeDetailModal.setAttribute('aria-hidden', 'true');
    };

    const openCreateModal = () => {
      const modal = document.querySelector('[data-case-create-modal]');
      if (!modal) return;
      activeCreateModal = modal;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      const focusTarget = modal.querySelector('[data-case-create-close]') || modal.querySelector('button, a, input, select, textarea');
      if (focusTarget) {
        window.setTimeout(() => focusTarget.focus(), 0);
      }
    };

    const closeCreateModal = () => {
      if (!activeCreateModal) {
        const modal = document.querySelector('[data-case-create-modal]');
        if (!modal) return;
        activeCreateModal = modal;
      }
      activeCreateModal.classList.remove('is-open');
      activeCreateModal.setAttribute('aria-hidden', 'true');
    };

    const openEditModal = (data = {}) => {
      const modal = document.querySelector('[data-case-edit-modal]');
      if (!modal) return;
      activeEditModal = modal;
      const idField = modal.querySelector('[data-case-edit-id]');
      const fullNameField = modal.querySelector('[data-case-edit-full-name]');
      const titleField = modal.querySelector('[data-case-edit-case-title]');
      const descriptionField = modal.querySelector('[data-case-edit-description]');
      const clientField = modal.querySelector('[data-case-edit-client]');
      const lawyerField = modal.querySelector('[data-case-edit-lawyer]');
      const statusField = modal.querySelector('[data-case-edit-status]');
      if (idField) idField.value = data.caseId || '';
      if (fullNameField) fullNameField.value = data.fullName || '';
      if (titleField) titleField.value = data.caseFileTitle || '';
      if (descriptionField) descriptionField.value = data.description || '';
      if (clientField && data.clientUserId && data.clientUserId !== '0') clientField.value = data.clientUserId;
      if (lawyerField && data.assignedLawyerUserId && data.assignedLawyerUserId !== '0') lawyerField.value = data.assignedLawyerUserId;
      if (statusField && data.status) statusField.value = data.status;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      const focusTarget = modal.querySelector('[data-case-edit-full-name]') || modal.querySelector('button, a, input, select, textarea');
      if (focusTarget) {
        window.setTimeout(() => focusTarget.focus(), 0);
      }
    };

    const closeEditModal = () => {
      if (!activeEditModal) {
        const modal = document.querySelector('[data-case-edit-modal]');
        if (!modal) return;
        activeEditModal = modal;
      }
      activeEditModal.classList.remove('is-open');
      activeEditModal.setAttribute('aria-hidden', 'true');
    };

    const bindDetailModal = () => {
      const modal = document.querySelector('[data-case-detail-modal]');
      if (!modal) return;
      modal.querySelectorAll('[data-case-detail-close]').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          closeDetailModal();
        });
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeDetailModal();
        }
      });
    };

    const bindCreateModal = () => {
      const modal = document.querySelector('[data-case-create-modal]');
      if (!modal) return;
      const openTrigger = document.querySelector('[data-case-create-open]');
      if (openTrigger) {
        openTrigger.addEventListener('click', (event) => {
          event.preventDefault();
          openCreateModal();
        });
      }
      modal.querySelectorAll('[data-case-create-close]').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          closeCreateModal();
        });
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeCreateModal();
        }
      });
    };

    const bindEditModal = () => {
      const modal = document.querySelector('[data-case-edit-modal]');
      if (!modal) return;
      modal.querySelectorAll('[data-case-edit-close]').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          closeEditModal();
        });
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeEditModal();
        }
      });
    };

    const bindPersistedForms = () => {
      Array.from(document.querySelectorAll('[data-persist-form]')).forEach((form) => {
        const key = `lex-case-files:${form.dataset.persistForm}`;
        const fields = Array.from(form.querySelectorAll('input, select, textarea')).filter((field) => {
          if (!field.name) return false;
          if (field.type === 'file' || field.type === 'hidden') return false;
          if (field.name === 'csrf_token') return false;
          return true;
        });
        const loadDraft = () => {
          try {
            const raw = localStorage.getItem(key);
            if (!raw) return;
            const draft = JSON.parse(raw);
            fields.forEach((field) => {
              if (!(field.name in draft)) return;
              if (field.type === 'checkbox') {
                field.checked = Boolean(draft[field.name]);
              } else {
                field.value = draft[field.name];
              }
            });
          } catch (error) {
            console.debug('Unable to restore case file draft.');
          }
        };
        const saveDraft = () => {
          try {
            const draft = {};
            fields.forEach((field) => {
              draft[field.name] = field.type === 'checkbox' ? field.checked : field.value;
            });
            localStorage.setItem(key, JSON.stringify(draft));
          } catch (error) {
            console.debug('Unable to store case file draft.');
          }
        };
        loadDraft();
        fields.forEach((field) => {
          field.addEventListener('input', saveDraft);
          field.addEventListener('change', saveDraft);
        });
        form.addEventListener('reset', () => {
          localStorage.removeItem(key);
        });
      });
    };

    const bindValidationForms = () => {
      Array.from(document.querySelectorAll('[data-casefile-form]')).forEach((form) => {
        const fields = Array.from(form.querySelectorAll('input, select, textarea'));
        const validate = () => {
          const errors = [];
          const fullName = form.querySelector('[name="full_name"]');
          const caseFileTitle = form.querySelector('[name="case_file_title"]');
          const client = form.querySelector('[name="client_user_id"]');
          const lawyer = form.querySelector('[name="assigned_lawyer_user_id"]');
          const status = form.querySelector('[name="status"]');
          const errorBox = form.querySelector('[data-form-errors]');
          const mark = (field, invalid) => {
            if (field) field.setAttribute('aria-invalid', invalid ? 'true' : 'false');
          };
          if (fullName && !fullName.value.trim()) { errors.push('FULLNAME is required.'); mark(fullName, true); } else { mark(fullName, false); }
          if (caseFileTitle && !caseFileTitle.value.trim()) { errors.push('CASE FILE is required.'); mark(caseFileTitle, true); } else { mark(caseFileTitle, false); }
          if (client && !client.value) { errors.push('Select a client.'); mark(client, true); } else { mark(client, false); }
          if (lawyer && !lawyer.value) { errors.push('Select an assigned lawyer.'); mark(lawyer, true); } else { mark(lawyer, false); }
          if (status && !status.value) { errors.push('Select a status.'); mark(status, true); } else { mark(status, false); }
          if (errorBox) {
            errorBox.textContent = errors.join(' ');
            errorBox.hidden = errors.length === 0;
          }
          return errors.length === 0;
        };

        fields.forEach((field) => {
          field.addEventListener('input', validate);
          field.addEventListener('change', validate);
        });
        form.addEventListener('submit', (event) => {
          if (!validate()) {
            event.preventDefault();
          }
        });
        validate();
      });
    };

    const bindVaultFolders = () => {
      const folders = Array.from(document.querySelectorAll('.case-vault-folder-section'));
      if (!folders.length) return;
      folders.forEach((folder) => {
        folder.addEventListener('toggle', () => {
          if (!folder.open) return;
          folders.forEach((other) => {
            if (other !== folder) {
              other.open = false;
            }
          });
        });
      });
    };

    const swapContent = (data) => {
      if (summaryContainer && data.summaryHtml) summaryContainer.innerHTML = data.summaryHtml;
      if (listContainer && data.listHtml) listContainer.innerHTML = data.listHtml;
      if (detailContainer && data.detailHtml) detailContainer.innerHTML = data.detailHtml;
      if (vaultContainer && data.vaultHtml) vaultContainer.innerHTML = data.vaultHtml;
      if (activityContainer && data.activityHtml) activityContainer.innerHTML = data.activityHtml;
      const pagination = document.querySelector('[data-case-pagination-container]');
      if (pagination && data.paginationHtml) pagination.innerHTML = data.paginationHtml;
      bindDetailModal();
      bindCreateModal();
      bindEditModal();
      bindPersistedForms();
      bindValidationForms();
      bindVaultFolders();
      closeDetailModal();
      closeCreateModal();
      closeEditModal();
    };

    const fetchState = async (state, options = {}) => {
      if (!endpoint) return;
      const nextState = {
        q: state.q || '',
        status: state.status || 'all',
        sort: state.sort || 'updated_at',
        dir: state.dir || 'desc',
        page: Math.max(1, parseInt(state.page || 1, 10) || 1),
        record: Math.max(0, parseInt(state.record || 0, 10) || 0),
      };
      const url = buildUrl(nextState);
      setBusy(true, 'Loading case files...');
      try {
        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
          throw new Error('Request failed');
        }
        const data = await response.json();
        if (!data.ok) {
          throw new Error('Invalid response');
        }
        swapContent(data);
        const meta = data.meta || {};
        const syncedState = {
          q: meta.search || nextState.q,
          status: meta.status || nextState.status,
          sort: meta.sort || nextState.sort,
          dir: meta.dir || nextState.dir,
          page: meta.page || nextState.page,
          record: meta.selectedId || nextState.record,
        };
        updateFilterForm(syncedState);
        syncUrl(syncedState, options.push ? 'push' : 'replace');
        setBusy(false, `Showing ${meta.total || 0} case file${(meta.total || 0) === 1 ? '' : 's'}.`);
        if (options.openDetail) {
          openDetailModal();
        } else if (options.openVault) {
          scrollVaultIntoView();
        } else if (options.openCreate) {
          openCreateModal();
        }
      } catch (error) {
        setBusy(false, 'Unable to load case files right now.');
      }
    };

    if (filterForm) {
      filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        fetchState({
          ...readFilters(),
          page: 1,
          record: 0,
        }, { push: true });
      });
    }

    trackedInputs.forEach((input) => {
      if (input.name === 'q') {
        input.addEventListener('input', () => {
          window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(() => {
            fetchState({
              ...readFilters(),
              page: 1,
              record: 0,
            }, { push: false });
          }, 220);
        });
      } else {
        input.addEventListener('change', () => {
          fetchState({
            ...readFilters(),
            page: 1,
            record: 0,
          }, { push: true });
        });
      }
    });

    document.addEventListener('click', (event) => {
      const createTrigger = event.target.closest('[data-case-create-open]');
      if (createTrigger) {
        event.preventDefault();
        openCreateModal();
        return;
      }

      const editTrigger = event.target.closest('[data-case-edit-open]');
      if (editTrigger) {
        event.preventDefault();
        openEditModal(editTrigger.dataset);
        return;
      }

      const rowTrigger = event.target.closest('[data-case-select]');
      if (rowTrigger) {
        event.preventDefault();
        const recordId = parseInt(rowTrigger.dataset.caseId || '0', 10) || 0;
        if (!recordId) return;
        fetchState({
          ...readFilters(),
          page: Math.max(1, parseInt(readStateFromUrl().page || 1, 10) || 1),
          record: recordId,
        }, { push: true, openDetail: true });
        return;
      }

      const vaultTrigger = event.target.closest('[data-case-open-vault]');
      if (vaultTrigger) {
        event.preventDefault();
        const recordId = parseInt(vaultTrigger.dataset.caseId || '0', 10) || 0;
        if (!recordId) return;
        fetchState({
          ...readFilters(),
          page: Math.max(1, parseInt(readStateFromUrl().page || 1, 10) || 1),
          record: recordId,
        }, { push: true, openVault: true });
        return;
      }

      const pageTrigger = event.target.closest('[data-case-page]');
      if (pageTrigger) {
        event.preventDefault();
        const page = parseInt(pageTrigger.dataset.casePage || '1', 10) || 1;
        fetchState({
          ...readFilters(),
          page,
          record: 0,
        }, { push: true });
      }
    });

    window.addEventListener('popstate', () => {
      fetchState(readStateFromUrl(), { push: false });
    });

    const initial = window.LEX_CASE_FILES_STATE || readStateFromUrl();
    updateFilterForm(initial);
    bindDetailModal();
    bindCreateModal();
    bindEditModal();

    bindPersistedForms();
    bindValidationForms();
    bindVaultFolders();

    if (initial.failedAction === 'create' && initial.error) {
      openCreateModal();
    } else if (initial.failedAction === 'update' && initial.error) {
      openEditModal();
    }
  }

  const chatShell = document.querySelector('[data-chat-shell]');
  const openModal = (modal) => {
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    const firstField = modal.querySelector('input, select, textarea, button:not([data-modal-close])');
    if (firstField) {
      setTimeout(() => firstField.focus(), 50);
    }
  };

  const closeModal = (modal) => {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  };

  const renderAttachmentPreview = (container, files, emptyText = 'No file selected') => {
    if (!container) return;
    container.innerHTML = '';
    if (!files || !files.length) {
      container.textContent = emptyText;
      return;
    }
    files.forEach((file) => {
      const item = document.createElement('div');
      item.className = 'attachment-chip';
      item.textContent = `${file.name} - ${Math.max(1, Math.round(file.size / 1024))} KB`;
      container.appendChild(item);
    });
  };

  const resetNewMessageModal = (modal) => {
    if (!modal || modal.id !== 'newMessageModal') return;
    const fileInput = modal.querySelector('[data-modal-attachment-input]');
    const fileName = modal.querySelector('[data-modal-attachment-name]');
    const errorBox = modal.querySelector('[data-modal-errors]');
    if (fileInput) fileInput.value = '';
    renderAttachmentPreview(fileName, []);
    if (errorBox) {
      errorBox.textContent = '';
      errorBox.hidden = true;
    }
  };

  if (chatShell) {
    const scrollArea = chatShell.querySelector('[data-chat-scroll]');
    if (scrollArea) {
      scrollArea.scrollTop = scrollArea.scrollHeight;
    }

    const searchInput = chatShell.querySelector('[data-conversation-search]');
    const items = Array.from(chatShell.querySelectorAll('[data-conversation-item]'));
    if (searchInput && items.length) {
      searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim().toLowerCase();
        items.forEach((item) => {
          const text = item.textContent.toLowerCase();
          item.hidden = query !== '' && !text.includes(query);
        });
      });
    }

    const tabs = Array.from(chatShell.querySelectorAll('[data-filter-tab]'));
    const applyFilter = (filter) => {
      items.forEach((item) => {
        const unread = item.dataset.unread === '1';
        const important = item.dataset.important === '1';
        const show = filter === 'all' || (filter === 'unread' && unread) || (filter === 'important' && important);
        if (searchInput && searchInput.value.trim() !== '') {
          const query = searchInput.value.trim().toLowerCase();
          item.hidden = !show || !item.textContent.toLowerCase().includes(query);
        } else {
          item.hidden = !show;
        }
      });
    };
    if (tabs.length) {
      tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          tabs.forEach((t) => t.classList.remove('is-active'));
          tab.classList.add('is-active');
          applyFilter(tab.dataset.filterTab || 'all');
        });
      });
      applyFilter('all');
    }

    chatShell.querySelectorAll('[data-modal-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const modal = button.closest('[data-modal]');
        closeModal(modal);
        resetNewMessageModal(modal);
      });
    });

    chatShell.querySelectorAll('[data-modal]').forEach((modal) => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal(modal);
        }
      });
    });

    chatShell.addEventListener('click', (event) => {
      const openTrigger = event.target.closest('[data-modal-open]');
      if (openTrigger) {
        event.preventDefault();
        const target = document.getElementById(openTrigger.dataset.modalOpen || '');
        if (!target) return;
        if (target.id === 'conversationInfoModal') {
          const data = openTrigger.dataset;
          const setText = (selector, value) => {
            const node = target.querySelector(selector);
            if (node) node.textContent = value || '';
          };
          setText('[data-info-name]', data.infoName);
          setText('[data-info-role]', data.infoRole);
          setText('[data-info-status]', data.infoStatus);
          setText('[data-info-case]', data.infoCase);
          setText('[data-info-id]', data.infoId);
          setText('[data-info-created]', data.infoCreated);
          setText('[data-info-activity]', data.infoActivity);
          const avatar = target.querySelector('[data-info-avatar]');
          if (avatar) avatar.textContent = (data.infoName || '?').slice(0, 2).toUpperCase();
        } else if (target.id === 'newMessageModal') {
          const data = openTrigger.dataset;
          const caseInput = target.querySelector('[data-default-case-input]');
          const recipientSelect = target.querySelector('[data-recipient-select]');
          const recipientRole = target.querySelector('[data-recipient-role]');
          const fileInput = target.querySelector('[data-modal-attachment-input]');
          const fileName = target.querySelector('[data-modal-attachment-name]');
          const errorBox = target.querySelector('[data-modal-errors]');
          if (caseInput && data.defaultCase) {
            caseInput.value = data.defaultCase;
          }
          if (recipientSelect && data.defaultRecipient) {
            recipientSelect.value = data.defaultRecipient;
          }
          if (recipientRole && data.defaultRole) {
            recipientRole.value = data.defaultRole;
          }
          if (recipientSelect) {
            recipientSelect.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (fileInput) fileInput.value = '';
          renderAttachmentPreview(fileName, []);
          if (errorBox) {
            errorBox.textContent = '';
            errorBox.hidden = true;
          }
        } else if (target.id === 'profileModal') {
          const data = openTrigger.dataset;
          const setText = (selector, value) => {
            const node = target.querySelector(selector);
            if (node) node.textContent = value || '';
          };
          setText('[data-profile-name]', data.profileName);
          setText('[data-profile-role]', data.profileRole);
          setText('[data-profile-status]', data.profileStatus);
          setText('[data-profile-case]', data.profileCase);
          setText('[data-profile-email]', data.profileEmail || 'Not provided');
          setText('[data-profile-note]', data.profileNote);
          const avatar = target.querySelector('[data-profile-avatar]');
          if (avatar) avatar.textContent = (data.profileAvatar || '?').slice(0, 2).toUpperCase();
        }
        openModal(target);
        return;
      }

      const closeTrigger = event.target.closest('[data-modal-close]');
      if (closeTrigger) {
        event.preventDefault();
        const modal = closeTrigger.closest('[data-modal]');
        closeModal(modal);
        resetNewMessageModal(modal);
        return;
      }

      const overlay = event.target.closest('[data-modal]');
      if (overlay && event.target === overlay) {
        closeModal(overlay);
        resetNewMessageModal(overlay);
      }
    });
  }

  clientDeleteModal = document.querySelector('[data-client-delete-modal]');
  if (clientDeleteModal) {
    const deleteForm = clientDeleteModal.querySelector('[data-client-delete-form]');
    const deleteNameField = clientDeleteModal.querySelector('[data-client-delete-name]');
    const deleteConfirmInput = clientDeleteModal.querySelector('[data-client-delete-confirm-text]');
    const deleteSubmit = clientDeleteModal.querySelector('[data-client-delete-submit]');
    const deleteError = clientDeleteModal.querySelector('[data-client-delete-error]');
    const deleteClientId = deleteForm ? deleteForm.querySelector('input[name="client_id"]') : null;
    const deleteConfirmationField = deleteForm ? deleteForm.querySelector('[data-client-delete-confirmation]') : null;
    let expectedClientName = '';

    const clearDeleteState = () => {
      expectedClientName = '';
      if (deleteNameField) deleteNameField.value = '';
      if (deleteConfirmInput) deleteConfirmInput.value = '';
      if (deleteConfirmationField) deleteConfirmationField.value = '';
      if (deleteError) deleteError.textContent = '';
    };

    const validateDeleteText = () => {
      const entered = (deleteConfirmInput?.value || '').trim();
      const normalized = entered.replace(/\s+/g, ' ').trim();
      const requiredPhrase = `${expectedClientName} DELETE`.trim();
      const matches = expectedClientName !== '' && normalized === requiredPhrase;
      if (deleteConfirmationField) {
        deleteConfirmationField.value = normalized;
      }
      if (deleteError) {
        deleteError.textContent = entered !== '' && !matches ? 'Type the exact client name followed by DELETE.' : '';
      }
      return matches;
    };

    document.querySelectorAll('[data-client-delete-open]').forEach((button) => {
      button.addEventListener('click', () => {
        if (deleteClientId) {
          deleteClientId.value = button.dataset.clientId || '';
        }
        clearDeleteState();
        expectedClientName = button.dataset.clientName || '';
        if (deleteNameField) {
          deleteNameField.value = expectedClientName;
        }
        openModal(clientDeleteModal);
        validateDeleteText();
        if (deleteConfirmInput) {
          setTimeout(() => deleteConfirmInput.focus(), 50);
        }
      });
    });

    clientDeleteModal.querySelectorAll('[data-client-delete-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal(clientDeleteModal);
        clearDeleteState();
      });
    });

    clientDeleteModal.addEventListener('click', (event) => {
      if (event.target === clientDeleteModal) {
        closeModal(clientDeleteModal);
        clearDeleteState();
      }
    });

    if (deleteConfirmInput) {
      deleteConfirmInput.addEventListener('input', validateDeleteText);
      deleteConfirmInput.addEventListener('keyup', validateDeleteText);
      deleteConfirmInput.addEventListener('change', validateDeleteText);
    }

    if (deleteForm) {
      deleteForm.addEventListener('submit', (event) => {
        if (!validateDeleteText()) {
          event.preventDefault();
          if (deleteError && (deleteConfirmInput?.value || '').trim() === '') {
            deleteError.textContent = 'Type the client name followed by DELETE to confirm deletion.';
          }
        }
      });
    }

    if (deleteSubmit && deleteForm) {
      deleteSubmit.addEventListener('click', (event) => {
        if (!validateDeleteText()) {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        deleteForm.submit();
      });
    }
  }

  if (chatShell) {
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      chatShell.querySelectorAll('[data-modal].is-open').forEach((modal) => {
        closeModal(modal);
        resetNewMessageModal(modal);
      });
      if (clientDeleteModal && clientDeleteModal.classList.contains('is-open')) {
        closeModal(clientDeleteModal);
      }
    }
  });

  const attachmentInput = chatShell.querySelector('[data-attachment-input]');
  const attachmentPreview = chatShell.querySelector('[data-attachment-preview]');
  if (attachmentInput && attachmentPreview) {
    attachmentInput.addEventListener('change', () => {
      attachmentPreview.innerHTML = '';
      const files = Array.from(attachmentInput.files || []);
      if (!files.length) {
        attachmentPreview.hidden = true;
        return;
      }
      files.forEach((file) => {
        const item = document.createElement('div');
        item.className = 'attachment-chip';
        item.textContent = `${file.name} • ${Math.max(1, Math.round(file.size / 1024))} KB`;
        attachmentPreview.appendChild(item);
      });
      attachmentPreview.hidden = false;
    });
  }

  const newMessageModal = chatShell.querySelector('#newMessageModal');
  const newMessageForm = chatShell.querySelector('[data-new-message-form]');
  if (newMessageModal && newMessageForm) {
    const caseInput = newMessageModal.querySelector('[data-default-case-input]');
    const recipientSelect = newMessageModal.querySelector('[data-recipient-select]');
    const messageInput = newMessageModal.querySelector('[data-message-input]');
    const errorBox = newMessageModal.querySelector('[data-modal-errors]');
    const recipientRole = newMessageModal.querySelector('[name="new_recipient_role"]');
    const attachmentButton = newMessageModal.querySelector('[data-modal-attachment-button]');
    const attachmentInputNew = newMessageModal.querySelector('[data-modal-attachment-input]');
    const attachmentName = newMessageModal.querySelector('[data-modal-attachment-name]');

    newMessageModal.querySelectorAll('[data-modal-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal(newMessageModal);
        resetNewMessageModal(newMessageModal);
      });
    });

    if (attachmentButton && attachmentInputNew && attachmentButton.tagName !== 'LABEL') {
      attachmentButton.addEventListener('click', () => attachmentInputNew.click());
    }
    if (attachmentInputNew) {
      attachmentInputNew.addEventListener('change', () => {
        renderAttachmentPreview(attachmentName, Array.from(attachmentInputNew.files || []));
      });
    }

    if (recipientSelect && recipientRole) {
      const syncRecipientRole = () => {
        const selected = recipientSelect.selectedOptions[0];
        recipientRole.value = selected?.dataset.role || recipientRole.value || '';
      };
      recipientSelect.addEventListener('change', syncRecipientRole);
      syncRecipientRole();
    }

    if (recipientSelect && caseInput) {
      const syncCaseToRecipient = () => {
        const selected = recipientSelect.selectedOptions[0];
        const linkedCaseId = selected?.dataset.caseId || '';
        caseInput.value = linkedCaseId;
      };
      recipientSelect.addEventListener('change', syncCaseToRecipient);
      syncCaseToRecipient();
    }

    newMessageForm.addEventListener('submit', (event) => {
      const errors = [];
      const recipient = recipientSelect?.value || '';
      const message = messageInput?.value.trim() || '';
      const hasAttachment = Boolean(attachmentInputNew?.files && attachmentInputNew.files.length > 0);
      if (!recipient) errors.push('Select a recipient first.');
      if (!message && !hasAttachment) errors.push('Message or attachment is required.');
      if (errorBox) {
        errorBox.textContent = errors.join(' ');
        errorBox.hidden = errors.length === 0;
      }
      if (errors.length) {
        event.preventDefault();
      }
    });
  }
  }
})();


