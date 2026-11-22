<?php
// Studio — "kit" di componenti condivisi dalle pagine di gestione (popup di inserimento/modifica,
// interruttori, selettori). Solo markup: stili in assets/studio.css (.kit-*), comportamenti in assets/studio-kit.js.

// Apre un popup con modulo. Opzioni: new_title, edit_title, action_new, action_edit, multipart (default true).
// Il pulsante che lo apre ha data-dlg="ID" e, per la modifica, data-fill='{"campo":"valore"}'.
function studioDlgOpen(string $id, array $o = []): void {
    $enc = ($o['multipart'] ?? true) ? ' enctype="multipart/form-data"' : '';
    echo '<dialog class="cmp kit-dlg" id="' . e($id) . '" aria-labelledby="' . e($id) . '-t"'
        . ' data-title-new="' . e($o['new_title'] ?? 'Nuovo') . '" data-title-edit="' . e($o['edit_title'] ?? 'Modifica') . '"'
        . ' data-action-new="' . e($o['action_new'] ?? 'add') . '" data-action-edit="' . e($o['action_edit'] ?? 'edit') . '">'
        . '<form method="post"' . $enc . ' class="cmp-form">' . csrfField()
        . '<input type="hidden" name="action" value="' . e($o['action_new'] ?? 'add') . '">'
        . '<input type="hidden" name="id" value="">'
        . '<input type="hidden" name="tz_offset_minutes" value="">'
        . '<section class="cmp-panel"><header class="cmp-head"><h2 id="' . e($id) . '-t">' . e($o['new_title'] ?? 'Nuovo') . '</h2>'
        . '<button type="button" class="cmp-x" data-kit-close aria-label="Chiudi"><i class="fa-solid fa-xmark"></i></button></header>'
        . '<div class="cmp-body">';
}
function studioDlgClose(string $submit = 'Salva'): void {
    echo '</div><footer class="cmp-foot"><button type="submit" class="tlx-submit"><i class="fa-solid fa-check"></i> <span>' . e($submit) . '</span></button></footer></section></form></dialog>';
}

// Interruttore (checkbox stilizzata).
function studioSwitch(string $name, string $label, bool $checked = false, string $value = '1'): void {
    echo '<label class="tlx-switch"><input type="checkbox" name="' . e($name) . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . '><i></i><span>' . e($label) . '</span></label>';
}
// Scelta a segmenti (radio): $opts = [valore => etichetta HTML già sicura].
function studioSeg(string $name, array $opts, string $checked): void {
    echo '<div class="tlx-seg" role="radiogroup">';
    foreach ($opts as $v => $label) {
        echo '<label><input type="radio" name="' . e($name) . '" value="' . e((string) $v) . '"' . ((string) $v === $checked ? ' checked' : '') . '><span>' . $label . '</span></label>';
    }
    echo '</div>';
}
// Blocco "scrivi con l'AI" per un campo di testo: collega il pulsante alla textarea con data-ai-target.
function studioAiBox(string $placeholder = 'es. parole chiave o istruzioni'): void {
    echo '<div class="kit-ai"><button type="button" class="tlx-chip ai-toggle"><i class="fa-solid fa-wand-magic-sparkles"></i> Scrivi con l\'AI</button>'
        . '<div class="tlx-ai ai-panel" hidden><label>Parole chiave o istruzioni per l\'AI</label>'
        . '<textarea class="ai-keywords" rows="2" placeholder="' . e($placeholder) . '"></textarea>'
        . '<div class="tlx-row"><button type="button" class="btn small ai-generate">Genera testo</button><button type="button" class="btn small secondary ai-cancel">Annulla</button></div>'
        . '<p class="tlx-hint ai-status"></p></div></div>';
}
