window['deploymintSS'] = {
    loginModal : null,
    addCredentials: function(data, callback){
        if (this.loginModal == null) {
            var login = jQuery("#DeployMint-Login");
            login.easyModal({
                onOpen : function(modal){
                    jQuery(":input:first", modal).focus();
                    jQuery("form", modal).unbind('submit').bind('submit',function(){return false;})
                    jQuery("[name='continue']", modal).unbind('click').bind('click',function(){
                        var u = jQuery("input[name='username']", modal).val();
                        var p = jQuery("input[name='password']", modal).val();
                        if (u.length > 0 && p.length > 0) {
                            data.username=u;
                            data.password=p;
                            login.trigger('closeModal');
                            (callback || function(){})(data);
                        }
                    });
                    jQuery("[name='close']", modal).unbind('click').bind('click',function(){
                        login.trigger('closeModal');
                    });
                },
                onClose : function(modal){}
            })
            this.loginModal = login;
        }
        this.loginModal.trigger('openModal');
    },
    addBlogToProject: function(data){
        var self = this;
        this.addCredentials(data, function(d){
            self.parent.addBlogToProject(d);
        });
    },  
    removeBlogFromProject: function(data){
        var self = this;
        this.addCredentials(data, function(d){
            self.parent.addBlogToProject(d);
        });
    },
    createSnapshot: function(data){
        var self = this;
        this.addCredentials(data, function(d){
            self.parent.createSnapshot(d);
        });
    },
    deploySnapshot: function(data){
        var self = this;
        this.addCredentials(data, function(d){
            self.parent.deploySnapshot(d);
        });
    },
    overwrite: function(){
        deploymint.parent = deploymint.parent || jQuery.extend({}, deploymint);
        for(var key in this){
            if (this.hasOwnProperty(key)){
                if (key != 'overwrite' && deploymint.hasOwnProperty(key)) {
                    deploymint.parent[key] = deploymint[key];
                }
                deploymint[key] = this[key];
            }
        }
    }
}
deploymintSS.overwrite();