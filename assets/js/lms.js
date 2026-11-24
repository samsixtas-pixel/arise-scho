/* ============================================================
   lms.js — lending-specific interactions
   camera capture · live loan maths · confirm dialogs · lookups
   ============================================================ */
(function () {
  'use strict';

  /* ----------------------------------------------------------
     1. Confirmation for destructive actions
     Usage: <form data-confirm="Reverse this payment?">
     ---------------------------------------------------------- */
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();

      Swal.fire({
        icon: 'warning',
        title: form.dataset.confirmTitle || 'Please confirm',
        text: form.dataset.confirm,
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmButton || 'Yes, continue',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#B91C1C',
        cancelButtonColor: '#5C7068'
      }).then(function (result) {
        if (result.isConfirmed) {
          form.removeAttribute('data-confirm');
          form.submit();
        }
      });
    });
  });

  /* ----------------------------------------------------------
     2. Loan calculator: principal / weeks / weekly → total
     ---------------------------------------------------------- */
  const weeklyInput = document.getElementById('weekly_amount');
  const weeksInput  = document.getElementById('number_of_weeks');
  const totalOut    = document.getElementById('loan_total_out');
  const shortfall   = document.getElementById('loan_shortfall');

  function updateLoanTotal() {
    if (!weeklyInput || !weeksInput || !totalOut) return;

    const weekly = parseFloat(weeklyInput.value) || 0;
    const weeks  = parseInt(weeksInput.value, 10) || 0;
    const total  = weekly * weeks;

    totalOut.textContent = 'TZS ' + total.toLocaleString('en-US', { maximumFractionDigits: 0 });

    const principal = parseFloat((document.getElementById('principal') || {}).value) || 0;

    if (shortfall) {
      if (principal > 0 && total > 0) {
        const diff = total - principal;
        const pct  = principal > 0 ? (diff / principal) * 100 : 0;

        shortfall.textContent = (diff >= 0 ? 'Service charge: ' : 'Shortfall: ')
          + 'TZS ' + Math.abs(diff).toLocaleString('en-US', { maximumFractionDigits: 0 })
          + ' (' + Math.abs(pct).toFixed(1) + '%)';
        shortfall.className = 'hint-inline ' + (diff >= 0 ? 'text-success' : 'text-danger');
      } else {
        shortfall.textContent = '';
      }
    }

    // Suggested first payment date = loan date + 7
    const loanDate = document.getElementById('loan_date');
    const firstOut = document.getElementById('first_payment_out');
    if (loanDate && firstOut && loanDate.value) {
      const d = new Date(loanDate.value + 'T00:00:00');
      d.setDate(d.getDate() + 7);
      firstOut.textContent = d.toLocaleDateString('en-GB');
    }
  }

  [weeklyInput, weeksInput, document.getElementById('principal'), document.getElementById('loan_date')]
    .forEach(function (el) { if (el) { el.addEventListener('input', updateLoanTotal); el.addEventListener('change', updateLoanTotal); } });

  updateLoanTotal();

  /* ----------------------------------------------------------
     3. Payment page: show balance for the chosen loan
     ---------------------------------------------------------- */
  const loanSelect = document.getElementById('loan_id');
  const balanceBox = document.getElementById('loan_balance_box');

  function updateBalance() {
    if (!loanSelect || !balanceBox) return;

    const option = loanSelect.options[loanSelect.selectedIndex];

    if (!option || !option.value) {
      balanceBox.innerHTML = '';
      return;
    }

    const balance  = parseFloat(option.dataset.balance || '0');
    const weekly   = parseFloat(option.dataset.weekly || '0');
    const nextDue  = option.dataset.next || '—';
    const customer = option.dataset.customer || '';
    const status   = option.dataset.status || '';

    balanceBox.innerHTML =
      '<div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">' +
        '<div><span class="text-muted small">Customer</span><div class="fw-semibold">' + customer + '</div></div>' +
        '<div class="text-end"><span class="text-muted small">Outstanding</span>' +
          '<div class="fw-bold text-tabular">TZS ' + balance.toLocaleString('en-US', { maximumFractionDigits: 0 }) + '</div></div>' +
        '<div class="text-end"><span class="text-muted small">Weekly</span>' +
          '<div class="fw-semibold text-tabular">TZS ' + weekly.toLocaleString('en-US', { maximumFractionDigits: 0 }) + '</div></div>' +
        '<div class="text-end"><span class="text-muted small">Next due</span>' +
          '<div class="fw-semibold">' + nextDue + '</div></div>' +
        '<div class="text-end"><span class="text-muted small">Status</span><div>' + status + '</div></div>' +
      '</div>';

    const amount = document.getElementById('amount');
    if (amount && !amount.value && weekly > 0) {
      amount.value = weekly;
    }
  }

  if (loanSelect) {
    loanSelect.addEventListener('change', updateBalance);
    updateBalance();
  }

  /* ----------------------------------------------------------
     4. Customer photograph capture now lives in
        assets/js/identity-capture.js.
     ---------------------------------------------------------- */

  /* ----------------------------------------------------------
     5. Server-side search for customer pickers
     ---------------------------------------------------------- */
  const searchInput = document.getElementById('customerSearch');
  const searchList  = document.getElementById('customerResults');
  const customerId  = document.getElementById('customer_id');
  const searchTimer = { id: null };

  if (searchInput && searchList && customerId) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer.id);
      const q = searchInput.value.trim();

      if (q.length < 2) { searchList.innerHTML = ''; return; }

      searchTimer.id = setTimeout(function () {
        fetch(BASE_URL + 'api/customer_search.php?q=' + encodeURIComponent(q), {
          headers: { 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            searchList.innerHTML = '';

            (data.customers || []).forEach(function (c) {
              const item = document.createElement('button');
              item.type = 'button';
              item.className = 'typeahead-item';
              item.innerHTML = '<strong>' + c.full_name + '</strong>'
                + '<span class="sub">' + c.reference + ' · ' + (c.phone || 'no phone') + '</span>';

              item.addEventListener('click', function () {
                customerId.value   = c.id;
                searchInput.value  = c.full_name;
                searchList.innerHTML = '';
              });

              searchList.appendChild(item);
            });

            if (!data.customers || data.customers.length === 0) {
              const empty = document.createElement('div');
              empty.className = 'typeahead-empty';
              empty.textContent = 'No customer found. Register them first.';
              searchList.appendChild(empty);
            }
          })
          .catch(function () {});
      }, 220);
    });

    document.addEventListener('click', function (event) {
      if (searchList && !searchList.contains(event.target) && event.target !== searchInput) {
        searchList.innerHTML = '';
      }
    });
  }

  /* ----------------------------------------------------------
     6. Scheduled instalments toggle
     ---------------------------------------------------------- */
  const scheduleToggle = document.getElementById('showSchedule');
  const scheduleTable  = document.getElementById('scheduleTable');

  if (scheduleToggle && scheduleTable) {
    scheduleToggle.addEventListener('change', function () {
      scheduleTable.classList.toggle('d-none', !scheduleToggle.checked);
    });
  }
})();
