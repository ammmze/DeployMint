<?php
/*
    Author: Mark Maunder <mmaunder@gmail.com>
    Author website: http://markmaunder.com/
    License: GPL 3.0
*/
?>

<script>
    (function($){
        $(document).ready(function(){
            $("#sdBackupDisabled").bind('change.backupDisabled', function(){
                var checked = $(this).is(':checked');
                $('.backup-enabled').toggle(!checked).trigger('change');
            }).trigger('change');

            $("#sdNumBackups").bind('change.numBackups', function(){
                if (this.value < 0) {
                    this.value = 0;
                }
            }).trigger('change');

            $("#sdNumBackups").bind('keyup.numBackups', function () {
                var $el = $(this);
                setTimeout(function(){
                    $el.trigger('change');
                }, 100);
            });
        })
    })(jQuery);
</script>

<div id="sdAjaxLoading" style="display: none; position: fixed; right: 1px; top: 1px; width: 100px; background-color: #F00; color: #FFF; font-size: 12px; font-family: Verdana, arial; font-weight: normal; text-align: center; z-index: 100; border: 1px solid #CCC;">Loading...</div>
<div class="wrap">
    <h2 class="depmintHead">DeployMint Options</h2>
    <div id="sdOptErrors">
    </div>
    <table class="form-table">
    <tr>
        <th>Path to git:</th>
        <td><input type="text" id="sdPathToGit" size="40" maxlength="255" value="<?php echo htmlspecialchars($opt['git']) ?>" /></td>
    </tr>
    <tr>
        <th>Path to mysql:</th>
        <td><input type="text" id="sdPathToMysql" size="40" maxlength="255" value="<?php echo htmlspecialchars($opt['mysql']) ?>" /></td>
    </tr>
    <tr>
        <th>Path to mysqldump:</th>
        <td><input type="text" id="sdPathToMysqldump" size="40" maxlength="255" value="<?php echo htmlspecialchars($opt['mysqldump']) ?>" /></td>
    </tr>
    <tr>
        <th>Path to rsync:</th>
        <td><input type="text" id="sdPathToRsync" size="40" maxlength="255" value="<?php echo htmlspecialchars($opt['rsync']) ?>" /></td>
    </tr>    
    <tr>
        <th style="white-space: nowrap;">Path to a data directory for DeployMint:</th>
        <td><input type="text" id="sdPathToDataDir" size="40" maxlength="255" value="<?php echo htmlspecialchars($opt['datadir']) ?>" /></td>
    </tr>
    <tr>
        <th>Preserve blog name:</th>
        <td><input type="checkbox" id="sdPreserveBlogName" value="1" <?php echo ($opt['preserveBlogName']) ? 'checked=checked' : ''?> /></td>
    </tr>
    <tr class="note">
        <th colspan="2" style="padding-left: 20px;">
            Checking this box will allow the blog name / site title to be preserved. So deploying a snapshot will not overwrite the destinations blog name.
        </th>
    </tr>
    <tr>
        <th>Tempory database:</th>
        <td><input type="text" id="sdTemporaryDatabase" size="40" maxlength="255" value="<?php echo htmlspecialchars($opt['temporaryDatabase']) ?>" /></td>
    </tr>
    <tr>
        <th>Backup disabled:</th>
        <td><input type="checkbox" id="sdBackupDisabled" <?php if ($opt['backupDisabled'] != ''){echo 'checked="checked"';} ?> /></td>
    </tr>
    <tr class="backup-enabled backup-name">
        <th style="padding-left: 20px;">Backup database:</th>
        <td><input type="text" id="sdBackupDatabase" size="40" maxlength="255" value="<?php echo htmlspecialchars($opt['backupDatabase']) ?>" /></td>
    </tr>
    <tr class="backup-enabled note">
        <th colspan="2" style="padding-left: 30px;">If we are only using 1 backup, then we will use this as the name of backup database. If the Number of backups is set to anything else, we will create a database using this value as the database name prefix.</th>
    </tr>
    <tr class="backup-enabled backup-count">
        <th style="padding-left: 20px;">Number of backups:</th>
        <td><input type="text" id="sdNumBackups" size="3" maxlength="255" value="<?php echo htmlspecialchars($opt['numBackups']) ?>" /></td>
    </tr>
    <tr class="backup-enabled note">
        <th colspan="2" style="padding-left: 30px;">This is the number of backups we will keep after each deploy. Once we have saved this number of backups, we will remove the oldest one after each deploy.</th>
    </tr>
    </table>
    <p class="submit">
        <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" onclick="deploymint.updateOptions({git: jQuery('#sdPathToGit').val(), mysql: jQuery('#sdPathToMysql').val(), mysqldump: jQuery('#sdPathToMysqldump').val(), rsync: jQuery('#sdPathToRsync').val(), datadir: jQuery('#sdPathToDataDir').val(), numBackups: jQuery('#sdNumBackups').val(), temporaryDatabase: jQuery('#sdTemporaryDatabase').val(), backupDatabase: jQuery('#sdBackupDatabase').val(), backupDisabled: jQuery('#sdBackupDisabled').attr('checked'),preserveBlogName: (jQuery('#sdPreserveBlogName').is(':checked') ? 1 : 0)}); return false;" />
    </p>
</div>
