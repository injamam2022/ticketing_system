'use strict';
const search = document.querySelector('#ticket-search');
const statusFilter = document.querySelector('#ticket-status');
function filterTickets() {
  let visible = 0;
  document.querySelectorAll('.portal-ticket-row').forEach(row => {
    row.hidden = !row.textContent.toLowerCase().includes(search.value.toLowerCase()) || (statusFilter.value !== '' && row.dataset.ticketStatus !== statusFilter.value);
    if (!row.hidden) visible++;
  });
  document.querySelector('#ticket-empty').hidden = visible > 0;
}
search?.addEventListener('input', filterTickets);
statusFilter?.addEventListener('change', filterTickets);

const instituteSelect = document.querySelector('#ticket-institute');
const projectSelect = document.querySelector('#ticket-project');
if (instituteSelect && projectSelect) {
  const applyInstitute = () => {
    const institute = instituteSelect.value;
    let selectedStillVisible = projectSelect.value === '';
    [...projectSelect.options].forEach(option => {
      if (!option.value) return;
      const visible = institute ? option.dataset.institute === institute : option.dataset.institute === '';
      option.hidden = !visible;
      option.disabled = !visible;
      if (option.value === projectSelect.value && visible) selectedStillVisible = true;
    });
    if (!selectedStillVisible) projectSelect.value = '';
  };
  instituteSelect.addEventListener('change', applyInstitute);
  applyInstitute();
}

const imageInput = document.querySelector('#ticket-images');
if (imageInput) {
  const dropzone = document.querySelector('#ticket-dropzone');
  const previews = document.querySelector('#ticket-image-previews');
  const error = document.querySelector('#ticket-image-error');
  const form = document.querySelector('#ticket-submit-form');
  const allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  let selected = [];
  let previewURLs = [];
  const showError = message => { error.textContent = message; error.hidden = !message; };
  const validate = files => {
    if (files.length > 5) return 'You can attach up to 5 images. Remove an image before adding another.';
    if (files.some(file => !allowed.includes(file.type) && !(file.type === '' && /\.(png|jpe?g|gif|webp)$/i.test(file.name)))) return 'Choose PNG, JPG, GIF, or WebP images.';
    if (files.some(file => file.size === 0 || file.size > 2 * 1024 * 1024)) return 'Each image must be a valid file up to 2 MB.';
    if (files.reduce((sum, file) => sum + file.size, 0) > 6 * 1024 * 1024) return 'Keep the total image size up to 6 MB.';
    return '';
  };
  function renderImages() {
    const transfer = new DataTransfer(); selected.forEach(file => transfer.items.add(file)); imageInput.files = transfer.files;
    previewURLs.forEach(url => URL.revokeObjectURL(url)); previewURLs = [];
    previews.replaceChildren();
    selected.forEach((file, index) => {
      const card = document.createElement('div'); card.className = 'ticket-upload-preview';
      const img = document.createElement('img'); img.src = URL.createObjectURL(file); img.alt = file.name; previewURLs.push(img.src);
      const name = document.createElement('span'); name.textContent = file.name; name.title = file.name;
      const size = document.createElement('small'); size.textContent = `${Math.ceil(file.size / 1024)} KB`;
      const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'remove-upload'; remove.textContent = '×'; remove.setAttribute('aria-label', `Remove ${file.name}`);
      remove.addEventListener('click', () => { selected.splice(index, 1); showError(''); renderImages(); (document.querySelector('.remove-upload') || imageInput).focus(); });
      card.append(img, name, size, remove); previews.append(card);
    });
    dropzone.classList.toggle('has-images', selected.length > 0);
  }
  function addImages(files) {
    const combined = [...selected];
    for (const file of files) if (!combined.some(old => old.name === file.name && old.size === file.size && old.lastModified === file.lastModified)) combined.push(file);
    const message = validate(combined);
    showError(message);
    if (!message) selected = combined;
    renderImages();
  }
  imageInput.addEventListener('change', () => addImages([...imageInput.files]));
  for (const type of ['dragenter', 'dragover']) dropzone.addEventListener(type, event => { event.preventDefault(); dropzone.classList.add('drag-over'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag-over'));
  dropzone.addEventListener('drop', event => { event.preventDefault(); dropzone.classList.remove('drag-over'); addImages([...event.dataTransfer.files]); });
  form.addEventListener('submit', event => {
    const message = validate(selected);
    if (message) { event.preventDefault(); showError(message); imageInput.focus(); }
  });
  window.addEventListener('pagehide', () => previewURLs.forEach(url => URL.revokeObjectURL(url)));
}
document.querySelectorAll('.portal-form').forEach(form => {
  form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    const button = form.querySelector('button[type="submit"],button:not([type])');
    if (button) { button.disabled = true; button.textContent = 'Saving…'; }
  });
});
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
