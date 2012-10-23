<?php
/*
    Author: Branden Cash <bcash@parchment.com>
    License: GPL 3.0
*/
?>
<div id="sdAjaxLoading" style="display: none; position: fixed; right: 1px; top: 1px; width: 100px; background-color: #F00; color: #FFF; font-size: 12px; font-family: Verdana, arial; font-weight: normal; text-align: center; z-index: 100; border: 1px solid #CCC;">Loading...</div>
<div class="wrap">
    <h2 class="depmintHead">Manage Blogs</h2>
    <p>These should be remote wordpress installations which also have the DeployMint plugin activated. These will be where snapshots can be deployed.</p>
    <table class="form-table deploymintTable">
    <tr>
        <td>Enter the blog url:</td>
        <td><input type="text" id="sdBlogUrl" value="" size="75" maxlength="255" /></td>

        <td><input type="button" name="but2" value="Add a blog" onclick="deploymint.addBlog(jQuery('#sdBlogUrl').val()); return false;" class="button-primary" /></td>
    </tr>
    </table>
    
    <div id="sdBlogs">
    </div>

        
</div>
<script type="text/x-jquery-tmpl" id="sdBlogTmpl">
<h2 class="">Current Blogs</h2>
<table class="deploymint-blogs">
    <thead>
        <tr>
            <th>URL</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        {{each(i,blog) blogs}}
            <tr class="deploymint-blog">
                <td>${blog.blog_url}</td>
                <td>
                    <input disabled="disabled" type="button" value="Edit" title="Not yet implemented" onclick="deploymint.editBlog(${blog.id}); return false;" class="button-primary">
                    <input type="button" value="Remove" onclick="deploymint.removeBlog(${blog.id}); return false;" class="button-secondary">
                </td>
            </tr>
        {{/each}}
    </tbody>
</table>
</script>
<script type="text/javascript">
jQuery(function(){
    deploymint.reloadBlogs();
    });
</script>
