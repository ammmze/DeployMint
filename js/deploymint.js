(function($){
$.fn.serializeObject = function () {
  var

    result = Object.create(null),
    mapper = function (element) {
      element.name = $.camelCase(element.name);
      return element;
    },
    extend = function (i, element) {
      var node = result[element.name];

// If node with same name exists already, need to convert it to an array as it
// is a multi-value field (i.e., checkboxes)

      if ('undefined' !== typeof node && node !== null) {
        result[element.name] = node.push ? node.push(element.value) : [node, element.value];
      } else {
        result[element.name] = element.value;
      }
    };

// For each serialzable element, convert element names to camelCasing and
// extend each of them to a JSON object

  $.each($.map(this.serializeArray(), mapper), extend);
  return result;
};
})(jQuery);




/*
    Author: Mark Maunder <mmaunder@gmail.com>
    Author website: http://markmaunder.com/
    License: GPL 3.0
*/

if(! window['deploymint']){
window['deploymint'] = {
    init: function(){
        var self = this;
        jQuery('#sdAjaxLoading').hide().ajaxStart(function(){ jQuery(this).show(); }).ajaxStop(function(){ jQuery(this).hide(); });
        jQuery('#sdDeploySnapshot [name="archiveSnapshot"]').live('click', function(){
            var projectId = jQuery('[name="projectid"]').val();
            var snapshot = jQuery('#sdDepSnapshot').val();
            if (snapshot.length > 0) {
                self.archiveSnapshot(projectId, snapshot);
            }
        });
    },
    alertModal : null,
    alert : function(message) {
        if (this.alertModal == null) {
            var d = jQuery("#DeployMint-Alert");
            d.easyModal({
                onOpen : function(modal){
                    jQuery(":input:first", modal).focus();
                    jQuery("[name='close']", modal).unbind('click').bind('click',function(){
                        d.trigger('closeModal');
                    });
                },
                onClose : function(modal){}
            })
            this.alertModal = d;
        }
        this.alertModal.find('.message').html(message);
        this.alertModal.trigger('openModal');
    },
    working : function(message) {
        var d = jQuery("#DeployMint-Working").clone().appendTo('body');
        d.easyModal({
            overlayClose : false,
            closeOnEscapse : false,
            onOpen : function(modal){
                jQuery(":input:first", modal).focus();
                jQuery("[name='close']", modal).unbind('click').bind('click',function(){
                    d.trigger('closeModal');
                });
            },
            onClose : function(modal){}
        })
        d.find('.message').html(message || 'Working');
        d.trigger('openModal');
        d.done = function(){
            d.trigger('closeModal');
        }
        return d;
    },
    updateOptions: function(data){
        var self = this;
        jQuery('#sdOptErrors').hide();
        jQuery('#sdOptErrors').empty();
        data.action = "deploymint_updateOptions"
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: data,
            success: function(resp){
                d.done();
                if(resp.errs){
                    for(var i = 0; i < resp.errs.length; i++){
                        console.log(resp.errs[i])
                        deploymint.addOptError(resp.errs[i], true);
                    }
                    jQuery('#sdOptErrors').fadeIn();
                    return;
                } else if(resp.ok){
                    jQuery('.error').hide();
                    deploymint.addOptError("Your options have been succesfully updated.");
                    jQuery('#sdOptErrors').fadeIn();
                } else {
                    deploymint.addOptError("An error occured updating your options.", true);
                    jQuery('#sdOptErrors').fadeIn();
                }
            },
            error: function(){
                deploymint.addOptError("An error occured updating your options.", true);
                jQuery('#sdOptErrors').fadeIn();
            }
            });

    },
    addOptError: function(err, isError){
        jQuery('#sdOptErrors').append('<div class="' + (isError ? 'error' : 'updated') + ' sdOptErrWrap"><p><strong>' + err + '</strong></p></div>');
    },
    addBlogToProject: function(data){
        var self = this;
        data.action = "deploymint_addBlogToProject";
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: data,
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                self.reloadProjects();
            },
            error: function(){}
            });

    },
    removeBlogFromProject: function(data){
        data.action = "deploymint_removeBlogFromProject";
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: data,
            success: function(resp){
                d.done()
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                deploymint.reloadProjects();
            },
            error: function(){}
            });

    },
    deleteBackups: function(delArr){
        if(confirm("Are you 100% sure you want to delete the selected backups? This can't be undone.")){
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_deleteBackups",
                toDel: delArr
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                //deploymint.reloadProjects();
                window.location.reload(false);

            },
            error: function(){}
            });
        }

    },
    deleteProject: function(projectID){
        var self = this;
        if(confirm("Are you 100% sure you want to delete this project? This can't be undone.")){
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_deleteProject",
                projectID: projectID
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                //deploymint.reloadProjects();
                window.location.reload(false);

            },
            error: function(){}
            });
        }

    },
    createProject: function(data){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: data,
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                //deploymint.reloadProjects();
                window.location.reload(false);
            },
            error: function(){}
            });
    },
    reloadProjects: function(){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_reloadProjects"
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                jQuery('#sdProjects').empty();
                jQuery('#sdProjTmpl').tmpl(resp).appendTo('#sdProjects');
            },
            error: function(){}
            });


    },
    addBlog: function(url, name, ignoreCert){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_addBlog",
                url: url,
                name: name,
                ignoreCert: ignoreCert ? 1 : 0
                },
            success: function(resp){
                d.done();
                self.reloadBlogs();
            },
            error: function(){}
            });


    },
    saveBlog: function(id, url, name, ignoreCert){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_saveBlog",
                id: id,
                url: url,
                name: name,
                ignoreCert: ignoreCert ? 1 : 0
                },
            success: function(resp){
                jQuery('#sdBlogName').val('');
                jQuery('#sdBlogUrl').val('');
                jQuery('#sdBlogId').val('');
                jQuery('#sdBlogIgnoreCert').removeAttr('checked');

                jQuery('#saveBlogBtn').hide();
                jQuery('#addBlogBtn').show();

                d.done();
                self.reloadBlogs();
            },
            error: function(){}
        });

    },
    editBlog: function(id){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_reloadBlogs"
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                jQuery.each(resp.blogs, function () {
                    if (this.id == id) {
                        jQuery('#sdBlogName').val(this.blog_name);
                        jQuery('#sdBlogUrl').val(this.blog_url);
                        jQuery('#sdBlogId').val(this.id);
                        if (this.ignore_cert == '1') {
                            jQuery('#sdBlogIgnoreCert').attr('checked', 'checked');
                        } else {
                            jQuery('#sdBlogIgnoreCert').removeAttr('checked');
                        }
                        jQuery('#addBlogBtn').hide();
                        jQuery('#saveBlogBtn').show();
                    }
                })
            },
            error: function(){}
        });


    },
    removeBlog: function(id){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_removeBlog",
                id: id
                },
            success: function(resp){
                d.done();
                self.reloadBlogs();
            },
            error: function(){}
            });


    },
    reloadBlogs: function(){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_reloadBlogs"
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                jQuery('#sdBlogs').empty();
                if (resp && resp.blogs && resp.blogs.length){
                    jQuery('#sdBlogTmpl').tmpl(resp).appendTo('#sdBlogs');
                }
            },
            error: function(){}
            });


    },
    createSnapshot: function(data){
        var self = this;
        data.action = "deploymint_createSnapshot";
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: data,
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                if(! resp.ok){
                    self.alert("An unknown error occurred taking your snapshot.");
                    return;
                }
                self.updateDeploySnapshot(projectid, name);
            },
            error: function(arg1, arg2, arg3){ throw("Ajax exception caught: " + arg1);  }
            });

    },
    deploySnapshot: function(data){
        var self = this;
        //data.action = "deploymint_deploySnapshot";
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: data,
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                if(resp.ok){
                    //self.alert("Deployed succesfully. The total time the database was locked was " + resp.lockTime + " seconds.");
                    self.alert("Deployed succesfully.");
                } else {
                    self.alert("An unknown error occurred deploying your snapshot.");
                    return;
                }
            },
            error: function(arg1, arg2, arg3){ throw("Ajax exception caught: " + arg1);  }
            });

    },
    undoDeploy: function(dbname){
        var self = this;
        if(! confirm("Are you sure you want to revert your ENTIRE Wordpress installation to this backup that we took before deployment?")){
            return;
        }
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_undoDeploy",
                dbname: dbname
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    window.location.reload(false);

                    return;
                }
                if(resp.ok){
                    self.alert("The Wordpress installation was sucessfully reverted.");
                    window.location.reload(false);
                    return;
                } else {
                    self.alert("An unknown error occurred trying to revert your wordpress installation.");
                    window.location.reload(false);
                    return;
                }
            },
            error: function(arg1, arg2, arg3){ throw("Ajax exception caught: " + arg1);  }
            });

    },

    updateDeploySnapshot: function(projectid, selectedSnap){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_updateDeploySnapshot",
                projectid: projectid
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                jQuery('#sdDeploySnapshot').empty();
                resp['selectedSnap'] = selectedSnap;
                jQuery('#sdDeploySnapTmpl').tmpl(resp).appendTo('#sdDeploySnapshot');
                deploymint.updateSnapDesc(projectid, jQuery('#sdDepSnapshot').val());
            },
            error: function(arg1, arg2, arg3){ throw("Ajax exception caught: " + arg1);  }
            });

    },
    updateSnapDesc: function(projectid, snapname){
        var self = this;
        if(! snapname){ return; }
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_updateSnapDesc",
                projectid: projectid,
                snapname: snapname
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                jQuery('#sdSnapDesc2').empty();
                jQuery('#sdSnapDesc2').html(resp.desc);
            },
            error: function(arg1, arg2, arg3){ throw("Ajax exception caught: " + arg1);  }
            });

    },


    updateCreateSnapshot: function(projectid){
        var self = this;
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_updateCreateSnapshot",
                projectid: projectid
                },
            success: function(resp){
                d.done();
                if(resp.err){
                    self.alert(resp.err);
                    return;
                }
                jQuery('#sdCreateSnapshot').empty();
                jQuery('#sdCreateSnapTmpl').tmpl(resp).appendTo('#sdCreateSnapshot');
            },
            error: function(arg1, arg2, arg3){ throw("Ajax exception caught: " + arg1);  }
            });

    },
    deploy: function(){
        var d = this.working();
        jQuery.ajax({
            type: "POST",
            url: DeployMintVars.ajaxURL,
            dataType: "json",
            data: {
                action: "deploymint_deploy",
                deployFrom: jQuery('#deploymintFrom').val(),
                deployTo: jQuery('#deploymintTo').val()
                },
            success: function(resp){
                d.done();
                jQuery('#deploymintnotice1').empty().hide();
                jQuery('#deployminttmpl1').tmpl(resp).appendTo('#deploymintnotice1');
                jQuery('#deploymintnotice1').fadeIn();
            },
            error: function(xhr, ajo, err){
            }
        });
    },
    deployFinal: function(fromid, toid, msg){

    },

    editOrigin : function(projectId, origin)
    {
        var self = this;
        var o = window.prompt("Enter new origin", origin);
        if (o != null) {
            var d = this.working();
            jQuery.ajax({
                type: "POST",
                url: DeployMintVars.ajaxURL,
                dataType: "json",
                data: {
                    action: "deploymint_updateOrigin",
                    projectId: projectId,
                    origin: o
                    },
                success: function(resp){
                    d.done();
                    self.reloadProjects();
                },
                error: function(xhr, ajo, err){
                }
            });
        }
    },

    editTables : function(projectId, tables)
    {
        var self = this;
        var o = window.prompt("Enter new tables (command separated and exclude the table prefix, if the table uses a different prefix or no prefix, then use a backslash before the table name. ie \\custom_table)", tables);
        if (o != null) {
            var d = this.working();
            jQuery.ajax({
                type: "POST",
                url: DeployMintVars.ajaxURL,
                dataType: "json",
                data: {
                    action: "deploymint_updateTables",
                    projectId: projectId,
                    tables: o
                    },
                success: function(resp){
                    d.done();
                    self.reloadProjects();
                },
                error: function(xhr, ajo, err){
                }
            });
        }
    },

    archiveSnapshot : function(projectId, snapshots)
    {
        if (typeof snapshots != 'array') {
            snapshots = [snapshots];
        }
        var self = this;
        var confirm = window.confirm("Are you sure you want to archive this snapshot?");
        if (confirm) {
            var d = this.working();
            jQuery.ajax({
                type: "POST",
                url: DeployMintVars.ajaxURL,
                dataType: "json",
                data: {
                    action: "deploymint_archiveSnapshot",
                    projectId: projectId,
                    snapshots: snapshots
                    },
                success: function(resp){
                    d.done();
                    if (resp && resp.err) {
                        self.alert(resp.err);
                    }
                    self.updateDeploySnapshot(projectId)
                },
                error: function(xhr, ajo, err){
                }
            });
        }
    }

};
}
jQuery(document).ready(function(){
    deploymint.init();
    });
