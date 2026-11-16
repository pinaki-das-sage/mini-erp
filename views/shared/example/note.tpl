<p>Signed in as {$actor.username}. This is infrastructure practice, not ERP logic.</p>
<p>A save checks permission/version, writes the note and audit, and stores its replay result in one transaction.</p>
<form id="note-form" data-version="{$data.note.current_version}">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>Training note <textarea name="text" maxlength="500" required>{$data.note.text}</textarea></label>
  <button type="submit">Save note</button>
</form>
<p id="note-status" role="status" aria-live="polite">Version {$data.note.current_version}</p>
