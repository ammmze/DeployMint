<?php
/*
  Author:Mark Maunder <mmaunder@gmail.com>
  Author website:http://markmaunder.com/
  License:GPL 3.0
*/

include( dirname( __FILE__ ) . '/widgets.php' );

?>
<div id="sdAjaxLoading" style="display:none;position:fixed;right:1px;top:1px;width:100px;background-color:#F00;color:#FFF;font-size:12px;font-family:Verdana, arial;font-weight:normal;text-align:center;z-index:100;border:1px solid #CCC;">Loading...</div>
<div class="wrap">

  <h2 class="depmintHead">Manage Projects</h2>
  <form>
    <input type="hidden" name="action" value="deploymint_createProject" />
    <table class="form-table deploymintTable">
      <tr>
        <td>Enter the name of a project to create:</td>
        <td><input type="text" name="name" id="sdProjectName" value="" size="55" maxlength="100" /></td>
      </tr>
      <tr>
        <td>Git Origin Location:</td>
        <td><input type="text" name="origin" id="sdProjectOrigin" value="" size="55" maxlength="255" /></td>
      </tr>
      <tr>
        <td>Additional Tables:</td>
        <td><input type="text" name="tables" value="" size="55" maxlength="255" /></td>
      </tr>
      <tr class="note">
        <td colspan="2">Additional tables to include the snapshots (comma separated and exclude
          the table prefix, if the table uses a different prefix or no prefix, then use a backslash
          before the table name. ie \custom_table). Default tables included in the snapshot are:
          <?php echo implode(', ', $this->getTableList());?></td>
      </tr>
      <tr>
        <td colspan="2"><input type="button" name="but2" value="Create project" onclick="deploymint.createProject(jQuery(this).parents('form:first').serializeObject());return false;" class="button-primary" /></td>
      </tr>
    </table>
  </form>

  <p id="sdProjects"></p>

</div>
<script type="text/x-jquery-tmpl" id="sdProjTmpl">
<div id="sdProj${id}">
{{each(i,proj) projects}}
<h2>Project:${proj.name}&nbsp;<a href="#" onclick="deploymint.deleteProject(${proj.id});return false;" style="font-size:10px;">remove</a></h2>
<div class="depProjWrap">
  {{if proj.project_uuid}}
  <div>UUID:${proj.project_uuid}</div>
  {{/if}}
  {{if proj.origin}}
  <div>
    Origin:
    <span class="deploymint-origin" title="${proj.originAvailableMessage}">
    {{if proj.originAvailable}}
      <span class="deploymint-success">${proj.origin}</span>
    {{else}}
      <span class="deploymint-error">${proj.origin} -- Unable to connect</span>
    {{/if}}
    </span>
    <input type="button" name="edit-origin" value="Edit Origin" class="button-secondary" onclick="deploymint.editOrigin(${proj.id}, '${proj.origin}')" />
  </div>
  {{/if}}
  <div>
    Tables:${proj.tables}<input type="button" name="edit-tables" value="Edit Tables" class="button-secondary" onclick="deploymint.editTables(${proj.id}, '${proj.tables}')" />
  </div>
  <br />
  Add a blog to this project:&nbsp;<select id="projAddSel${proj.id}">
  {{if proj.numNonmembers}}
  {{each(k,blog) proj.nonmemberBlogs}}
    <option value="${blog.blog_id}">${blog.blog_name}</option>
  {{/each}}
  {{else}}
  <option value="">--No blogs left to add--</option>
  {{/if}}
  </select>&nbsp;<input type="button" name="but12" value="Add this blog to the project" onclick="deploymint.addBlogToProject({projectID:${proj.id}, blogID:jQuery('#projAddSel${proj.id}').val()});return false;" />
  <h3 class="depSmallHead">Blogs that are part of this project:</h3>
  {{if proj.memberBlogs.length}}
  <ul class="depList">
    {{each(l,blog) proj.memberBlogs}}
        <li>${blog.blog_name}&nbsp;<a href="#" onclick="deploymint.removeBlogFromProject({projectID:${proj.id}, blogID:${blog.blog_id}}); return false;" style="font-size: 10px;">remove</a></li>
    {{/each}}
  </ul>
  {{else}}
  <i>&nbsp;&nbsp;You have not added any blogs to this project yet.</i>
  {{/if}}
</div>
{{/each}}

</div>
</script>
<script type="text/javascript">
jQuery(function(){

  deploymint.reloadProjects();

});
</script>
