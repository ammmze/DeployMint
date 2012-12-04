<?php
/*
    Author: Mark Maunder <mmaunder@gmail.com>
    Author website: http://markmaunder.com/
    License: GPL 3.0
*/
include dirname(__FILE__) . '/widgets.php';
?>

<div id="sdAjaxLoading" style="display: none; position: fixed; right: 1px; top: 1px; width: 100px; background-color: #F00; color: #FFF; font-size: 12px; font-family: Verdana, arial; font-weight: normal; text-align: center; z-index: 100; border: 1px solid #CCC;">Loading...</div>
<div class="wrap">
<h2 class="depmintHead">DepoyMint Project: &#8220;<?php echo $proj['name'] ?>&#8221;</h2> 

<h3>Create a snapshot from a blog:</h3>
<form id="sdCreateSnapshot"></form>

<h3>Deploy a snapshot to a blog:</h3>
<form id="sdDeploySnapshot">
</form>

</div>
<script type="text/x-jquery-tmpl" id="sdDeploySnapTmpl">
<input type="hidden" name="action" value="deploymint_deploySnapshot" />
<input type="hidden" name="projectid" value="<?php echo $proj['id'];?>" />
<table class="form-table deploymintTable">
<tr>
    <td>Select a snapshot to deploy:</td>
    <td><select id="sdDepSnapshot" name="name" onchange="deploymint.updateSnapDesc(projectid, jQuery(this).val()); return true;">
        {{if snapshots.length}}
        {{each(i,snap) snapshots}}
        <option value="${snap.name}"{{if selectedSnap == snap.name}} selected{{/if}}>${snap.name} - Created on: ${snap.created}</option>
        {{/each}}
        {{else}}
        <option value="">--No snapshots created yet--</option>
        {{/if}}
        </select> <a href="javascript:void(0)" name='archiveSnapshot'>archive this snapshot</a>
    </td>
</tr>
<tr>
    <td>Currently selected snapshot description:</td>
    <td>
        <textarea rows="5" cols="50" style="width: 550px; height: 200px;" id="sdSnapDesc2" READONLY></textarea>
    </td>
</tr>
<tr>
    <td>Select what to deploy:</td>
    <td>
    {{each(i,opt) deployParts}}
        <label class="deployPart">
            <input type="checkbox" value="1" checked="checked" name="deployParts[${i}]" />
            ${opt}
        </label>
    {{/each}}
    </td>
</tr>
<tr>
    <td>Select a blog to deploy to:</td>
    <td><select id="sdDepBlog" name="blogid">
{{each(i,blog) blogs}}
<option value="${blog.blog_id}">${blog.domain}${blog.path}</option>
{{/each}}
</select>
    </td>
</tr>
<tr><td colspan="2">
    <input type="button" value="Deploy this snapshot to the selected blog" onclick="deploymint.deploySnapshot(jQuery('#sdDeploySnapshot').serializeObject()); return false;" class="button-primary" />
</td></tr>
</table>
</script>

<script type="text/x-jquery-tmpl" id="sdCreateSnapTmpl">
<table class="form-table deploymintTable">
<tr>
    <td>Select a blog to snapshot:</td>
    <td><select id="sdSnapBlog">
{{if blogs.length}}
{{each(i,blog) blogs}}
<option value="${blog.blog_id}">${blog.domain}${blog.path}</option>
{{/each}}
{{else}}
<option value="">--Please add a blog to this project--</option>
{{/if}}
</select>
    </td>
</tr>
<tr>
    <td>Enter a short unique name for your snapshot<br />(only a-z, A-Z, 0-9 and .-_ chars allowed. No spaces.):</td>
    <td><input type="text" id="sdSnapName" size="20" maxlength="20" /></td>
</tr>
<tr>
    <td>Enter a description for your snapshot (text only):</td>
    <td><textarea id="sdSnapDesc" rows="5" cols="60"></textarea></td>
</tr>
<tr><td colspan="2">
    <input type="button" value="Create this snapshot" onclick="deploymint.createSnapshot({projectid:projectid, blogid:jQuery('#sdSnapBlog').val(), name:jQuery('#sdSnapName').val(), desc:jQuery('#sdSnapDesc').val()}); return false;" class="button-primary" />
</td></tr>
</table>
</script>
<script type="text/javascript">
var projectid = <?php echo $proj['id'] ?>;
jQuery(function(){ 
    deploymint.updateCreateSnapshot(projectid); 
    deploymint.updateDeploySnapshot(projectid); 
});
</script>
