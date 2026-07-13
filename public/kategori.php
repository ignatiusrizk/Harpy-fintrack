<?php
// Halaman Kategori: 2 seksi (Pengeluaran/Pemasukan), tree 1 level (parent ->
// anak), sheet tambah/edit (nama/jenis/ikon/warna/parent), hapus via
// lmConfirm. Diakses dari menu Lainnya -- $active tetap 'lainnya'.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/kategori.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();
$spaceId = currentSpaceId();
$tree = listCategoriesTree($spaceId);

$sections = [
    'expense' => 'Pengeluaran',
    'income' => 'Pemasukan',
];

$swatches = [
    '#F97316', '#3B82F6', '#EC4899', '#EAB308', '#EF4444', '#8B5CF6',
    '#F43F5E', '#14B8A6', '#0EA5E9', '#94A3B8', '#22C55E', '#84CC16',
];

pageHeader('Kategori', $user);
?>
<section id="catRoot">
<?php foreach ($sections as $type => $label): ?>
  <section class="cat-section">
    <h2 class="cat-section-title"><?= e($label) ?></h2>
    <div class="cat-list" id="catList-<?= e($type) ?>" data-type="<?= e($type) ?>">
<?php if (count($tree[$type]) === 0): ?>
      <p class="cat-empty">Belum ada kategori <?= e(mb_strtolower($label)) ?>.</p>
<?php endif; ?>
<?php foreach ($tree[$type] as $root): ?>
      <div class="cat-row" data-id="<?= (int) $root['id'] ?>" data-name="<?= e($root['name']) ?>" data-type="<?= e($root['type']) ?>" data-icon="<?= e($root['icon']) ?>" data-color="<?= e($root['color']) ?>" data-parent-id="" data-has-children="<?= count($root['children']) > 0 ? '1' : '0' ?>">
        <span class="cat-icon" style="background:<?= e($root['color']) ?>22"><?= e($root['icon']) ?></span>
        <span class="cat-name"><?= e($root['name']) ?></span>
      </div>
<?php foreach ($root['children'] as $child): ?>
      <div class="cat-row cat-row-child" data-id="<?= (int) $child['id'] ?>" data-name="<?= e($child['name']) ?>" data-type="<?= e($child['type']) ?>" data-icon="<?= e($child['icon']) ?>" data-color="<?= e($child['color']) ?>" data-parent-id="<?= (int) $root['id'] ?>" data-has-children="0">
        <span class="cat-icon cat-icon-sm"><?= e($child['icon']) ?></span>
        <span class="cat-name"><?= e($child['name']) ?></span>
      </div>
<?php endforeach; ?>
<?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</section>

<button type="button" class="fab" id="fabAdd" aria-label="Tambah kategori">+</button>

<div class="sheet-overlay" id="catSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="catSheetTitle">Tambah Kategori</h2>
    <form id="catForm">
      <input type="hidden" id="catId" value="">
      <label class="field">
        <span>Nama</span>
        <input type="text" id="catName" maxlength="100" placeholder="mis. Kado, Parkir" required>
      </label>
      <label class="field">
        <span>Jenis</span>
        <select id="catType">
          <option value="expense">Pengeluaran</option>
          <option value="income">Pemasukan</option>
        </select>
      </label>
      <label class="field">
        <span>Ikon (emoji)</span>
        <input type="text" id="catIcon" maxlength="8" placeholder="🏷️">
      </label>
      <label class="field">
        <span>Warna</span>
        <div class="swatch-grid" id="catSwatches">
<?php foreach ($swatches as $sw): ?>
          <button type="button" class="swatch" data-color="<?= e($sw) ?>" style="background:<?= e($sw) ?>" aria-label="Warna <?= e($sw) ?>"></button>
