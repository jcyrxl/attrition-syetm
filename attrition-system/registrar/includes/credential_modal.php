<?php
// Small reusable PIN / password prompt used for one-click actions (Delete, Reset).
$credType  = actionCredentialType();
$credLabel = actionCredentialLabel();
?>
<div class="modal-overlay" id="credModal" data-type="<?= $credType ?>">
    <div class="modal-box" style="width:430px;">
        <h2 id="credTitle">Please confirm</h2>
        <p id="credMessage" style="font-size:14px;color:#64727a;margin-bottom:16px;"></p>
        <div class="form-group">
            <label><i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($credLabel) ?></label>
            <input type="password" id="credInput" autocomplete="off" class="cred-input">
            <div class="inline-error" id="credError"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="closeCredModal()">Cancel</button>
            <button type="button" class="btn-primary" id="credConfirmBtn">Confirm</button>
        </div>
    </div>
</div>
<script src="../assets/credential-check.js?v=1"></script>