<?php endforeach; ?>
        </div>
        <input type="hidden" id="catColor" value="<?= e($swatches[0]) ?>">
      </label>
      <label class="field" id="catParentField">
        <span>Sub-kategori dari</span>
        <select id="catParent">
          <option value="">(Tidak ada — kategori utama)</option>
        </select>
      </label>
      <p class="sheet-msg" id="catSheetMsg"></p>
      <div class="sheet-actions">
        <button type="button" class="btn-danger-link" id="catDelete" hidden>Hapus</button>
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="catCancel">Batal</button>
        <button type="submit" class="btn-primary" id="catSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var rootEl = document.getElementById('catRoot');
  var overlay = document.getElementById('catSheetOverlay');
  var form = document.getElementById('catForm');
  var sheetTitle = document.getElementById('catSheetTitle');
  var msgEl = document.getElementById('catSheetMsg');
  var idInput = document.getElementById('catId');
  var nameInput = document.getElementById('catName');
  var typeInput = document.getElementById('catType');
  var iconInput = document.getElementById('catIcon');
  var colorInput = document.getElementById('catColor');
  var parentField = document.getElementById('catParentField');
  var parentSelect = document.getElementById('catParent');
  var submitBtn = document.getElementById('catSubmit');
  var deleteBtn = document.getElementById('catDelete');
  var swatchGrid = document.getElementById('catSwatches');

  var sectionLabels = { expense: 'Pengeluaran', income: 'Pemasukan' };

  // ---------- swatch picker ----------

  function setActiveSwatch(color) {
    colorInput.value = color;
    Array.prototype.forEach.call(swatchGrid.children, function (btn) {
      btn.classList.toggle('active', btn.dataset.color === color);
    });
  }

  swatchGrid.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.swatch');
    if (!btn) return;
    setActiveSwatch(btn.dataset.color);
  });

  // ---------- parent select (dibangun dari DOM row root yg sedang tampil) ----------

  function buildParentOptions(type, excludeId) {
    parentSelect.innerHTML = '';
    var def = document.createElement('option');
    def.value = '';
    def.textContent = '(Tidak ada — kategori utama)';
    parentSelect.appendChild(def);

    var list = document.getElementById('catList-' + type);
    if (!list) return;
    var rows = list.querySelectorAll('.cat-row:not(.cat-row-child)');
    rows.forEach(function (row) {
      if (excludeId && row.dataset.id === String(excludeId)) return;
      var opt = document.createElement('option');
      opt.value = row.dataset.id;
      opt.textContent = row.dataset.name;
      parentSelect.appendChild(opt);
    });
  }

  // ---------- sheet form (tambah/edit) ----------

  function openSheet(mode, cat) {
    msgEl.textContent = '';
    form.reset();
    deleteBtn.hidden = true;
    parentField.hidden = false;
    parentSelect.disabled = false;

    if (mode === 'edit') {
      sheetTitle.textContent = 'Edit Kategori';
      idInput.value = cat.id;
      nameInput.value = cat.name;
      typeInput.value = cat.type;
      iconInput.value = cat.icon;
      setActiveSwatch(cat.color);
      buildParentOptions(cat.type, cat.id);
      parentSelect.value = cat.parentId || '';
      if (cat.hasChildren === '1') {
        // Kategori ini sendiri punya sub-kategori -> tidak bisa dijadikan
        // sub-kategori kategori lain (maks 1 level).
        parentField.hidden = true;
      }
      deleteBtn.hidden = false;
    } else {
      sheetTitle.textContent = 'Tambah Kategori';
      idInput.value = '';
      typeInput.value = (cat && cat.defaultType) || 'expense';
      iconInput.value = '';
      setActiveSwatch(document.querySelector('.swatch').dataset.color);
      buildParentOptions(typeInput.value, null);
      parentSelect.value = '';
    }

    overlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { overlay.classList.add('show'); });
    });
    setTimeout(function () { nameInput.focus(); }, 200);
  }

  function closeSheet() {
    overlay.classList.remove('show');
    setTimeout(function () { overlay.hidden = true; }, 180);
  }

  document.getElementById('fabAdd').addEventListener('click', function () {
    openSheet('add', null);
  });

  document.getElementById('catCancel').addEventListener('click', closeSheet);

  overlay.addEventListener('click', function (ev) {
    if (ev.target === overlay) closeSheet();
  });

  typeInput.addEventListener('change', function () {
    if (parentField.hidden) return; // sedang edit kategori yg punya anak -- parent terkunci
    buildParentOptions(typeInput.value, idInput.value || null);
    parentSelect.value = '';
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    msgEl.textContent = '';
    submitBtn.disabled = true;

    var payload = {
      name: nameInput.value.trim(),
      type: typeInput.value,
      icon: iconInput.value.trim(),
      color: colorInput.value,
      parent_id: parentField.hidden ? null : (parentSelect.value || null),
    };
    var action;
    if (idInput.value) {
      action = 'update';
      payload.id = idInput.value;
    } else {
      action = 'create';
    }

    try {
      await api('api/kategori.php?a=' + action, payload);
      closeSheet();
      toast('Kategori tersimpan');
      refreshList();
    } catch (err) {
      msgEl.textContent = err.message;
    } finally {
      submitBtn.disabled = false;
    }
  });

  deleteBtn.addEventListener('click', async function () {
    var id = idInput.value;
    var name = nameInput.value;
    if (!id) return;
    var ok = await lmConfirm('Hapus kategori "' + name + '"? Tindakan ini tidak bisa dibatalkan.', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/kategori.php?a=delete', { id: id });
      closeSheet();
      toast('Kategori dihapus');
      refreshList();
    } catch (err) {
      msgEl.textContent = err.message;
    }
  });

  // ---------- tap baris -> buka edit ----------

  rootEl.addEventListener('click', function (ev) {
    var row = ev.target.closest('.cat-row');
    if (!row) return;
    openSheet('edit', {
      id: row.dataset.id,
      name: row.dataset.name,
      type: row.dataset.type,
      icon: row.dataset.icon,
      color: row.dataset.color,
      parentId: row.dataset.parentId,
      hasChildren: row.dataset.hasChildren,
    });
  });

  // ---------- render ulang (dipakai setelah create/update/delete) ----------

  function categoryRowEl(cat, isChild, parentId) {
    var row = document.createElement('div');
    row.className = 'cat-row' + (isChild ? ' cat-row-child' : '');
    row.dataset.id = cat.id;
    row.dataset.name = cat.name;
    row.dataset.type = cat.type;
    row.dataset.icon = cat.icon;
    row.dataset.color = cat.color;
    row.dataset.parentId = isChild ? parentId : '';
    row.dataset.hasChildren = (!isChild && cat.children && cat.children.length > 0) ? '1' : '0';

    var icon = document.createElement('span');
    icon.className = 'cat-icon' + (isChild ? ' cat-icon-sm' : '');
    if (!isChild) icon.style.background = cat.color + '22';
    icon.textContent = cat.icon;

    var name = document.createElement('span');
    name.className = 'cat-name';
    name.textContent = cat.name;

    row.appendChild(icon);
    row.appendChild(name);
    return row;
  }

  function renderSection(type, roots) {
    var list = document.getElementById('catList-' + type);
    list.innerHTML = '';
    if (!roots || roots.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'cat-empty';
      empty.textContent = 'Belum ada kategori ' + sectionLabels[type].toLowerCase() + '.';
      list.appendChild(empty);
      return;
    }
    roots.forEach(function (root) {
      list.appendChild(categoryRowEl(root, false, ''));
      (root.children || []).forEach(function (child) {
        list.appendChild(categoryRowEl(child, true, root.id));
      });
    });
  }

  function refreshList() {
    return api('api/kategori.php?a=list', {}).then(function (json) {
      var tree = json.categories || { expense: [], income: [] };
      renderSection('expense', tree.expense);
      renderSection('income', tree.income);
    }).catch(function (err) {
      toast(err.message);
    });
  }
})();
</script>
<?php
pageFooter('lainnya');
